"""Safe curriculum deletion and dependency locations; OS-temp database only."""
import json,re
from pathlib import Path
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

CUR='/modules/learning/curriculum.php';QB='/modules/learning/questions.php'
EQ='/modules/learning/exam_questions.php';MCQ='/modules/learning/exam_mcqs.php'
GRIDS='/modules/learning/exam_specification_grids.php'

def seed_curriculum_delete(app):
    f=extend_learning(app)
    cls=app.db.execute('SELECT source_id FROM classes WHERE id=?',(f['class'],)).fetchone()[0]
    sub=app.db.execute('SELECT source_id FROM subjects WHERE id=?',(f['subject'],)).fetchone()[0]
    assert cls and sub
    ch=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(0,?,?,'Deletion demo chapter')",(cls,sub)).lastrowid
    tid=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(0,?,?,?,'Deletion demo topic')",(cls,sub,ch)).lastrowid
    ids={}
    for key,kind,bank,school,c,s,creator in (('bank','Short','learning',0,cls,sub,f['ids']['super_admin']),('exam','Short','exam',0,cls,sub,f['ids']['super_admin']),('mcq','MCQ','exam',0,cls,sub,f['ids']['super_admin']),('private','Short','learning',1,f['class'],f['subject'],f['ids']['teacher'])):
        ids[key]=app.db.execute('INSERT INTO questions(school_id,class_id,subject_id,chapter,topic_id,catalog_chapter_id,catalog_topic_id,type,question_text,marks,required_minutes,cognitive_domain,bank_kind,options,options_np,correct_option,created_by_user_id,visibility) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            (school,c,s,'Deletion demo chapter',tid if school==0 else None,ch,tid,kind,key+' preserved content body',2,2,'Knowledge',bank,json.dumps(dict(A='1',B='2',C='3',D='4')) if kind=='MCQ' else None,'{}' if kind=='MCQ' else None,'A' if kind=='MCQ' else None,creator,'private' if school else 'public')).lastrowid
    class_name=app.db.execute('SELECT name FROM classes WHERE id=?',(cls,)).fetchone()[0];subject_name=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(sub,)).fetchone()[0]
    ids['note']=app.db.execute('INSERT INTO learning_notes(school_id,class_id,subject_id,class_level,subject_name,chapter,topic,chapter_id,topic_id,catalog_chapter_id,catalog_topic_id,title,content,created_by_user_id,visibility) VALUES(0,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',(cls,sub,class_name,subject_name,'Deletion demo chapter','Deletion demo topic',ch,tid,ch,tid,'Preserved note','Preserved note body',f['ids']['super_admin'],'public')).lastrowid
    config={'full_marks':2,'duration_minutes':10,'rows':[{'name':'Selected topic','type':'Short','chapter_ids':[ch],'topic_ids':[tid],'count':1,'total_marks':2}]}
    ids['grid']=app.db.execute('INSERT INTO exam_paper_grids(school_id,class_id,subject_id,name,config_json,created_by_user_id) VALUES(0,?,?,?,?,?)',(cls,sub,'Preserved grid',json.dumps(config),f['ids']['super_admin'])).lastrowid
    app.db.commit();app.login('admin')
    return f,cls,sub,ch,tid,ids

def api(app,data):return json.loads(app.request(CUR,data))

