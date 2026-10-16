"""Question Bank imports require existing, explicitly selected catalog identities."""
import csv, io, re, zipfile
from urllib.request import Request
from urllib.error import HTTPError
from xml.etree import ElementTree as ET
from http_support import ERPHttpFixture
from question_import_context_support import catalog_context, context_query


def upload(app, content, filename='questions.csv', defaults=None, status=200):
    boundary='QuestionImportBoundary9041'
    fields={'csrf_token':app.token,'action_import_excel':'1',**getattr(app,'import_context',{}),**(defaults or {})}
    parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode() for key,value in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
    request=Request(app.base+'/modules/learning/questions.php',data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:response=app.opener.open(request,timeout=30)
    except HTTPError as error:response=error
    with response:
        body=response.read().decode('utf-8');assert response.status==status,(response.status,body[:1000])
    assert not re.search(r'Fatal error|Uncaught|Warning:',body),body[:500]
    app.count+=1
    return body


def csv_bytes(rows):
    stream=io.StringIO();csv.writer(stream).writerows(rows);return stream.getvalue().encode()


with ERPHttpFixture() as app:
    app.login('admin');url='/modules/learning/questions.php'
    app.import_context=catalog_context(app)
    protected={t:app.snapshot(t) for t in ('students','marks','exams','ac_invoices','ac_payments')}
    page=app.request(url+'?view=import')
    assert 'name="import_chapter_id"' in page and 'name="import_topic_id"' in page and 'Fallback Defaults' not in page
    for kind in ('mcq','other'):
      for fmt in ('csv','xlsx'):
        _,template=app.request_bytes(url+f'?action=download_template&type={kind}&format={fmt}'+context_query(app))
        if fmt=='xlsx':
            with zipfile.ZipFile(io.BytesIO(template)) as archive:
                expected=len(ET.fromstring(archive.read('xl/worksheets/sheet1.xml')).findall('{*}sheetData/{*}row'))-1
        else:expected=len(list(csv.reader(io.StringIO(template.decode('utf-8-sig')))))-1
        before=len(app.snapshot('questions'));body=upload(app,template,'template.'+fmt)
        assert len(app.snapshot('questions'))==before+expected,body[-2000:]
        assert body.count('alert-success')==1 and '&lt;strong&gt;' not in body
    headers=['Class','Subject','Chapter','Topic','Question','Option A','Option B','Option C','Option D','Correct Option','Marks']
    row=['Class 10','  COMPULSORY   MATHEMATICS ','Source Chapter','Source Topic','Alias math','1','2','3','4','B',1]
    assert 'Successfully imported 1 questions' in upload(app,csv_bytes([headers,row]))
    assert app.db.execute("SELECT COUNT(*) FROM questions WHERE question_text='Alias math'").fetchone()[0]==1
    stable={t:app.snapshot(t) for t in ('questions','chapters','topics','activity_logs')}
    for index,value in ((0,'Class 1'),(0,'Class 11'),(1,'Science'),(2,'New chapter from file'),(3,'New topic from file'),(0,''),(1,''),(2,''),(3,'')):
        bad=row.copy();bad[index]=value
        body=upload(app,csv_bytes([headers,row,bad]))
        assert 'No questions were imported' in body and 'Row 3:' in body and 'alert-danger' in body
        assert all(app.snapshot(t)==r for t,r in stable.items())
    for field in app.import_context:
        upload(app,csv_bytes([headers,row]),defaults={field:''})
        assert all(app.snapshot(t)==r for t,r in stable.items())
    body=upload(app,csv_bytes([['Question'],['Missing metadata']]),defaults={'default_class_id':app.import_context['import_class_id'],'default_subject_id':app.import_context['import_subject_id']})
    assert 'missing required class column' in body
    assert all(app.snapshot(t)==r for t,r in stable.items())
    assert not app.db.execute("SELECT id FROM chapters WHERE name='New chapter from file'").fetchone()
    assert not app.db.execute("SELECT id FROM topics WHERE name='New topic from file'").fetchone()
    app.db.execute("CREATE TRIGGER reject_import_fixture BEFORE INSERT ON questions WHEN NEW.question_text='Fail batch' BEGIN SELECT RAISE(ABORT,'Synthetic posting failure'); END");app.db.commit()
    bad=row.copy();bad[4]='Fail batch'
    body=upload(app,csv_bytes([headers,row,bad]));assert 'alert-danger' in body
    assert all(app.snapshot(t)==r for t,r in stable.items())
    app.db.execute('DROP TRIGGER reject_import_fixture');app.db.commit()
    cls,sub=app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=1 AND c.name='Class 10' AND s.name_en='Mathematics'").fetchone()
    app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE class_id=? AND subject_id=?',(str(app.fixture['ids']['teacher']),cls,sub));app.db.commit()
    shared=dict(app.import_context);app.import_context=catalog_context(app,1,cls,sub)
    app.login('teacher');row[4]='Teacher own import'
    assert 'Successfully imported 1 questions' in upload(app,csv_bytes([headers,row]))
    assert app.db.execute("SELECT school_id,class_id,subject_id FROM questions WHERE question_text='Teacher own import'").fetchone()==(1,cls,sub)
    before=app.snapshot('questions');upload(app,csv_bytes([headers,row]),defaults=shared)
    assert app.snapshot('questions')==before
    app.login('school_admin');before=app.snapshot('questions');upload(app,csv_bytes([headers,row]),defaults=shared)
    assert app.snapshot('questions')==before
    for t,r in protected.items():assert app.snapshot(t)==r,t
    print(f'Question import HTTP passed: {app.count} requests; selected catalog, matching templates, whole-file metadata rejection, no auto-creation, rollback and teacher/tenant scope.')
