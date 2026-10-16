"""Chapter-only exams, topic-aware learning imports, and row-based unit papers."""
import csv,io,json,re,zipfile
from urllib.parse import urlencode
from urllib.request import Request
from urllib.error import HTTPError
from http_support import ERPHttpFixture,visible_exam_text
from learning_sharing_support import extend_learning

BANK='/modules/learning/questions.php';EXAM='/modules/learning/exam_questions.php';GEN='/modules/learning/paper_generator.php'
def upload(app,path,fields,binary,extension='csv',status=200):
    boundary='TopicBankImport9417';fields={'csrf_token':app.token,'action':'import','bank_ui':'1',**fields}
    data=b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k,v in fields.items())
    data+=f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="sample.{extension}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+binary+f'\r\n--{boundary}--\r\n'.encode()
    req=Request(app.base+path,data=data,headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    try:response=app.opener.open(req,timeout=30)
    except HTTPError as error:response=error
    with response:body=response.read().decode();assert response.status==status,(response.status,body[:600])
    app.count+=1;return body

def run():
  with ERPHttpFixture() as app:
    f=extend_learning(app);cls,sub=f['class'],f['subject'];app.login('teacher')
    c1=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Combined chapter')",(cls,sub)).lastrowid
    c2=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Topic chapter')",(cls,sub)).lastrowid
    t1=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Learning topic A')",(cls,sub,c2)).lastrowid
    t2=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Learning topic B')",(cls,sub,c2)).lastrowid;app.db.commit()
    protected={t:app.snapshot(t) for t in ('students','marks','exams','chapters','topics','ac_invoices','ac_payments')}
    # No topic exists in this exam chapter; all four sample kinds must round-trip.
    scope=dict(import_class_id=cls,import_subject_id=sub,import_chapter_id=c1)
    for mode in ('normal','html'):
      for kind,route in (('mcq','/modules/learning/exam_mcqs.php'),('other',EXAM)):
        _,binary=app.request_bytes(route+'?'+urlencode(dict(action='download_template',import_kind=kind,format='csv',content_mode=mode,**scope)))
        data=list(csv.reader(io.StringIO(binary.decode('utf-8-sig'))));assert 'Topic' not in data[0]
        assert 'Successfully imported' in upload(app,route,dict(import_kind=kind,import_content_mode=mode,**scope),binary)
    assert app.db.execute("SELECT COUNT(*) FROM questions WHERE bank_kind='exam' AND (topic_id IS NOT NULL OR catalog_topic_id IS NOT NULL)").fetchone()==(0,)
    exam_edit=app.request(EXAM+'?view=add');assert 'id="examTopic"' not in exam_edit
    # New normal Question Bank authoring requires a topic and uses isolated pages.
    for view in ('add','import'):
      body=app.request(BANK+'?view='+view);assert 'id="examTopic"' in body if view=='add' else 'name="import_topic_id"' in body
      assert 'data-question-id=' not in body
    scope=dict(import_class_id=cls,import_subject_id=sub,import_chapter_id=c2,import_topic_id=t1)
    for mode in ('normal','html'):
      for kind in ('mcq','other'):
       for extension in ('csv','xlsx'):
        query=dict(action='download_template',bank_ui=1,import_kind=kind,format=extension,content_mode=mode,**scope)
        _,binary=app.request_bytes(BANK+'?'+urlencode(query))
        if extension=='csv':assert 'Topic' in list(csv.reader(io.StringIO(binary.decode('utf-8-sig'))))[0]
        else:
          with zipfile.ZipFile(io.BytesIO(binary)) as archive:assert 'Topic' in archive.read('xl/worksheets/sheet1.xml').decode()
        assert 'Successfully imported' in upload(app,BANK,dict(import_kind=kind,import_content_mode=mode,**scope),binary,extension)
    assert app.db.execute("SELECT COUNT(*) FROM questions WHERE bank_kind='learning' AND topic_id=?",(t1,)).fetchone()[0]>0
    before=app.snapshot('questions')
    _,binary=app.request_bytes(BANK+'?'+urlencode(dict(action='download_template',bank_ui=1,import_kind='other',format='csv',**scope)))
    upload(app,BANK,{**scope,'import_kind':'other','import_topic_id':t2},binary,status=422)
    upload(app,BANK,{**scope,'import_kind':'other','import_topic_id':''},binary,status=422)
    assert app.snapshot('questions')==before
    base=dict(bank_ui=1,action='add',class_id=cls,subject_id=sub,chapter='Topic chapter',type='Short',marks='2.5',question_text='<p>Statement EN</p><p>Part EN</p>',question_text_np='<p>नेपाली कथन</p><p>नेपाली भाग</p>',visibility='public',difficulty='Hard',cognitive_domain='Application')
    app.request(BANK,base,status=422);app.request(BANK,{**base,'topic_id':t2})
    qid=app.db.execute("SELECT id FROM questions WHERE question_text LIKE '%Statement EN%'").fetchone()[0]
    for lang in ('both','en','ne'):
      body=app.request(BANK+f'?record_id={qid}&language={lang}')
      content=re.search(r'<section class="exam-question-list".*?</section>',body,re.S)[0]
      assert ('Statement EN' in content)==(lang in ('both','en')) and ('नेपाली कथन' in content)==(lang in ('both','ne'))
      assert content.count('>(a)</span>')==1
    edit=app.request(BANK+f'?view=edit&question_id={qid}');assert 'id="examQuestionForm"' in edit and 'data-question-id=' not in edit
    # Legacy learning consumers render the same language layout, without changing
    # their content/role scopes or using raw hidden variants as visible output.
    for lang in ('both','en','ne'):
      hub=app.request('/modules/learning/index.php?'+urlencode(dict(class_id=cls,subject_id=sub,chapter='Topic chapter',language=lang)))
      assert 'id="hubQuestionLanguage"' in hub
      assert ('Statement EN' in hub)==(lang in ('both','en')) and ('नेपाली कथन' in hub)==(lang in ('both','ne'))
      grid=app.request('/modules/learning/question_grid.php?'+urlencode(dict(class_id=cls,**{'class':cls,'subject':sub},mcq_count=0,short_count=200,long_count=0,language=lang)))
      assert 'id="gridLanguage"' in grid and 'data-exam-print="questions"' in grid
      output=visible_exam_text(grid.split('<!-- A4 Paper Output -->',1)[1])
      assert ('Statement EN' in output)==(lang in ('both','en')) and ('नेपाली कथन' in output)==(lang in ('both','ne'))
    # Two exact topic/type allocations; known saved weight 2.5 is never rewritten.
    initial=dict(duration_minutes=30,rows=[dict(chapter_id=c2,topic_id=t2,type='Short',count=1,total_marks='2.5'),dict(chapter_id=c2,topic_id=t1,type='MCQ',count=1,total_marks='1')])
    payload=dict(action='generate_test',generation_mode='rows',class_id=cls,subject_id=sub,paper_title='Unit test',allocations_json=json.dumps(initial))
    snapshots={t:app.snapshot(t) for t in ('questions','marks','students','ac_invoices')}
    body=app.request(GEN,payload);assert 'Test paper generated successfully: 2 questions, 3.50 marks.' in body
    sheet=body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0];assert f'data-question-id="{qid}"' in sheet
    assert 'Print Test Paper' in body and 'Print Answer Key' in body and 'id="testPrintLanguage"' in body
    for lang in ('en','ne'):
      body=app.request(GEN,{**payload,'language':lang});sheet=visible_exam_text(body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0])
      assert ('Statement EN' in sheet)==(lang=='en') and ('नेपाली कथन' in sheet)==(lang=='ne')
    bad=json.loads(json.dumps(initial));bad['rows'][0]['type']='Reasoning'
    body=app.request(GEN,{**payload,'allocations_json':json.dumps(bad)},status=422);assert 'id="examQuestionSheet"' not in body
    bad['rows'][0]['topic_id']=999999;app.request(GEN,{**payload,'allocations_json':json.dumps(bad)},status=422)
    app.request(GEN,payload,csrf=False,status=419)
    app.login('other_admin');app.request(GEN,payload,status=422)
    app.login('student_login');app.request(GEN,status=403);app.request(BANK,status=403)
    for t,rows in snapshots.items():assert app.snapshot(t)==rows,t
    for t,rows in protected.items():assert app.snapshot(t)==rows,t
    print(f'Topic bank workflows HTTP passed: {app.count} requests; chapter-only exam samples, strict topic-wise normal imports, bilingual isolated forms, unit-paper rows and unchanged official data.')

if __name__=='__main__':run()
