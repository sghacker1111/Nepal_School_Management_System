"""Normal/HTML XLSX and CSV import through Question Bank, then student practice.

All records and uploads are confined to a disposable SQLite/PHP fixture.
"""
import csv, io, json, re
from urllib.request import Request
from urllib.error import HTTPError
from http_support import ERPHttpFixture

PAGE='/modules/learning/questions.php'
PRACTICE='/modules/learning/practice_mcqs.php'

def upload(app, content, filename='source.csv', mode='html'):
    boundary='QuestionSourceBoundary9815'
    fields={'csrf_token':app.token,'action_import_excel':'1','import_content_mode':mode}
    parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key,value in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
    request=Request(app.base+PAGE,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:response=app.opener.open(request,timeout=30)
    except HTTPError as error:response=error
    with response:
        body=response.read().decode();assert response.status==200,(response.status,body[:500])
    assert not re.search(r'Fatal error|Uncaught|Warning:',body),body[:1000]
    app.count+=1
    return body

def csv_bytes(rows):
    stream=io.StringIO();csv.writer(stream).writerows(rows);return stream.getvalue().encode()

def seed_source(app):
    """Shared fixture for the HTTP and browser verification."""
    app.login('admin')
    for kind in ('mcq','other'):
        _,template=app.request_bytes(PAGE+f'?action=download_template&type={kind}&format=xlsx&content_mode=html')
        before=app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]
        body=upload(app,template,'html-template.xlsx')
        assert 'Successfully imported 2 questions' in body
        assert app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]==before+2
    headers=['Class','Subject','Chapter','Topic','Question HTML','Question Nepali HTML','Option A HTML','Option B HTML','Option C HTML','Option D HTML','Correct Option','Answer HTML','Answer Nepali HTML']
    row=['Class 10','Compulsory Mathematics','Source Chapter','Source Topic',
         '<p><strong>Source fraction</strong>: \\(\\frac{1}{2}+\\frac{1}{2}\\)</p><script>alert(1)</script>',
         '<p>भिन्नको योग कति हो?</p>', '<p>\\(0\\)</p>', '<p><strong>\\(1\\)</strong></p>', '<p>\\(2\\)</p>', '<p>\\(3\\)</p>', 'B',
         '<ol><li>\\[\\frac{1+1}{2}=1\\]</li></ol><img src="javascript:bad" onerror="boom()">', '<p>उत्तर १ हो।</p>']
    assert 'Successfully imported 1 questions' in upload(app,csv_bytes([headers,row]))
    record=app.db.execute("SELECT id,question_text,question_text_np,options,answer,topic_id,class_id,subject_id FROM questions WHERE question_text LIKE '%Source fraction%'").fetchone()
    assert record and '<strong>Source fraction</strong>' in record[1] and '\\frac{1}{2}' in record[1]
    assert record[3],record
    assert record[2]=='<p>भिन्नको योग कति हो?</p>' and '<strong>' in json.loads(record[3])['B']
    assert '<ol>' in record[4] and '\\[' in record[4] and 'onerror' not in record[4] and 'javascript:' not in record[4]
    assert app.db.execute('SELECT t.name,c.name FROM topics t JOIN chapters c ON c.id=t.chapter_id WHERE t.id=?',(record[5],)).fetchone()==('Source Topic','Source Chapter')
    return record

