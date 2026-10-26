"""Recoverable bulk/single deletes through real authenticated routes and PDO."""
import re
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from question_import_context_support import catalog_context
from routes_http_test import send
from app_response_http_test import HX

def run():
  with ERPHttpFixture() as app:
    f=extend_learning(app);cls,subject=f['class'],f['subject']
    catalog=catalog_context(app,1,cls,subject,'Archive QA','Archive QA topic')
    protected={name:app.snapshot(name) for name in ('students','marks','exams','ac_payments','ac_invoices','chapters','topics')}
    app.login('teacher')
    for route,bank,kind in [('/learning/question-bank','learning','Short'),('/learning/question-bank?category=mcq','learning','MCQ'),('/exam-questions','exam','Short'),('/exam-mcqs','exam','MCQ')]:
      data=dict(action='add',bank_ui='1',class_id=cls,subject_id=subject,chapter='Archive QA',topic_id=catalog['import_topic_id'],type=kind,question_text='Archive QA '+bank+' '+kind,question_text_np='अभिलेख प्रश्न',marks='2',difficulty='Medium',required_minutes='3',visibility='public')
      if kind=='MCQ':data.update(option_a='1',option_b='2',option_c='3',option_d='4',correct_option='B')
      ids=[]
      for _ in range(3):
        app.request(route,data);ids.append(app.db.execute('SELECT MAX(id) FROM questions').fetchone()[0])
      body=app.request(route)
      assert 'data-question-bulk-form' in body and 'autocomplete="current-password"' in body
      for qid in ids:assert re.search(r'name="question_ids\[\]" value="'+str(qid)+r'" form="questionBulkArchive"',body)
      batch=dict(action='bulk_delete',bank_ui='1',**{'question_ids[]':ids[:2]})
      before=app.snapshot('questions')
      for pwd in (None,'incorrect'):
        result=app.request(route,{**batch,**({'current_password':pwd} if pwd else {})},status=422)
        assert 'password is incorrect' in result and app.snapshot('questions')==before
        assert 'value="incorrect"' not in result
      app.request(route,{**batch,'current_password':'TestPassword123!'},csrf=False,status=419)
      app.request(route,{**batch,'question_ids[]':[ids[0],999999999],'current_password':'TestPassword123!'},status=422)
      assert app.snapshot('questions')==before
      # Password-authenticated app POST behaves the same as the native form.
      headers,_,_=send(app,route,data={**batch,'current_password':'TestPassword123!'},headers=HX,status=204)
      assert 'HX-Location' in headers
      for qid in ids[:2]:assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(qid,)).fetchone()[0]
      active=app.request(route);assert all(f'data-question-id="{qid}"' not in active for qid in ids[:2])
      archived=app.request(route+('&' if '?' in route else '?')+'archived=1')
      assert all(f'data-question-id="{qid}"' in archived for qid in ids[:2]) and 'data-question-bulk-form' not in archived
      app.request(route,dict(action='restore',bank_ui='1',question_id=ids[0]))
      # Single delete has a warning and never requires a password.
      warning=app.request(route);assert 'Delete this question?' in warning and 'You can restore it' in warning
      app.request(route,dict(action='delete',bank_ui='1',question_id=ids[2]))
      assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(ids[2],)).fetchone()[0]
      app.login('co_teacher');co=app.request(route)
      assert not re.search(r'name="question_ids\[\]" value="'+str(ids[0])+r'"',co)
      app.request(route,{**batch,'question_ids[]':[ids[0]],'current_password':'TestPassword123!'},status=422)
      app.login('other_admin');app.request(route,{**batch,'question_ids[]':[ids[0]],'current_password':'TestPassword123!'},status=422)
      assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(ids[0],)).fetchone()[0] is None
      app.login('teacher')
    for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed'
    print(f'Question archive HTTP passed: {app.count} requests; both banks, written/MCQs, password/CSRF, atomic invalid batches, app responses, creator/tenant isolation, single delete and restoration.')

if __name__=='__main__':run()
