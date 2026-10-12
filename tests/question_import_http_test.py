"""Question imports must save real XLSX rows with permitted class/subject aliases."""
import argparse, csv, io, re, zipfile
from pathlib import Path
from urllib.request import Request
from urllib.error import HTTPError
from xml.etree import ElementTree as ET
from http_support import ERPHttpFixture

def upload(app, content, filename='questions.csv', defaults=None, status=200):
    boundary='QuestionImportBoundary9041'
    fields={'csrf_token':app.token,'action_import_excel':'1',**(defaults or {})}
    parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key,value in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
    request=Request(app.base+'/modules/learning/questions.php',data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:response=app.opener.open(request,timeout=30)
    except HTTPError as error:response=error
    with response:
        body=response.read().decode('utf-8');assert response.status==status
    assert not re.search(r'Fatal error|Uncaught|Warning:',body),body[:500]
    app.count+=1
    return body

def csv_bytes(rows):
    stream=io.StringIO();csv.writer(stream).writerows(rows);return stream.getvalue().encode()

parser=argparse.ArgumentParser()
parser.add_argument('--workbook',type=Path,help='Optional original workbook; imported only into the disposable test database')
args=parser.parse_args()
with ERPHttpFixture() as app:
    app.login('admin')
    url='/modules/learning/questions.php'
    _,template=app.request_bytes(url+'?action=download_template&type=mcq&format=xlsx')
    with zipfile.ZipFile(io.BytesIO(template)) as archive:
        expected=len(ET.fromstring(archive.read('xl/worksheets/sheet1.xml')).findall('{*}sheetData/{*}row'))-1
    body=upload(app,template,'template.xlsx')
    assert app.db.execute('SELECT COUNT(*) FROM questions WHERE school_id=0').fetchone()[0]==expected, 'The application MCQ template must import all its questions'
    assert '&lt;strong&gt;' not in body and body.count('alert-success')==1, 'One plain-text success notice, without duplicate audit notices'
    assert app.db.execute("SELECT COUNT(*) FROM questions q JOIN subjects s ON s.id=q.subject_id WHERE q.school_id=0 AND q.type='MCQ' AND s.name_en IN('Mathematics','Science and Technology')").fetchone()[0]==expected
    headers=['Class','Subject','Question','Option A','Option B','Option C','Option D','Correct Option','Marks']
    rows=[headers,['Class 10','  COMPULSORY   MATHEMATICS ','Alias math','1','2','3','4','B',1],['Class 10','Science & Technology','Alias science','1','2','3','4','A',1],['Class 10','Nepali','Alias Nepali','1','2','3','4','A',1]]
    upload(app,csv_bytes(rows))
    assert app.db.execute("SELECT COUNT(*) FROM questions WHERE question_text LIKE 'Alias %'").fetchone()[0]==3
    before=app.snapshot('questions')
    body=upload(app,csv_bytes([headers,['Class 10','Not a mapped subject','Must skip','1','2','3','4','A',1]]))
    assert app.snapshot('questions')==before
    assert 'No questions were imported' in body and 'alert-danger' in body and 'alert-success' not in body, 'Zero imported rows are an error, never Saved successfully'
    assert not app.db.execute("SELECT id FROM activity_logs WHERE action='Bulk Imported 0 Questions from Excel'").fetchone()
    assert 'Not a mapped subject' in body and 'subject' in body
    body=upload(app,csv_bytes([headers,['Class 10','Mathematics','Mixed valid','1','2','3','4','A',1],['Class 10','Missing subject','Mixed invalid','1','2','3','4','A',1]]))
    assert 'alert-warning' in body and 'alert-success' not in body, 'Partial imports must disclose skipped rows in one warning'
    assert app.db.execute("SELECT COUNT(*) FROM questions WHERE question_text='Mixed valid'").fetchone()[0]==1
    assert not app.db.execute("SELECT id FROM questions WHERE question_text='Mixed invalid'").fetchone()
    # A refresh after a successful POST must show the list without submitting the file again.
    before=app.snapshot('questions');app.request(url);assert app.snapshot('questions')==before
    global_cls,global_math=app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name='Class 10' AND s.name_en='Mathematics'").fetchone()
    upload(app,csv_bytes([headers,['','','Blank fallback','1','2','3','4','A',1]]),defaults={'default_class_id':global_cls,'default_subject_id':global_math})
    assert app.db.execute("SELECT class_id,subject_id FROM questions WHERE question_text='Blank fallback'").fetchone()==(global_cls,global_math)
    # Alias matching must not turn optional mathematics into compulsory mathematics.
    upload(app,csv_bytes([headers,['Class 10','Optional Mathematics','Optional import','1','2','3','4','A',1]]))
    assert app.db.execute("SELECT s.name_en FROM questions q JOIN subjects s ON s.id=q.subject_id WHERE q.question_text='Optional import'").fetchone()==('Optional Mathematics',)
    # A database failure after a saved first row must roll the whole batch and its curriculum back.
    before={table:app.snapshot(table) for table in ('questions','chapters','topics','activity_logs')}
    app.db.execute("CREATE TRIGGER reject_import_fixture BEFORE INSERT ON questions WHEN NEW.question_text='Fail batch' BEGIN SELECT RAISE(ABORT,'Synthetic posting failure'); END")
    app.db.commit()
    failed_rows=[['Class','Subject','Chapter','Topic','Question'],['Class 10','Mathematics','Atomic fixture chapter','Atomic fixture topic','First batch row'],['Class 10','Mathematics','Atomic fixture chapter','Atomic fixture topic','Fail batch']]
    body=upload(app,csv_bytes(failed_rows));assert 'alert-danger' in body and 'alert-success' not in body
    assert all(app.snapshot(table)==rows for table,rows in before.items()), 'Failed batches must not leave partial questions, topics or audit success'
    app.db.execute('DROP TRIGGER reject_import_fixture');app.db.commit()
    if args.workbook:
        before_count=app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]
        original=app.snapshot('questions')
        body=upload(app,args.workbook.read_bytes(),'original-mcqs.xlsx')
        imported=app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]-before_count
        assert imported==80, f'Original 80-row workbook imported only {imported}'
        assert app.snapshot('questions')[:len(original)]==original
        saved=app.db.execute('SELECT q.type,q.correct_option,q.marks,q.question_text_np,q.options_np,s.name_en FROM questions q JOIN subjects s ON s.id=q.subject_id ORDER BY q.id DESC LIMIT 80').fetchall()
        assert all(t=='MCQ' and key in 'ABCD' and marks==1 and np and options and subject=='Mathematics' for t,key,marks,np,options,subject in saved)
        assert 'Successfully imported 80 questions' in body
        print('Original workbook: all 80 MCQs saved with bilingual options and answer keys.')
    # Class/subject permissions remain enforced for teacher imports and cross-school defaults.
    cls,math=app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=1 AND c.name='Class 10' AND s.name_en='Mathematics'").fetchone()
    app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE class_id=? AND subject_id=?',(str(app.fixture['ids']['teacher']),cls,math));app.db.commit()
    app.login('teacher')
    upload(app,csv_bytes([headers,['Class 10','Compulsory Mathematics','Teacher own import','1','2','3','4','A',1],['Class 10','Science','Teacher forbidden import','1','2','3','4','A',1]]))
    assert app.db.execute("SELECT school_id,class_id,subject_id FROM questions WHERE question_text='Teacher own import'").fetchone()==(1,cls,math)
    assert not app.db.execute("SELECT id FROM questions WHERE question_text='Teacher forbidden import'").fetchone()
    app.login('school_admin')
    before=app.snapshot('questions')
    upload(app,csv_bytes([headers,['','','Foreign fallback','1','2','3','4','A',1]]),defaults={'default_class_id':global_cls,'default_subject_id':global_math},status=422)
    assert app.snapshot('questions')==before
    print(f'Question import HTTP passed: {app.count} requests; real XLSX templates, aliases, preserved links, clear outcomes and teacher/tenant scope.')
