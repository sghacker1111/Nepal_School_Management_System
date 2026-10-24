"""Real HTTP + SQLite regression checks. A fresh temporary database is always used."""
import os, sys, tempfile, subprocess, socket, time, re, json, sqlite3
from datetime import datetime, timedelta, timezone
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from http.cookiejar import CookieJar

root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='erp-http-') as tmp:
    env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(Path(tmp)/'test.sqlite'),'ERP_DEBUG':'1'}
    fixture=subprocess.run(['php','tests/http_fixture.php'],cwd=root,env=env,capture_output=True,text=True,check=True)
    f=json.loads(fixture.stdout)
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    log=open(Path(tmp)/'server.log','w+',encoding='utf-8')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','router.php'],cwd=root,env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}';opener=build_opener(HTTPCookieProcessor(CookieJar())); token=''
    count=0; db=None
    def request(path,data=None,expected=200,csrf=True):
        global count
        if data is not None:
            data=dict(data)
            if csrf:data['csrf_token']=token
        req=Request(base+path,data=urlencode(data).encode() if data is not None else None)
        try:
            with opener.open(req,timeout=25) as response: status=response.status; body=response.read().decode()
        except HTTPError as error: status=error.code;body=error.read().decode()
        assert status==expected,(path,status,body[:700])
        assert not re.search(r'(Fatal error|Warning:|Deprecated:|Parse error|Uncaught)',body),(path,body[:1200])
        count+=1
        return body
    def login(username):
        global token,opener
        opener=build_opener(HTTPCookieProcessor(CookieJar()))
        body=request('/modules/auth/login.php')
        token=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1)
        body=request('/modules/auth/login.php',{'username':username,'password':'TestPassword123!'})
        assert 'Invalid username' not in body
        token=re.search(r'name="csrf-token" content="([^"]+)"',body).group(1)
    def upload_csv(path,content):
        global count
        boundary='ERPTestBoundary28101'
        body=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf_token"\r\n\r\n{token}\r\n'
              f'--{boundary}\r\nContent-Disposition: form-data; name="csv_file"; filename="test.csv"\r\nContent-Type: text/csv\r\n\r\n{content}\r\n--{boundary}--\r\n').encode()
        req=Request(base+path,data=body,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
        with opener.open(req,timeout=25) as response: result=response.read().decode();assert response.status==200
        assert not re.search(r'(Fatal error|Warning:|Deprecated:|Parse error|Uncaught)',result)
        count+=1
        return result
    try:
        for _ in range(50):
            try: opener.open(base+'/modules/auth/login.php',timeout=1);break
            except (URLError,ConnectionError): time.sleep(.1)
        login('school_admin')
        routes=['/index.php','/modules/students/index.php','/modules/students/add.php','/modules/students/import_export.php','/modules/teachers/index.php','/modules/teachers/add.php','/modules/teachers/import_export.php','/modules/classes/index.php','/modules/classes/add.php','/modules/classes/routine.php','/modules/subjects/index.php','/modules/subjects/add.php','/modules/exams/index.php','/modules/exams/add.php','/modules/results/index.php','/modules/results/marks_entry.php','/modules/results/ledger.php','/modules/attendance/index.php','/modules/reports/index.php','/modules/settings/school.php','/modules/settings/years.php','/modules/settings/users.php','/modules/auth/profile.php','/modules/auth/change_password.php','/modules/learning/index.php','/modules/learning/questions.php','/modules/learning/paper_generator.php','/modules/learning/curriculum.php','/modules/learning/specification_grids.php','/modules/learning/ocr_scanner.php']
        for route in routes:request(route,expected=403 if route.endswith('/subjects/add.php') else 200)
        for route in [f"/modules/students/edit.php?id={f['student']}",f"/modules/students/view.php?id={f['student']}",f"/modules/classes/edit.php?class_id={f['class']}",f"/modules/classes/subjects.php?class_id={f['class']}",f"/modules/teachers/edit.php?id={f['ids']['teacher']}",f"/modules/exams/view.php?id={f['exam']}",f"/modules/exams/edit.php?id={f['exam']}",f"/modules/subjects/edit.php?id={f['subject']}"]:request(route,expected=403 if '/subjects/edit.php' in route else 200)
        query=urlencode({'exam':f['exam'],'class':f['class'],'section':f['section']})
        marks={'save_marks':'1','marks_complete':'1','exam_id':f['exam'],'class_id':f['class'],'section_id':f['section'],f"attendance[{f['student']}]":'120'}
        for key,val in [('theory','60'),('participation','3'),('project_work','12'),('previous_exam','5')]:marks[f"marks[{f['student']}][{f['subject']}][{key}]"]=val
        body=request('/modules/results/marks_entry.php?'+query,marks)
        assert 'saved successfully' in body
        db=sqlite3.connect(env['ERP_DB_PATH'])
        body=request('/modules/results/index.php?'+query);assert 'Graded' in body
        # Saved scheme, rather than grade-based constants, controls rendered inputs.
        db.execute('UPDATE exam_subjects SET theory_full=100, practical_full=40 WHERE exam_id=?',(f['exam'],));db.commit()
        body=request('/modules/results/marks_entry.php?'+query)
        theory_input=re.search(r'<input[^>]+name="marks\['+str(f['student'])+r'\]\['+str(f['subject'])+r'\]\[theory\]"[^>]*>',body).group(0)
        assert 'max="100"' in theory_input, 'Marks UI must use configured theory maximum'
        db.execute('UPDATE exam_subjects SET theory_full=75, practical_full=25 WHERE exam_id=?',(f['exam'],));db.commit()
        row=db.execute('SELECT theory_obtained,practical_obtained,grade_point FROM marks WHERE student_id=? AND subject_id=?',(f['student'],f['subject'])).fetchone()
        assert row==(60,20,3.6),row
        for route in ['/modules/results/ledger.php?'+query,'/modules/results/print_bulk.php?'+query,f"/modules/results/report_card.php?student={f['student']}&exam={f['exam']}"]:
            body=request(route);assert '3.60' in body,(route,'GPA missing')
        # Issue dates are shared across single/bulk formats, validated, and read-only on print.
        today=datetime.now(timezone(timedelta(hours=5,minutes=45))).strftime('%Y-%m-%d')
        report_routes=[f"/modules/results/report_card.php?student={f['student']}&exam={f['exam']}",'/modules/results/print_bulk.php?'+query]
        for route in report_routes:
            body=request(route);assert f'data-issue-date="{today}"' in body and 'Date of Issue:' in body
            body=request(route+'&issue_date_mode=manual&issue_date=2026-09-25&system=percentage&issue_calendar=AD&display_calendar=AD&date_format=long')
            assert 'data-issue-date="2026-09-25"' in body and '25 Sep 2026 AD' in body
            request(route+'&issue_date_mode=manual&issue_date=2026-02-30&issue_calendar=AD',expected=422)
            request(route+'&issue_date_mode=invalid',expected=422)
        assert db.execute('SELECT issue_date_mode,issue_date FROM exams WHERE id=?',(f['exam'],)).fetchone()==('auto',None)
        request(f"/modules/exams/edit.php?id={f['exam']}",{'name':'Regression Exam','type':'terminal','result_system':'gpa','has_internal':'1','issue_date_mode':'manual','issue_date':'2026-09-25'})
        for route in report_routes:
            body=request(route);assert 'data-issue-date="2026-09-25"' in body
        request(f"/modules/exams/edit.php?id={f['exam']}",{'name':'Regression Exam','type':'terminal','result_system':'gpa','has_internal':'1','issue_date_mode':'auto'})
        bad=dict(marks);bad[f"marks[{f['student']}][{f['subject']}][theory]"]='76'
        body=request('/modules/results/marks_entry.php?'+query,bad);assert 'Marks must be between' in body
        assert db.execute('SELECT theory_obtained FROM marks').fetchone()[0]==60
        body=request(f"/modules/exams/index.php?delete={f['exam']}",{});assert 'cannot be deleted' in body
        body=request(f"/modules/exams/edit.php?id={f['exam']}",{'name':'Exam','type':'unit','start_date':'','end_date':'','result_system':'gpa'})
        assert 'grading system cannot change' in body
        empty_class=db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 8'").fetchone()[0]
        body=request(f"/modules/exams/view.php?id={f['exam']}",{'initialize_defaults':'1','init_class_id':empty_class})
        assert 'Initialized' in body and 'successfully' in body
        request(f"/modules/exams/index.php?lock={f['exam']}",{})
        request(f"/modules/exams/index.php?publish={f['exam']}",{})
        assert db.execute('SELECT is_published,is_locked FROM exams').fetchone()==(1,1)
        body=request('/modules/results/marks_entry.php?'+query,marks);assert 'locked or published' in body
        request(f"/modules/exams/index.php?reopen={f['exam']}",{})
        assert db.execute('SELECT is_published,is_locked FROM exams').fetchone()==(0,0)
        request('/modules/settings/users.php',{'full_name':'No token'},expected=419,csrf=False)
        request('/modules/students/add.php',{'first_name_en':'Invalid','gender':'Male','class_id':f['otherClass']},expected=422)
        request(f"/modules/results/marks_entry.php?class={f['otherClass']}",expected=404)
        request('/modules/super_admin/users.php',expected=403)
        attendance={'save_attendance':'1','class_id':f['class'],'date_ad':'2026-10-09',f"attendance[{f['student']}]":'P'}
        request(f"/modules/attendance/index.php?class={f['class']}&date=2026-10-09",attendance)
        assert db.execute('SELECT status,date_bs FROM attendances').fetchone()==('P','2083-06-23')
        request('/modules/settings/users.php',{'user_id':'','full_name':'New Staff','username':'newstaff','password':'TestPassword123!','role':'exam_officer','status':'1'})
        assert db.execute("SELECT role FROM users WHERE username='newstaff'").fetchone()[0]=='exam_officer'
        class11=db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 11'").fetchone()[0]
        electives=[r[0] for r in db.execute('SELECT subject_id FROM class_subjects WHERE school_id=1 AND class_id=? AND is_optional=1 LIMIT 3',(class11,))]
        enrollment={'first_name_en':'Higher','last_name_en':'Secondary','gender':'Female','class_id':class11,'emis_id':'OLD-EMIS-ID','emis_registration_number':'001234-2083','optional_subject_i':electives[0],'optional_subject_ii':electives[1],'optional_subject_iii':electives[2]}
        request('/modules/students/add.php',enrollment)
        assert db.execute("SELECT optional_subject_iii FROM students WHERE first_name_en='Higher'").fetchone()[0]==electives[2]
        higher=db.execute("SELECT id,emis_id,emis_registration_number FROM students WHERE first_name_en='Higher'").fetchone()
        assert higher[1:]==('OLD-EMIS-ID','001234-2083')
        body=request('/modules/students/view.php?id='+str(higher[0]));assert '001234-2083' in body
        missing=dict(enrollment);missing.pop('emis_registration_number');missing['first_name_en']='Missing Registration'
        body=request('/modules/students/add.php',missing);assert 'EMIS Registration Number is required' in body
        assert db.execute("SELECT COUNT(*) FROM students WHERE first_name_en='Missing Registration'").fetchone()[0]==0
        invalid=dict(enrollment);invalid['emis_registration_number']='X'*101
        request('/modules/students/add.php',invalid,expected=422)
        edit=dict(enrollment);edit.update(status='active',emis_registration_number='0000456')
        request('/modules/students/edit.php?id='+str(higher[0]),edit)
        assert db.execute('SELECT emis_registration_number FROM students WHERE id=?',(higher[0],)).fetchone()[0]=='0000456'
        body=request('/modules/students/index.php');assert 'EMIS Reg: 0000456' in body
        db.execute('UPDATE class_subjects SET weekly_periods=0 WHERE class_id=?',(f['class'],))
        db.execute('UPDATE class_subjects SET weekly_periods=5 WHERE class_id=? AND subject_id=?',(f['class'],f['subject']));db.commit()
        request('/modules/classes/routine.php',{'action':'generate','class_id':f['class'],'password':'TestPassword123!'})
        assert db.execute('SELECT COUNT(*) FROM class_routines WHERE class_id=?',(f['class'],)).fetchone()[0]>0
        upload_csv('/modules/teachers/import_export.php','Full Name,Username,Email,Phone,DOB\nImport Teacher,importteacher,teacher@example.test,9800000000,1990-01-01')
        assert db.execute("SELECT status FROM users WHERE username='importteacher'").fetchone()[0]==0
        upload_csv('/modules/students/import_export.php','First Name,Last Name,Gender,Class,DOB\nImported,Student,Female,Class 11,\nInvalid,Date,Male,Class 11,2026-02-30')
        assert db.execute("SELECT dob_ad FROM students WHERE first_name_en='Imported'").fetchone()==(None,)
        assert db.execute("SELECT COUNT(*) FROM students WHERE first_name_en='Invalid'").fetchone()[0]==0
        upload_csv('/modules/students/import_export.php','First Name,Last Name,Gender,Class,EMIS Registration Number\nEMIS Import,Student,Female,Class 9,00000089')
        assert db.execute("SELECT emis_registration_number FROM students WHERE first_name_en='EMIS Import'").fetchone()[0]=='00000089'
        for action in ['export','template']:
            body=request('/modules/students/import_export.php?action='+action);assert 'EMIS Registration Number' in body
            if action=='export':assert '00000089' in body
        for module in ['teachers','students']:
            request(f'/modules/{module}/import_export.php?action=export')
            request(f'/modules/{module}/import_export.php?action=template')
        # GET action URLs render confirmation and must not mutate records.
        request(f"/modules/students/index.php?delete={f['student']}")
        assert db.execute('SELECT status FROM students WHERE id=?',(f['student'],)).fetchone()[0]=='active'
        request(f"/modules/students/index.php?delete={f['student']}",{})
        assert db.execute('SELECT status FROM students WHERE id=?',(f['student'],)).fetchone()[0]=='inactive'
        assert db.execute('SELECT COUNT(*) FROM marks').fetchone()[0]==1
        # Old academic years and archived students remain available for reports.
        request('/modules/settings/years.php',{'action':'add','year_bs':'2084','year_ad':'2027/28'})
        newyear=db.execute("SELECT id FROM academic_years WHERE school_id=1 AND year_bs='2084'").fetchone()[0]
        request('/modules/settings/years.php',{'action':'set_active','id':newyear,'password':'TestPassword123!'})
        for report in ['index','ledger','print_bulk']:
            body=request('/modules/results/'+report+'.php?'+query);assert 'Test Student' in body
        # Distinct subjects may have blank codes: report rows must retain their own marks.
        db.execute("UPDATE subjects SET code=NULL WHERE id=?",(f['subject'],))
        second_subject=db.execute("INSERT INTO subjects(name_en,type,school_id) VALUES ('Distinct custom subject','compulsory',1)").lastrowid
        db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id,is_active) VALUES(1,?,?,1)',(f['class'],second_subject))
        db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES (?,?,?,75,26.25,25,10,4,0)',(f['exam'],f['class'],second_subject))
        db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES (?,?,?,37,11)',(f['exam'],f['student'],second_subject));db.commit()
        for route in [f"/modules/results/report_card.php?student={f['student']}&exam={f['exam']}&system=percentage",'/modules/results/print_bulk.php?'+query+'&system=percentage']:
            body=request(route)
            assert re.search(r'<td[^>]*>37</td>',body),'Blank-code subjects must not repeat the first subject marks'
        db.execute('UPDATE marks SET theory_obtained=NULL WHERE subject_id=?',(second_subject,));db.commit()
        for route in [f"/modules/results/report_card.php?student={f['student']}&exam={f['exam']}&system=percentage",'/modules/results/print_bulk.php?'+query+'&system=gpa']:
            body=request(route);assert 'Incomplete' in body and 'Passed (Incomplete)' not in body and 'Graded (GPA Incomplete)' not in body
        # Source-aware CDC import UI and real annual/achievement workflows.
        for grade in [1,4,9,11,12]:
            body=request('/modules/settings/cdc.php?grade='+str(grade))
            assert 'CDC 2083 curriculum' in body and 'Class 12 is illustrative' in body
        saved_schemes=db.execute('SELECT * FROM exam_subjects ORDER BY id').fetchall()
        request('/modules/settings/cdc.php',{'curriculum_key':'11:4','subject_code':'101','confirmed':'1'},expected=403)
        # School users cannot confirm or rewrite CDC catalog definitions.
        assert db.execute('SELECT * FROM exam_subjects ORDER BY id').fetchall()==saved_schemes
        mapping_count=db.execute('SELECT COUNT(*) FROM class_subjects').fetchone()[0]
        request('/modules/settings/cdc.php',{'curriculum_key':'11:4','subject_code':'101','confirmed':'1'},expected=403)
        assert db.execute('SELECT COUNT(*) FROM class_subjects').fetchone()[0]==mapping_count
        request('/modules/settings/cdc.php',{'curriculum_key':'11:4','subject_code':'101','confirmed':'1'},expected=403,csrf=False)
        for grade in [1,4]:
            c=db.execute('SELECT id FROM classes WHERE school_id=1 AND name=?',('Class '+str(grade),)).fetchone()[0]
            request('/modules/classes/subjects.php?class_id='+str(c))
            student=db.execute("INSERT INTO students(student_id,academic_year_id,class_id,first_name_en,last_name_en,gender,school_id,status) VALUES (?,1,?,'CDC','Example','Male',1,'active')",('CDC-'+str(grade),c)).lastrowid
            exam=db.execute("INSERT INTO exams(name,type,academic_year_id,school_id,has_internal,result_system) VALUES ('CDC Annual','final',1,1,0,'gpa')").lastrowid
            db.commit()
            request('/modules/exams/view.php?id='+str(exam),{'initialize_defaults':'1','init_class_id':c})
            schemes=db.execute('SELECT subject_id,assessment_method,internal_credit_hours,external_credit_hours FROM exam_subjects WHERE exam_id=?',(exam,)).fetchall()
            assert len(schemes)==(5 if grade==1 else 7)
            assert all(s[1]==('THEMATIC_CONTINUOUS' if grade==1 else 'DUAL_50_50') for s in schemes)
            q=urlencode({'exam':exam,'class':c})
            # Enter TH first, then enable IN on the real edit endpoint without rewriting TH.
            theory_values={'save_marks':'1','marks_complete':'1','exam_id':exam,'class_id':c}
            for subject,*_ in schemes:theory_values[f'marks[{student}][{subject}][theory]']=80 if grade==1 else 40
            request('/modules/results/marks_entry.php?'+q,theory_values)
            theory_before=db.execute('SELECT id,student_id,subject_id,theory_obtained FROM marks WHERE exam_id=? ORDER BY id',(exam,)).fetchall()
            theory_limits=db.execute('SELECT theory_full,theory_pass,credit_hours FROM exam_subjects WHERE exam_id=? ORDER BY id',(exam,)).fetchall()
            enable={'name':'CDC Annual','type':'final','result_system':'gpa','has_internal':'1','issue_date_mode':'auto'}
            request('/modules/exams/edit.php?id='+str(exam),enable,expected=419,csrf=False)
            assert db.execute('SELECT has_internal FROM exams WHERE id=?',(exam,)).fetchone()[0]==0
            request('/modules/exams/edit.php?id='+str(exam),enable)
            assert db.execute('SELECT has_internal FROM exams WHERE id=?',(exam,)).fetchone()[0]==1
            assert db.execute('SELECT id,student_id,subject_id,theory_obtained FROM marks WHERE exam_id=? ORDER BY id',(exam,)).fetchall()==theory_before
            assert db.execute('SELECT theory_full,theory_pass,credit_hours FROM exam_subjects WHERE exam_id=? ORDER BY id',(exam,)).fetchall()==theory_limits
            if grade==4:
                assert db.execute('SELECT DISTINCT practical_full,practical_pass FROM exam_subjects WHERE exam_id=?',(exam,)).fetchall()==[(50,20)]
                assert db.execute('SELECT DISTINCT practical_obtained,remarks FROM marks WHERE exam_id=?',(exam,)).fetchall()==[(None,'Incomplete')]
                body=request(f'/modules/results/report_card.php?student={student}&exam={exam}');assert 'Incomplete' in body
                request('/modules/exams/index.php?lock='+str(exam),{})
                body=request('/modules/exams/index.php?publish='+str(exam),{});assert 'incomplete' in body
                request('/modules/exams/index.php?reopen='+str(exam),{})
                remove=dict(enable);remove.pop('has_internal')
                body=request('/modules/exams/edit.php?id='+str(exam),remove);assert 'grading system cannot change' in body
                assert db.execute('SELECT has_internal FROM exams WHERE id=?',(exam,)).fetchone()[0]==1
            request('/modules/exams/edit.php?id='+str(exam),enable)
            assert db.execute('SELECT COUNT(*) FROM exam_subjects WHERE exam_id=?',(exam,)).fetchone()[0]==len(schemes)
            body=request('/modules/results/marks_entry.php?'+q)
            assert ('Achievement (%)' if grade==1 else 'Second trimester contribution') in body
            values={'save_marks':'1','marks_complete':'1','exam_id':exam,'class_id':c}
            for subject,*_ in schemes:
                parts={'theory':80} if grade==1 else {'theory':40,'participation':4,'project_work':36,'first_trimester':5,'second_trimester':5}
                for k,v in parts.items():values[f'marks[{student}][{subject}][{k}]']=v
            body=request('/modules/results/marks_entry.php?'+q,values);assert 'saved successfully' in body
            points=db.execute('SELECT DISTINCT grade_point FROM marks WHERE exam_id=?',(exam,)).fetchall()
            assert points==[(3.6 if grade==1 else 3.8,)],points
            for route in [f'/modules/results/report_card.php?student={student}&exam={exam}','/modules/results/print_bulk.php?'+q,'/modules/results/ledger.php?'+q]:
                body=request(route)
                # Ledger controls can offer an Incomplete filter even when all rows are complete.
                result_body=re.search(r'<tbody id="ledgerBody">.*?</tbody>',body,re.S).group(0) if '/ledger.php?' in route else body
                assert ('3.60' if grade==1 else '3.80') in result_body and 'Incomplete' not in result_body,(route,'Completed result is missing or incomplete')
            subject=schemes[0][0]
            if grade==4:
                bad=dict(values);bad[f'marks[{student}][{subject}][participation]']=5
                body=request('/modules/results/marks_entry.php?'+q,bad);assert 'Marks must be between' in body
            else:
                assert all(s[2] is None and s[3] is None for s in schemes),'Achievement split must remain unspecified'
                row=db.execute('SELECT id,credit_hours FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?',(c,subject)).fetchone()
                request('/modules/classes/subjects.php?class_id='+str(c),{'action':'edit_mapping','mapping_id':row[0],'credit_hours':row[1],'is_optional':'0'},expected=403)
                assert db.execute('SELECT th_credit_hours,in_credit_hours,th_full_marks,in_full_marks FROM class_subjects WHERE id=?',(row[0],)).fetchone()==(None,None,None,None)
                request('/modules/subjects/edit.php?id='+str(subject),expected=403)
        for path in ['/database.sqlite','/database_sqlite.sql','/school_erp_mysql.sql.gz','/migration-manifest.json','/nested/projected-manifest-private.json','/nested/migration-report-private.json','/verification-error.json','/statements.jsonl','/private-migration/package.sql','/config/migrate.php','/.git/config','/tests/http_fixture.php']:request(path,expected=404)
        login('teacher');request('/modules/settings/users.php',expected=403);request('/modules/settings/cdc.php',expected=403);request('/modules/classes/routine.php',{'action':'generate'},expected=403)
        login('admin')
        for path in ['/modules/super_admin/index.php','/modules/super_admin/schools.php','/modules/super_admin/users.php','/modules/super_admin/subjects.php','/modules/classes/index.php','/modules/learning/questions.php','/modules/settings/cdc.php']:request(path)
        request('/modules/super_admin/switch_school.php?school_id=1')
        request('/modules/settings/school.php',{'name_en':'Unauthorized change'},expected=403)
        print(f'HTTP integration passed: {count} requests; marks, reports, attendance, users, tenant isolation, CSRF, archive preservation and private-file protection.')
    finally:
        if db is not None: db.close()
        server.terminate();server.wait(timeout=10);log.flush();log.seek(0);logs=log.read();log.close()
        issues=[line for line in logs.splitlines() if re.search(r'PHP (Warning|Deprecated|Fatal|Parse)',line)]
        if issues:raise AssertionError('\n'.join(issues))
