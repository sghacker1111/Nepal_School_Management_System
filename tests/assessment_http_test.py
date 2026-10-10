"""IN configuration edits enforce default/exam scope over real HTTP."""
import re
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    f=app.fixture
    mapping=app.db.execute('SELECT id FROM class_subjects WHERE class_id=? AND subject_id=?',(f['class'],f['subject'])).fetchone()[0]
    exam_mapping=app.db.execute('SELECT id FROM exam_subjects WHERE exam_id=?',(f['exam'],)).fetchone()[0]
    def payload(body,kinds):
        data={'revision':re.search(r'name="revision" value="([^"]+)"',body).group(1),'total':25,'pass':10,'confirm_assessment_change':1}
        for kind in kinds:
            for i,(key,label,maximum) in enumerate([('participation','Participation',3),('project_work','Practical project',16),('previous_exam','Earlier written tests',6)]):
                for field,value in [('key',key),('label',label),('max',maximum)]:data[f'components[{kind}][{i}][{field}]']=value
        return data
    app.login('exam_officer')
    officer_path=f'/modules/subjects/internal.php?mapping={exam_mapping}&exam={f["exam"]}'
    officer_body=app.request(officer_path)
    app.request(officer_path,payload(officer_body,['terminal']))
    app.login('school_admin')
    path=f'/modules/subjects/internal.php?mapping={mapping}'
    body=app.request(path);data=payload(body,['terminal','annual'])
    body=app.request(path,data);assert 'Internal assessment saved successfully' in body,body[-3000:]
    before=app.snapshot('class_subjects');body=app.request(path,data)
    assert 'changed' in body and app.snapshot('class_subjects')==before
    app.login('exam_officer');app.request(path,status=403)
    path=f'/modules/subjects/internal.php?mapping={exam_mapping}&exam={f["exam"]}'
    body=app.request(path);data=payload(body,['terminal'])
    app.request(path,data,status=419,csrf=False)
    body=app.request(path,data);assert 'Internal assessment saved successfully' in body
    before=app.snapshot('exam_subjects');body=app.request(path,data)
    assert 'changed' in body and app.snapshot('exam_subjects')==before
    app.login('school_admin')
    optional=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND is_optional=1 LIMIT 1',(f['class'],)).fetchone()[0]
    app.db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES (?,?,?,75,26.25,25,10,4,1)',(f['exam'],f['class'],optional))
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained,internal_breakdown) VALUES (?,?,?,55,20,?)',(f['exam'],f['student'],optional,'{"participation":3,"project_work":12,"previous_exam":5}'));app.db.commit()
    path=f'/modules/results/marks_entry.php?exam={f["exam"]}&class={f["class"]}'
    body=app.request(path)
    def mark_field(key):return re.search(r'<input[^>]+name="marks\['+str(f['student'])+r'\]\['+str(optional)+r'\]\['+key+r'\]"[^>]*>',body).group(0)
    assert 'Take this subject' in body
    assert 'value="55"' in mark_field('theory'),'Imported elective theory mark must remain visible'
    assert 'value="12"' in mark_field('project_work'),'Imported elective component must remain visible'
    assert app.db.execute('SELECT optional_subject_i FROM students WHERE id=?',(f['student'],)).fetchone()[0] is None,'GET must not auto-enroll'
    data={'save_marks':1,'exam_id':f['exam'],'class_id':f['class'],'confirm_optional_enrollment':1,f'optional_enrollment[{f["student"]}][{optional}]':1}
    for key,value in [('theory',56),('participation',3),('project_work',12),('previous_exam',5)]:data[f'marks[{f["student"]}][{optional}][{key}]']=value
    body=app.request(path,data);assert 'success' in body.lower()
    body=app.request(path);assert 'disabled' not in mark_field('theory') and 'value="56"' in mark_field('theory')
    assert app.db.execute('SELECT optional_subject_i FROM students WHERE id=?',(f['student'],)).fetchone()[0]==optional
    print(f'Assessment HTTP passed: {app.count} requests')
