import re
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);route='/modules/learning/questions.php'
    chapter=app.db.execute('SELECT name FROM chapters WHERE school_id=1 AND class_id=? AND subject_id=? LIMIT 1',(f['class'],f['subject'])).fetchone()[0]
    payload={'action':'add','class_id':f['class'],'subject_id':f['subject'],'chapter':chapter,'type':'Short','question_text':'Creator owned test','marks':'2'}
    qid=app.db.execute('INSERT INTO questions(school_id,class_id,subject_id,chapter,question_text,created_by_user_id,visibility) VALUES(1,?,?,?,?,?,?)',(f['class'],f['subject'],chapter,'Teacher private test',f['ids']['teacher'],'private')).lastrowid;app.db.commit()
    app.login('co_teacher');before=app.snapshot('questions')
    app.request(route,{**payload,'action':'edit','question_id':qid},status=403)
    assert app.snapshot('questions')==before
    app.login('school_admin');app.request(route,{'action':'set_visibility','question_id':qid,'visibility':'public'},status=403)
    app.login('teacher');app.request(route,payload)
    new=app.db.execute('SELECT id,school_id,created_by_user_id,visibility FROM questions WHERE question_text=?',(payload['question_text'],)).fetchone()
    assert new[1:]==(1,f['ids']['teacher'],'private')
    app.request(route,{'action':'set_visibility','question_id':new[0],'visibility':'public'})
    assert app.db.execute('SELECT visibility FROM questions WHERE id=?',(new[0],)).fetchone()==('public',)
    app.login('unrelated_teacher');body=app.request(route+'?search=Creator+owned+test')
    table=re.search(r'<table\b.*?</table>',body,re.S).group(0)
    assert 'Creator owned test' in table and 'onclick="editQuestion(' not in table
    app.login('other_admin');body=app.request(route+'?search=Creator+owned+test');assert 'Creator owned test' not in re.search(r'<table\b.*?</table>',body,re.S).group(0)
    app.login('teacher');before=app.snapshot('questions')
    app.request(route,{**payload,'school_id':0,'created_by_user_id':f['ids']['co_teacher']},status=403)
    assert app.snapshot('questions')==before
    app.request(route,{'action':'archive','question_id':new[0]})
    assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(new[0],)).fetchone()[0]
    app.request(route,{'action':'set_visibility','question_id':new[0],'visibility':'private'},status=422)
    app.request(route,{'action':'restore','question_id':new[0]})
    assert app.db.execute('SELECT archived_at,visibility FROM questions WHERE id=?',(new[0],)).fetchone()==(None,'public')
    before=app.snapshot('chapters');app.request(route,{**payload,'chapter':'Missing school chapter'})
    assert app.snapshot('chapters')==before
    assert not app.db.execute("SELECT id FROM questions WHERE chapter='Missing school chapter'").fetchone()
    from question_source_http_test import upload,csv_bytes
    from question_import_context_support import catalog_context
    app.import_context=catalog_context(app,1,f['class'],f['subject'],chapter,'Ownership import topic')
    before={table:app.snapshot(table) for table in ('questions','chapters','topics')}
    result=upload(app,csv_bytes([['Class','Subject','Chapter','Topic','Question HTML'],['Class 9','Nepali','Missing imported chapter','Missing imported topic','<p>Do not import</p>']]))
    assert 'No questions were imported' in result and all(app.snapshot(t)==rows for t,rows in before.items())
    app.request(route+'?delete='+str(new[0]),{**payload,'action':'edit','question_id':qid},status=422)
    app.request(route,{'action':'set_visibility','question_id':new[0],'visibility':'public'},status=419,csrf=False)
    print(f'Question ownership HTTP passed: {app.count} requests')
