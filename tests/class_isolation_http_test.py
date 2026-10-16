"""Class dropdowns select identities, never partial class-name strings."""
import json, re
from http_support import ERPHttpFixture

def grid(body, name):
    return re.search(r'<table[^>]*id="'+name+r'".*?</table>',body,re.S).group(0)

with ERPHttpFixture() as app:
    classes={}
    for grade in [1,10,11,12]:
        cid=app.db.execute('SELECT id FROM classes WHERE school_id=1 AND name=?',('Class '+str(grade),)).fetchone()[0]
        classes[grade]=cid
        app.db.execute("INSERT INTO subjects(school_id,name_en,code,type) VALUES(1,?,?,'compulsory')",('OnlyGrade'+str(grade),'FILTER-'+str(grade)))
        sub=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
        app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id,teacher_id,is_active) VALUES(1,?,?,?,1)',(cid,sub,str(app.fixture['ids']['teacher'])))
        app.db.execute("INSERT INTO students(school_id,academic_year_id,class_id,student_id,first_name_en,last_name_en,gender,status) VALUES(1,1,?,?,?,'Pupil','Male','active')",(cid,'CLASS-ID-'+str(grade),'OnlyGrade'+str(grade)))
        sid=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
        app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours) VALUES(?,?,?,100,35,0,0,4)',(app.fixture['exam'],cid,sub))
        app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,80,0)',(app.fixture['exam'],sid,sub))
        app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,question_text,type,marks) VALUES(1,?,? ,'Test',?,'Short',1)",(cid,sub,'OnlyGrade'+str(grade)+' question'))
    app.db.commit();app.login('school_admin')
    for grade,cid in classes.items():
        for section,table in [('subjects','subjectTable'),('students','studentTable')]:
            body=app.request(f'/modules/{section}/index.php?class={cid}')
            t=grid(body,table)
            assert 'OnlyGrade'+str(grade) in t
            for other in classes:
                if other!=grade: assert 'OnlyGrade'+str(other)+(r'<' if section=='subjects' else ' Pupil') not in t,(section,grade,other)
        result=json.loads(app.request(f'/modules/learning/curriculum.php?action=get_subjects&class_id={cid}'))
        names=[row['name_en'] for row in result['subjects']]
        assert 'OnlyGrade'+str(grade) in names
        assert not any('OnlyGrade'+str(other) in names for other in classes if other!=grade)
        exam=app.fixture['exam']
        for path,marker in [(f'/modules/results/index.php?exam={exam}&class={cid}','resultsTable'),(f'/modules/results/ledger.php?exam={exam}&class={cid}&mode=marks','ledgerTable'),(f'/modules/attendance/index.php?class={cid}','attendanceTable')]:
            t=grid(app.request(path),marker);assert 'OnlyGrade'+str(grade)+' Pupil' in t
            assert not any('OnlyGrade'+str(other)+' Pupil' in t for other in classes if other!=grade)
        for path in [f'/modules/results/print_bulk.php?exam={exam}&class={cid}',f'/modules/results/marks_entry.php?exam={exam}&class={cid}',f'/modules/exams/admit_cards.php?exam_id={exam}&class_id={cid}&student_mode=all&render=1']:
            body=app.request(path);assert 'OnlyGrade'+str(grade)+' Pupil' in body
            assert not any('OnlyGrade'+str(other)+' Pupil' in body for other in classes if other!=grade)
        body=app.request(f'/modules/learning/questions.php?class={cid}')
        t=re.search(r'<section class="exam-question-list".*?</section>',body,re.S).group(0)
        assert 'OnlyGrade'+str(grade)+' question' in t
        assert not any('OnlyGrade'+str(other)+' question' in t for other in classes if other!=grade)
    app.request('/modules/subjects/index.php?class='+str(app.fixture['otherClass']),status=404)
    app.request('/modules/subjects/index.php?class[]=1',status=404)
    print(f'Class isolation HTTP passed: {app.count} requests; exact Class 1/10/11/12 subjects, students, attendance, results, ledgers, marks, report/admit cards and question selectors.')
