import json
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);app.login('teacher')
    route='/modules/learning/curriculum.php'
    before=app.snapshot('chapters')
    app.request(route,{'action':'add_chapter','class_id':f['class'],'subject_id':f['subject'],'name':'Forbidden school chapter'},status=403)
    assert app.snapshot('chapters')==before
    app.login('school_admin')
    body=app.request(route)
    assert 'id="chapterModal"' not in body and 'id="topicModal"' not in body,'No school catalog editor'
    app.request(route,{'action':'add_chapter','class_id':f['class'],'subject_id':f['subject'],'name':'Forbidden admin chapter'},status=403)
    source=app.db.execute('SELECT c.source_id,s.source_id FROM classes c,subjects s WHERE c.id=? AND s.id=?',(f['class'],f['subject'])).fetchone()
    app.login('admin')
    chapter=json.loads(app.request(route,{'action':'add_chapter','class_id':source[0],'subject_id':source[1],'name':'New global chapter'}))['id']
    topic=json.loads(app.request(route,{'action':'add_topic','class_id':source[0],'subject_id':source[1],'chapter_id':chapter,'name':'New global topic'}))['id']
    app.login('teacher')
    chapters=json.loads(app.request(f'{route}?action=get_chapters&class_id={f["class"]}&subject_id={f["subject"]}'))['chapters']
    assert any(c['id']==chapter for c in chapters)
    topics=json.loads(app.request(f'{route}?action=get_topics&class_id={f["class"]}&subject_id={f["subject"]}&chapter_id={chapter}'))['topics']
    assert any(t['id']==topic for t in topics)
    app.request(route,{'action':'edit_chapter','id':chapter,'name':'Forbidden rename'},status=403)
    assert app.db.execute('SELECT name FROM chapters WHERE id=?',(chapter,)).fetchone()==('New global chapter',)
    print(f'Learning catalog HTTP passed: {app.count} requests')