def run():
  with ERPHttpFixture() as app:
    # Simulate the deployed schema16 shape, then confirm additive upgrade and backup.
    for table,column in (('chapters','archived_at'),('topics','archived_at'),('topics','deleted_with_chapter_id')):app.db.execute(f'ALTER TABLE {table} DROP COLUMN {column}')
    app.db.execute("UPDATE app_meta SET meta_value='16' WHERE meta_key='workflow_schema'");app.db.commit()
    prior={table:app.snapshot(table) for table in ('chapters','topics','questions','learning_notes','students','marks')}
    old_columns={table:[r[1] for r in app.db.execute(f'PRAGMA table_info({table})')] for table in prior}
    app.login('admin')
    assert app.db.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone()==('17',)
    assert list((Path(app.tmp.name)/'.backups').glob('*.sqlite')),'Schema16 upgrade makes a pre-upgrade backup'
    for table,rows in prior.items():assert app.db.execute('SELECT '+','.join(old_columns[table])+f' FROM {table} ORDER BY id').fetchall()==rows,table
    f,cls,sub,ch,tid,ids=seed_curriculum_delete(app)
    protected={table:app.snapshot(table) for table in ('students','marks','exams','ac_invoices','ac_payments')}
    bodies=app.db.execute('SELECT id,question_text FROM questions ORDER BY id').fetchall()
    empty=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(0,?,?,'Rollback demo chapter')",(cls,sub)).lastrowid;app.db.commit()
    original_counts=(len(app.snapshot('questions')),len(app.snapshot('learning_notes')),len(app.snapshot('chapters')),len(app.snapshot('topics')))
    assert not api(app,{'action':'delete_chapter','id[]':[empty]})['success']
    assert not json.loads(app.request('/modules/super_admin/subjects.php',{'action_chapter':'delete','chapter_id[]':[empty]}))['success']
    app.db.execute("CREATE TRIGGER reject_curriculum_audit BEFORE INSERT ON activity_logs WHEN NEW.action='Delete unused chapter' BEGIN SELECT RAISE(ABORT,'Synthetic audit failure'); END");app.db.commit()
    assert not api(app,{'action':'delete_chapter','id':empty})['success']
    assert app.db.execute('SELECT archived_at FROM chapters WHERE id=?',(empty,)).fetchone()==(None,),'Audit failure rolls back the deletion'
    app.db.execute('DROP TRIGGER reject_curriculum_audit');app.db.commit()
    denied=api(app,{'action':'delete_topic','id':tid})
    assert not denied['success'] and denied['blocked'] and denied['dependencies']['total']==6
    sections={g['section'] for g in denied['dependencies']['groups']}
    assert sections=={'Question Bank','Exam Questions','Exam Multiple Choice Questions','Learning Hub / Notes','Exam Paper Grids'}
    assert 'private preserved content body' not in json.dumps(denied)
    for group in denied['dependencies']['groups']:
      for item in group['items']:
        assert item['class'] and item['subject'] and item['owner']
        if item['school']=='Shared catalog' and group['section']!='Exam Paper Grids':
          assert 'record_id=' in item['url'];body=app.request(item['url']);assert 'Error found' not in body
        elif item['school']!='Shared catalog':assert item['url'] is None
    blocked=json.loads(app.request('/modules/super_admin/subjects.php',{'action_chapter':'delete_by_name','class_id':cls,'subject_id':sub,'name':'Deletion demo chapter'}))
    assert not blocked['success'] and blocked['dependencies']['total']==6
    assert not app.db.execute('SELECT archived_at FROM chapters WHERE id=?',(ch,)).fetchone()[0]
    app.request(CUR,{'action':'delete_chapter','id':ch},csrf=False,status=419)
    for user in ('teacher','school_admin','co_teacher','student_login'):
      app.login(user);app.request(CUR,{'action':'delete_chapter','id':ch},status=403)
      app.request(CUR,{'action':'restore_topic','id':tid},status=403)
    app.login('teacher');app.request(QB,{'action':'archive','question_id':ids['private']})
    app.login('admin')
    for route,key,action in ((QB,'bank','archive'),(EQ,'exam','delete'),(MCQ,'mcq','delete')):app.request(route,{'action':action,'question_id':ids[key]})
    app.request('/modules/learning/index.php',{'action_note':'archive','note_id':ids['note']})
    blocked=api(app,{'action':'delete_chapter','id':ch});assert blocked['dependencies']['total']==1 and blocked['dependencies']['groups'][0]['section']=='Exam Paper Grids'
    app.request(GRIDS,{'action':'archive','grid_id':ids['grid'],'class_id':cls,'subject_id':sub})
    assert api(app,{'action':'delete_topic','id':tid})['success']
    deleted=json.loads(app.request(CUR+f'?action=get_deleted&class_id={cls}&subject_id={sub}'))
    assert any(i['id']==tid and i['kind']=='topics' for i in deleted['items'])
    assert not api(app,{'action':'add_topic','chapter_id':ch,'class_id':cls,'subject_id':sub,'name':'Deletion demo topic'})['success']
    app.request(EQ,{'action':'restore','question_id':ids['exam']},status=422)
    assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(ids['exam'],)).fetchone()[0]
    assert api(app,{'action':'restore_topic','id':tid})['success']
    assert api(app,{'action':'delete_chapter','id':ch})['success']
    active=json.loads(app.request(CUR+f'?action=get_chapters&class_id={cls}&subject_id={sub}'))['chapters'];assert all(c['id']!=ch for c in active)
    active=json.loads(app.request(QB+f'?action_get_chapters=1&class_id={cls}&subject_id={sub}'));assert all(c['id']!=ch for c in active)
    deleted=json.loads(app.request(CUR+f'?action=get_deleted&class_id={cls}&subject_id={sub}'))['items'];assert any(c['id']==ch and c['kind']=='chapters' for c in deleted)
    assert any(t['id']==tid and t['kind']=='topics' and t['parent_deleted'] for t in deleted)
    assert not api(app,{'action':'restore_topic','id':tid})['success']
    app.request(GRIDS,{'action':'restore','grid_id':ids['grid'],'class_id':cls,'subject_id':sub},status=422)
    assert api(app,{'action':'restore_chapter','id':ch})['success']
    app.request(EQ,{'action':'restore','question_id':ids['exam']})
    app.request(GRIDS,{'action':'restore','grid_id':ids['grid'],'class_id':cls,'subject_id':sub})
    assert api(app,{'action':'delete_chapter','id':ch})['dependencies']['total']==2
    assert app.db.execute('SELECT id,question_text FROM questions ORDER BY id').fetchall()==bodies
    assert original_counts==(len(app.snapshot('questions')),len(app.snapshot('learning_notes')),len(app.snapshot('chapters')),len(app.snapshot('topics')))
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    # Exact-record links do not bypass ownership or school visibility.
    app.login('other_student_login');body=app.request(MCQ+'?record_id='+str(ids['mcq']));assert 'mcq preserved content body' not in body
    app.login('school_admin');app.request(CUR+f'?action=get_deleted&class_id={f["class"]}&subject_id={f["subject"]}',status=403)
    print(f'Curriculum deletion HTTP passed: {app.count} requests; schema16 backup/upgrade, dependency locations, read-only roles, archive/retry, exact links, parent-first recovery and preserved records.')

if __name__=='__main__':run()
