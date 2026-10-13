"""Timetable, curriculum selectors, catalogue persistence and request security."""
import csv,io,json,re
from urllib.request import Request
from urllib.parse import urlencode
from http_support import ERPHttpFixture

def select(body,name):
    return re.search(r'<select[^>]+name="'+name+r'"[^>]*>(.*?)</select>',body,re.S).group(0)

def import_csv(app,rows,defaults=None):
    stream=io.StringIO();csv.writer(stream).writerows(rows);boundary='RoutineLearningCsvBoundary'
    fields={'csrf_token':app.token,'action_import_excel':'1',**(defaults or {})}
    payload=''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n' for key,value in fields.items())
    payload+=f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="questions.csv"\r\nContent-Type: text/csv\r\n\r\n{stream.getvalue()}\r\n--{boundary}--\r\n'
    request=Request(app.base+'/modules/learning/questions.php',data=payload.encode(),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    with app.opener.open(request,timeout=20) as response:
        body=response.read().decode();assert response.status==200 and not re.search('Fatal error|Uncaught|Warning:',body)
    app.count+=1;return body

with ERPHttpFixture() as app:
    f=app.fixture;cls=f['class'];own=f['subject'];teacher=f['ids']['teacher'];exam=f['exam']
    app.db.execute("INSERT INTO subjects(school_id,name_en,name_np,code,type,is_hidden) VALUES(1,'Synthetic Optional','ऐच्छिक','SYN-OPT','optional',0)")
    opt=app.db.execute('SELECT last_insert_rowid()').fetchone()[0]
    app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id,teacher_id,is_optional,is_active) VALUES(1,?,?,?,1,0)',(cls,opt,str(teacher)))
    app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id,teacher_id,is_active,section_id) VALUES(1,?,?,?,1,?)',(cls,own,str(teacher),f['section']))
    app.db.execute('UPDATE schools SET routine_include_sunday=1,routine_periods_per_day=2 WHERE id=1');app.db.commit()
    app.login('school_admin')
    routine='/modules/classes/routine.php?class_id='+str(cls)
    body=app.request(routine);assert 'Saturday and Sunday are holidays' in body and 'name="include_sunday"' not in body
    data={'action':'save_requirements','class_id':cls,'password':'TestPassword123!'}
    for mid,sid in app.db.execute('SELECT cs.id,cs.subject_id FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=1 AND cs.class_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND COALESCE(s.is_hidden,0)=0',(cls,)).fetchall():
        data[f'requirements[{mid}][weekly]']='5' if sid==own else '0'
        if sid==own:data[f'requirements[{mid}][teachers][]']=[teacher]
    credits=app.db.execute('SELECT id,credit_hours FROM class_subjects WHERE class_id=? ORDER BY id',(cls,)).fetchall()
    body=app.request(routine,data);assert 'requirements saved successfully' in body
    assert app.db.execute('SELECT id,credit_hours FROM class_subjects WHERE class_id=? ORDER BY id',(cls,)).fetchall()==credits
    app.request(routine,{'action':'generate','class_id':cls,'password':'TestPassword123!'})
    assert app.db.execute('SELECT day_of_week,subject_id,teacher_id FROM class_routines WHERE class_id=? AND period_number=1 ORDER BY day_of_week',(cls,)).fetchall()==[(d,own,teacher) for d in range(1,6)]
    assert app.db.execute('SELECT COUNT(*) FROM class_routines WHERE day_of_week IN(6,7)').fetchone()[0]==0
    before=app.snapshot('class_routines')
    grid={'action':'customize','class_id':cls,'day':1,'password':'TestPassword123!', 'grid[1][subject]':own,'grid[1][teacher]':teacher,'grid[2][subject]':'','grid[2][teacher]':''}
    app.request(routine,grid);assert app.snapshot('class_routines_history')
    stable=[r[1:] for r in app.snapshot('class_routines')]
    body=app.request(routine,{**grid,'grid[1][subject]':'','grid[1][teacher]':''});assert 'First period must use' in body
    assert [r[1:] for r in app.snapshot('class_routines')]==stable
    app.request('/modules/classes/routine.php?class_id='+str(cls)+'&day=7',status=422)
    app.request(routine,grid,status=419,csrf=False)
    empty=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 8'").fetchone()[0]
    body=app.request(f'/modules/results/index.php?exam={exam}&class={empty}');assert 'id="resultsTable"' not in body and 'No students found' in body
    body=app.request(f'/modules/results/print_bulk.php?exam={exam}&class={cls}')
    assert re.search(r'<option value="BS" selected>BS only',body) and re.search(r'<option value="ymd" selected>YYYY-MM-DD',body)
    assert 'print-signature-name' in body and 'print-signature-post' in body
    questions='/modules/learning/questions.php'
    body=app.request(questions);assert 'disabled' in select(body,'subject') and 'Quick Add Class' not in body and 'quickAddSubject' not in body
    body=app.request(f'{questions}?class={cls}');assert 'Synthetic Optional' in select(body,'subject')
    mapping=json.loads(re.search(r'const classSubjectsMap = (.*?);',body).group(1));assert all(int(r['class_id'])!=f['otherClass'] for r in mapping)
    ids=[int(r['subject_id']) for r in mapping if int(r['class_id'])==cls];assert ids.count(own)==1
    body=app.request(f'/modules/learning/paper_generator.php?class_id={cls}');assert 'Synthetic Optional' in select(body,'subject_id')
    app.request(questions,{'action_quick_add_class':1,'class_name':'Forbidden'},status=422)
    app.login('teacher')
    before=app.snapshot('class_routines');app.request(routine,grid,status=403);assert app.snapshot('class_routines')==before
    body=app.request(f'{questions}?class={cls}');options=select(body,'subject');assert str(own) in options and 'Synthetic Optional' in options # Public viewing is separate from assigned-subject creation.
    other=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND subject_id<>? AND subject_id<>? LIMIT 1',(cls,own,opt)).fetchone()[0]
    app.request(f'{questions}?class={cls}&subject={other}')
    app.request('/modules/learning/paper_generator.php',{'action_save_grid':1,'class_id':cls,'subject_id':other,'grid_name':'Forged','grid_data':'{}'},status=422)
    app.request(f'/modules/learning/question_grid.php?class={cls}&subject={own}')
    app.request(f'/modules/learning/question_grid.php?class={cls}&subject={other}',status=404)
    app.request(f'/modules/learning/index.php?class_id={cls}&subject_id={other}')
    app.request(questions,{'action_quick_add_subject':1,'subject_name':'Forbidden'},status=422)
    app.request(f'{questions}?class[]={cls}',status=404)
    own_name=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(own,)).fetchone()[0]
    other_name=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(other,)).fetchone()[0]
    app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'General')",(cls,own));app.db.commit()
    import_csv(app,[['Class','Subject','Question','Marks'],['Class 9',own_name,'Synthetic authorized import',2],['Class 8',own_name,'Synthetic forbidden class',2],['Class 9',other_name,'Synthetic forbidden subject',2],['','','Synthetic missing defaults',2]])
    assert app.db.execute("SELECT question_text FROM questions WHERE question_text LIKE 'Synthetic %' ORDER BY id").fetchall()==[('Synthetic authorized import',)]
    import_csv(app,[['Class','Subject','Question','Marks'],['','','Synthetic explicit defaults',2]],{'default_class_id':cls,'default_subject_id':own})
    assert app.db.execute("SELECT class_id,subject_id FROM questions WHERE question_text='Synthetic explicit defaults'").fetchone()==(cls,own)
    app.login('admin')
    catalog='/modules/super_admin/subjects.php'
    uc,us,um=app.db.execute("SELECT cs.class_id,cs.subject_id,cs.id FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND cs.is_active=1 AND COALESCE(s.is_hidden,0)=0 LIMIT 1").fetchone()
    url=catalog+'?'+urlencode({'filter_class':uc,'show_hidden':1})
    before=app.db.execute('SELECT is_hidden FROM subjects WHERE id=?',(us,)).fetchone()
    app.request(catalog+'?toggle_hide='+str(us));assert app.db.execute('SELECT is_hidden FROM subjects WHERE id=?',(us,)).fetchone()==before
    for visible in [0,1,1]:
        app.request(url,{'action_visibility':1,'subject_id':us,'visible':visible})
        assert app.db.execute('SELECT is_hidden FROM subjects WHERE id=?',(us,)).fetchone()==(0 if visible else 1,)
    app.request('/modules/super_admin/index.php');app.request(url);assert app.db.execute('SELECT is_hidden FROM subjects WHERE id=?',(us,)).fetchone()==(0,)
    app.request(url,{'action_mapping_visibility':1,'mapping_id':um,'active':0});assert app.db.execute('SELECT is_active FROM class_subjects WHERE id=?',(um,)).fetchone()==(0,)
    app.request(url,{'action_mapping_visibility':1,'mapping_id':um,'active':1});assert app.db.execute('SELECT is_active FROM class_subjects WHERE id=?',(um,)).fetchone()==(1,)
    app.request(url,{'action_visibility':1,'subject_id':own,'visible':0});assert app.db.execute('SELECT is_hidden FROM subjects WHERE id=?',(own,)).fetchone()==(0,)
    mappings=app.snapshot('class_subjects');subjects=app.snapshot('subjects')
    app.request(url,{'action_map':1,'class_id':cls,'subject_id':us});assert app.snapshot('class_subjects')==mappings
    app.request(url,{'action_map':1,'class_id':uc,'subject_id':own});assert app.snapshot('class_subjects')==mappings
    app.request(url,{'action_subject':'add','name_en':'Invalid Universal Mapping','map_class_id':cls});assert app.snapshot('subjects')==subjects
    headers,body=app.request_bytes('/modules/auth/logout.php');assert b'Sign out?' in body and headers['X-Frame-Options']=='DENY' and headers['X-Content-Type-Options']=='nosniff'
    app.request('/modules/super_admin/index.php') # GET logout has not logged out.
    app.request('/modules/auth/logout.php',{},status=419,csrf=False)
    for path in ['/database.sqlite3','/database.sqlite-journal','/database.db-wal','/config/config.php','/tests/http_fixture.php','/backups/data.sqlite','/.git/config','/uploads/payload.phtml']:
        app.request(path,status=404)
    print(f'Routine, learning, print and security HTTP passed: {app.count} requests.')
