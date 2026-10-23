"""Complete exam papers and grids, never production examination mutations."""
import json,re
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from app_response_http_test import config as app_config
with ERPHttpFixture() as app:
    f=extend_learning(app);cls,sub=f['class'],f['subject'];questions='/modules/learning/exam_mcqs.php';grids='/modules/learning/exam_specification_grids.php';generator='/modules/learning/exam_paper_generator.php'
    chapter=app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Printed exam unit')",(cls,sub)).lastrowid;app.db.commit()
    app.login('teacher');payload={'action':'add','class_id':cls,'subject_id':sub,'chapter':'Printed exam unit','type':'MCQ','question_text':'<b>New exam MCQ</b> \\(1+1\\)','option_a':'1','option_b':'<b>2</b>','option_c':'3','option_d':'4','correct_option':'B','marks':'1','difficulty':'Easy','required_minutes':'2','cognitive_domain':'Knowledge'}
    app.request(questions,payload);qid=app.db.execute("SELECT id FROM questions WHERE bank_kind='exam'").fetchone()[0]
    config={'full_marks':'1','duration_minutes':'10','rows':[{'name':'MCQ across chapters','type':'MCQ','chapter_ids':[chapter],'count':1,'marks_each':'1','total_marks':'1','domains':{'K':1}}]}
    body=app.request(grids+'?class_id='+str(cls)+'&subject_id='+str(sub));assert 'Exam Paper Grids' in body and 'Normal paper grids are separate' in body
    saved={'action':'save','class_id':cls,'subject_id':sub,'name':'Exact exam grid','config_json':json.dumps(config)}
    app.request(grids,saved);gid,revision=app.db.execute('SELECT id,revision FROM exam_paper_grids').fetchone();assert revision==1
    assert app.db.execute('SELECT COUNT(*) FROM specification_grids').fetchone()==(0,), 'Exam grids must not mix with normal grids'
    body=app.request(generator+'?grid_id='+str(gid));assert 'Exact exam grid' in body
    generate={'action':'generate','class_id':cls,'subject_id':sub,'exam_title':'Synthetic final exam','config_json':json.dumps(config)}
    records={t:app.snapshot(t) for t in ['marks','students','exams','questions']}
    body=app.request(generator,generate);assert 'Exam paper generated successfully' in body and 'id="examQuestionSheet"' in body and 'id="examAnswersSheet"' in body
    assert '<b>New exam MCQ</b>' in body and '<b>2</b>' in body and 'Print Exam Paper' in body and 'Print Answer Key' in body
    assert records=={t:app.snapshot(t) for t in records},'Generation must not rewrite marks or bank values'
    app.request(generator+'?grid_id='+str(gid),{**generate,'class_id':0},status=404)
    failed={**config,'full_marks':2}
    body=app.request(grids+'?edit_grid_id='+str(gid),{**saved,'grid_id':gid,'revision':revision,'name':'Preserve attempted edit','config_json':json.dumps(failed)},status=422)
    editor_data=json.loads(app_config(body.encode())['values']['shared-exam-grid-editor-value-0'])
    assert 'value="Preserve attempted edit"' in body and editor_data['initial']==failed,'Failed edits retain their submitted name and grid, not the old GET defaults'
    app.request(generator,generate,csrf=False,status=419)
    bad={**config,'rows':[{**config['rows'][0],'count':2,'total_marks':2,'domains':{'K':2}}],'full_marks':2}
    body=app.request(generator,{**generate,'config_json':json.dumps(bad)},status=422);assert 'needs 2' in body and 'id="examQuestionSheet"' not in body
    app.login('co_teacher');app.request(grids,{**saved,'grid_id':gid,'revision':revision},status=403)
    app.login('school_admin');app.request(grids,{**saved,'grid_id':gid,'revision':revision},status=403)
    app.login('teacher');app.request(grids,{**saved,'grid_id':gid,'revision':revision});app.request(grids,{**saved,'grid_id':gid,'revision':revision},status=422)
    app.request(grids,{'action':'archive','grid_id':gid,'class_id':cls,'subject_id':sub});app.request(generator+'?grid_id='+str(gid),status=404)
    app.request(grids,{'action':'restore','grid_id':gid,'class_id':cls,'subject_id':sub});assert app.db.execute('SELECT id FROM exam_paper_grids').fetchone()==(gid,)
    app.login('other_admin');app.request(generator+'?grid_id='+str(gid),status=404)
    app.login('student_login');app.request(generator,status=403);app.request(grids,status=403)
    app.db.execute("UPDATE schools SET packages_json='[\"learning_system\"]' WHERE id=1");app.db.commit()
    app.login('teacher');app.request(generator,status=403);app.request(grids,status=403);app.request(questions)
    print(f'Exam paper HTTP passed: {app.count} requests; independent grids, rich questions, complete print/key, quotas, preservation, roles, revisions, archive and packages.')
