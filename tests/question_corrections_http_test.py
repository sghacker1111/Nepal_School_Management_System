"""Correction UI through real sessions/CSRF/PDO, with no production data."""
import re
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
STAFF='/modules/learning/question_corrections.php'; ADMIN='/modules/super_admin/question_corrections.php'
with ERPHttpFixture() as app:
    f=extend_learning(app)
    cls=app.db.execute('SELECT source_id FROM classes WHERE id=?',(f['class'],)).fetchone()[0]
    sub=app.db.execute('SELECT source_id FROM subjects WHERE id=?',(f['subject'],)).fetchone()[0]
    qid=app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,type,question_text,visibility,bank_kind) VALUES(0,?,?,'Sets','Short','Shared correction sample','public','exam')",(cls,sub)).lastrowid;app.db.commit()
    app.login('teacher'); body=app.request(STAFF); assert 'My correction requests' in body
    assert 'Request correction' in app.request('/modules/learning/exam_questions.php?record_id='+str(qid))
    body=app.request(STAFF+'?view=request&question_id='+str(qid)); assert 'Mistake found' in body and 'Suggested correction' in body
    data=dict(action='submit',question_id=qid,mistake='<script>Wrong key</script>',suggestion='Use B')
    before=app.snapshot('question_correction_requests');app.request(STAFF,data,csrf=False,status=419); assert app.snapshot('question_correction_requests')==before
    app.request(STAFF,{**data,'school_id':f['otherSchool']},status=403)
    app.request(STAFF,{**data,'reply':'second action'},status=422)
    app.request(STAFF,{**data,'mistake':''},status=422)
    body=app.request(STAFF,data); assert '&lt;script&gt;Wrong key&lt;/script&gt;' in body
    rid=app.db.execute('SELECT id FROM question_correction_requests').fetchone()[0]
    app.request(STAFF,data); assert len(app.snapshot('question_correction_requests'))==1
    app.login('school_admin');app.request(STAFF+'?view=detail&request_id='+str(rid),status=404)
    app.login('unrelated_teacher');app.request(STAFF+'?view=request&question_id='+str(qid),status=404)
    for name in ('student_login','cashier','principal'):
        app.login(name);app.request(STAFF,status=403)
    app.login('admin'); body=app.request(ADMIN)
    for text in ('Question Bank','Exam Questions','Question type','School','Class','Subject','100'):assert text in body,text
    detail=app.request(ADMIN+'?view=detail&request_id='+str(rid)); assert 'Original submission' in detail and 'Current shared question' in detail and 'Open shared question editor' in detail
    hash_value=re.search(r'name="expected_hash" value="([a-f0-9]{64})"',detail).group(1)
    response=dict(action='respond',request_id=rid,revision=1,expected_hash=hash_value,decision='rejected',reply='<b>The question is already correct.</b>')
    before=app.snapshot('questions');app.request(ADMIN,response,csrf=False,status=419)
    app.request(ADMIN,{**response,'revision':2},status=409)
    app.request(ADMIN,{**response,'reply':''},status=422)
    app.request(ADMIN,response);assert app.snapshot('questions')==before
    app.request(ADMIN,response,status=409)
    app.login('teacher'); before=app.snapshot('question_correction_requests'); detail=app.request(STAFF+'?view=detail&request_id='+str(rid))
    assert 'Mark reply read' in detail and '&lt;b&gt;The question is already correct.&lt;/b&gt;' in detail
    assert app.snapshot('question_correction_requests')==before,'GET does not mark read'
    app.request(STAFF,dict(action='mark_read',request_id=rid)); assert app.db.execute('SELECT response_read_at FROM question_correction_requests WHERE id=?',(rid,)).fetchone()[0]
    app.db.execute("UPDATE schools SET packages_json='[\"question_view\"]' WHERE id=1");app.db.commit()
    app.request(STAFF,data);assert len(app.snapshot('question_correction_requests'))==2,'View-only reporter permitted'
    app.request('/modules/learning/exam_questions.php',dict(action='add'),status=403)
    app.db.execute("UPDATE schools SET packages_json='[\"result_system\"]' WHERE id=1");app.db.commit()
    body=app.request(STAFF+'?view=detail&request_id='+str(rid));assert 'Shared correction sample' not in body and 'Original question is no longer available' in body
    app.login('admin');app.request('/modules/super_admin/switch_school.php?school_id=1')
    app.request(ADMIN,response,status=403)
    print(f'Correction UI HTTP passed: {app.count} requests; roles, forms, safe replies, counts, view-only reports, CSRF and stale reviews.')
