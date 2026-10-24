import json,re
from urllib.parse import urlencode
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);bank='/modules/learning/questions.php';hub='/modules/learning/index.php';curriculum='/modules/learning/curriculum.php';practice='/modules/learning/practice_mcqs.php'
    stable={t:app.snapshot(t) for t in ('marks','exams','students','ac_invoices','ac_payments','ac_journals','ac_journal_lines')}
    cls,sub=f['class'],f['subject']
    gc,gs=app.db.execute('SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name=(SELECT name FROM classes WHERE id=?) AND s.name_en=(SELECT name_en FROM subjects WHERE id=?) LIMIT 1',(cls,sub)).fetchone()
    app.login('admin');ch=json.loads(app.request(curriculum,{'action':'add_chapter','class_id':gc,'subject_id':gs,'name':'Integration Shared Chapter'}))['id'];topic=json.loads(app.request(curriculum,{'action':'add_topic','class_id':gc,'subject_id':gs,'chapter_id':ch,'name':'Integration Shared Topic'}))['id']
    payload={'action':'add','class_id':gc,'subject_id':gs,'chapter':'Integration Shared Chapter','catalog_topic_id':topic,'type':'MCQ','question_text':'<p>Shared integration MCQ</p>','option_a':'<b>one</b>','option_b':'two','option_c':'three','option_d':'four','correct_option':'A'}
    app.request(bank,payload);shared_id=app.db.execute("SELECT id FROM questions WHERE question_text LIKE '%Shared integration MCQ%'").fetchone()[0]
    app.request(hub,{'action_note':'add','class_id':gc,'subject_id':gs,'chapter':'Integration Shared Chapter','catalog_topic_id':topic,'title':'Shared integration note','content':'<p>\\(x^2\\)</p>'})
    app.db.execute("INSERT INTO learning_notes(class_level,subject_name,chapter,topic,title,content,visibility) VALUES('Unknown','Unknown','Legacy','', 'Unassigned retained note','Keep legacy content','public')");app.db.commit()
    assert 'Unassigned retained note' in app.request(hub+'?unassigned=1'),'Super admin can review unmatched legacy notes'
    for user,school in [('school_admin',1),('other_admin',f['otherSchool'])]:
        app.login(user)
        c,s=app.db.execute('SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND c.name=(SELECT name FROM classes WHERE id=?) AND s.name_en=(SELECT name_en FROM subjects WHERE id=?) LIMIT 1',(school,gc,gs)).fetchone()
        url=hub+'?'+urlencode({'class_id':c,'subject_id':s,'chapter':'Integration Shared Chapter'})
        body=app.request(url);assert 'Shared integration note' in body and 'Shared integration MCQ' in body
        app.request(bank,{**payload,'action':'edit','class_id':c,'subject_id':s,'question_id':shared_id},status=403)
    app.login('student_login');query='?'+urlencode({'action':'catalog','class_id':cls,'subject_id':sub})
    assert shared_id in [q['id'] for q in json.loads(app.request(practice+query))['questions']]
    app.db.execute('UPDATE subjects SET is_hidden=1 WHERE id=?',(gs,));app.db.commit()
    app.request(practice+query,status=404) # Hidden shared catalog subject is no longer a school offering.
    app.db.execute('UPDATE subjects SET is_hidden=0 WHERE id=?',(gs,));app.db.commit()
    app.login('teacher');payload={**payload,'class_id':cls,'subject_id':sub,'question_text':'<p>Local integration MCQ</p>'};app.request(bank,payload)
    qid=app.db.execute("SELECT id FROM questions WHERE question_text LIKE '%Local integration MCQ%'").fetchone()[0]
    app.request(hub,{'action_note':'add','class_id':cls,'subject_id':sub,'chapter':'Integration Shared Chapter','title':'Local private note','content':'<p>Keep body</p>'});nid=app.db.execute("SELECT id FROM learning_notes WHERE title='Local private note'").fetchone()[0]
    before=app.snapshot('learning_notes');app.request(hub+'?delete_note='+str(nid),{'action_note':'set_visibility','note_id':nid,'visibility':'public'},status=422);assert app.snapshot('learning_notes')==before
    app.request(bank,{'action':'set_visibility','question_id':qid,'visibility':'public'})
    app.login('student_login');assert qid in [q['id'] for q in json.loads(app.request(practice+query))['questions']]
    app.login('other_admin');body=app.request(bank);assert 'Local integration MCQ' not in body
    app.login('teacher');app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE class_id=? AND subject_id=?',(str(f['ids']['co_teacher']),cls,sub));app.db.commit()
    app.request(bank,{'action':'set_visibility','question_id':qid,'visibility':'private'},status=403)
    app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE class_id=? AND subject_id=?',(str(f['ids']['teacher']),cls,sub));app.db.commit()
    app.db.execute("UPDATE schools SET packages_json='[]' WHERE id=1");app.db.commit();app.request(bank,status=403)
    app.db.execute('UPDATE schools SET packages_json=NULL WHERE id=1');app.db.commit()
    app.request(bank,{'action':'archive','question_id':qid},status=419,csrf=False)
    assert all(app.snapshot(t)==rows for t,rows in stable.items()),'Official records and money unchanged'
    print(f'Learning sharing acceptance passed: {app.count} requests; shared catalog, ownership, hidden source, school privacy, forgery and unchanged records')
