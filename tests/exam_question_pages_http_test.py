"""Separate exam sections, bounded rich-content pages and isolated authoring."""
import json,re
from urllib.parse import urlencode
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

WRITTEN='/modules/learning/exam_questions.php'
MCQ='/modules/learning/exam_mcqs.php'
def cards(body):return [int(i) for i in re.findall(r'data-question-id="(\d+)"',body)]

def seed_pages(app):
    f=extend_learning(app);cls,sub=f['class'],f['subject']
    ch=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Page unit')",(cls,sub)).lastrowid
    topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Page topic')",(cls,sub,ch)).lastrowid
    ids={'MCQ':[],'Short':[]}
    for kind in ids:
      for i in range(125):
        public=i<33
        q=app.db.execute('INSERT INTO questions(school_id,class_id,subject_id,chapter,topic_id,type,question_text,question_text_np,options,options_np,correct_option,answer,answer_np,marks,difficulty,required_minutes,cognitive_domain,created_by_user_id,visibility,bank_kind,student_visible) VALUES(1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          (cls,sub,'Page unit',topic,kind,f'<p><span style="font-size:8px">{kind} item {i:03d}</span></p>','<p><span style="font-size:9px">समुच्चयका साझा तत्व लेख्नुहोस्।</span></p>',json.dumps(dict(A='One',B='Two',C='Three',D='Four')) if kind=='MCQ' else None,json.dumps(dict(A='एक',B='दुई',C='तीन',D='चार'),ensure_ascii=False) if kind=='MCQ' else None,'A' if kind=='MCQ' else None,'<p>Model answer</p>','<p>नमुना उत्तर</p>',1,'Easy',2,'Knowledge',f['ids']['teacher'],'public' if public else 'private','exam',1 if public else 0)).lastrowid
        ids[kind].append(q)
    app.db.commit();app.login('teacher')
    return f,ch,topic,ids

def run():
  with ERPHttpFixture() as app:
    f,ch,topic,ids=seed_pages(app)
    protected={t:app.snapshot(t) for t in ('students','marks','exams','ac_invoices','ac_payments','chapters','topics')}
    for route,kind,other in ((WRITTEN,'Short','MCQ'),(MCQ,'MCQ','Short')):
      expected=list(reversed(ids[kind]));body=app.request(route)
      assert cards(body)==expected[:10] and f'{other} item' not in body
      assert 'view=add' in body and 'view=import' in body and 'exam_mcqs.php' in body
      assert 'id="examQuestionForm"' not in body and 'id="examImportPanel"' not in body
      for size in (10,20,30,50,100):
        body=app.request(route+f'?per_page={size}&page=2')
        assert cards(body)==expected[size:size*2],(route,size,cards(body))
        assert f'per_page={size}' in body and 'rel="prev"' in body
      assert cards(app.request(route+'?page=9999999'))==expected[120:]
      filtered=app.request(route+'?'+urlencode(dict(class_id=f['class'],subject_id=f['subject'],chapter='Page unit',filter_topic=topic,search=f'{kind} item 0',per_page=20,page=2)))
      matched=[q for i,q in reversed(list(enumerate(ids[kind]))) if i<100]
      assert cards(filtered)==matched[20:40]
      assert 'search='+kind.replace(' ','+')+'+item+0' in filtered
      for query in ('page=0','page=-1','page[]=1','per_page=11','per_page[]=10','page=99999999'):
        app.request(route+'?'+query,status=422)
      qid=expected[0]
      edit=app.request(route+f'?view=edit&question_id={qid}&per_page=20&page=2')
      assert 'id="examQuestionEditor"' in edit and 'id="examQuestionForm"' in edit
      assert not cards(edit) and 'id="examQuestionFilters"' not in edit and 'id="examImportPanel"' not in edit
      assert f'{kind} item 124' in edit and f'{kind} item 123' not in edit and f'{other} item' not in edit
      add=app.request(route+'?view=add');assert 'id="examQuestionForm"' in add and not cards(add)
      imports=app.request(route+'?view=import');assert ('id="examImportForm-mcq"' in imports)==(kind=='MCQ')
      assert ('id="examImportForm-other"' in imports)==(kind=='Short') and not cards(imports) and 'id="examQuestionForm"' not in imports
      if kind=='Short':assert 'name="option_a"' not in add and '<option >MCQ</option>' not in add
      else:assert 'name="option_a"' in add and '<option >Short</option>' not in add
      app.request(route+f'?view=edit&question_id={ids[other][0]}',status=404)
    before=app.snapshot('questions')
    payload=dict(action='edit',question_id=ids['MCQ'][0],class_id=f['class'],subject_id=f['subject'],chapter='Page unit',topic_id=topic,type='MCQ',question_text='Edited sole MCQ',marks='2',difficulty='Medium',required_minutes='3',cognitive_domain='Understanding',option_a='1',option_b='2',option_c='3',option_d='4',correct_option='B',visibility='public',student_visible='1')
    app.request(MCQ+f'?view=edit&question_id={ids["MCQ"][0]}',payload)
    assert app.db.execute('SELECT question_text,type,marks FROM questions WHERE id=?',(ids['MCQ'][0],)).fetchone()==('Edited sole MCQ','MCQ',2)
    assert len(app.snapshot('questions'))==len(before)
    stable=app.snapshot('questions')
    app.request(WRITTEN,payload,status=422);app.request(MCQ,{**payload,'type':'Short'},status=422)
    written={**payload,'action':'add','question_id':'','type':'Short'};app.request(WRITTEN,written,status=422)
    assert app.snapshot('questions')==stable
    app.request(MCQ,{**payload,'school_id':f['otherSchool']},status=403)
    app.request(MCQ,payload,csrf=False,status=419)
    app.login('co_teacher');app.request(MCQ,payload,status=403)
    app.request(MCQ+f'?view=edit&question_id={ids["MCQ"][0]}',status=404)
    app.login('unrelated_teacher')
    assert not cards(app.request(MCQ+'?per_page=100')),'All exam questions require an exact teaching assignment'
    app.login('student_login')
    for route,kind in ((MCQ,'MCQ'),(WRITTEN,'Short')):
      body=app.request(route+'?per_page=20&page=2');assert cards(body)==list(reversed(ids[kind][:33]))[20:]
      assert 'view=add' not in body and 'view=import' not in body
      for view in ('add','import','edit'):app.request(route+'?view='+view,status=403)
      app.request(route,payload,status=403)
    app.login('other_student_login');assert not cards(app.request(MCQ)) and not cards(app.request(WRITTEN))
    app.login('teacher');app.db.execute("UPDATE schools SET packages_json='[\"question_view\"]' WHERE id=1");app.db.commit()
    assert cards(app.request(MCQ))
    app.request(MCQ+'?view=add',status=403);app.request(MCQ,payload,status=403)
    for t,r in protected.items():assert app.snapshot(t)==r,t
    print(f'Exam question pages HTTP passed: {app.count} requests; separate lists/forms/imports, 5 page sizes, exact filters, edit-only view, preserved records and role/package isolation.')

if __name__=='__main__':run()
