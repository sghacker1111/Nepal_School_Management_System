"""Local disposable browser acceptance server. Stop via --stop-file; no real records."""
import argparse,json,time
from pathlib import Path
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

parser=argparse.ArgumentParser();parser.add_argument('--stop-file',required=True);args=parser.parse_args()
stop=Path(args.stop_file).resolve()
with ERPHttpFixture() as app:
    f=extend_learning(app);cls,sub=f['class'],f['subject']
    gc=app.db.execute('SELECT source_id FROM classes WHERE id=?',(cls,)).fetchone()[0]
    gs=app.db.execute('SELECT source_id FROM subjects WHERE id=?',(sub,)).fetchone()[0]
    app.login('admin');curriculum='/modules/learning/curriculum.php'
    chapter=json.loads(app.request(curriculum,dict(action='add_chapter',class_id=gc,subject_id=gs,name='QA Shared Sets')))['id']
    topic=json.loads(app.request(curriculum,dict(action='add_topic',class_id=gc,subject_id=gs,chapter_id=chapter,name='QA finite sets')))['id']
    common=dict(action='add',class_id=gc,subject_id=gs,chapter='QA Shared Sets',visibility='public',difficulty='Medium',required_minutes='2',cognitive_domain='Knowledge')
    for kind,marks in [('MCQ','1'),('Short','2.25'),('Long','3.50')]:
        text='<p><strong>Shared '+kind+' question</strong>: \\(\\mathbf{x} \\in A\\)</p><p>(a) Explain your answer.</p>'
        payload={**common,'type':kind,'marks':marks,'question_text':text,'question_text_np':'<p><strong>साझा प्रश्न</strong>: \\(x \\in A\\)</p><p>(a) आफ्नो उत्तर लेख्नुहोस्।</p>','answer':'<p>Original model answer.</p>','answer_np':'<p>नमुना उत्तर।</p>'}
        if kind=='MCQ':payload.update(option_a='One',option_b='Two',option_c='Three',option_d='Four',option_a_np='एक',option_b_np='दुई',option_c_np='तीन',option_d_np='चार',correct_option='A')
        app.request('/modules/learning/'+('exam_mcqs.php' if kind=='MCQ' else 'exam_questions.php'),payload)
    exam_qid=app.db.execute("SELECT id FROM questions WHERE bank_kind='exam' AND type='Short'").fetchone()[0]
    app.request('/modules/learning/questions.php',{**common,'bank_ui':'1','catalog_topic_id':topic,'type':'MCQ','marks':1,'question_text':'<p><b>Shared learning MCQ</b></p>','question_text_np':'<p><b>साझा सिकाइ प्रश्न</b></p>','option_a':'A','option_b':'B','option_c':'C','option_d':'D','correct_option':'A'})
    learning_qid=app.db.execute("SELECT id FROM questions WHERE bank_kind='learning'").fetchone()[0]
    app.login('teacher');app.request('/modules/learning/exam_questions.php',{**common,'class_id':cls,'subject_id':sub,'type':'Short','marks':'8','question_text':'<p>Teacher own control sample</p>','required_minutes':'2','visibility':'private'})
    protected={t:app.snapshot(t) for t in ('students','marks','exams','users','ac_invoices','ac_payments','ac_journals','ac_journal_lines','chapters','topics')}
    fields=[r[1] for r in app.db.execute('PRAGMA table_info(questions)')]
    allowed={exam_qid}
    before=[r for r in app.snapshot('questions') if r[fields.index('id')] not in allowed]
    print(json.dumps(dict(base=app.base,cls=cls,subject=sub,global_class=gc,global_subject=gs,chapter=chapter,exam_question=exam_qid,learning_question=learning_qid,users=['admin','teacher','school_admin','unrelated_teacher'],stop_file=str(stop))),flush=True)
    while not stop.exists():time.sleep(.25)
    for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed during QA'
    assert [r for r in app.snapshot('questions') if r[fields.index('id')] not in allowed]==before,'Unrelated question changed'
    print('Browser fixture preservation passed: only explicitly allowed synthetic correction/question records changed.',flush=True)
