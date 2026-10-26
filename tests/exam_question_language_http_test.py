"""Bilingual exam-only viewing/printing; isolated SQLite, no production data."""
import json,re
from html import unescape
from urllib.parse import urlencode
from http_support import ERPHttpFixture,visible_exam_text
from learning_sharing_support import extend_learning

WRITTEN='/modules/learning/exam_questions.php'
MCQ='/modules/learning/exam_mcqs.php'
GENERATOR='/modules/learning/exam_paper_generator.php'

def seed_language_questions(app):
    f=extend_learning(app);cls,sub=f['class'],f['subject']
    ch=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Bilingual sets')",(cls,sub)).lastrowid
    topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Statement and subparts')",(cls,sub,ch)).lastrowid
    app.db.commit();app.login('teacher')
    base=dict(class_id=cls,subject_id=sub,chapter='Bilingual sets',topic_id=topic,action='add',difficulty='Medium',required_minutes='5',marks='4',cognitive_domain='Understanding',visibility='public',student_visible='1')
    en=r'<p><strong>Consider the following set.</strong></p><p>(a) Find <span class="math-tex">\(\frac{1}{2}+\frac{1}{2}\)</span>.</p><p>(b) Explain your answer.</p>'
    ne=r'<p><strong>तलको समुच्चयलाई विचार गर्नुहोस्।</strong></p><p>(a) <span class="math-tex">\(\frac{1}{2}+\frac{1}{2}\)</span> पत्ता लगाउनुहोस्।</p><p>(b) आफ्नो उत्तर व्याख्या गर्नुहोस्।</p>'
    app.request(WRITTEN,{**base,'type':'Long','question_text':en,'question_text_np':ne,'answer':'<p>Written explanation</p>','answer_np':'<p>लिखित व्याख्या</p>'})
    written=app.db.execute("SELECT MAX(id) FROM questions WHERE bank_kind='exam'").fetchone()[0]
    app.request(MCQ,{**base,'type':'MCQ','marks':'1','question_text':'<p>Choose the value.</p>','question_text_np':'<p>मान छान्नुहोस्।</p>','option_a':'<p>One</p>','option_a_np':'<p>एक</p>','option_b':'Two','option_b_np':'दुई','option_c':'Three','option_c_np':'तीन','option_d':'Four','option_d_np':'चार','correct_option':'A','answer':'<p>MCQ explanation</p>','answer_np':'<p>वस्तुगत व्याख्या</p>'})
    mcq=app.db.execute("SELECT MAX(id) FROM questions WHERE bank_kind='exam'").fetchone()[0]
    config={'full_marks':'5','duration_minutes':'20','rows':[{'name':'Multiple choice','type':'MCQ','chapter_ids':[ch],'count':1,'marks_each':'1','total_marks':'1','domains':{'U':1}},{'name':'Written question','type':'Long','chapter_ids':[ch],'count':1,'marks_each':'4','total_marks':'4'}]}
    return f,ch,topic,written,mcq,config

def card(body,qid):
    return re.search(r'<article[^>]*data-question-id="'+str(qid)+r'"[^>]*>(.*?)</article>',body,re.S)[1]

def run():
  with ERPHttpFixture() as app:
    f,ch,topic,written,mcq,config=seed_language_questions(app)
    protected={t:app.snapshot(t) for t in ('questions','chapters','topics','students','marks','exams','ac_invoices','ac_payments')}
    for route,qid,en,ne in ((WRITTEN,written,'Consider the following set.','तलको समुच्चयलाई'),(MCQ,mcq,'Choose the value.','मान छान्नुहोस्।')):
      default=app.request(route);content=card(default,qid)
      assert content.index(ne)<content.index(en)
      assert 'name="language"' in default and 'Print this page' in default and 'Model answers are not printed.' in default
      if qid==written:
        assert content.index(en)<content.index('पत्ता')<content.index('Explain your answer.')
        assert content.count('>(a)</span>')==1 and content.count('>(b)</span>')==1
        assert r'\frac{1}{2}' in content
      english=card(app.request(route+'?language=en'),qid);nepali=card(app.request(route+'?language=ne'),qid)
      english_first=card(app.request(route+'?language=both_en'),qid)
      assert english_first.index(en)<english_first.index(ne)
      assert en in english and ne not in english
      assert ne in nepali and en not in nepali
      if qid==mcq:
        assert 'One' in english and 'एक' not in english and 'एक' in nepali and 'One' not in nepali
        assert 'MCQ explanation' in english and 'वस्तुगत व्याख्या' not in english
      assert 'language=en' in app.request(route+'?language=en&per_page=20')
      for query in ('language=fr','language[]=en'):app.request(route+'?'+query,status=422)
      edit=app.request(route+f'?view=edit&question_id={qid}&language=ne')
      assert 'matching paragraphs' in edit and 'language=ne' in edit
    generate=dict(action='generate',class_id=f['class'],subject_id=f['subject'],exam_title='Bilingual synthetic exam',config_json=json.dumps(config))
    for lang in ('both','both_en','en','ne'):
      body=app.request(GENERATOR,{**generate,'language':lang})
      assert 'id="examQuestionSheet"' in body and 'id="examPrintLanguage"' in body
      assert f'data-exam-language-view="{lang}">' in body
      for other in {'both','both_en','en','ne'}-{lang}:assert f'data-exam-language-view="{other}" hidden' in body
      assert 'data-exam-print="answers"' in body and 'data-exam-print="questions"' in body
      sheet=visible_exam_text(body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0])
      assert ('Choose the value.' in sheet)==(lang in ('both','both_en','en'))
      assert ('मान छान्नुहोस्।' in sheet)==(lang in ('both','both_en','ne'))
      if lang=='both_en':assert sheet.index('Choose the value.')<sheet.index('मान छान्नुहोस्।')
      assert sheet.count('Choose the value.')<=1 and sheet.count('मान छान्नुहोस्।')<=1
    app.request(GENERATOR,{**generate,'language':'xx'},status=422)
    app.login('student_login');assert 'मान छान्नुहोस्।' in card(app.request(MCQ+'?language=ne'),mcq)
    app.request(GENERATOR,generate,status=403)
    app.login('other_student_login');assert f'data-question-id="{mcq}"' not in app.request(MCQ+'?language=en')
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    print(f'Exam language HTTP passed: {app.count} requests; paired parts, languages, options/answers, print variants, role isolation and unchanged records.')

if __name__=='__main__':run()
