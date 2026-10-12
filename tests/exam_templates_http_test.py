"""System defaults, copied manual exams and immutable automatic schemes through real routes."""
import json,re
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    f=app.fixture;super_name=app.db.execute("SELECT username FROM users WHERE role='super_admin'").fetchone()[0]
    app.login('school_admin')
    body=app.request('/modules/exams/add.php')
    assert 'Creation mode' in body and 'Automatic' in body and 'Manual' in body and 'First Terminal Examination' in body
    app.request('/modules/super_admin/exam_templates.php',status=403)
    app.login(super_name)
    body=app.request('/modules/super_admin/exam_templates.php');assert 'Default Exam Templates' in body and 'Second Terminal Examination' in body
    tid=app.db.execute("SELECT id FROM exam_templates WHERE template_key='first'").fetchone()[0]
    data={'template_id':tid,'revision':1,'name':'Super Admin First Term','type':'terminal','term_no':1,'has_internal':1,'result_system':'gpa','date_calendar':'BS','date_format':'ymd','is_active':1,'action':'save_template'}
    body=app.request(f'/modules/super_admin/exam_templates.php?template_id={tid}',data);assert 'saved successfully' in body
    before=app.snapshot('exam_templates');app.request(f'/modules/super_admin/exam_templates.php?template_id={tid}',data,csrf=False,status=419)
    body=app.request(f'/modules/super_admin/exam_templates.php?template_id={tid}',data);assert 'changed in another session' in body and app.snapshot('exam_templates')==before
    app.login('school_admin')
    url=f'/modules/exams/add.php?creation_mode=automatic&template_id={tid}'
    body=app.request(url);assert 'read-only' in body and 'Super Admin First Term' in body
    body=app.request(url,{'creation_mode':'automatic','template_id':tid,'name':'Tampered Name','type':'unit','has_internal':0,'result_system':'percentage','start_date':'2026-10-12','end_date':'2026-10-15'})
    assert 'created successfully' in body and 'Automatic exam' in body
    eid=app.db.execute("SELECT id FROM exams WHERE creation_mode='automatic'").fetchone()[0]
    assert app.db.execute('SELECT name,type,has_internal,result_system FROM exams WHERE id=?',(eid,)).fetchone()==('Super Admin First Term','terminal',1,'gpa')
    mapping=app.db.execute('SELECT id FROM exam_subjects WHERE exam_id=? AND class_id=? AND subject_id=?',(eid,f['class'],f['subject'])).fetchone()[0]
    snapshots={t:app.snapshot(t) for t in ['exams','exam_subjects','marks']}
    for path,data in [(f'/modules/exams/edit.php?id={eid}',{'name':'Forbidden','type':'terminal','has_internal':1,'result_system':'gpa'}),(f'/modules/exams/view.php?id={eid}',{'map_subject':1,'class_id':f['class'],'subject_id':f['subject'],'theory_full':75,'theory_pass':26.25,'practical_full':25,'practical_pass':10,'credit_hours':4,'internal_credit_hours':1,'external_credit_hours':3}),(f'/modules/exams/view.php?id={eid}&delete_mapping={mapping}',{})]:
        app.request(path,data,status=403)
        assert all(app.snapshot(t)==old for t,old in snapshots.items())
    body=app.request(f'/modules/exams/view.php?id={eid}',{'initialize_defaults':1,'init_class_id':f['class']});assert 'Initialized 0' in body
    assert app.snapshot('exam_subjects')==snapshots['exam_subjects']
    app.request(f'/modules/subjects/internal.php?exam={eid}&mapping={mapping}',{'confirm_assessment_change':1,'total':25,'pass':10,'revision':'forged'},status=403)
    app.request(f'/modules/exams/symbols.php?exam_id={eid}&class_id={f["class"]}',{'symbol_mode':'alphabetical','first_symbol':'7001'})
    assert app.db.execute('SELECT symbol_no FROM exam_symbols WHERE exam_id=? AND student_id=?',(eid,f['student'])).fetchone()==('7001',)
    app.login('teacher')
    marks_url=f'/modules/results/marks_entry.php?exam={eid}&class={f["class"]}&subject={f["subject"]}'
    entered={'save_marks':1,'exam_id':eid,'class_id':f['class'],f'marks[{f["student"]}][{f["subject"]}][theory]':60,f'marks[{f["student"]}][{f["subject"]}][participation]':3,f'marks[{f["student"]}][{f["subject"]}][project_work]':12,f'marks[{f["student"]}][{f["subject"]}][previous_exam]':5}
    body=app.request(marks_url,entered);assert 'Marks and attendance saved successfully' in body
    assert app.db.execute('SELECT theory_obtained,practical_obtained FROM marks WHERE exam_id=? AND student_id=?',(eid,f['student'])).fetchone()==(60,20)
    app.login('school_admin')
    app.request(f'/modules/exams/index.php?lock={eid}',{});assert app.db.execute('SELECT is_locked FROM exams WHERE id=?',(eid,)).fetchone()==(1,)
    app.login('teacher');before_marks=app.snapshot('marks');body=app.request(marks_url,{**entered,f'marks[{f["student"]}][{f["subject"]}][theory]':61});assert 'locked or published' in body and app.snapshot('marks')==before_marks
    app.login('school_admin')
    app.request(f'/modules/exams/index.php?reopen={eid}',{});assert app.db.execute('SELECT is_locked FROM exams WHERE id=?',(eid,)).fetchone()==(0,)
    chosen_year=app.db.execute("INSERT INTO academic_years(school_id,year_bs,year_ad,is_active,is_hidden) VALUES(1,'2080','2023',0,0)").lastrowid;app.db.commit()
    body=app.request('/modules/exams/add.php',{'creation_mode':'manual','template_id':tid,'academic_year_id':chosen_year,'name':'School Manual Test','type':'terminal','has_internal':1,'result_system':'percentage'})
    assert 'created successfully' in body
    manual=app.db.execute("SELECT id FROM exams WHERE name='School Manual Test'").fetchone()[0]
    assert app.db.execute('SELECT academic_year_id FROM exams WHERE id=?',(manual,)).fetchone()==(chosen_year,)
    before_exams=app.snapshot('exams');other_year=app.db.execute("INSERT INTO academic_years(school_id,year_bs,year_ad,is_active,is_hidden) VALUES(?,'2080','2023',0,0)",(f['otherSchool'],)).lastrowid;app.db.commit()
    for year_data in [{'academic_year_id':other_year},{'academic_year_id[]':chosen_year}]:
        app.request('/modules/exams/add.php',{'creation_mode':'automatic','template_id':tid,**year_data},status=422);assert app.snapshot('exams')==before_exams
    hidden_year=app.db.execute("INSERT INTO academic_years(school_id,year_bs,year_ad,is_active,is_hidden) VALUES(1,'2079','2022',0,1)").lastrowid;app.db.commit()
    body=app.request('/modules/exams/add.php',{'creation_mode':'automatic','template_id':tid,'academic_year_id':hidden_year});assert 'available academic year' in body and app.snapshot('exams')==before_exams
    body=app.request(f'/modules/exams/edit.php?id={manual}',{'name':'School Edited Test','type':'terminal','has_internal':1,'result_system':'gpa'});assert 'updated successfully' in body
    app.request('/modules/exams/add.php',{'creation_mode':'automatic','template_id[]':1});assert len(app.snapshot('exams'))==len(snapshots['exams'])+1
    for role in ['teacher','principal']:
        app.login(role);app.request('/modules/exams/add.php',{'creation_mode':'automatic','template_id':tid},status=403)
    app.login(super_name);app.request(f'/modules/super_admin/exam_templates.php?template_id={tid}',{**data,'action':'save_template','template_id':tid,'revision':2,'name':'Later Default','type':'terminal','term_no':1,'has_internal':1,'result_system':'gpa','date_calendar':'BS','date_format':'ymd','is_active':0})
    app.login('school_admin');body=app.request('/modules/exams/add.php',{'creation_mode':'automatic','template_id':tid});assert 'unavailable' in body
    assert app.db.execute('SELECT name FROM exams WHERE id=?',(eid,)).fetchone()==('Super Admin First Term',)
    print(f'Exam template HTTP passed: {app.count} requests; system permissions, modes, snapshots, forgery, initialization and normal lifecycle.')
