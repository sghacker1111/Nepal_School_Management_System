"""Custom headers and arbitrary section allocations through both real generators."""
import json,re
from http_support import ERPHttpFixture,visible_exam_text
from learning_sharing_support import extend_learning
from question_import_context_support import catalog_context
from routes_http_test import send
from app_response_http_test import HX

def run():
  with ERPHttpFixture() as app:
    f=extend_learning(app);cls,subject=f['class'],f['subject'];catalog=catalog_context(app,1,cls,subject,'Paper format QA','Layout topic');chapter=catalog['import_chapter_id']
    app.login('teacher')
    for route in ('/exam-questions','/learning/question-bank'):
      for i,(kind,marks) in enumerate((('MCQ',1),('Very Short',1),('Short',2),('Short',2),('Long',4))):
        target='/exam-mcqs' if route=='/exam-questions' and kind=='MCQ' else route
        data=dict(action='add',bank_ui='1',class_id=cls,subject_id=subject,chapter='Paper format QA',topic_id=catalog['import_topic_id'],type=kind,question_text=f'Layout question {i}',question_text_np=f'प्रश्न {i}',answer=f'Model answer {i}',marks=marks,required_minutes=1,difficulty='Medium',visibility='public')
        if kind=='MCQ':data.update(option_a='A',option_b='B',option_c='C',option_d='D',correct_option='B')
        app.request(target,data)
    protected={name:app.snapshot(name) for name in ('questions','schools','students','marks','exams','chapters','topics','ac_payments','ac_invoices')}
    row=lambda kind,n,marks:dict(chapter_ids=[chapter],type=kind,count=n,total_marks=marks)
    config=dict(duration_minutes=150,sections=[dict(title='Objective',rows=[row('MCQ',1,1),row('Very Short',1,1)]),dict(title='Written',rows=[row('Short',2,4),row('Long',1,4)])])
    for route,action,title_key in (('/exam-paper-generator','generate','exam_title'),('/learning/paper-generator','generate_test','paper_title')):
      form=app.request(route+f'?class_id={cls}&subject_id={subject}&generation_mode=sections')
      assert 'data-paper-sections-editor' in form and 'data-paper-heading-style' in form and 'data-paper-exam-preset' in form
      payload=dict(action=action,generation_mode='sections',class_id=cls,subject_id=subject,sections_json=json.dumps(config),paper_school_name='My School <example>',paper_instructions='Read carefully.\nAnswer all questions.',**{title_key:'First Terminal Examination'})
      for style in ('Group','Section','None'):
        body=app.request(route,{**payload,'heading_style':style,'language':'both_en'})
        assert 'id="examQuestionSheet"' in body
        question_html=body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0];answer_html=body.split('id="examAnswersSheet"')[1].split('Allocation audit')[0]
        assert 'My School &lt;example&gt;' in question_html and 'First Terminal Examination' in question_html
        assert 'Class: 9' in question_html and 'Time: 02:30 Hours' in question_html and 'Full Marks: 10' in question_html and 'Subject:' in question_html
        assert 'Read carefully.\nAnswer all questions.' in question_html and 'paper-custom-header' in question_html and 'paper-custom-instructions' in question_html
        ids=re.findall(r'data-exam-question-id="(\d+)"',question_html);key_ids=re.findall(r'data-exam-question-id="(\d+)"',answer_html);assert len(ids)==len(set(ids))==5 and ids==key_ids
        assert 'Correct option:' in answer_html and 'Marks: 1' in answer_html and 'Marks: 4' in answer_html
        visible=visible_exam_text(question_html);assert visible.index('Layout question 0')<visible.index('प्रश्न 0')
        assert re.findall(r'<strong>(\d+)\.</strong>',question_html)==['1','2','3','4','5']
        if style=='None':assert 'class="exam-section-title"' not in question_html and 'class="exam-section-title"' not in answer_html
        else:
          for fragment in (style+' - A — Objective',style+' - B — Written','(2 × 1 = 2)','(2 × 2 + 1 × 4 = 8)'):assert fragment in question_html and fragment in answer_html
      headers,body,_=send(app,route,data={**payload,'heading_style':'Section'},headers=HX)
      assert headers['X-ERP-Fragment']=='app-page' and b'data-paper-sections-editor' in body and b'id="examQuestionSheet"' in body
      for changes in (dict(heading_style='<script>'),dict(paper_school_name='x'*201),dict(paper_instructions='x'*3001)):
        app.request(route,{**payload,**changes},status=422)
      invalid=json.loads(json.dumps(config));invalid['sections'][0]['rows'][0]['chapter_ids']=[999999]
      rejected=app.request(route,{**payload,'sections_json':json.dumps(invalid)},status=422)
      assert 'id="examQuestionSheet"' not in rejected and 'data-paper-sections-data' in rejected
      invalid=json.loads(json.dumps(config));invalid['sections'][1]['rows'][0]['count']=3
      rejected=app.request(route,{**payload,'sections_json':json.dumps(invalid)},status=422);assert 'id="examQuestionSheet"' not in rejected
      app.request(route,payload,csrf=False,status=419)
      app.login('other_admin');app.request(route,payload,status=422);app.login('teacher')
    # Older automatic/manual paper generation uses the same custom header.
    ids=[row[0] for row in app.db.execute("SELECT id FROM questions WHERE bank_kind='learning' AND chapter='Paper format QA'").fetchall()]
    legacy=dict(action_generate='1',generation_mode='manual',class_id=cls,subject_id=subject,paper_title='Unit Test',paper_duration='150',paper_school_name='Legacy School',paper_instructions='Answer all questions.',heading_style='None',**{'manual_questions[]':ids})
    legacy_body=app.request('/learning/paper-generator?generation_mode=manual',legacy)
    printed=legacy_body.split('id="printArea"')[1]
    assert 'Legacy School' in printed and 'Time: 02:30 Hours' in printed and 'Full Marks: 10' in printed and 'Answer all questions.' in printed
    assert '<div class="question-group-title' not in printed
    for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed'
    print(f'Paper format HTTP passed: {app.count} requests; both banks, custom centered header, hours, instructions, all heading styles, mixed blocks, marks, bilingual paper/key parity, rejection and data preservation.')

if __name__=='__main__':run()
