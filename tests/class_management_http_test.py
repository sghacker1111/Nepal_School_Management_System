"""Catalog classes are protected; per-school visibility preserves every linked record."""
import re
from http_support import ERPHttpFixture

def names(body):
    return re.findall(r'<td><strong>(.*?)</strong></td>',body,re.S)

with ERPHttpFixture() as app:
    f=app.fixture
    valid=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 7'").fetchone()[0]
    app.db.execute("INSERT INTO classes(school_id,name,name_np,level,grading_type,is_active) VALUES(1,'7','7','Secondary','gpa',1)")
    legacy=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO sections(school_id,class_id,name) VALUES(1,?,'Legacy')",(legacy,))
    section=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,section_id,first_name_en,last_name_en,gender,school_id,status) VALUES('LEGACY-FIXTURE',1,?,?,'Legacy','Student','Male',1,'active')",(legacy,section))
    student=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id) VALUES(1,?,?)',(legacy,f['subject']))
    app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours) VALUES(?,?,?,75,26.25,25,10,4)',(f['exam'],legacy,f['subject']))
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,40,15)',(f['exam'],student,f['subject']));app.db.commit()
    retained={table:app.snapshot(table) for table in ['students','sections','class_subjects','exam_subjects','marks']}
    schema=app.db.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone()
    app.login('school_admin')
    assert '7' in names(app.request('/classes'))
    for action in ['remove_legacy_seven','restore_class']:
        app.request('/classes',{'action':action,'class_id':legacy,'password':'TestPassword123!','confirm_cleanup':1},status=403)
    app.request('/classes?delete_class='+str(valid),{},status=403)
    app.request('/classes/edit?class_id='+str(valid),{'name':'Changed catalog class'},status=403)
    app.request('/admin/offerings?school_id=1',{'offering_action':'hide_class','offering_class':legacy},status=403)
    super_name=app.db.execute("SELECT username FROM users WHERE role='super_admin'").fetchone()[0];app.login(super_name)
    source=app.db.execute('SELECT source_id FROM classes WHERE id=?',(valid,)).fetchone()[0]
    app.request('/classes?delete_class='+str(source),{},status=403)
    url='/admin/offerings?school_id=1'
    app.request(url,{'offering_action':'hide_class','offering_class':legacy},status=419,csrf=False)
    body=app.request(url,{'offering_action':'hide_class','offering_class':legacy});assert 'School class visibility saved' in body,re.findall(r'<div class="alert alert-danger".*?</div>',body,re.S)
    assert app.db.execute('SELECT is_active FROM classes WHERE id=?',(legacy,)).fetchone()==(0,)
    for role in ['school_admin','teacher','principal','exam_officer']:
        app.login(role);body=app.request('/classes');assert '7' not in names(body) and 'Class 7' in names(body)
        app.request(url,{'offering_action':'show_class','offering_class':legacy},status=403)
    app.login(super_name)
    body=app.request(url,{'offering_action':'show_class','offering_class':legacy});assert 'School class visibility saved' in body,re.findall(r'<div class="alert alert-danger".*?</div>',body,re.S)
    assert app.db.execute('SELECT is_active FROM classes WHERE id=?',(legacy,)).fetchone()==(1,)
    app.login('other_admin');assert '7' not in names(app.request('/classes'))
    app.request('/classes/edit?class_id='+str(valid),status=404)
    for table,rows in retained.items():assert app.snapshot(table)==rows,table
    assert schema==app.db.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone()
    print(f'Class management ownership and visibility passed: {app.count} requests; linked records and schema preserved.')
