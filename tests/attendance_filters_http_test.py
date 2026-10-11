"""Exact enrollment filters, dual-calendar attendance and broader read-only ledgers."""
import re
from urllib.parse import urlencode
from http_support import ERPHttpFixture

def table(body, name):
    return re.search(r'<table[^>]+id="'+name+r'".*?</table>', body, re.S).group(0)

with ERPHttpFixture() as app:
    f=app.fixture;cls=f['class'];sid=f['student'];exam=f['exam']
    c1=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 1'").fetchone()[0]
    c10=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 10'").fetchone()[0]
    for class_id,name,status,roll in [(c1,'OneOnly','active',17),(c10,'TenOnly','active',117),(c1,'InactiveOnly','inactive',19)]:
        app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,first_name_en,last_name_en,first_name_np,roll_number,emis_id,emis_registration_number,gender,school_id,status) VALUES(?,1,? ,?,'Student','विद्यार्थी',?,?,?,'Male',1,?)",('ID-'+name,class_id,name,roll,'EMIS-'+name,'REG-'+name,status))
    app.db.execute('UPDATE students SET roll_number=7 WHERE id=?',(sid,));app.db.commit()
    app.login('school_admin')
    path='/modules/students/index.php'
    body=app.request(path+'?'+urlencode({'class':c1}));grid=table(body,'studentTable');assert 'OneOnly Student' in grid and 'InactiveOnly Student' in grid and 'TenOnly Student' not in grid
    grid=table(app.request(path+'?'+urlencode({'class':c10})),'studentTable');assert 'TenOnly Student' in grid and 'OneOnly Student' not in grid
    grid=table(app.request(path+'?'+urlencode({'class':c1,'status':'active'})),'studentTable');assert 'OneOnly Student' in grid and 'InactiveOnly Student' not in grid
    for search in ['OneOnly','17','ID-OneOnly','EMIS-OneOnly','REG-OneOnly','विद्यार्थी']:
        grid=table(app.request(path+'?'+urlencode({'class':c1,'search':search})),'studentTable');assert 'OneOnly Student' in grid
    for search in ['Class 1','Male','active','%','_']:
        grid=table(app.request(path+'?'+urlencode({'search':search})),'studentTable');assert 'OneOnly Student' not in grid,'Text search leaked into class/status/gender or treated wildcards as all records'
    app.request(path+'?search[]=bad',status=422)
    app.request(path+'?class='+str(f['otherClass']),status=404)
    app.db.execute("INSERT INTO sections(class_id,name,school_id) VALUES(?,'B',1)",(cls,));section_b=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,section_id,first_name_en,last_name_en,gender,school_id,status) VALUES('FILTER-SECTION-B',1,?,?,'SectionBOnly','Student','Female',1,'active')",(cls,section_b));app.db.commit()
    grid=table(app.request(path+'?'+urlencode({'class':cls,'section':f['section']})),'studentTable');assert 'Test Student' in grid and 'SectionBOnly Student' not in grid
    grid=table(app.request(path+'?'+urlencode({'class':cls,'section':section_b})),'studentTable');assert 'SectionBOnly Student' in grid and 'Test Student' not in grid
    base='/modules/attendance/index.php'
    def attendance_url(**params):return base+'?'+urlencode({'class':cls,**params})
    monthly=attendance_url(view='monthly',calendar='BS',year=2083,month=6)
    body=app.request(monthly);assert all(name in body for name in ['Baishak','Jestha','Asad','Shrawan','Bhadra','Asoj','Kartik','Mangsir','Paush','Magh','Falgun','Chaitra']) and 'January' not in body
    body=app.request(attendance_url(view='monthly',calendar='BS',year=2083,month=6,section=f['section']));assert 'SectionBOnly Student' not in table(body,'attendanceTable')
    body=app.request(attendance_url(view='monthly',calendar='AD',year=2026,month=10));assert 'January' in body and 'December' in body and 'Baishak' not in body
    range_path=attendance_url(view='range',calendar='BS',range_start='2083-06-15',range_end='2083-06-17',search='Test Student')
    body=app.request(range_path);dates=re.findall(r'name="attendance_grid\['+str(sid)+r'\]\[([^\]]+)\]"',body);assert len(dates)==3
    cells={'save_attendance':'1','class_id':cls,'section_id':0,'expected_cells':3,**{f'attendance_grid[{sid}][{date}]':status for date,status in zip(dates,['P','A','L'])}}
    body=app.request(range_path,cells);assert 'Attendance saved successfully' in body
    assert app.db.execute('SELECT status FROM attendances WHERE student_id=? ORDER BY date_ad',(sid,)).fetchall()==[('P',),('A',),('L',)]
    before=app.snapshot('attendances');edit={**cells,f'attendance_grid[{sid}][{dates[0]}]':'H'}
    body=app.request(range_path,edit);assert 'password is required' in body and app.snapshot('attendances')==before
    body=app.request(range_path,{**edit,'edit_password':'wrong'});assert 'password is required' in body and app.snapshot('attendances')==before
    app.request(range_path,{**edit,'edit_password':'TestPassword123!'});assert app.db.execute('SELECT status FROM attendances WHERE student_id=? AND date_ad=?',(sid,dates[0])).fetchone()==('H',)
    before=app.snapshot('attendances');app.request(range_path,{**edit,'expected_cells':4,'edit_password':'TestPassword123!'});assert app.snapshot('attendances')==before
    app.request(range_path,{**edit,f'attendance_grid[{sid}][{dates[1]}]':'BAD','edit_password':'TestPassword123!'});assert app.snapshot('attendances')==before
    app.request(range_path,{**edit,f'attendance_grid[{f["otherStudent"]}][{dates[0]}]':'P','expected_cells':4,'edit_password':'TestPassword123!'});assert app.snapshot('attendances')==before
    yearly=app.request(attendance_url(view='yearly',calendar='BS',year=2083));assert 'Next dates' in yearly and 'Totals cover the full selected period' in yearly
    app.request(attendance_url(view='yearly',calendar='AD',year=2024,day_page=12))
    for search in ['Test Student','7','FIXTURE-1']:
        assert 'Test Student' in table(app.request(attendance_url(view='monthly',year=2026,month=10,search=search)),'attendanceTable')
    app.request(attendance_url(view='range',range_start='2026-10-10',range_end='2026-10-01'),status=422)
    app.request(attendance_url(view='yearly',calendar='BS',year=2091),status=422)
    app.request(attendance_url(search='x'*101),status=422)
    # Own-school class teachers retain attendance authority; other subject teachers do not gain it.
    app.login('teacher');app.request(monthly)
    app.db.execute('UPDATE classes SET class_teacher_id=NULL WHERE id=?',(cls,));app.db.commit()
    app.request(monthly,status=422);app.request(range_path,cells,status=422)
    app.login('principal');body=app.request(monthly);assert 'name="save_attendance"' not in body
    app.request(range_path,cells,status=403);assert app.snapshot('attendances')==before
    print(f'Attendance and enrollment HTTP passed: {app.count} requests; exact class/status filters, narrow search, AD/BS periods, multi-day corrections, rollback and permissions.')
