"""Class archive visibility and recovery on a disposable current-schema database."""
import re
from html import unescape
from http_support import ERPHttpFixture


def class_names(body):
    first_table = re.search(r'<table\b[^>]*>(.*?)</table>', body, re.S).group(1)
    return [unescape(name) for name in re.findall(r'<td><strong>(.*?)</strong></td>', first_table)]


with ERPHttpFixture() as app:
    f = app.fixture
    schema_before = app.db.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone()
    assert schema_before == ('16',)
    valid = app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 7'").fetchone()[0]
    valid_before = app.db.execute('SELECT * FROM classes WHERE id=?', (valid,)).fetchone()
    app.db.execute("UPDATE users SET username='sgsunil' WHERE id=?", (f['ids']['teacher'],))
    app.db.execute("INSERT INTO classes(school_id,name,name_np,level,grading_type,is_active) VALUES(1,'7','7','Secondary','gpa',1)")
    legacy = app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO sections(school_id,class_id,name) VALUES(1,?,'Legacy')", (legacy,))
    legacy_section = app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,section_id,first_name_en,last_name_en,gender,school_id,status) VALUES('LEGACY-FIXTURE',1,?,?,'Legacy','Student','Male',1,'active')", (legacy, legacy_section))
    legacy_student = app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id) VALUES(1,?,?)', (legacy, f['subject']))
    app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours) VALUES(?,?,?,75,26.25,25,10,4)', (f['exam'], legacy, f['subject']))
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,40,15)', (f['exam'], legacy_student, f['subject']))
    app.db.execute("UPDATE classes SET name='Other-school private class' WHERE id=?", (f['otherClass'],))
    app.db.commit()
    retained = {table: app.snapshot(table) for table in ['students', 'sections', 'class_subjects', 'exam_subjects', 'marks']}

    def unchanged_records():
        assert retained == {table: app.snapshot(table) for table in retained}
        assert app.db.execute('SELECT * FROM classes WHERE id=?', (valid,)).fetchone() == valid_before
        assert app.db.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone() == schema_before

    path = '/modules/classes/index.php'
    app.login('school_admin')
    body = app.request(path)
    assert '7' in class_names(body) and 'Class 7' in class_names(body)
    assert 'Other-school private class' not in class_names(body)
    assert 'Show archived classes' in body and 'all associated data' not in body
    app.login('sgsunil')
    body = app.request(path)
    assert '7' in class_names(body) and 'Remove legacy class' not in body
    app.login('school_admin')
    cleanup = {'action': 'remove_legacy_seven', 'class_id': legacy, 'password': 'TestPassword123!', 'confirm_cleanup': 1}
    for data, expected in [
        ({**cleanup, 'confirm_cleanup': ''}, 'Confirm removal'),
        ({**cleanup, 'password': 'wrong'}, 'current administrator password'),
        ({**cleanup, 'class_id': valid}, 'class named exactly 7'),
    ]:
        body = app.request(path, data)
        assert expected in body and app.db.execute('SELECT is_active FROM classes WHERE id=?', (legacy,)).fetchone() == (1,)
        unchanged_records()
    app.request(path, cleanup, status=419, csrf=False)
    app.request(path, {**cleanup, 'class_id': f['otherClass']}, status=422)
    body = app.request(path, cleanup)
    assert 'All linked records are retained' in body and app.db.execute('SELECT is_active FROM classes WHERE id=?', (legacy,)).fetchone() == (0,)
    assert '7' not in class_names(body) and 'Class 7' in class_names(body)
    assert 'Section Legacy' not in body
    unchanged_records()
    body = app.request(path+'?show_archived=1')
    assert '7' in class_names(body) and 'Archived' in body and 'Confirm restore' in body and 'Section Legacy' in body
    assert 'Other-school private class' not in class_names(body)
    for role in ['sgsunil', 'principal', 'exam_officer']:
        app.login(role)
        for query in ['', '?show_archived=1']:
            body = app.request(path+query)
            assert '7' not in class_names(body) and 'Class 7' in class_names(body)
            assert 'Confirm restore' not in body and 'Show archived classes' not in body
        app.request(path, cleanup, status=403)
        app.request(path, {'action': 'restore_class', 'class_id': legacy, 'password': 'TestPassword123!'}, status=403)
        unchanged_records()
    app.login('school_admin')
    restore = {'action': 'restore_class', 'class_id': legacy, 'password': 'TestPassword123!'}
    body = app.request(path, restore)
    assert 'already exists' in body and app.db.execute('SELECT is_active FROM classes WHERE id=?', (legacy,)).fetchone() == (0,)
    app.request(path, {**restore, 'class_id': f['otherClass']}, status=422)
    app.request(path, restore, status=419, csrf=False)
    # A duplicate must be renamed before the normal recovery action can restore it.
    body = app.request(f'/modules/classes/edit.php?class_id={legacy}', {'name': 'Recovered legacy class', 'name_np': '', 'level': 'Secondary'})
    assert 'Class updated successfully' in body
    body = app.request(path, {**restore, 'password': 'wrong'})
    assert 'current administrator password' in body and app.db.execute('SELECT is_active FROM classes WHERE id=?', (legacy,)).fetchone() == (0,)
    body = app.request(path, restore)
    assert 'Class restored successfully' in body and 'Recovered legacy class' in class_names(body)
    assert app.db.execute('SELECT is_active FROM classes WHERE id=?', (legacy,)).fetchone() == (1,)
    unchanged_records()
    app.login('other_admin')
    body = app.request(path+'?show_archived=1')
    assert 'Recovered legacy class' not in class_names(body) and 'Other-school private class' in class_names(body)
    app.request(path, restore, status=422)
    unchanged_records()
    print(f'Class Management archive visibility and recovery passed: {app.count} HTTP requests; schema {schema_before[0]} and linked records unchanged.')
