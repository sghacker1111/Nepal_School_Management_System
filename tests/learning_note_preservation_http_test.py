import json,re,subprocess
from urllib.parse import urlencode
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from app_response_http_test import config as app_config

with ERPHttpFixture() as app:
    failures=[]
    f=extend_learning(app);hub='/modules/learning/index.php';catalog='/modules/learning/curriculum.php';cls,sub=f['class'],f['subject']
    gc,gs=app.db.execute('SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name=(SELECT name FROM classes WHERE id=?) AND s.name_en=(SELECT name_en FROM subjects WHERE id=?) LIMIT 1',(cls,sub)).fetchone()
    lc=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Alias chapter')",(cls,sub)).lastrowid
    lt=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Alias topic')",(cls,sub,lc)).lastrowid
    app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(0,?,?,'Alias chapter')",(gc,gs));shared_ch=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(0,?,?,?,'Alias topic')",(gc,gs,shared_ch));app.db.commit()
    app.login('teacher')
    note={'action_note':'add','class_id':cls,'subject_id':sub,'chapter':'Alias chapter','topic':'Alias topic','chapter_id':lc,'topic_id':lt,'title':'Keep local references','content':'<p>Original</p>'}
    app.request(hub,note);nid=app.db.execute("SELECT id FROM learning_notes WHERE title='Keep local references'").fetchone()[0]
    before=app.db.execute('SELECT chapter_id,topic_id,catalog_chapter_id,catalog_topic_id FROM learning_notes WHERE id=?',(nid,)).fetchone()
    assert before==(lc,lt,None,None)
    edit={k:v for k,v in note.items() if k not in ('chapter_id','topic_id')};app.request(hub,{**edit,'action_note':'edit','note_id':nid,'title':'Title changed only','content':'<p>Body changed only</p>'})
    if app.db.execute('SELECT chapter_id,topic_id,catalog_chapter_id,catalog_topic_id FROM learning_notes WHERE id=?',(nid,)).fetchone()!=before:failures.append('Ordinary title/body edit loses legacy local alias IDs')
    app.login('admin')
    app.db.execute("INSERT INTO learning_notes(school_id,class_id,subject_id,class_level,subject_name,chapter,topic,title,content,visibility) VALUES(0,?,?, 'Class 9','Nepali','Legacy uncatalogued chapter','','Legacy unreachable note','<p>Keep preserved body</p>','public')",(gc,gs));app.db.commit()
    body=app.request(hub+'?unassigned=1')
    if 'Legacy unreachable note' not in body:failures.append('Super-admin review misses retained uncatalogued chapter notes')
    title="King's & Queens"
    result=json.loads(app.request(catalog,{'action':'add_chapter','class_id':gc,'subject_id':gs,'name':title}));assert result['success']
    body=app.request(hub+'?'+urlencode({'class_id':gc,'subject_id':gs,'chapter':title}))
    controller=(app.root/'frontend/pages/generated/learning-index.js').read_text(encoding='utf8')
    function=re.search(r'function showAddNoteModal\(\) \{(.*?)\n\}',controller,re.S).group(1)
    # Execute the emitted JavaScript literal, not a grep assertion on serialization style.
    match=re.search(r"document\.getElementById\('modalNoteChapter'\)\.value\s*=\s*([^\n]+);",function)
    literal=match.group(1)
    data={'values':app_config(body.encode())['values']}
    evaluated=subprocess.run(['node','-e','const config='+json.dumps(data)+';process.stdout.write(String('+literal+'));'],capture_output=True,text=True,check=True).stdout
    if evaluated!=title:failures.append('Add Note chooses an entity-encoded chapter instead of the option title')
    assert not failures,'; '.join(failures)
    print(f'Learning note preservation HTTP passed: {app.count} requests; alias IDs, legacy review and exact JS chapter title')
