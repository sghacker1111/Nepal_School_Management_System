"""Note creation and catalog selectors use the same school/teacher boundaries."""
import json,re
from html import unescape
from urllib.parse import urlencode
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);cls,sub=f['class'],f['subject'];route='/learning'
    chapter=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Formula chapter')",(cls,sub)).lastrowid
    app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Formula topic')",(cls,sub,chapter));app.db.commit()
    app.login('teacher')
    query=urlencode({'action':'note_catalog','class_id':cls,'subject_id':sub})
    catalog=json.loads(app.request(route+'?'+query));selected=next(c for c in catalog['chapters'] if c['name']=='Formula chapter')
    assert catalog['topics'][str(selected['id'])][0]['name']=='Formula topic'
    page=app.request(route+'?'+urlencode({'class_id':cls,'subject_id':''}))
    assert 'id="modalNoteClass"' in page and 'id="modalNoteSubject"' in page and 'id="texModal"' in page
    assert 'Notes, formulas and reading materials' in page and 'class="form-control exam-rich"' in page
    payload={'action_note':'add','class_id':cls,'subject_id':sub,'chapter':'Formula chapter','topic':'Formula topic','title':"Formula & reader's note",'content':'<h2>Notes</h2><p>Read \\(x^2+y^2\\)</p><table><tr><td>Example</td></tr></table><script>alert(1)</script>','visibility':'private'}
    app.request(route,payload)
    row=app.db.execute('SELECT id,content,topic,title FROM learning_notes WHERE title=?',(payload['title'],)).fetchone()
    assert row and '<script>' not in row[1] and '\\(x^2+y^2\\)' in row[1] and row[2]=='Formula topic'
    page=app.request(route+'?'+urlencode({'class_id':cls,'subject_id':sub,'chapter':'Formula chapter'}))
    args=re.search(r'data-erp-click="learning-index-action-7" data-erp-click-args="([^"]+)"',page).group(1)
    note=json.loads(json.loads(unescape(args))[0]);assert note['title']==payload['title'] and note['content']==row[1]
    # Failed validation keeps the draft available for correction.
    page=app.request(route,{**payload,'chapter':'Missing chapter','title':'Retain unsaved draft'})
    assert 'Retain unsaved draft' in page and 'note-editor' in page
    app.login('unrelated_teacher');app.request(route+'?'+query,status=422)
    app.login('other_admin');app.request(route+'?'+query,status=404)
    app.login('student_login');app.request(route+'?'+query,status=422)
    print(f'Learning note catalog HTTP passed: {app.count} requests; selectors, formulas, topics, editing payload, draft recovery and scope checks.')
