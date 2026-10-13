import re
from urllib.parse import quote
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);route='/modules/learning/index.php'
    chapter=app.db.execute('SELECT name FROM chapters WHERE school_id=1 AND class_id=? AND subject_id=? LIMIT 1',(f['class'],f['subject'])).fetchone()[0]
    payload={'action_note':'add','class_id':f['class'],'subject_id':f['subject'],'chapter':chapter,'title':'Owned note secret','content':'<p>Private note body \\(x^2\\)</p><script>bad()</script>'}
    app.login('teacher');result=app.request(route,payload)
    assert result.count('<h2><i class="fas fa-book-open text-primary"></i> Learning Hub</h2>')==1,'Render one Hub, with no duplicate forms/scripts'
    note=app.db.execute('SELECT id,school_id,created_by_user_id,visibility,content FROM learning_notes WHERE title=?',(payload['title'],)).fetchone()
    if not note:
        app.log.flush();app.log.seek(0);print(app.log.read())
    assert note and note[1:4]==(1,f['ids']['teacher'],'private'),re.findall(r'<div class="alert alert-danger">(.*?)</div>',result,re.S)
    assert '<script>' not in note[4]
    url=route+f'?class_id={f["class"]}&subject_id={f["subject"]}&chapter='+quote(chapter)
    app.login('co_teacher');assert 'Owned note secret' in app.request(url)
    before=app.snapshot('learning_notes');app.request(route,{**payload,'action_note':'edit','note_id':note[0]},status=403);assert app.snapshot('learning_notes')==before
    app.login('school_admin');app.request(route,{'action_note':'set_visibility','note_id':note[0],'visibility':'public'},status=403)
    app.login('student_login');assert 'Owned note secret' not in app.request(url)
    app.login('unrelated_teacher');assert 'Owned note secret' not in app.request(url)
    app.login('teacher');app.request(route,{'action_note':'set_visibility','note_id':note[0],'visibility':'public'})
    app.login('student_login');body=app.request(url);assert 'Owned note secret' in body and 'id="noteModal"' not in body
    app.login('other_admin');assert 'Owned note secret' not in app.request(route)
    app.login('school_admin');app.request(route,{**payload,'title':'Admin owned note'});admin_note=app.db.execute("SELECT created_by_user_id FROM learning_notes WHERE title='Admin owned note'").fetchone();assert admin_note==(f['ids']['school_admin'],)
    app.login('teacher');app.request(route,{'action_note':'archive','note_id':note[0]});app.request(route,{'action_note':'restore','note_id':note[0]})
    assert app.db.execute('SELECT id,archived_at FROM learning_notes WHERE id=?',(note[0],)).fetchone()==(note[0],None)
    app.request(route,{**payload,'school_id':0},status=403)
    print(f'Learning notes HTTP passed: {app.count} requests')
