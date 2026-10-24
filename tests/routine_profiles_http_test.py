"""Real authenticated routes on disposable data: profiles, catalog rights and TH/IN print rows."""
import json,re
from http_support import ERPHttpFixture

def payload(cls,subject,teacher,name='Summer 2083',mode='manual'):
    data={'action':'save_profile','class_id':cls,'name':name,'mode':mode,'password':'TestPassword123!','intent':'save'}
    for day in range(1,6):
        count=3 if day==2 else 2
        data[f'layout[{day}][count]']=count
        for p,(start,end) in enumerate([('09:00','09:45'),('09:45','10:30'),('10:45','11:30')][:count],1):
            data[f'layout[{day}][times][{p}][start]']=start
            data[f'layout[{day}][times][{p}][end]']=end
            data[f'grid[{day}][{p}][subject_id]']=subject if p==1 else ''
            data[f'grid[{day}][{p}][teacher_id]']=teacher if p==1 else ''
        for kind in ['short','tiffin']:
            data[f'layout[{day}][breaks][{kind}][start]']='10:30' if day==2 and kind=='short' else ''
            data[f'layout[{day}][breaks][{kind}][end]']='10:45' if day==2 and kind=='short' else ''
    return data

with ERPHttpFixture() as app:
    f=app.fixture;cls=f['class'];teacher=f['ids']['teacher'];subject=f['subject'];exam=f['exam']
    app.db.execute('UPDATE class_subjects SET weekly_periods=0 WHERE class_id=?',(cls,))
    app.db.execute('UPDATE class_subjects SET weekly_periods=5 WHERE class_id=? AND subject_id=?',(cls,subject))
    app.db.execute("UPDATE users SET full_name='Sunil Gotame' WHERE id=?",(teacher,))
    app.db.commit();app.login('school_admin')
    url=f'/modules/classes/routine.php?class_id={cls}'
    body=app.request(url);assert 'Load weekly routine profile' in body and 'routineProfileForm' in body
    data=payload(cls,subject,teacher)
    body=app.request(url,data);assert 'Routine profile saved successfully' in body
    first=app.db.execute('SELECT id,revision FROM routine_profiles').fetchone();pid=first[0]
    assert not app.snapshot('class_routines')
    saved=json.loads(app.db.execute('SELECT payload_json FROM routine_profiles').fetchone()[0]);assert saved['layout']['2']['count']==3 and saved['layout']['2']['breaks']['short']['end']=='10:45'
    body=app.request(url+'&profile_id='+str(pid));assert '(SG)' in body and '10:30–10:45' in body
    activation={'action':'activate_profile','class_id':cls,'profile_id':pid,'revision':1,'password':'TestPassword123!'}
    app.db.execute('UPDATE class_subjects SET weekly_periods=8 WHERE class_id=? AND subject_id=?',(cls,subject));app.db.commit()
    body=app.request(url,activation);assert '5 of 8 scheduled' in body and not app.snapshot('class_routines')
    assert app.db.execute('SELECT revision FROM routine_profiles WHERE id=?',(pid,)).fetchone()==(1,)
    app.db.execute('UPDATE class_subjects SET weekly_periods=5 WHERE class_id=? AND subject_id=?',(cls,subject));app.db.commit()
    body=app.request(url,activation);assert 'activated successfully' in body
    assert len(app.snapshot('class_routines'))==11
    body=app.request(url,{**data,'name':'Winter 2083','mode':'automatic'});assert 'saved successfully' in body
    assert len(app.snapshot('routine_profiles'))==2
    body=app.request(url,{**data,'profile_id':pid,'revision':1,'name':'Summer 2083 revised'});assert 'saved successfully' in body
    before=app.snapshot('routine_profiles');body=app.request(url,{**data,'profile_id':pid,'revision':1});assert 'changed in another session' in body and app.snapshot('routine_profiles')==before
    body=app.request(url,{**data,'name':'winter   2083'});assert 'profile with this name already exists' in body and app.snapshot('routine_profiles')==before
    before_active=app.snapshot('class_routines')
    body=app.request(url,{**data,'name':'Invalid overlap','layout[1][times][2][start]':'09:30','intent':'activate'});assert 'no overlaps' in body and app.snapshot('class_routines')==before_active
    assert not app.db.execute("SELECT id FROM routine_profiles WHERE name='Invalid overlap'").fetchone()
    # A bad Monday time must not discard otherwise-valid Tuesday manual entries.
    app.db.execute('UPDATE class_subjects SET weekly_periods=6 WHERE class_id=? AND subject_id=?',(cls,subject));app.db.commit()
    recovery={**data,'name':'Recover entered week','layout[1][times][2][start]':'09:30','grid[2][2][subject_id]':subject,'grid[2][2][teacher_id]':teacher,'intent':'activate'}
    body=app.request(url,recovery);assert 'no overlaps' in body
    tuesday_subject=re.search(r'<select\b[^>]*name="grid\[2\]\[2\]\[subject_id\]"[^>]*>(.*?)</select>',body,re.S).group(1)
    tuesday_teacher=re.search(r'<select\b[^>]*name="grid\[2\]\[2\]\[teacher_id\]"[^>]*>(.*?)</select>',body,re.S).group(1)
    assert re.search(r'<option value="'+str(subject)+r'"[^>]*selected',tuesday_subject),'Tuesday subject lost after unrelated time error'
    assert re.search(r'<option value="'+str(teacher)+r'"[^>]*selected',tuesday_teacher),'Tuesday teacher lost after unrelated time error'
    assert re.search(r'name="layout\[1\]\[times\]\[2\]\[start\]" value="09:30"',body),'Invalid overlapping time must remain available to correct'
    assert app.snapshot('class_routines')==before_active and not app.db.execute("SELECT id FROM routine_profiles WHERE name='Recover entered week'").fetchone()
    app.db.execute('UPDATE class_subjects SET weekly_periods=5 WHERE class_id=? AND subject_id=?',(cls,subject));app.db.commit()
    body=app.request(url,{**data,'password':'wrong'});assert 'current administrator password' in body and app.snapshot('class_routines')==before_active
    app.request(url,data,status=419,csrf=False)
    app.request(f'/modules/classes/routine.php?class_id={f["otherClass"]}&profile_id={pid}',status=404)
    app.request(url+'&profile_id=999999',status=404)
    app.request(url+'&profile_id[]=1',status=404)
    body=app.request(url,{**data,'profile_id':pid,'revision[]':2});assert 'valid routine profile' in body
    body=app.request(url,{**activation,'profile_id[]':1});assert 'valid routine profile' in body
    # Both draft modes may overlap another class; activation remains atomic and strict.
    other_cls=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 8'").fetchone()[0]
    app.db.execute('INSERT INTO class_routines(school_id,class_id,day_of_week,period_number,teacher_id,start_time,end_time) VALUES(1,?,1,2,?,?,?)',(other_cls,teacher,'09:00','09:45'))
    booking=app.db.execute('SELECT last_insert_rowid()').fetchone()[0];app.db.commit()
    booked=app.snapshot('class_routines')
    for mode in ['manual','automatic']:
        name=mode+' overlap draft'
        body=app.request(url,{**data,'name':name,'mode':mode});assert 'saved successfully' in body
        draft=app.db.execute('SELECT id,revision FROM routine_profiles WHERE name=?',(name,)).fetchone()
        body=app.request(url,{**activation,'profile_id':draft[0],'revision':draft[1]});assert 'already scheduled' in body and app.snapshot('class_routines')==booked
    body=app.request(url,{**data,'name':'Automatic blocked activation','mode':'automatic','intent':'activate'});assert 'already scheduled' in body
    assert not app.db.execute("SELECT id FROM routine_profiles WHERE name='Automatic blocked activation'").fetchone() and app.snapshot('class_routines')==booked
    app.db.execute('DELETE FROM class_routines WHERE id=?',(booking,));app.db.commit()
    assert app.snapshot('class_routines')==before_active
    # Duplicate checks for creation, edit and class-subject mapping.
    classes=app.snapshot('classes')
    for name in ['9','  Grade   9 ','कक्षा ९']:
        app.request('/modules/classes/add.php',{'add_class':1,'name':name,'level':'Secondary'},status=403)
        assert app.snapshot('classes')==classes
    app.request('/modules/classes/edit.php?class_id='+str(cls),{'name':'Class 8','level':'Basic'},status=403);assert app.snapshot('classes')==classes
    own_name,own_code=app.db.execute('SELECT name_en,code FROM subjects WHERE id=?',(subject,)).fetchone()
    subjects=app.snapshot('subjects');mappings=app.snapshot('class_subjects')
    for name,code in [('  '+own_name.upper()+'  ','OTHER-CODE'),('Different duplicate',own_code.lower())]:
        app.request('/modules/subjects/add.php',{'class_id':cls,'name_en':name,'code':code},status=403)
        assert app.snapshot('subjects')==subjects and app.snapshot('class_subjects')==mappings
    app.db.execute("INSERT INTO subjects(school_id,name_en,code,type) VALUES(1,'Different catalog name',?,'optional')",(own_code,));new=app.db.execute('SELECT last_insert_rowid()').fetchone()[0];app.db.commit()
    app.request(f'/modules/classes/subjects.php?class_id={cls}',{'action':'map_subject','subject_id':new},status=403);assert app.snapshot('class_subjects')==mappings
    app.request('/modules/learning/curriculum.php',{'action':'map_subject','class_id':cls,'subject_id':new},status=403);assert app.snapshot('class_subjects')==mappings # Schools use Class/Subject Management; curriculum is read-only.
    # Both printing routes use the same two-row percentage layout.
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,?,?)',(exam,f['student'],subject,60,20));app.db.commit()
    for path in [f'/modules/results/report_card.php?student={f["student"]}&exam={exam}&system=percentage',f'/modules/results/print_bulk.php?class={cls}&exam={exam}&system=percentage']:
        body=app.request(path);assert 'percentage-components' in body and own_name+' (PR)' in body and own_name+' (TH)' in body and 'rowspan="2"' in body
    # Every non-admin school role can preview, but forged writes are blocked.
    profiles=app.snapshot('routine_profiles')
    for role in ['teacher','principal','exam_officer']:
        app.login(role);body=app.request(url+'&profile_id='+str(pid));assert '(SG)' in body and 'routineProfileForm' not in body and 'name="password"' not in body
        app.request(url,data,status=403);app.request(url,activation,status=403);assert app.snapshot('routine_profiles')==profiles and app.snapshot('class_routines')==before_active
    app.login('teacher')
    app.request('/modules/classes/add.php',{'add_class':1,'name':'7'},status=403)
    app.request('/modules/subjects/add.php',{'class_id':cls,'name_en':'Forbidden'},status=403)
    app.login('principal');app.request('/modules/learning/curriculum.php',{'action':'map_subject','class_id':cls,'subject_id':new},status=403)
    # Exact legacy cleanup is confirmed, scoped and retains linked educational records.
    app.db.execute("UPDATE users SET username='sgsunil' WHERE id=?",(teacher,))
    app.db.execute("INSERT INTO classes(school_id,name,level,grading_type,is_active) VALUES(1,'7','Basic','gpa',1)")
    legacy=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('INSERT INTO sections(school_id,class_id,name) VALUES(1,?,?)',(legacy,'Legacy'));app.db.commit();app.login('school_admin')
    cleanup={'action':'remove_legacy_seven','class_id':legacy,'password':'TestPassword123!','confirm_cleanup':1}
    body=app.request('/modules/classes/index.php');assert 'Remove legacy class' not in body
    app.request('/modules/classes/index.php',cleanup,status=403)
    super_name=app.db.execute("SELECT username FROM users WHERE role='super_admin'").fetchone()[0];app.login(super_name)
    body=app.request('/modules/super_admin/offerings.php?school_id=1',{'offering_action':'hide_class','offering_class':legacy})
    assert 'School class visibility saved' in body
    assert app.db.execute('SELECT is_active FROM classes WHERE id=?',(legacy,)).fetchone()==(0,) and app.db.execute('SELECT COUNT(*) FROM sections WHERE class_id=?',(legacy,)).fetchone()==(1,)
    app.login('sgsunil');body=app.request('/modules/classes/index.php');assert '<td><strong>7</strong></td>' not in body
    app.request('/modules/classes/index.php',cleanup,status=403)
    print(f'Routine profiles, catalog and component print HTTP passed: {app.count} requests.')
