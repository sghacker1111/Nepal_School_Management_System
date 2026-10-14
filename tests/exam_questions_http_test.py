"""Exam authoring and explicit student release use disposable schools only."""
import re,json
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
with ERPHttpFixture() as app:
    f=extend_learning(app);route='/modules/learning/exam_questions.php';cls,sub=f['class'],f['subject']
    chapter=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Exam unit')",(cls,sub)).lastrowid
    topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Exam topic')",(cls,sub,chapter)).lastrowid;app.db.commit()
    app.login('teacher');body=app.request(route+'?class_id='+str(cls)+'&subject_id='+str(sub));assert 'Exam Questions' in body
    catalog=json.loads(app.request(route+'?action=catalog&class_id='+str(cls)+'&subject_id='+str(sub)))
    assert 'Exam unit' in [c['name'] for c in catalog['chapters']]
    payload={'action':'add','class_id':cls,'subject_id':sub,'chapter':'Exam unit','topic_id':topic,'type':'Short','question_text':'<b>Secret exam item</b>','marks':'5','difficulty':'Hard','required_minutes':'7','cognitive_domain':'Application'}
    app.request(route,payload);qid=app.db.execute("SELECT id FROM questions WHERE bank_kind='exam'").fetchone()[0]
    assert app.db.execute('SELECT student_visible,visibility FROM questions WHERE id=?',(qid,)).fetchone()==(0,'private')
    assert 'Secret exam item' not in app.request('/modules/learning/questions.php?class='+str(cls))
    body=app.request(route+'?class_id='+str(cls)+'&subject_id='+str(sub)+'&difficulty=Easy');assert 'Secret exam item' not in body
    app.login('student_login');body=app.request(route);assert 'Secret exam item' not in body and 'name="action"' not in body
    app.request(route,{**payload,'action':'edit','question_id':qid},status=403)
    app.request('/modules/learning/exam_paper_generator.php',status=403)
    app.login('co_teacher');app.request(route,{**payload,'action':'edit','question_id':qid},status=403)
    app.login('teacher');app.request(route,{**payload,'action':'edit','question_id':qid,'visibility':'public','student_visible':'1'})
    app.login('student_login');assert 'Secret exam item' in app.request(route)
    app.login('other_student_login');assert 'Secret exam item' not in app.request(route)
    app.login('teacher');app.request(route,{'action':'set_visibility','question_id':qid,'visibility':'private'})
    app.login('student_login');assert 'Secret exam item' not in app.request(route)
    app.login('teacher');before=app.snapshot('questions');app.request(route,{**payload,'school_id':f['otherSchool']},status=403);assert app.snapshot('questions')==before
    app.request(route,payload,csrf=False,status=419);assert app.snapshot('questions')==before
    body=app.request(route,{**payload,'action':'edit','question_id':qid,'required_minutes':'0'},status=422)
    assert 'name="action" value="edit"' in body and ('name="question_id" value="'+str(qid)+'"') in body,'Failed edit must not become a new question'
    app.request(route,{**payload,'action':'edit','question_id':qid});assert len(app.snapshot('questions'))==len(before)
    malformed={k:v for k,v in payload.items() if k!='question_text'};malformed['question_text[]']='not a scalar'
    app.request(route,malformed,status=422);assert len(app.snapshot('questions'))==len(before)
    app.db.execute("UPDATE schools SET packages_json='[\"question_view\"]' WHERE id=1");app.db.commit()
    body=app.login('student_login');assert 'Only questions explicitly released for your class and subjects.' in body,'A student with only question-view access lands on the released exam-question page'
    app.request('/modules/learning/exam_paper_generator.php',status=403)
    print(f'Exam questions HTTP passed: {app.count} requests; separate banks, filters, catalog, creator writes, release/revocation and school/student isolation.')
