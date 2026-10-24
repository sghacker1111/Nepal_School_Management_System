"""Catalog ownership, school selections, defaults, exceptions and live scope revocation."""
import json,re
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    app.login('school_admin')
    super_name=app.db.execute("SELECT username FROM users WHERE role='super_admin'").fetchone()[0]
    school=1;c=app.fixture['class'];other=app.fixture['otherSchool'];teacher=app.fixture['ids']['teacher']
    original=app.snapshot('subjects')
    for path in ['/modules/subjects/add.php','/modules/subjects/edit.php?id='+str(app.fixture['subject'])]:
        app.request(path,status=403)
        app.request(path,{'name_en':'Forged'},status=403)
    app.request('/modules/classes/add.php',{'add_class':'1','name':'Forged'},status=403)
    app.request('/modules/classes/edit.php?class_id='+str(c),{'name':'Forged'},status=403)
    app.request('/modules/subjects/index.php?toggle_hide='+str(app.fixture['subject']),{},status=403)
    app.request('/modules/settings/cdc.php',{'confirmed':'1'},status=403)
    app.request('/modules/super_admin/offerings.php?school_id=1',status=403)
    assert original==app.snapshot('subjects')
    # A system catalog save must synchronize all current schools atomically.
    app.login(super_name)
    source_class=app.db.execute('SELECT source_id FROM classes WHERE id=?',(c,)).fetchone()[0]
    assert source_class
    data={'action_subject':'add','name_en':'Offering Test Elective','code':'OFFER-OPT','is_optional':'1','map_class_id':source_class}
    body=app.request('/modules/super_admin/subjects.php',data)
    assert 'School database error' not in body
    source=app.db.execute("SELECT id FROM subjects WHERE school_id=0 AND code='OFFER-OPT'").fetchone()
    assert source,re.findall(r'<div class="alert alert-danger".*?</div>',body,re.S)
    local=app.db.execute('SELECT id FROM subjects WHERE school_id=1 AND source_id=?',(source[0],)).fetchone()
    assert local,'Catalog save did not reach the school'
    sub=local[0]
    assert app.db.execute('SELECT is_active FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,sub)).fetchone()[0]==0
    assert app.db.execute('SELECT COUNT(*) FROM subjects WHERE school_id=? AND source_id=?',(other,source[0])).fetchone()[0]==1
    snapshots={table:app.snapshot(table) for table in ['marks','students','exam_subjects']}
    app.login('school_admin')
    path='/modules/subjects/index.php?class='+str(c)
    body=app.request(path);assert 'Offering Test Elective' in body and 'Assign to teach' in body
    assert 'Offering Test Elective' in app.request('/modules/classes/subjects.php?class='+str(c))
    selectors='/modules/learning/curriculum.php?action=get_subjects&class_id='+str(c)
    assert sub not in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    payload={'offering_class':c,'offering_subject':sub,'offering_action':'assign'}
    body=app.request(path,payload);assert 'School teaching assignment saved' in body
    assert sub in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    app.request(path,{**payload,'offering_action':'teachers','teacher_ids[]':[teacher],'weekly_periods':3})
    app.login('teacher');assert sub in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    app.request(path,payload,status=403)
    app.login('school_admin')
    app.request(path,{**payload,'offering_action':'unassign'})
    assert sub not in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    app.request('/modules/learning/questions.php?class='+str(c)+'&subject='+str(sub),status=404)
    # Compulsory defaults are inherited by all schools; only system admin can hide one.
    app.login(super_name)
    app.request('/modules/super_admin/subjects.php',{'action_subject':'add','name_en':'Offering Test Required','code':'OFFER-REQ','map_class_id':source_class})
    required=app.db.execute("SELECT id FROM subjects WHERE school_id=1 AND code='OFFER-REQ'").fetchone()[0]
    assert app.db.execute('SELECT is_active FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,required)).fetchone()[0]==1
    app.login('school_admin')
    body=app.request(path,{'offering_class':c,'offering_subject':required,'offering_action':'unassign'})
    assert 'Compulsory subjects are assigned by system admin' in body
    app.request(path,{**payload,'offering_subject':app.fixture['subject'],'offering_class':app.fixture['otherClass']},status=200)
    # Per-school admin exception remains after a later global save/sync.
    app.login(super_name);admin='/modules/super_admin/offerings.php?school_id=1&class='+str(c)
    app.request(admin,{'offering_class':c,'offering_subject':required,'offering_action':'block'})
    app.request('/modules/super_admin/subjects.php',{'synchronize_schools':'1'})
    assert app.db.execute('SELECT is_active FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,required)).fetchone()[0]==0
    app.login('school_admin')
    body=app.request(path,{'offering_class':c,'offering_subject':required,'offering_action':'assign'});assert 'hidden this subject' in body
    assert required not in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    # A school exception cannot affect another school or delete historical records.
    assert app.db.execute("SELECT cs.is_active FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND s.code='OFFER-REQ'",(other,)).fetchone()[0]==1
    for table,rows in snapshots.items():assert rows==app.snapshot(table),table+' was rewritten'
    app.login(super_name)
    app.request(admin,{'offering_action':'hide_class','offering_class':c})
    app.request('/modules/super_admin/subjects.php',{'synchronize_schools':'1'})
    assert app.db.execute('SELECT is_active FROM classes WHERE id=?',(c,)).fetchone()[0]==0
    app.request(admin,{'offering_action':'show_class','offering_class':c})
    app.request(admin,{'offering_action':'restore','offering_class':c,'offering_subject':required})
    app.login('school_admin');assert required in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    # A school-choice subject can be core for enrolled students; it is not forced into elective slots.
    app.login(super_name)
    app.request('/modules/super_admin/subjects.php',{'action_subject':'add','name_en':'School Choice Core','code':'OFFER-CORE','school_default':'choose','map_class_id':source_class})
    core=app.db.execute("SELECT id FROM subjects WHERE school_id=1 AND code='OFFER-CORE'").fetchone()[0]
    source_core=app.db.execute('SELECT source_id FROM subjects WHERE id=?',(core,)).fetchone()[0]
    assert app.db.execute('SELECT is_active,is_optional FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,core)).fetchone()==(0,0)
    app.login('school_admin');core_input={'offering_class':c,'offering_subject':core,'offering_action':'assign'}
    assert 'Assign to teach' in app.request(path)
    app.request(path,core_input)
    assert core in [int(s['id']) for s in json.loads(app.request(selectors))['subjects']]
    before=app.snapshot('class_subjects')
    app.request(path,{**core_input,'offering_action':'teachers','th_full_marks':999})
    assert app.snapshot('class_subjects')==before,'Catalog settings were writable through assignment forms'
    app.request(path,core_input,status=419,csrf=False)
    # Global hide/unhide retains a school's explicit choice; edits preserve local subject IDs.
    app.login(super_name)
    for visible in [0,1]:
        app.request('/modules/super_admin/subjects.php',{'action_visibility':1,'subject_id':source_core,'visible':visible})
        assert app.db.execute('SELECT is_active FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,core)).fetchone()[0]==visible
    app.request('/modules/super_admin/subjects.php',{'action_subject':'edit','subject_id':source_core,'name_en':'Renamed School Choice Core','code':'OFFER-CORE','school_default':'auto'})
    assert app.db.execute('SELECT name_en FROM subjects WHERE id=?',(core,)).fetchone()[0]=='Renamed School Choice Core'
    app.login('school_admin');app.request(path,{**core_input,'offering_action':'unassign'})
    app.login(super_name)
    source_mapping=app.db.execute('SELECT id FROM class_subjects WHERE school_id=0 AND class_id=? AND subject_id=?',(source_class,source_core)).fetchone()[0]
    app.request('/modules/super_admin/subjects.php',{'mapping_id':source_mapping,'required_default':1})
    assert app.db.execute('SELECT is_active FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,core)).fetchone()[0]==1
    # Removing an offering revokes open marks forms, while published exam snapshots remain readable.
    subject=app.fixture['subject'];exam=app.fixture['exam'];student=app.fixture['student']
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,60,20)',(exam,student,subject));app.db.commit()
    historical=app.snapshot('marks')
    app.request(admin,{'offering_action':'block','offering_class':c,'offering_subject':subject})
    app.login('school_admin')
    marks_url=f'/modules/results/marks_entry.php?exam={exam}&class={c}'
    body=app.request(marks_url)
    assert f'name="marks[{student}][{subject}]' not in body
    app.request(marks_url,{'save_marks':1,'marks_complete':1,'exam_id':exam,'class_id':c,f'marks[{student}][{subject}][theory]':70})
    assert historical==app.snapshot('marks'),'A stale marks form changed an unassigned subject'
    app.db.execute('UPDATE exams SET is_locked=1,is_published=1 WHERE id=?',(exam,));app.db.commit()
    label=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(subject,)).fetchone()[0]
    assert label in app.request(f'/modules/results/report_card.php?student={student}&exam={exam}')
    assert historical==app.snapshot('marks')
    print(f'School offerings HTTP passed: {app.count} requests; catalog ownership, all-school defaults, elective choices, school exceptions, teacher assignment and record preservation.')
