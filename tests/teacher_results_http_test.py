"""Class supervision, subject ledgers and locked corrections through actual forms."""
import re
from http_support import ERPHttpFixture

def ledger_table(body):
    return re.search(r'<table[^>]+id="ledgerTable".*?</table>',body,re.S).group(0)

with ERPHttpFixture() as app:
    f=app.fixture;cls=f['class'];own=f['subject'];sid=f['student'];exam=f['exam'];teacher=f['ids']['teacher']
    app.db.execute("INSERT INTO users(username,password,role,full_name,school_id,status) SELECT 'class_teacher',password,'teacher','Synthetic Class Teacher',1,1 FROM users WHERE id=?",(teacher,));ct=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('UPDATE classes SET class_teacher_id=? WHERE id=?',(ct,cls))
    other=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND subject_id<>? AND is_optional=0 LIMIT 1',(cls,own)).fetchone()[0]
    own_name=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(own,)).fetchone()[0];other_name=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(other,)).fetchone()[0]
    app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES(?,?,?,75,26.25,25,10,4,0)',(exam,cls,other))
    app.db.execute("INSERT INTO sections(class_id,name,school_id) VALUES(?,'B',1)",(cls,));section_b=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,section_id,first_name_en,last_name_en,roll_number,gender,school_id,status) VALUES('SECTION-B',1,?,?,'SecondSection','Student',999,'Female',1,'active')",(cls,section_b));student_b=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO exam_symbols(exam_id,student_id,school_id,symbol_no) VALUES(?,?,1,'RESULT-2001')",(exam,sid));app.db.commit()
    marks_path=f'/modules/results/marks_entry.php?exam={exam}&class={cls}';ledger_path=f'/modules/results/ledger.php?exam={exam}&class={cls}'
    def marks_input(student,subject,th):
        return {'save_marks':'1','marks_complete':'1','exam_id':exam,'class_id':cls,f'marks[{student}][{subject}][theory]':str(th),f'marks[{student}][{subject}][participation]':'3',f'marks[{student}][{subject}][project_work]':'12',f'marks[{student}][{subject}][previous_exam]':'5'}
    app.login('teacher');body=app.request(marks_path,marks_input(sid,own,55));assert 'Marks and attendance saved successfully' in body
    before=app.snapshot('marks');body=app.request(marks_path,marks_input(sid,other,61));assert 'Subject teachers may enter marks only' in body and app.snapshot('marks')==before
    body=app.request(ledger_path);table=ledger_table(body);assert own_name in table and other_name in table and 'All Subjects (full-class ledger)' in body and 'SecondSection Student' in table
    body=app.request(ledger_path+f'&subject={own}');table=ledger_table(body);assert own_name in table and other_name not in table and 'Subject GPA' in table
    assert 'Print Ledger' in body and 'RESULT-2001' in table
    missing_row=re.search(r'<tr[^>]+data-name="SecondSection Student".*?</tr>',table,re.S).group(0)
    assert 'data-status="incomplete"' in missing_row and '>0<' not in missing_row and '—' in missing_row,'Missing marks must not be printed as zero or a completed result'
    body=app.request(ledger_path+f'&subject={other}');assert other_name in ledger_table(body) and 'View / correct marks' not in body and 'Open assigned marks entry' not in body
    app.request(marks_path+f'&subject={other}',status=404)
    app.login('class_teacher');body=app.request(marks_path);assert 'Class teacher access' in body and f'name="marks[{sid}][{other}][theory]"' in body and 'Full-class ledger' in body
    app.request(marks_path,marks_input(sid,other,61));app.request(marks_path,marks_input(student_b,other,62));app.request(marks_path,marks_input(student_b,own,63))
    assert app.db.execute('SELECT theory_obtained,entered_by FROM marks WHERE student_id=? AND subject_id=?',(sid,other)).fetchone()==(61,ct)
    body=app.request(ledger_path+'&mode=marks');table=ledger_table(body);assert own_name in table and other_name in table and 'All Subjects (full-class ledger)' in body and 'SecondSection Student' in table and '<strong>999</strong>' in table and '>61<' in table
    school_name=app.db.execute('SELECT name_en FROM schools WHERE id=1').fetchone()[0];assert school_name in body and 'MODEL SECONDARY SCHOOL' not in body and '/assets/css/result-ledger.css' in body
    css=app.request('/assets/css/result-ledger.css');assert '@page result-ledger' in css and 'size: A4 landscape' in css and 'page: result-ledger' in css
    table=ledger_table(app.request(ledger_path+f'&subject={other}&mode=marks'));assert other_name in table and own_name not in table and '>61<' in table and '>62<' in table
    # A pupil not taking an optional subject is N/A, not a failed or zero-mark pupil.
    optional=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND is_optional=1 LIMIT 1',(cls,)).fetchone()[0]
    app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES(?,?,?,75,26.25,25,10,4,1)',(exam,cls,optional));app.db.commit()
    table=ledger_table(app.request(ledger_path+f'&subject={optional}'));assert table.count('data-status="na"')==2 and 'Not Graded' not in table and '>0<' not in table
    app.db.execute('DELETE FROM exam_subjects WHERE exam_id=? AND subject_id=?',(exam,optional));app.db.commit()
    before=app.snapshot('marks');app.request('/modules/exams/index.php?reopen='+str(exam),{},status=403);assert app.snapshot('marks')==before
    # Lock still applies to all class-teacher subjects, including crafted saves.
    app.login('school_admin');app.request('/modules/exams/index.php?lock='+str(exam),{})
    app.login('class_teacher');body=app.request(marks_path);assert re.search(r'<fieldset\s+disabled[^>]+id="marksFields"',body) and 'name="save_marks"' not in body
    before=app.snapshot('marks');body=app.request(marks_path,marks_input(sid,other,70));assert 'locked or published' in body and app.snapshot('marks')==before
    app.request(ledger_path);app.request(ledger_path+f'&subject={other}')
    app.login('school_admin');app.request('/modules/exams/index.php?reopen='+str(exam),{})
    app.login('class_teacher');app.request(marks_path,marks_input(sid,other,70));assert app.db.execute('SELECT theory_obtained FROM marks WHERE student_id=? AND subject_id=?',(sid,other)).fetchone()[0]==70
    # Supervision never grants a second class or a second school's records.
    unassigned=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 8'").fetchone()[0]
    unassigned_subject=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND is_optional=0 LIMIT 1',(unassigned,)).fetchone()[0]
    app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES(?,?,?,75,26.25,25,10,4,0)',(exam,unassigned,unassigned_subject))
    app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,first_name_en,last_name_en,gender,school_id,status) VALUES('OTHER-CLASS-LEDGER',1,?,'OtherClass','Candidate','Female',1,'active')",(unassigned,));other_class_student=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,70,20)',(exam,other_class_student,unassigned_subject));app.db.commit()
    other_class_ledger=app.request(f'/modules/results/ledger.php?exam={exam}&class={unassigned}&mode=marks')
    assert 'OtherClass Candidate' in ledger_table(other_class_ledger) and '>70<' in ledger_table(other_class_ledger) and 'Print Ledger' in other_class_ledger and 'View / correct marks' not in other_class_ledger
    for path in [f'/modules/results/marks_entry.php?exam={exam}&class={unassigned}',f'/modules/results/ledger.php?exam={exam}&class={f["otherClass"]}']:
        app.request(path,status=404)
    before=app.snapshot('marks');app.request(marks_path,{**marks_input(sid,other,75),'class_id':unassigned},status=422);assert app.snapshot('marks')==before
    app.request(f'/modules/learning/curriculum.php?action=get_chapters&class_id={cls}&subject_id={other}',status=404)
    # Section-specific teaching assignments remain confined when printing/entering marks.
    app.db.execute('UPDATE class_subjects SET section_id=? WHERE class_id=? AND subject_id=?',(f['section'],cls,own));app.db.commit()
    app.login('teacher');body=app.request(ledger_path);assert 'SecondSection Student' in ledger_table(body)
    app.request(ledger_path+f'&section={section_b}')
    body=app.request(marks_path);assert 'SecondSection' not in body
    before=app.snapshot('marks');app.request(marks_path,marks_input(student_b,own,74));assert app.snapshot('marks')==before
    app.login('class_teacher');table=ledger_table(app.request(ledger_path));assert 'SecondSection Student' in table
    # Grade Only is the default; Marks Only retains actual numeric unit marks.
    app.db.execute("UPDATE exams SET type='unit',has_internal=0 WHERE id=?",(exam,));app.db.commit()
    table=ledger_table(app.request(ledger_path+f'&subject={other}'));assert 'GRADE (TH)' in table and '>70<' not in table and '>62<' not in table
    table=ledger_table(app.request(ledger_path+f'&subject={other}&mode=marks'));assert '>TH<' in table and '>70<' in table and '>62<' in table
    # Reassigning a class removes the old supervisor's full-class authority immediately.
    app.db.execute('UPDATE classes SET class_teacher_id=? WHERE id=?',(teacher,cls));app.db.commit()
    app.request(ledger_path);app.request(marks_path,status=404)
    print(f'Teacher results HTTP passed: {app.count} requests; class corrections, full/subject ledgers, numeric unit marks, sections, lock/reopen and tenant isolation.')
