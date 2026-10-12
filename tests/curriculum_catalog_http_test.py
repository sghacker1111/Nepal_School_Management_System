"""Shared curriculum must retain chapter/topic identity across the super-admin screens."""
import json
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    app.login('admin')
    cls,subject=app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name='Class 9' AND s.name_en='Mathematics' LIMIT 1").fetchone()
    curriculum='/modules/learning/curriculum.php'
    questions='/modules/learning/questions.php'
    qurl=f'{questions}?action_get_chapters=1&class_id={cls}&subject_id={subject}'
    curl=f'{curriculum}?action=get_chapters&class_id={cls}&subject_id={subject}'
    bank=json.loads(app.request(qurl));manager=json.loads(app.request(curl))['chapters']
    assert bank and [(c['id'],c['name']) for c in bank]==[(c['id'],c['name']) for c in manager], 'Bundled Question Bank chapters must be persisted and visible in Curriculum Manager'
    assert all(int(c['id'])>0 for c in bank),'Chapters need persistent IDs for topics'
    chapter=bank[0];topic_name="Teacher's topic <example>"
    topic=json.loads(app.request(curriculum,{'action':'add_topic','class_id':cls,'subject_id':subject,'chapter_id':chapter['id'],'name':topic_name}))
    assert topic['success'];tid=int(topic['id'])
    topics=json.loads(app.request(f'{questions}?action_get_topics=1&chapter_id={chapter["id"]}'))
    assert any(int(t['id'])==tid and t['name']==topic_name for t in topics)
    duplicate=json.loads(app.request(curriculum,{'action':'add_topic','class_id':cls,'subject_id':subject,'chapter_id':chapter['id'],'name':topic_name}))
    assert duplicate['success'] and int(duplicate['id'])==tid,'Duplicate topic reuses shared ID'
    payload={'action':'add','class_id':cls,'subject_id':subject,'chapter':chapter['name'],'topic_id':tid,'type':'Short','question_text':'Synthetic linked question','marks':'2'}
    app.request(questions,payload)
    qid=app.db.execute("SELECT id FROM questions WHERE question_text='Synthetic linked question'").fetchone()[0]
    before=app.snapshot('questions')
    denied=json.loads(app.request(curriculum,{'action':'delete_topic','id':tid}))
    assert not denied['success'] and app.snapshot('questions')==before,'Referenced topic cannot be deleted'
    # Historical duplicates must keep their IDs and topic links when the shared chapter is renamed.
    alias=app.db.execute('INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(0,?,?,?)',(cls,subject,chapter['name'].upper())).lastrowid
    alias_topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(0,?,?,?,'Legacy alias topic')",(cls,subject,alias)).lastrowid
    app.db.commit()
    renamed="Renamed shared chapter's title"
    result=json.loads(app.request(curriculum,{'action':'edit_chapter','id':chapter['id'],'name':renamed,'sort_order':2}))
    assert result['success']
    assert app.db.execute('SELECT chapter,topic_id FROM questions WHERE id=?',(qid,)).fetchone()==(renamed,tid),'Rename preserves Question Bank linkage'
    assert app.db.execute('SELECT name FROM chapters WHERE id=?',(alias,)).fetchone()==(renamed,), 'Rename preserves historical duplicate chapter/topic identity'
    shared_topics=json.loads(app.request(f'{questions}?action_get_topics=1&chapter_id={chapter["id"]}'))
    assert any(int(t['id'])==alias_topic for t in shared_topics),'Legacy alias topics remain visible'
    duplicate=json.loads(app.request(curriculum,{'action':'edit_topic','id':alias_topic,'name':topic_name}))
    assert not duplicate['success'],'Editing cannot introduce duplicate topics across shared chapter aliases'
    edited="Edited topic's <title>"
    result=json.loads(app.request(curriculum,{'action':'edit_topic','id':tid,'name':edited}))
    assert result['success'] and app.db.execute('SELECT name FROM topics WHERE id=?',(tid,)).fetchone()==(edited,), 'Topic edits store plain text without corrupting apostrophes or markup'
    denied=json.loads(app.request(curriculum,{'action':'delete_chapter','id':chapter['id']}))
    assert not denied['success'],'Referenced chapter cannot be deleted'
    denied=json.loads(app.request('/modules/super_admin/subjects.php',{'action_chapter':'delete','chapter_id':chapter['id']}))
    assert not denied['success'],'Universal catalog also protects linked chapters'
    app.request(questions,{**payload,'chapter':'Custom from Question Bank','topic_id':'','question_text':'Synthetic custom chapter question'})
    manager=json.loads(app.request(curl))['chapters']
    assert sum(c['name']=='Custom from Question Bank' for c in manager)==1,'Custom question chapters appear automatically'
    app.request(questions,{**payload,'chapter':'Different chapter','question_text':'Mismatched topic must fail'})
    assert not app.db.execute("SELECT id FROM questions WHERE question_text='Mismatched topic must fail'").fetchone(),'Topic cannot belong to a different chapter'
    # Revisit every list: no duplicate rows and no mutation from GET.
    counts=(len(app.snapshot('chapters')),len(app.snapshot('topics')))
    for _ in range(2):
        manager=json.loads(app.request(curl))['chapters'];bank=json.loads(app.request(qurl))
        assert [(c['id'],c['name']) for c in bank]==[(c['id'],c['name']) for c in manager]
    assert counts==(len(app.snapshot('chapters')),len(app.snapshot('topics')))
    # A version12 copy contains legacy free-text chapters but no corresponding row.
    app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,type,question_text) VALUES(0,?,?,'Legacy chapter only in questions','Short','Legacy preserved text')",(cls,subject))
    question_snapshot=app.snapshot('questions')
    school_cls,school_sub=app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=1 AND c.name='Class 9' AND s.name_en='Mathematics' LIMIT 1").fetchone()
    app.db.execute('DELETE FROM chapters WHERE school_id=1 AND class_id=? AND subject_id=?',(school_cls,school_sub))
    app.db.execute("UPDATE app_meta SET meta_value='12' WHERE meta_key='workflow_schema'");app.db.commit()
    manager=json.loads(app.request(curl))['chapters']
    assert sum(c['name']=='Legacy chapter only in questions' for c in manager)==1
    assert app.snapshot('questions')==question_snapshot,'Migration preserves every question field'
    app.login('school_admin')
    school_bank=json.loads(app.request(f'{questions}?action_get_chapters=1&class_id={school_cls}&subject_id={school_sub}'))
    school_manager=json.loads(app.request(f'{curriculum}?action=get_chapters&class_id={school_cls}&subject_id={school_sub}'))['chapters']
    assert school_bank and school_bank==school_manager,'Existing school fallback chapters also remain available after upgrade'
    assert all(int(c['school_id'])==1 for c in school_bank),'School catalogs retain tenant-specific ownership'
    app.login('teacher')
    app.request(curl,status=404)
    app.request(curriculum,{'action':'add_topic','class_id':cls,'subject_id':subject,'chapter_id':chapter['id'],'name':'Forbidden'},status=422)
    print(f'Shared curriculum HTTP passed: {app.count} requests; persistent chapters, topic parity, duplicates, rename preservation and scope checks.')
