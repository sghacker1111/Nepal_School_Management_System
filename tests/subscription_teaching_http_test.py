"""Actual forms plus crafted URLs/POSTs against a disposable school database."""
import json, re
from html import unescape
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    f=app.fixture
    own=f['subject']; cls=f['class']; teacher=f['ids']['teacher']
    other=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND subject_id<>? LIMIT 1',(cls,own)).fetchone()[0]
    app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id,teacher_id,is_active,section_id) VALUES(1,?,?,?,1,?)',(cls,own,str(teacher),f['section']));app.db.commit()
    app.login('teacher')
    curriculum='/modules/learning/curriculum.php'
    subjects=json.loads(app.request(f'{curriculum}?action=get_subjects&class_id={cls}'))['subjects']
    assert [int(s['id']) for s in subjects].count(own)==1 and other in [int(s['id']) for s in subjects], 'Public curriculum has distinct subject IDs once'
    app.request(f'{curriculum}?action=get_chapters&class_id={cls}&subject_id={other}')
    app.request(f'{curriculum}?action=get_all_subjects',status=404)
    chapter_input={'action':'add_chapter','class_id':cls,'subject_id':own,'name':'Own synthetic chapter'}
    before=app.snapshot('chapters');app.request(curriculum,chapter_input,status=403);assert app.snapshot('chapters')==before
    app.db.execute('INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,?)',(cls,own,'Own synthetic chapter'));chapter=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,?)',(cls,own,chapter,'Own synthetic topic'));topic=app.db.execute('SELECT last_insert_rowid()').fetchone()[0];app.db.commit()
    topic_input={'action':'add_topic','class_id':cls,'subject_id':own,'chapter_id':chapter,'name':'Own synthetic topic'}
    before=app.snapshot('chapters'),app.snapshot('topics')
    for action,record in [('edit_chapter',chapter),('delete_chapter',chapter),('edit_topic',topic),('delete_topic',topic)]:
        app.request(curriculum,{'action':action,'id':record,'name':'Forbidden','class_id':cls,'subject_id':own},status=403)
    app.request(curriculum,topic_input,status=403)
    app.request(curriculum,{'action':'map_subject','class_id':cls,'subject_id':other},status=403)
    assert (app.snapshot('chapters'),app.snapshot('topics'))==before
    grid_path='/modules/learning/specification_grids.php'
    grid={'action':'add','name':'Own grid','class_id':cls,'subject_id':own,'count_MCQ':5,'marks_MCQ':1}
    body=app.request(grid_path,grid);assert 'Own grid' in body
    mapping=json.loads(re.search(r'const gridSubjectsByClass=(.*?);',body).group(1))
    assert [int(s['id']) for s in mapping[str(cls)]]==[own]
    grid_id=app.db.execute("SELECT id FROM specification_grids WHERE name='Own grid'").fetchone()[0]
    app.request(grid_path,{**grid,'action':'edit','grid_id':grid_id,'name':'Edited own grid'})
    before=app.snapshot('specification_grids');app.request(grid_path,{**grid,'subject_id':other},status=422);assert app.snapshot('specification_grids')==before
    app.db.execute('INSERT INTO specification_grids(school_id,class_id,subject_id,name,config_json) VALUES(1,?,?,?,?)',(cls,other,'Other grid','{}'));other_grid=app.db.execute('SELECT last_insert_rowid()').fetchone()[0];app.db.commit()
    before=app.snapshot('specification_grids');body=app.request(grid_path);assert 'Other grid' not in body
    app.request(grid_path,{**grid,'action':'edit','grid_id':other_grid},status=422)
    app.request(f'{grid_path}?delete={other_grid}',{},status=422);assert app.snapshot('specification_grids')==before
    marks_path=f'/modules/results/marks_entry.php?exam={f["exam"]}&class={cls}'
    marks={'save_marks':'1','marks_complete':'1','exam_id':f['exam'],'class_id':cls,f'marks[{f["student"]}][{own}][theory]':'58',f'marks[{f["student"]}][{own}][participation]':'3',f'marks[{f["student"]}][{own}][project_work]':'12',f'marks[{f["student"]}][{own}][previous_exam]':'5'}
    app.request(marks_path,marks)
    app.login('school_admin');app.request(f'/modules/exams/index.php?lock={f["exam"]}',{})
    app.login('teacher');before=app.snapshot('marks');body=app.request(marks_path)
    assert re.search(r'<fieldset\s+disabled[^>]+id="marksFields"',body)
    assert 'Marks are view-only' in body and 'name="save_marks"' not in body
    body=app.request(marks_path,{**marks,f'marks[{f["student"]}][{own}][theory]':'65'});assert 'locked or published' in body and app.snapshot('marks')==before
    app.request(f'/modules/exams/index.php?reopen={f["exam"]}',{},status=403)
    app.login('exam_officer');app.request(f'/modules/exams/index.php?reopen={f["exam"]}',{},status=403)
    app.login('school_admin');app.request(f'/modules/exams/index.php?reopen={f["exam"]}',{})
    app.login('teacher');body=app.request(marks_path);assert 'name="save_marks"' in body
    app.request(marks_path,{**marks,f'marks[{f["student"]}][{own}][theory]':'65'});assert app.db.execute('SELECT theory_obtained FROM marks WHERE exam_id=? AND subject_id=?',(f['exam'],own)).fetchone()[0]==65
    # School creation/editing use actual multi-checkbox duration fields, not a single dropdown.
    app.login('admin');schools='/modules/super_admin/schools.php';body=app.request(schools)
    assert 'name="packages[]"' in body and 'name="subscription_type"' not in body
    school={'action':'add','name_en':'Synthetic Subscription School','name_np':'Synthetic School NP','emis_code':'SYNTHETIC-EMIS','address':'Synthetic address','phone':'9800000000','email':'synthetic@example.test','academic_year_current':'2083/2026','principal_name':'Synthetic Principal','principal_mobile':'9800000001','technical_person':'Synthetic Contact','admin_full_name':'Synthetic Admin','admin_username':'synthetic_subscription_admin','admin_password':'TestPassword123!','packages[]':['result_system','learning_system'],'subscription_mode':'yearly','subscription_start':'2026-10-10','subscription_enabled':'1'}
    before=app.snapshot('schools'),app.snapshot('users')
    body=app.request(schools,{**school,'packages[]':[]})
    assert 'Tick at least one package' in body and 'TestPassword123!' not in body
    metadata=json.loads(unescape(re.search(r'data-erp-config="([^"]+)"',body).group(1)))
    recovery=json.loads(metadata['values']['super-admin-schools-value-1'])
    assert recovery['name_en']==school['name_en'] and recovery['packages']==[] and 'admin_password' not in recovery
    assert (app.snapshot('schools'),app.snapshot('users'))==before
    body=app.request(schools,school);assert 'School registered' in body
    sch=app.db.execute("SELECT id,packages_json,subscription_end FROM schools WHERE name_en=?",(school['name_en'],)).fetchone();new_school=sch[0];assert set(json.loads(sch[1]))=={'result_system','learning_system'} and sch[2]=='2027-10-09'
    body=app.request(schools,{**school,'action':'edit','school_id':new_school,'admin_password':'','subscription_mode':'custom','subscription_start':'2083-06-24','subscription_end':'2084-06-22','calendar_fields[subscription_start]':'BS','calendar_fields[subscription_end]':'BS'})
    assert app.db.execute('SELECT subscription_start,subscription_end FROM schools WHERE id=?',(new_school,)).fetchone()==('2026-10-10','2027-10-09')
    for flag in ['perm_action','view_perm_action','hub_perm_action']:
        before=app.snapshot('schools');app.request(f'{schools}?{flag}=revoke&school_id={new_school}',{})
        assert app.snapshot('schools')==before, 'Obsolete permission flags must not override purchased packages'
    school.update(action='edit',school_id=new_school,admin_password='',subscription_mode='custom',subscription_start='2020-01-01',subscription_end='2020-12-31')
    app.request(schools,school)
    app.login('school_admin');app.db.execute("UPDATE schools SET packages_json=?,subscription_mode='custom',subscription_start='2020-01-01',subscription_end='2020-12-31' WHERE id=1",(json.dumps(['result_system','learning_system']),));app.db.commit()
    for path in ['/','/modules/results/marks_entry.php','/modules/learning/curriculum.php','/modules/settings/users.php']:
        assert 'subscription is expired' in app.request(path,status=403)
    app.login('admin');school.update(subscription_start='2020-01-01',subscription_end='2090-12-31');app.request(schools,school)
    app.db.execute("UPDATE schools SET subscription_end='2090-12-31' WHERE id=1");app.db.commit()
    app.login('school_admin');app.request('/modules/results/marks_entry.php');app.request(curriculum);app.request('/modules/accounting/invoices.php',status=403)
    # Revoking and restoring packages affects an existing session immediately.
    app.db.execute("UPDATE schools SET packages_json='[]' WHERE id=1");app.db.commit();app.request('/modules/results/marks_entry.php',status=403)
    app.db.execute("UPDATE schools SET packages_json='[\"full\"]',subscription_mode='no_expiry' WHERE id=1");app.db.commit();app.request('/modules/results/marks_entry.php')
    before=app.snapshot('schools');app.login('teacher');app.request(schools,school,status=403);assert app.snapshot('schools')==before
    print(f'Subscription and teaching HTTP passed: {app.count} requests; teacher scopes, duplicates, lock/reopen, multi-package creation, expiry, renewal and revocation.')
