"""Restricted student provisioning and real HTTP practice; disposable data only."""
import json, re
from http_support import ERPHttpFixture

PAGE='/modules/learning/practice_mcqs.php'

def seed_practice(app):
    f=app.fixture; db=app.db; ids=[]
    db.execute("DELETE FROM questions WHERE school_id=1 AND class_id=? AND subject_id=?",(f['class'],f['subject']))
    for i,correct in enumerate(('A','B','C','D')):
        ids.append(db.execute("INSERT INTO questions(school_id,class_id,subject_id,type,chapter,question_text,options,options_np,correct_option,answer) VALUES(1,?,?,'MCQ','Sets',?,?,?,?,?)",(f['class'],f['subject'],f'<p>Practice question {i+1}: choose the matching number.</p>',json.dumps({'A':'One','B':'Two','C':'Three','D':'Four'}),'{}',correct,'Use the number sequence.')).lastrowid)
    universal=db.execute('SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name=(SELECT name FROM classes WHERE id=?) AND s.name_en=(SELECT name_en FROM subjects WHERE id=?) LIMIT 1',(f['class'],f['subject'])).fetchone()
    assert universal,'Universal class and subject fixture must exist'
    ids.append(db.execute("INSERT INTO questions(school_id,class_id,subject_id,type,chapter,question_text,options,options_np,correct_option,answer) VALUES(0,?,?,'MCQ','Sets','Shared catalog question',?,'{}','D','Shared explanation')",(*universal,json.dumps({'A':'One','B':'Two','C':'Three','D':'Four'}))).lastrowid)
    # Invalid, other tenant, and wrong class rows must never leak into the pool.
    db.execute("INSERT INTO questions(school_id,class_id,subject_id,type,chapter,question_text,options,correct_option) VALUES(1,?,?,'MCQ','Sets','Incomplete','{}','A')",(f['class'],f['subject']))
    other_subject=db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? LIMIT 1',(f['otherClass'],)).fetchone()[0]
    db.execute("INSERT INTO questions(school_id,class_id,subject_id,type,chapter,question_text,options,correct_option) VALUES(?,?,?,'MCQ','Sets','Foreign question',?,'A')",(f['otherSchool'],f['otherClass'],other_subject,json.dumps({'A':'One','B':'Two','C':'Three','D':'Four'})))
    db.execute("UPDATE questions SET visibility='public'") # Synthetic published records; new school saves remain private.
    db.commit()
    return ids

def provision(app,username='practice_student',record=None):
    return app.request('/modules/settings/users.php',{'full_name':'Test Student','username':username,'role':'student','student_record_id':record or app.fixture['student'],'password':'TestPassword123!','status':1})

