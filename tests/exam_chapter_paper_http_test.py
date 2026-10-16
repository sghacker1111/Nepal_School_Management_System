"""Chapter-table generation and recoverable exam deletion on synthetic records only."""
import json,re
from http_support import ERPHttpFixture,visible_exam_text
from learning_sharing_support import extend_learning

GEN='/modules/learning/exam_paper_generator.php'
WRITTEN='/modules/learning/exam_questions.php'
MCQ='/modules/learning/exam_mcqs.php'

def seed_chapter_papers(app):
    f=extend_learning(app);cls,sub=f['class'],f['subject']
    chapters=[];topics=[];ids={'MCQ':[],'Short':[],'Long':[]}
    for chapter_name in ('Sets','Algebra'):
        chapter=app.db.execute('INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,?)',(cls,sub,chapter_name)).lastrowid
        chapters.append(chapter)
        for topic_name in ('First topic','Second topic'):
            topics.append(app.db.execute('INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,?)',(cls,sub,chapter,topic_name)).lastrowid)
    for kind,marks,topic in (('MCQ',1,topics[0]),('MCQ',1,topics[1]),('Short',2,topics[0]),('Long',3,topics[1])):
        qid=app.db.execute('INSERT INTO questions(school_id,class_id,subject_id,chapter,topic_id,type,question_text,question_text_np,options,options_np,correct_option,answer,marks,difficulty,required_minutes,cognitive_domain,created_by_user_id,visibility,bank_kind,student_visible) VALUES(1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          (cls,sub,'Sets',topic,kind,f'<p>{kind} from topic {topic}</p>','<p>समुच्चयका साझा तत्व लेख्नुहोस्।</p>',json.dumps(dict(A='One',B='Two',C='Three',D='Four')) if kind=='MCQ' else None,json.dumps(dict(A='एक',B='दुई',C='तीन',D='चार'),ensure_ascii=False) if kind=='MCQ' else None,'A' if kind=='MCQ' else None,'<p>Model answer</p>',marks,'Easy',2,'Knowledge',f['ids']['teacher'],'public','exam',1)).lastrowid
        ids[kind].append(qid)
    app.db.commit();app.login('teacher')
    return f,chapters,topics,ids

def run():
  with ERPHttpFixture() as app:
    f,chapters,topics,ids=seed_chapter_papers(app);a,b=chapters;cls,sub=f['class'],f['subject']
    protected={t:app.snapshot(t) for t in ('students','marks','exams','ac_invoices','ac_payments','chapters','topics')}
    path=GEN+f'?class_id={cls}&subject_id={sub}'
    body=app.request(path)
    assert 'id="examChapterEditor"' in body and 'Advanced grid' in body and 'name="config_json"' not in body
    counts=re.findall(r'data-chapter-count[^>]*value="([^"]+)"',body);assert len(counts)>=2 and set(counts)=={'0'}
    assert 'No. of Questions' in body and 'Marks <span' in body and 'Choose topics (optional)' not in body
    assert all(str(t) in body for t in topics) and '[object Object]' not in body
    base={'action':'generate','generation_mode':'chapter','class_id':cls,'subject_id':sub,'exam_title':'Chapter paper','duration_minutes':'30','generation_kind':'written',f'chapters[{a}][count]':'2',f'chapters[{a}][marks]':'5',f'chapters[{b}][count]':'0',f'chapters[{b}][marks]':'1000'}
    questions=app.snapshot('questions')
    body=app.request(GEN,base);assert 'Exam paper generated successfully: 2 question items, 5.00 marks.' in body
    sheet=body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0]
    assert 'Short from topic' in sheet and 'Long from topic' in sheet and 'MCQ from topic' not in sheet
    assert 'id="examAnswersSheet"' in body and 'Print Answer Key' in body
    assert app.snapshot('questions')==questions,'Generation must not rewrite questions'
    topic={**base,f'chapters[{a}][count]':'1',f'chapters[{a}][marks]':'3',f'chapters[{a}][topic_ids][]':[topics[1]]}
    body=app.request(GEN,topic);sheet=body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0]
    assert 'Long from topic' in sheet and 'Short from topic' not in sheet
    failed={**topic,f'chapters[{a}][topic_ids][]':[topics[0]]}
    body=app.request(GEN,failed);assert 'id="examQuestionSheet"' in body and 'Long from topic' in body
    assert f'name="chapters[{a}][marks]"' in body and 'value="3"' in body and 'Choose topics' not in body
    app.request(GEN,{**topic,f'chapters[{a}][topic_ids][]':[topics[2]]})
    app.request(GEN,{**base,'chapters[999999][count]':'0'},status=422)
    app.request(GEN,{**base,f'chapters[{a}][count]':'0'},status=422)
    app.request(GEN,{**base,'generation_kind':'all'},status=422)
    app.request(GEN,{**base,'duration_minutes':'1'},status=422)
    app.request(GEN,base,csrf=False,status=419)
    mcq={**base,'generation_kind':'mcq',f'chapters[{a}][marks]':'2'}
    body=app.request(GEN,mcq);sheet=body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0]
    assert visible_exam_text(sheet).count('MCQ from topic')==2 and 'Short from topic' not in sheet and 'Long from topic' not in sheet
    advanced=app.request(path+'&generation_mode=advanced');assert 'name="config_json"' in advanced and 'id="examChapterEditor"' not in advanced
    for route,kind in ((MCQ,'MCQ'),(WRITTEN,'Short')):
      qid=ids[kind][0];original=app.db.execute('SELECT * FROM questions WHERE id=?',(qid,)).fetchone()
      body=app.request(route);assert 'value="delete"' in body and '>Delete</button>' in body and 'Deleted / archived' in body
      app.request(route,{'action':'delete','question_id':qid},csrf=False,status=419)
      other=WRITTEN if route==MCQ else MCQ
      app.request(other,{'action':'delete','question_id':qid},status=422)
      app.login('co_teacher');app.request(route,{'action':'delete','question_id':qid},status=403)
      app.login('school_admin');app.request(route,{'action':'delete','question_id':qid},status=403)
      app.login('other_admin');app.request(route,{'action':'delete','question_id':qid},status=404)
      app.login('student_login');app.request(route,{'action':'delete','question_id':qid},status=403)
      app.login('teacher')
      body=app.request(route,{'action':'delete','question_id':qid});assert 'deleted successfully' in body and f'data-question-id="{qid}"' not in body
      row=app.db.execute('SELECT * FROM questions WHERE id=?',(qid,)).fetchone();assert row is not None and len(app.snapshot('questions'))==len(questions)
      assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(qid,)).fetchone()[0]
      body=app.request(route+'?archived=1');assert f'data-question-id="{qid}"' in body and '>Restore</button>' in body
      app.request(GEN,mcq if kind=='MCQ' else base,status=422)
      app.login('student_login');assert f'data-question-id="{qid}"' not in app.request(route)
      app.login('teacher');app.request(route+'?archived=1',{'action':'restore','question_id':qid})
      restored=app.db.execute('SELECT * FROM questions WHERE id=?',(qid,)).fetchone()
      # Audit timestamps may change, but content, identity, ownership, marks and release are preserved.
      fields=[r[1] for r in app.db.execute('PRAGMA table_info(questions)')]
      for index,name in enumerate(fields):
        if name not in ('updated_at','archived_at'):assert restored[index]==original[index],name
      assert app.db.execute('SELECT archived_at FROM questions WHERE id=?',(qid,)).fetchone()==(None,)
      assert f'data-question-id="{qid}"' in app.request(route)
    app.request(GEN,mcq);app.request(GEN,base)
    app.login('unrelated_teacher');app.request(GEN,base,status=404)
    app.login('student_login');app.request(path,status=403)
    app.login('teacher');app.db.execute("UPDATE schools SET packages_json='[\"question_view\"]' WHERE id=1");app.db.commit()
    app.request(MCQ,{'action':'delete','question_id':ids['MCQ'][0]},status=403);app.request(GEN,base,status=403)
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    print(f'Chapter paper/deletion HTTP passed: {app.count} requests; defaults, topic filters, exact marks/counts, no partial papers, recoverable deletes, permissions and preserved records.')

if __name__=='__main__':run()