def run():
    with ERPHttpFixture() as app:
        original={t:app.snapshot(t) for t in ('students','marks','exams','ac_invoices','ac_payments','ac_journals')}
        record=seed_source(app)
        assert 'data-import-template' in app.request(PAGE)
        assert 'practiceReviewBack' in app.request(PRACTICE)
        # Both CSV template types preserve rich questions/options/model answers.
        for kind in ('mcq','other'):
            _,template=app.request_bytes(PAGE+f'?action=download_template&type={kind}&format=csv&content_mode=html')
            assert 'Successfully imported 2 questions' in upload(app,template)
        very_short=app.db.execute("SELECT type,answer FROM questions WHERE question_text LIKE '%as a decimal%' LIMIT 1").fetchone()
        assert very_short==('Very Short','<p><strong>0.5</strong></p>')
        # Invalid questions must not create chapters/topics or invent answer keys.
        before={t:app.snapshot(t) for t in ('questions','chapters','topics')}
        headers=['Class','Subject','Chapter','Topic','Question Type','Question','Option A','Option B','Option C','Option D','Correct Option']
        for key,option in (('E','4'),('','4'),('A','<script>empty</script>')):
            body=upload(app,csv_bytes([headers,['Class 10','Mathematics','Do not create','Do not create','MCQ','Bad MCQ','1','2','3',option,key]]))
            assert 'No questions were imported' in body and 'alert-danger' in body
            assert all(app.snapshot(t)==rows for t,rows in before.items())
        assert 'Correct Option must be A' in upload(app,csv_bytes([headers,['Class 10','Mathematics','','','MCQ','Bad key','1','2','3','4','True']]))
        assert 'Choose Normal Text or HTML Source' in upload(app,csv_bytes([headers]),mode='forged')
        assert all(app.snapshot(t)==rows for t,rows in before.items())
        # Dual content columns choose the selected mode, regardless of order.
        dual=['Class','Subject','Question HTML','Question','Answer HTML','Answer']
        values=['Class 10','Mathematics','<p><strong>Dual rich</strong> \\(x^2\\)</p>','Dual plain x < 2 & y > 1\n<strong>literal</strong>','<ol><li>Rich answer</li></ol>','Normal answer']
        upload(app,csv_bytes([dual,values]),mode='normal');upload(app,csv_bytes([dual,values]))
        plain=app.db.execute("SELECT question_text,answer FROM questions WHERE question_text LIKE 'Dual plain%'").fetchone()
        assert '&lt;strong&gt;literal&lt;/strong&gt;' in plain[0] and '<br' in plain[0] and plain[1]=='Normal answer'
        assert app.db.execute("SELECT answer FROM questions WHERE question_text LIKE '%Dual rich%'").fetchone()[0]=='<ol><li>Rich answer</li></ol>'
        # Link student enrollment to the same class, not a copied access grant.
        cls,sub=app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=1 AND c.name='Class 10' AND s.name_en='Mathematics'").fetchone()
        app.db.execute('UPDATE students SET class_id=? WHERE id=?',(cls,app.fixture['student']));app.db.commit()
        app.login('school_admin')
        app.request('/modules/settings/users.php',{'full_name':'HTML Practice Student','username':'html_student','role':'student','student_record_id':app.fixture['student'],'password':'TestPassword123!','status':1})
        app.login('html_student')
        catalog=json.loads(app.request(PRACTICE+f'?action=catalog&class_id={cls}&subject_id={sub}'))['questions']
        assert record[0] in [q['id'] for q in catalog]
        state=json.loads(app.request(PRACTICE,{'action':'start','class_id':cls,'subject_id':sub,'count':1,'selection_mode':'chosen','question_ids[]':[record[0]]}))['practice']
        assert '<strong>Source fraction</strong>' in state['question']['text'] and '\\frac{1}{2}' in state['question']['text']
        assert 'alert(' not in state['question']['text'] and '<strong>' in state['question']['options']['B']['text']
        transition={'token':state['token'],'index':0,'question_id':record[0]}
        feedback=json.loads(app.request(PRACTICE,{**transition,'action':'answer','choice':'B'}))['practice']
        assert feedback['feedback']['correct'] and '<ol>' in feedback['feedback']['explanation']
        result=json.loads(app.request(PRACTICE,{**transition,'action':'next'}))['practice']
        assert result['complete'] and '<strong>Source fraction</strong>' in result['review'][0]['text']
        app.request(PRACTICE,{'action':'reset'});assert json.loads(app.request(PRACTICE+'?action=state'))['practice'] is None
        # Only the intentionally moved enrollment changed; official results/billing never did.
        for table,rows in original.items():
            if table!='students':assert app.snapshot(table)==rows,table
        print(f'HTML source HTTP passed: {app.count} requests; HTML/normal XLSX and CSV, bilingual TeX, safe content, shared curriculum, student practice and Back/reset.')

def serve_browser_fixture():
    import time
    with ERPHttpFixture() as app:
        seed_source(app)
        cls=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 10'").fetchone()[0]
        app.db.execute('UPDATE students SET class_id=? WHERE id=?',(cls,app.fixture['student']));app.db.commit()
        app.login('school_admin')
        app.request('/modules/settings/users.php',{'full_name':'HTML Practice Student','username':'html_student','role':'student','student_record_id':app.fixture['student'],'password':'TestPassword123!','status':1})
        print(json.dumps({'base':app.base,'server_pid':app.server.pid,'database':'disposable OS-temp fixture'}),flush=True)
        # Time-bounded fixture. The original ERP database is never opened here.
        for _ in range(300):time.sleep(3)

if __name__=='__main__':
    import sys
    if '--serve' in sys.argv:serve_browser_fixture()
    else:run()
