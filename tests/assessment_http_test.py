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
    print(f'Assessment HTTP passed: {app.count} requests')
