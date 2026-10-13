"""Disposable local-only browser QA server; stop with Ctrl-C. No real records."""
import json,time
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning

with ERPHttpFixture() as app:
    f=extend_learning(app);cls,sub=f['class'],f['subject']
    gc,gs=app.db.execute('SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=0 AND c.name=(SELECT name FROM classes WHERE id=?) AND s.name_en=(SELECT name_en FROM subjects WHERE id=?) LIMIT 1',(cls,sub)).fetchone()
    app.login('admin');route='/modules/learning/curriculum.php';ch=json.loads(app.request(route,{'action':'add_chapter','class_id':gc,'subject_id':gs,'name':'Shared practice unit'}))['id'];topic=json.loads(app.request(route,{'action':'add_topic','class_id':gc,'subject_id':gs,'chapter_id':ch,'name':'Fractions and numbers'}))['id']
    q={'action':'add','class_id':gc,'subject_id':gs,'chapter':'Shared practice unit','catalog_topic_id':topic,'type':'MCQ','question_text':'<p><strong>Shared fraction</strong>: \\(\\frac{1}{2}+\\frac{1}{2}\\)</p>','question_text_np':'<p>भिन्नको योग कति हो?</p>','option_a':'<p>0</p>','option_b':'<p><strong>1</strong></p>','option_c':'<p>2</p>','option_d':'<p>3</p>','correct_option':'B','answer':'<p>The sum is 1.</p>'}
    app.request('/modules/learning/questions.php',q)
    app.login('teacher');app.request('/modules/learning/questions.php',{**q,'class_id':cls,'subject_id':sub,'question_text':'<p>Teacher private question</p>','visibility':'private'})
    app.request('/modules/learning/index.php',{'action_note':'add','class_id':cls,'subject_id':sub,'chapter':'Shared practice unit','catalog_topic_id':topic,'title':'Private teacher study note','content':'<p>Private content for the subject team.</p>'})
    app.request('/modules/learning/index.php',{'action_note':'add','class_id':cls,'subject_id':sub,'chapter':'Shared practice unit','title':'School public study note','content':'<p>Read the fraction \\(\\frac{1}{2}\\).</p><p>नेपाली पाठ</p>','visibility':'public'})
    print(json.dumps({'base':app.base,'class':cls,'subject':sub,'chapter':'Shared practice unit','users':['teacher','co_teacher','student_login','school_admin']}),flush=True)
    try:
        while True:time.sleep(1)
    except KeyboardInterrupt:pass