def run():
    with ERPHttpFixture() as app:
        f=app.fixture; ids=seed_practice(app); student_snapshot=app.snapshot('students')
        app.login('school_admin')
        lookup=json.loads(app.request('/modules/settings/users.php?student_lookup=1&search=FIXTURE'))
        assert [int(s['id']) for s in lookup['students']]==[f['student']]
        assert str(f['otherStudent']) not in [str(s['id']) for s in lookup['students']]
        body=provision(app); assert 'Account saved successfully' in body
        assert app.snapshot('students')==student_snapshot
        user=app.db.execute("SELECT id,password,student_record_id FROM users WHERE username='practice_student'").fetchone()
        assert user[1]!='TestPassword123!' and user[2]==f['student']
        assert 'already has a login' in provision(app,'duplicate_student')
        assert 'belonging to this school' in provision(app,'foreign_student',f['otherStudent'])
        body=app.login('practice_student'); assert 'Learning Hub' in body and 'Practice MCQs' in body
        assert 'Student Management' not in body and 'Accounting & Billing' not in body
        for route in ['/modules/students/index.php','/modules/results/marks_entry.php','/modules/results/index.php','/modules/accounting/index.php','/modules/classes/index.php','/modules/settings/users.php','/modules/super_admin/index.php','/modules/learning/questions.php','/modules/learning/curriculum.php','/modules/learning/paper_generator.php','/modules/learning/specification_grids.php']:
            app.request(route,status=403)
        app.request('/modules/learning/index.php',{'action_note':'add','title':'Forged'},status=403)
        app.request('/modules/learning/index.php?action=request_hub_permission',status=403)
        body=app.request(PAGE); assert 'practiceBootstrap' in body
        subjects=json.loads(app.request(PAGE+'?action=subjects&class_id='+str(f['class'])))['subjects']
        assert f['subject'] in [int(s['id']) for s in subjects]
        optional=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND is_optional=1 LIMIT 1',(f['class'],)).fetchone()[0]
        assert optional not in [int(s['id']) for s in subjects]
        app.db.execute('UPDATE students SET optional_subject_i=? WHERE id=?',(optional,f['student']));app.db.commit()
        selected_subjects=json.loads(app.request(PAGE+'?action=subjects&class_id='+str(f['class'])))['subjects']
        assert optional in [int(s['id']) for s in selected_subjects]
        app.db.execute('UPDATE students SET optional_subject_i=NULL WHERE id=?',(f['student'],));app.db.commit()
        other_same_school=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 10'").fetchone()[0]
        app.request(PAGE+'?action=subjects&class_id='+str(other_same_school),status=404)
        app.request(PAGE+'?action=subjects&class_id='+str(f['otherClass']),status=404)
        query=f'?action=catalog&class_id={f["class"]}&subject_id={f["subject"]}'
        catalog=json.loads(app.request(PAGE+query))['questions']; assert {q['id'] for q in catalog}==set(ids)
        assert all(not any(k in q for k in ('key','correct_option','options','answer')) for q in catalog)
        snapshots={table:app.snapshot(table) for table in ('students','questions','marks','exams','ac_invoices','ac_payments','ac_journals')}
        base={'action':'start','class_id':f['class'],'subject_id':f['subject'],'count':2,'selection_mode':'chosen','question_ids[]':ids[:2]}
        app.request(PAGE,base,status=419,csrf=False)
        for bad in ({'count':101},{'count':0},{'count':'abc'},{'question_ids[]':[ids[0],ids[0]]},{'question_ids[]':[999999]},{'class_id':other_same_school}):
            app.request(PAGE,{**base,**bad},status=422)
        state=json.loads(app.request(PAGE,base))['practice']; assert state['total']==2 and state['score']==0
        assert 'key' not in state['question'] and 'feedback' not in state
        def transition(action,choice=None,status=200,extra=None):
            data={'action':action,'token':state['token'],'index':state['index'],'question_id':state['question']['id']}
            if choice is not None:data['choice']=choice
            return json.loads(app.request(PAGE,{**data,**(extra or {})},status=status))
        transition('next',status=422)
        transition('answer','A',422,{'token':'forged'})
        transition('answer','X',422)
        feedback=transition('answer','D',extra={'correct':1,'correct_option':'D'})['practice']
        assert feedback['feedback']['correct'] is False and feedback['score']==0
        assert transition('answer','D')['practice']==feedback
        transition('answer','C',422)
        state=transition('next')['practice']; assert state['index']==1
        key=app.db.execute('SELECT correct_option FROM questions WHERE id=?',(state['question']['id'],)).fetchone()[0]
        correct=transition('answer',key)['practice']; assert correct['feedback']['correct'] is True and correct['score']==1
        result=transition('next')['practice']; assert result['complete'] and result['score']==1 and len(result['review'])==2
        assert json.loads(app.request(PAGE+'?action=state'))['practice']==result
        assert '"complete":true' in app.request(PAGE)
        app.request(PAGE,{'action':'reset'}); assert json.loads(app.request(PAGE+'?action=state'))['practice'] is None
        random=json.loads(app.request(PAGE,{**base,'selection_mode':'random','count':4}))['practice']; assert random['total']==4
        # Enrollment change is observed without logging out/re-authenticating.
        app.db.execute('UPDATE students SET class_id=? WHERE id=?',(other_same_school,f['student']));app.db.commit()
        app.request(PAGE+'?action=state',status=422)
        app.db.execute('UPDATE students SET class_id=? WHERE id=?',(f['class'],f['student']));app.db.commit()
        app.db.execute("UPDATE students SET status='withdrawn' WHERE id=?",(f['student'],));app.db.commit();app.request(PAGE,status=403)
        app.db.execute("UPDATE students SET status='active' WHERE id=?",(f['student'],));app.db.commit()
        for packages in ('["question_view"]','[]'):
            app.db.execute('UPDATE schools SET packages_json=? WHERE id=1',(packages,));app.db.commit();app.request(PAGE,status=403)
        app.db.execute('UPDATE schools SET packages_json=? WHERE id=1',('["learning_system"]',));app.db.commit();app.request(PAGE)
        app.db.execute('UPDATE schools SET subscription_enabled=0 WHERE id=1');app.db.commit();app.request(PAGE,status=403)
        app.db.execute('UPDATE schools SET subscription_enabled=1 WHERE id=1');app.db.commit()
        for table,before in snapshots.items():assert app.snapshot(table)==before,table+' modified by practice'
        app.login('teacher'); app.request('/modules/settings/users.php',status=403); app.request(PAGE)
        app.request(PAGE+query)
        other_sub=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND subject_id<>? LIMIT 1',(f['class'],f['subject'])).fetchone()[0]
        app.request(PAGE+f'?action=catalog&class_id={f["class"]}&subject_id={other_sub}') # Staff may practice school-public subjects beyond their own assignment.
        app.login('admin')
        other_lookup=json.loads(app.request('/modules/settings/users.php?student_lookup=1&lookup_school='+str(f['otherSchool'])))['students']
        assert [int(s['id']) for s in other_lookup]==[f['otherStudent']]
        app.request('/modules/super_admin/users.php',{'school_id':f['otherSchool'],'full_name':'Other Student','username':'other_practice_student','role':'student','student_record_id':f['otherStudent'],'password':'TestPassword123!','status':1})
        app.login('other_practice_student');app.request(PAGE)
        app.request(PAGE+query,status=404)
        app.db.execute("UPDATE users SET status=0 WHERE username='other_practice_student'");app.db.commit()
        assert 'Sign in to your account' in app.request(PAGE)
        print(f'MCQ practice HTTP checks passed: {app.count} requests; student restrictions, CSRF, scopes, grading and record preservation.')

if __name__=='__main__':run()
