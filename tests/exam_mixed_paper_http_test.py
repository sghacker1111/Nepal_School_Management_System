import json,re
from http_support import ERPHttpFixture,visible_exam_text
from exam_chapter_paper_http_test import seed_chapter_papers,GEN
with ERPHttpFixture() as app:
    f,chapters,topics,ids=seed_chapter_papers(app);a=chapters[0];cls,sub=f['class'],f['subject']
    query=f'?class_id={cls}&subject_id={sub}&generation_kind=mixed'
    body=app.request(GEN+query);assert 'Written + MCQs' in body and 'id="examMixedEditor"' in body
    assert 'name="mixed_config_json"' in body and 'Choose topics' not in body
    config={'duration_minutes':30,'rows':[{'chapter_id':a,'type':'Long','count':1,'total_marks':3},{'chapter_id':a,'type':'MCQ','count':1,'total_marks':1},{'chapter_id':a,'type':'Short','count':1,'total_marks':2}]}
    data=dict(action='generate',class_id=cls,subject_id=sub,exam_title='Mixed paper',generation_mode='chapter',generation_kind='mixed',mixed_config_json=json.dumps(config),heading_style='Group')
    before=app.snapshot('questions');body=app.request(GEN,data); assert '3 question items, 6.00 marks' in body
    sheet=body.split('id="examQuestionSheet"')[1].split('id="examAnswersSheet"')[0]; key=body.split('id="examAnswersSheet"')[1].split('Allocation audit')[0]
    headings=['Group A — Multiple Choice Questions','Group B — Short Questions','Group C — Long Questions']
    assert [sheet.index(x) for x in headings]==sorted(sheet.index(x) for x in headings)
    assert all(x in key for x in headings)
    assert re.findall('data-exam-question-id="(\\d+)"',sheet)==re.findall('data-exam-question-id="(\\d+)"',key)
    for language in ('en','ne','both'):
        body=app.request(GEN,{**data,'language':language,'heading_style':'Section'});assert 'Section A — Multiple Choice Questions' in body
    config['rows'][0]['count']=2
    failed=app.request(GEN,{**data,'mixed_config_json':json.dumps(config)},status=422);assert 'id="examQuestionSheet"' not in failed and 'id="examMixedEditor"' in failed
    app.request(GEN,{**data,'heading_style':'<script>'},status=422);app.request(GEN,data,csrf=False,status=419)
    assert app.snapshot('questions')==before
    print(f'Mixed paper HTTP passed: {app.count} requests; separate mode, ordered matching paper/key, language choices and no partial output.')
