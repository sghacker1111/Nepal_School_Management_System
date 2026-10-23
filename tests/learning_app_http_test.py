"""Learning app navigation uses trusted adapters and leaves stored rich content unchanged."""
import json,re,subprocess,tempfile
from pathlib import Path
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX,marked
def run():
 with ERPHttpFixture() as app:
  f=app.fixture;cls=f['class'];subject=f['subject'];pages=[]
  tables=('questions','learning_notes','chapters','topics','exam_paper_grids')
  before={table:app.snapshot(table) for table in tables}
  app.login('school_admin')
  paths=('/learning',f'/learning?class_id={cls}&subject_id={subject}','/learning/curriculum','/learning/question-bank',f'/learning/question-bank?class_id={cls}&subject_id={subject}&view=add&bank_ui=1',f'/learning/question-bank?class_id={cls}&subject_id={subject}&view=import&bank_ui=1','/exam-questions',f'/exam-questions?class_id={cls}&subject_id={subject}&view=add',f'/exam-questions?class_id={cls}&subject_id={subject}&view=import','/exam-mcqs',f'/exam-mcqs?class_id={cls}&subject_id={subject}&view=add','/exam-paper-generator',f'/exam-paper-generator?class_id={cls}&subject_id={subject}','/exam-paper-grids',f'/exam-paper-grids?class_id={cls}&subject_id={subject}','/learning/paper-generator',f'/learning/paper-generator?class_id={cls}&subject_id={subject}','/learning/specification-grids','/learning/question-grid','/learning/ocr-scanner','/learning/practice-mcqs','/learning/question-corrections')
  for path in paths:
   h,b,_=send(app,path,headers=HX);data=marked(h,b)
   assert data['adapterReady'] is True,(path,data['pageId'])
   assert not re.search(rb'<script(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|keyup|focus)=',b,re.I),path
   pages.append({'url':app.base+path,'html':b.decode(),'config':data})
  app.login('admin');h,b,_=send(app,'/admin/question-corrections',headers=HX);data=marked(h,b);assert data['adapterReady'];pages.append({'url':app.base+'/admin/question-corrections','html':b.decode(),'config':data})
  with tempfile.TemporaryDirectory(prefix='erp-learning-mounts-') as directory:
   fixture=Path(directory)/'pages.json';fixture.write_text(json.dumps(pages),encoding='utf8');subprocess.run(['node','tests/school_page_mounts.mjs',str(fixture)],cwd=app.root,check=True)
  for table,rows in before.items():assert app.snapshot(table)==rows,table
  print('Learning app HTTP passed: trusted inert fragments and unchanged question/note/catalog/grid records.')
 # Paper generation returns an explicit preview, not an unconfirmed save.
 from exam_chapter_paper_http_test import seed_chapter_papers
 with ERPHttpFixture() as app:
  f,chapters,topics,ids=seed_chapter_papers(app);chapter=chapters[0]
  before=app.snapshot('questions')
  payload={'action':'generate','generation_mode':'chapter','generation_kind':'mixed','class_id':f['class'],'subject_id':f['subject'],'exam_title':'Synthetic app paper','mixed_config_json':json.dumps({'duration_minutes':30,'rows':[{'chapter_id':chapter,'type':'MCQ','count':1,'total_marks':1},{'chapter_id':chapter,'type':'Short','count':1,'total_marks':2}]})}
  h,b,_=send(app,'/exam-paper-generator',data=payload,headers=HX);data=marked(h,b);assert data['mutation']['outcome']=='preview' and b'id="examQuestionSheet"' in b
  payload['mixed_config_json']=json.dumps({'duration_minutes':30,'rows':[]})
  h,b,_=send(app,'/exam-paper-generator',data=payload,headers=HX,status=422);assert marked(h,b)['mutation']['outcome']=='invalid' and b'id="examQuestionSheet"' not in b
  assert app.snapshot('questions')==before
if __name__=='__main__':run()
