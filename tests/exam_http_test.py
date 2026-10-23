"""Exam/date/symbol/printing workflows over real HTTP, using disposable data only."""
import os, tempfile, subprocess, socket, time, re, json, sqlite3
from pathlib import Path
from urllib.request import build_opener, HTTPCookieProcessor, Request
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from http.cookiejar import CookieJar

root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='erp-exam-http-') as tmp:
    env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(Path(tmp)/'test.sqlite')}
    f=json.loads(subprocess.run(['php','tests/http_fixture.php'],cwd=root,env=env,capture_output=True,text=True,check=True).stdout)
    with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
    log=open(Path(tmp)/'server.log','w+',encoding='utf-8')
    server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','router.php'],cwd=root,env=env,stdout=log,stderr=log)
    base=f'http://127.0.0.1:{port}'; opener=build_opener(HTTPCookieProcessor(CookieJar()));token='';count=0
    db=sqlite3.connect(env['ERP_DB_PATH'])
    def request(path,data=None,expected=200,csrf=True):
        global count
        if data is not None:
            data=dict(data)
            if csrf:data['csrf_token']=token
        req=Request(base+path,data=urlencode(data).encode() if data is not None else None)
        try:
            with opener.open(req,timeout=20) as response:status=response.status;body=response.read().decode()
        except HTTPError as e:status=e.code;body=e.read().decode()
        assert status==expected,(path,status,body[:800])
        assert not re.search(r'Fatal error|Warning:|Deprecated:|Parse error|Uncaught',body),(path,body[:900])
        count+=1
        return body
    def login(user):
        global opener,token
        opener=build_opener(HTTPCookieProcessor(CookieJar()));body=request('/modules/auth/login.php')
        token=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1)
        body=request('/modules/auth/login.php',{'username':user,'password':'TestPassword123!'})
        token=re.search(r'name="csrf-token" content="([^"]+)"',body).group(1)
    def get(path,values):return path+'?'+urlencode(values,doseq=True)
    try:
        for _ in range(50):
            try:opener.open(base+'/modules/auth/login.php',timeout=1);break
            except (URLError,ConnectionError):time.sleep(.1)
        login('school_admin')
        cid=f['class'];sid=f['student'];sub=f['subject']
        db.execute('UPDATE students SET roll_number=88,exam_roll_number=? WHERE id=?',('GLOBAL-LEGACY',sid))
        ids=[]
        for i in range(19):
            ids.append(db.execute("INSERT INTO students(student_id,academic_year_id,class_id,first_name_en,last_name_en,gender,school_id,status,roll_number) VALUES (?,1,?,?,'Example','Male',1,'active',?)",('SELECT-'+str(i),cid,'Alpha '+str(i).zfill(2),100+i)).lastrowid)
        previous=[]
        for name,date,mark in [('First Terminal','2026-07-01',75),('Second Terminal','2026-08-01',65)]:
            eid=db.execute("INSERT INTO exams(name,type,academic_year_id,school_id,has_internal,result_system,start_date) VALUES (?,'terminal',1,1,0,'gpa',?)",(name,date)).lastrowid
            db.execute('INSERT INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES (?,?,?,100,35,0,0,4,0)',(eid,cid,sub))
            db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES (?,?,?,?,0)',(eid,sid,sub,mark));previous.append(eid)
        optional=db.execute('SELECT subject_id FROM class_subjects WHERE school_id=1 AND class_id=? AND is_optional=1 LIMIT 1',(cid,)).fetchone()[0]
        db.execute('UPDATE class_subjects SET is_active=0 WHERE class_id=? AND subject_id=?',(cid,optional));db.commit()
        data={'name':'Third Terminal BS','type':'terminal','has_internal':'1','result_system':'gpa','exam_date_calendar':'BS','start_date_bs':'2083-06-24','end_date_bs':'2083-06-25','issue_date_mode':'manual','issue_calendar':'BS','issue_date':'2083-06-24','display_calendar':'both','date_format':'ymd'}
        body=request('/modules/exams/add.php',data); assert 'created successfully' in body
        target=db.execute("SELECT id FROM exams WHERE name='Third Terminal BS'").fetchone()[0]
        assert db.execute('SELECT start_date,end_date,issue_date,date_calendar,date_format FROM exams WHERE id=?',(target,)).fetchone()==('2026-10-10','2026-10-11','2026-10-10','both','ymd')
        body=request('/modules/exams/view.php?id='+str(target),{'initialize_defaults':'1','init_class_id':cid});assert 'Initialized' in body
        assert db.execute('SELECT COUNT(*) FROM exam_subjects WHERE exam_id=? AND class_id=? AND subject_id=?',(target,cid,optional)).fetchone()[0]==1,'Visible inactive elective must initialize exactly once in the selected class'
        assert db.execute('SELECT is_active FROM class_subjects WHERE class_id=? AND subject_id=?',(cid,optional)).fetchone()[0]==0,'Mapping must not certify/activate an offering'
        symbols=f'/modules/exams/symbols.php?exam_id={target}&class_id={cid}'
        request(symbols,{'symbol_mode':'alphabetical','first_symbol':'3001'},expected=419,csrf=False)
        body=request(symbols,{'symbol_mode':'alphabetical','first_symbol':'3001'});assert 'saved' in body.lower()
        assert db.execute('SELECT symbol_no FROM exam_symbols WHERE exam_id=? AND student_id=?',(target,ids[0])).fetchone()[0]=='3001'
        assert db.execute('SELECT symbol_no FROM exam_symbols WHERE exam_id=? AND student_id=?',(target,sid)).fetchone()[0]=='3020'
        assert db.execute('SELECT roll_number,exam_roll_number FROM students WHERE id=?',(sid,)).fetchone()==(88,'GLOBAL-LEGACY')
        body=request(symbols,{'symbol_mode':'manual',f'symbols[{sid}]':'3001','confirm_overwrite':'1'});assert 'Duplicate symbol' in body
        admit='/modules/exams/admit_cards.php';args={'exam_id':target,'class_id':cid}
        body=request(get(admit,args));assert 'class="admit-check"' not in body and 'First select class' in body
        args['student_mode']='choose'
        body=request(get(admit,args));assert body.count('class="admit-check"')==10 and 'Page 1 / 2' in body
        args['selected[]']=[ids[0],ids[18]];args['page']=2
        body=request(get(admit,args));assert body.count('class="admit-check"')==10 and 'id="selection_count">2</span>' in body
        body=request(get(admit,{**args,'search':'3020'}));assert body.count('class="admit-check"')==1 and 'Test Student' in body
        body=request(get(admit,{**args,'render':1}));assert body.count('class="admit-card"')==2 and 'GLOBAL-LEGACY' not in body and 'Symbol No.' in body
        assert '2083-06-24 BS (2026-10-10 AD)' in body and 'flex-direction: row;' in body
        body=request(get(admit,{'exam_id':target,'class_id':cid,'student_mode':'choose','render':1}));assert 'Choose at least one' in body and 'class="admit-card"' not in body
        body=request(get(admit,{'exam_id':target,'class_id':cid,'student_mode':'all','render':1}));assert body.count('class="admit-card"')==20 and body.count('class="admit-card-page"')==5
        body=request(get('/modules/students/admit_card.php',{'id':sid,'exam_id':target}));assert body.count('class="admit-card"')==1 and '3020' in body
        request(get(admit,{**args,'selected[]':[999999],'render':1}),expected=422)
        q={'exam':target,'class':cid}
        future=db.execute("INSERT INTO exams(name,type,academic_year_id,school_id,start_date,has_internal) VALUES ('Future should not appear','terminal',1,1,'2026-11-15',1)").lastrowid;db.commit()
        body=request(get('/modules/results/marks_entry.php',q));assert 'Future should not appear' not in re.search(r'<select name="prev_exams\[\]".*?</select>',body,re.S).group(0)
        assert body.lower().index('nepali')<body.lower().index('english')
        count_before=db.execute('SELECT COUNT(*) FROM marks WHERE exam_id=?',(target,)).fetchone()[0]
        body=request(get('/modules/results/marks_entry.php',{**q,'prev_exams[]':previous,'load_previous':1}))
        field=re.search(r'<input[^>]+name="marks\['+str(sid)+r'\]\['+str(sub)+r'\]\[previous_exam\]"[^>]*>',body).group(0)
        assert 'value="4.2"' in field,field
        assert db.execute('SELECT COUNT(*) FROM marks WHERE exam_id=?',(target,)).fetchone()[0]==count_before,'Loading is read only'
        body=request(get('/modules/results/marks_entry.php',{**q,'prev_exams[]':[future],'load_previous':1}));assert 'must be earlier exams' in body
        values={'save_marks':1,'marks_complete':'1','exam_id':target,'class_id':cid}
        for k,v in {'theory':60,'participation':3,'project_work':12,'previous_exam':4.2}.items():values[f'marks[{sid}][{sub}][{k}]']=v
        request(get('/modules/results/marks_entry.php',q),values)
        mapping=db.execute('SELECT id FROM exam_subjects WHERE exam_id=? AND class_id=? AND subject_id=?',(target,cid,sub)).fetchone()[0]
        edit=f'/modules/exams/view.php?id={target}&edit_mapping_id={mapping}'
        body=request(edit);revision=re.search(r'name="mapping_revision" value="([^"]+)"',body).group(1)
        assert 'confirm_grade_change' in body and 'saved grades' in body
        config={'map_subject':1,'edit_mapping_id':mapping,'class_id':cid,'subject_id':sub,'theory_full':80,'theory_pass':26.25,'practical_full':25,'practical_pass':10,'credit_hours':4,'internal_credit_hours':1,'external_credit_hours':3}
        body=request(edit,config);assert 'Confirm the warning' in body
        body=request(edit,{**config,'confirm_grade_change':1,'mapping_revision':revision});assert 'recalculated successfully' in body
        assert db.execute('SELECT theory_obtained,practical_obtained FROM marks WHERE exam_id=? AND student_id=? AND subject_id=?',(target,sid,sub)).fetchone()==(60,19.2)
        assert db.execute('SELECT COUNT(*) FROM exam_scheme_history WHERE exam_id=?',(target,)).fetchone()[0]==1
        body=request(edit,{**config,'confirm_grade_change':1,'mapping_revision':revision});assert 'changed since you opened' in body
        body=request(f'/modules/results/report_card.php?student={sid}&exam={target}');assert '3020' in body and 'GLOBAL-LEGACY' not in body and 'data-issue-date="2026-10-10">2083-06-24 BS' in body
        body=request(get('/modules/attendance/index.php',{'class':cid,'date':'2083-06-24','calendar_fields[date]':'BS'}));assert '2083-06-24 BS (2026-10-10 AD)' in body
        login('teacher');request(symbols,{'symbol_mode':'alphabetical','first_symbol':'8001'},expected=403)
        body=request(symbols);assert 'read-only' in body and '/assets/js/notifications.js' in body
        login('exam_officer');body=request(symbols);assert '<fieldset disabled' not in body
        assert db.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
        print(f'Exam HTTP passed: {count} requests; BS dates, optional mappings, symbols, 10-row search, multi-print, IN preview, confirmations and roles.')
    finally:
        db.close();server.terminate();server.wait(timeout=10);log.flush();log.seek(0);logs=log.read();log.close()
        issues=[line for line in logs.splitlines() if re.search(r'PHP (Warning|Deprecated|Fatal|Parse)',line)]
        if issues:raise AssertionError('\n'.join(issues))
