"""Temporary synthetic data for actual browser/editor/print-layout verification."""
import json,time
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
with ERPHttpFixture(session_name='ERP_EDITOR_PRACTICE_QA') as app:
    f=extend_learning(app);cls,sub=f['class'],f['subject']
    ch=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Sets and fractions')",(cls,sub)).lastrowid
    topic=app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Set operations')",(cls,sub,ch)).lastrowid;app.db.commit()
    app.login('teacher');base={'action':'add','class_id':cls,'subject_id':sub,'chapter':'Sets and fractions','topic_id':topic,'difficulty':'Medium','required_minutes':'2','visibility':'public','marks':'1','type':'MCQ','option_a':'0','option_b':'<b>1</b>','option_c':'2','option_d':'3','correct_option':'B','answer':'<p>The sum is one.</p>'}
    for domain in ['Knowledge','Understanding','Application','Higher Ability']:
        app.request('/modules/learning/exam_questions.php',{**base,'cognitive_domain':domain,'question_text':'<p><strong>'+domain+' fraction</strong>: \\(\\frac{1}{2}+\\frac{1}{2}\\)</p>','question_text_np':'<p>भिन्नको योग कति हो?</p>'})
    app.request('/modules/learning/exam_questions.php',{**base,'question_text':'<p>Released revision example</p>','question_text_np':'<p>विद्यार्थीका लागि प्रकाशित नमुना</p>','cognitive_domain':'Knowledge','student_visible':'1'})
    practice={**base,'question_text':'<p><span style="font-size:10px;font-weight:normal">What is the sum of \\(\\frac{1}{2}+\\frac{1}{2}\\)?</span></p>','question_text_np':'<p><span style="font-size:12px;font-weight:normal">आधा र आधाको योगफल कति हुन्छ?</span></p>','option_a':'<p><span style="font-size:10px">Zero</span></p>','option_b':'<p>One</p>','option_c':'<p>Two</p>','option_d':'<p>Three</p>','option_a_np':'<p>शून्य</p>','option_b_np':'<p>एक</p>','option_c_np':'<p>दुई</p>','option_d_np':'<p>तीन</p>','answer_np':'<p>दुई आधा मिलाउँदा एक हुन्छ।</p>'}
    app.request('/modules/learning/questions.php',practice)
    config={'full_marks':'4','duration_minutes':'15','rows':[{'name':'MCQs from selected chapters','type':'MCQ','chapter_ids':[ch],'count':'4','total_marks':'4','marks_each':'1','domains':{'K':1,'U':1,'A':1,'HA':1}}]}
    app.request('/modules/learning/exam_specification_grids.php',{'action':'save','class_id':cls,'subject_id':sub,'name':'Synthetic Exam Grid — 4 marks','config_json':json.dumps(config)});gid=app.db.execute('SELECT id FROM exam_paper_grids').fetchone()[0]
    print(json.dumps({'base':app.base,'class':cls,'subject':sub,'grid':gid,'chapter':ch,'topic':topic,'users':['teacher','co_teacher','student_login']}),flush=True)
    try:
        while True:time.sleep(1)
    except KeyboardInterrupt:pass
