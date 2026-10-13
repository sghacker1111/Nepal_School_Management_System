import json
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);practice='/modules/learning/practice_mcqs.php';bank='/modules/learning/questions.php';cls=f['class'];sub=f['subject']
    def add(text,visibility,school=1,class_id=cls,subject=sub):
        return app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,type,question_text,options,correct_option,created_by_user_id,visibility) VALUES(?,?,?,'Test','MCQ',?,?,'A',?,?)",(school,class_id,subject,text,json.dumps(dict(A='one',B='two',C='three',D='four')),f['ids']['teacher'],visibility)).lastrowid
    public=add('Revocable public MCQ','public');private=add('Never leak private MCQ','private')
    gc,gs=app.db.execute('SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name=(SELECT name FROM classes WHERE id=?) AND s.name_en=(SELECT name_en FROM subjects WHERE id=?) LIMIT 1',(cls,sub)).fetchone();shared=add('Shared paper MCQ','public',0,gc,gs);app.db.commit()
    app.login('student_login');catalog=json.loads(app.request(practice+f'?action=catalog&class_id={cls}&subject_id={sub}'))['questions']
    assert private not in [q['id'] for q in catalog] and public in [q['id'] for q in catalog]
    start={'action':'start','class_id':cls,'subject_id':sub,'count':1,'selection_mode':'chosen','question_ids[]':[public]}
    state=json.loads(app.request(practice,start))['practice'];student_session=app.opener
    app.login('teacher');app.request(bank,{'action':'set_visibility','question_id':public,'visibility':'private'})
    app.opener=student_session;body=app.request(practice+'?action=state',status=422);assert 'Revocable public MCQ' not in body and 'restart' in body.lower()
    assert json.loads(app.request(practice+'?action=state'))['practice'] is None
    app.login('teacher');app.request(bank,{'action':'set_visibility','question_id':public,'visibility':'public'})
    app.login('student_login');state=json.loads(app.request(practice,start))['practice'];transition={'token':state['token'],'index':0,'question_id':public}
    app.request(practice,{**transition,'action':'answer','choice':'A'});app.request(practice,{**transition,'action':'next'});student_session=app.opener
    app.login('teacher');app.request(bank,{'action':'archive','question_id':public});app.opener=student_session
    body=app.request(practice+'?action=state',status=422);assert 'Revocable public MCQ' not in body
    app.request('/modules/learning/question_grid.php',status=403);app.request('/modules/learning/paper_generator.php',status=403)
    app.login('unrelated_teacher')
    for url in [f'/modules/learning/question_grid.php?class={cls}&subject={sub}&mcq_count=10&short_count=0&long_count=0',f'/modules/learning/paper_generator.php?class_id={cls}&subject_id={sub}&generation_mode=manual']:
        body=app.request(url);assert 'Shared paper MCQ' in body and 'Never leak private MCQ' not in body,(url,'shared' in body,'Never leak private MCQ' in body)
    app.db.execute('UPDATE questions SET question_text=?,options=? WHERE id=?',('<p><strong>Rich paper question</strong> \\(x^2\\)</p>',json.dumps(dict(A='<em>Rich option</em>',B='two',C='three',D='four')),private));app.db.commit()
    app.login('teacher');body=app.request(f'/modules/learning/question_grid.php?class={cls}&subject={sub}&mcq_count=10&short_count=0&long_count=0');assert '<strong>Rich paper question</strong>' in body and '<em>Rich option</em>' in body,'Rich HTML math and options render in both generator flows'
    print(f'Learning consumer HTTP passed: {app.count} requests')
