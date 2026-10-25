"""Download actual CSV/XLSX samples and import structured questions in both banks."""
import csv,io,re
from urllib.request import Request
from urllib.error import HTTPError
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from question_import_context_support import context_query


def upload(app,route,content,filename,kind,mode,status=200):
    boundary='StructuredImportBoundary20261025'
    fields={'csrf_token':app.token,'bank_ui':'1','action':'import','import_kind':kind,'import_content_mode':mode,'import_visibility':'private',**app.import_context}
    parts=[f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
    request=Request(app.base+route,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:response=app.opener.open(request,timeout=30)
    except HTTPError as error:response=error
    with response:
        body=response.read().decode('utf-8');assert response.status==status,(response.status,body[:1000])
    assert not re.search(r'Fatal error|Uncaught|Warning:',body);app.count+=1
    return body


def run():
    with ERPHttpFixture() as app:
        f=extend_learning(app);cls=f['class']
        sub=app.db.execute("SELECT s.id FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND s.school_id=cs.school_id WHERE cs.class_id=? AND s.name_en='Mathematics' LIMIT 1",(cls,)).fetchone()[0]
        app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE school_id=1 AND class_id=? AND subject_id=?',(str(f['ids']['teacher']),cls,sub))
        chapter=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Structured import HTTP')",(cls,sub)).lastrowid
        topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Grouped spreadsheet rows')",(cls,sub,chapter)).lastrowid;app.db.commit();app.login('teacher')
        protected={name:app.snapshot(name) for name in ('students','marks','exams','chapters','topics','ac_payments')}
        for bank in ('exam','learning'):
            app.import_context=dict(import_class_id=cls,import_subject_id=sub,import_chapter_id=chapter)
            if bank=='learning':app.import_context['import_topic_id']=topic
            for kind in ('other','mcq'):
                route=('/exam-mcqs?' if kind=='mcq' else '/exam-questions?') if bank=='exam' else '/learning/question-bank?category='+kind
                sep='' if route.endswith('?') else '&'
                page=app.request(route+sep+'view=import');assert 'Question Group' in page and 'Subquestion Label' in page
                for mode in ('normal','html'):
                    for fmt in ('csv','xlsx'):
                        url=route+sep+f'action=download_template&bank_ui=1&import_kind={kind}&format={fmt}&content_mode={mode}'+context_query(app)
                        _,sample=app.request_bytes(url)
                        if fmt=='csv':
                            values=list(csv.reader(io.StringIO(sample.decode('utf-8-sig'))));assert 'Question Group' in values[0] and 'Subquestion Label' in values[0]
                            assert any('Statement (English)' in h for h in values[0])
                        before=len(app.snapshot('questions'));body=upload(app,route,sample,'grouped.'+fmt,kind,mode)
                        count=2 if kind=='mcq' else 4
                        assert f'Successfully imported {count}' in body and len(app.snapshot('questions'))==before+count
                        q=app.db.execute("SELECT id,marks,question_text FROM questions WHERE bank_kind=? AND question_text LIKE '%data-question-structure%' ORDER BY id DESC LIMIT 1",(bank,)).fetchone()
                        assert float(q[1])==(2.5 if kind=='mcq' else 4)
                        visible=app.request(route+sep+'record_id='+str(q[0]))
                        assert re.search(r'<div class="exam-language-ne[^>]*><p><span class="exam-part-label">\(a\)</span> ',visible), 'Label must be inside first Nepali paragraph'
                        assert '(2.5U)' in visible if kind=='mcq' else '(2KU)' in visible
                        edit=app.request(route+sep+'view=edit&question_id='+str(q[0]));assert 'question-parts-config' in edit and '&quot;stored&quot;:true' in edit
        app.login('other_admin');app.request('/exam-mcqs?view=edit&question_id='+str(q[0]),status=404)
        for name,snapshot in protected.items():assert app.snapshot(name)==snapshot,name
        print(f'Structured import HTTP passed: {app.count} requests; 16 sample roundtrips, both banks, MCQs, normal/HTML, CSV/XLSX, inline labels, editor retention and unchanged records.')


if __name__=='__main__':run()
