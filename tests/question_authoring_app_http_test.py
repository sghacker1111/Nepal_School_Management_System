"""Unified question forms save bilingual TeX/MCQs through real PHP/PDO routes.

ERP_HTTP_NATIVE_MYSQL=1 selects a private, disposable native MySQL server.
No owner database or production credentials are used.
"""
import json
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from question_import_context_support import catalog_context
from routes_http_test import send
from app_response_http_test import HX, marked


def run():
    with ERPHttpFixture() as app:
        fixture=extend_learning(app)
        cls,subject=fixture['class'],fixture['subject']
        context=catalog_context(app,1,cls,subject,'Authoring QA chapter','Authoring QA topic')
        protected={name:app.snapshot(name) for name in ('students','marks','exams','ac_payments','ac_invoices','chapters','topics')}
        app.login('teacher')
        for route,bank,kind in [('/learning/question-bank','learning','Short'),('/learning/question-bank','learning','MCQ'),('/exam-questions','exam','Short'),('/exam-mcqs','exam','MCQ')]:
            marker='Authoring QA '+bank+' '+kind
            common=dict(class_id=cls,subject_id=subject,chapter='Authoring QA chapter',bank_ui=1,type=kind,marks='2.5',required_minutes='3',difficulty='Medium',cognitive_domain='Understanding',visibility='private')
            if bank=='learning': common['topic_id']=context['import_topic_id']
            payload={**common,'action':'add','question_text':'<p>'+marker+r' \(\frac{1}{2}+x^2\)</p>','question_text_np':r'<p>भिन्नको मान \(\frac{1}{2}\) निकाल्नुहोस्।</p>','answer':r'<p>Answer: \(x=2\)</p>','answer_np':'<p>उत्तर लेख्नुहोस्।</p>'}
            if kind=='MCQ':
                for option,text in [('a',r'\(\frac{1}{2}\)'),('b','Two'),('c','Three'),('d','Four')]:
                    payload['option_'+option]='<p>'+text+'</p>'
                    payload['option_'+option+'_np']='<p>विकल्प '+option+'</p>'
                payload['correct_option']='A'
            headers,body,_=send(app,route+f'?view=add&class_id={cls}&subject_id={subject}',headers=HX)
            metadata=marked(headers,body)
            assert {'mathjax','tinymce'}<=set(metadata['assets'])
            assert b'id="examQuestionForm"' in body and b'id="texModal"' in body
            headers,_,_=send(app,route+'?view=add',data=payload,headers=HX,status=204)
            assert json.loads(headers['HX-Location'])['target']=='#main-content'
            row=app.db.execute('SELECT id,question_text,question_text_np,options,options_np,correct_option FROM questions WHERE bank_kind=? AND question_text LIKE ?', (bank,'%'+marker+'%')).fetchone()
            assert row and r'\frac{1}{2}' in row[1] and 'भिन्न' in row[2]
            if kind=='MCQ':assert r'\frac{1}{2}' in json.loads(row[3])['A'] and json.loads(row[4])['B']=='<p>विकल्प b</p>' and row[5]=='A'
            count=app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]
            edit={**payload,'action':'edit','question_id':row[0],'question_text':'<p>'+marker+r' corrected \(\frac{3}{4}\)</p>'}
            send(app,route+f'?view=edit&question_id={row[0]}',data=edit,headers=HX,status=204)
            saved=app.db.execute('SELECT question_text FROM questions WHERE id=?',(row[0],)).fetchone()[0]
            assert r'\frac{3}{4}' in saved and app.db.execute('SELECT COUNT(*) FROM questions').fetchone()[0]==count
            headers,body,_=send(app,route+f'?view=edit&question_id={row[0]}',headers=HX)
            assert marked(headers,body)['adapterReady'] and b'id="examQuestionForm"' in body and b'class="exam-question-list"' not in body
            catalog=json.loads(send(app,route+f'?action=catalog&class_id={cls}&subject_id={subject}',headers={'Accept':'application/json'})[1])
            assert any(chapter['name']=='Authoring QA chapter' for chapter in catalog['chapters'])
        for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed during question authoring'
        print('Unified question authoring passed: learning/exam written and MCQ add/edit, bilingual TeX/options, app redirects, catalog loading, exact record counts and unrelated data preservation.')


if __name__=='__main__':run()
