"""Admin-managed mathematics setup, source defaults, areas and CDC authoring."""
import json,re
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from question_parts_http_test import payload_parts
from routes_http_test import send
from app_response_http_test import HX,marked

def run():
  with ERPHttpFixture() as app:
    f=extend_learning(app);route='/exam-paper-grids';app.login('admin')
    protected={table:app.snapshot(table) for table in ('schools','students','marks','exams','ac_payments','users')}
    before={table:app.snapshot(table) for table in ('chapters','exam_paper_grids','cdc_reference')}
    page=app.request(route);assert 'Install missing mathematics chapters and default grids' in page
    assert all(app.snapshot(table)==rows for table,rows in before.items()),'GET must never install a catalog'
    app.request(route,dict(action='install_math_defaults'),csrf=False,status=419)
    app.request(route,dict(action='install_math_defaults'))
    assert len(app.db.execute("SELECT item_key FROM cdc_reference WHERE category='MATH_DEFAULT_GRID'").fetchall())==9
    saved={table:app.snapshot(table) for table in ('chapters','exam_paper_grids','cdc_reference')}
    app.request(route,dict(action='install_math_defaults'));assert all(app.snapshot(table)==rows for table,rows in saved.items())
    cls,sub=app.db.execute("SELECT c.id,s.id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id WHERE c.school_id=0 AND c.name='Class 9' AND s.name_en='Mathematics' AND s.type='compulsory'").fetchone()
    page=app.request(route+f'?class_id={cls}&subject_id={sub}')
    assert 'Class 9 First Term' in page and 'Class 9 Mid Term' in page and 'Class 9 Second Term' in page and 'Class 9 Annual' in page and 'CDC 2078' in page
    assert '50 while the footer prints 49' in page and 'K=11/U=13' in page and 'not supplied' in page
    config=json.loads(re.search(r'data-math-areas-data value="([^"]+)"',page).group(1).replace('&quot;','"').replace('&amp;','&'))
    assert len(config['areas'])==7
    fields={'action':'save_math_areas','class_id':cls,'subject_id':sub}
    for i,area in enumerate(config['areas']):fields.update({f'areas[{i}][key]':area['key'],f'areas[{i}][name]':'Set Theory' if i==0 else area['name'],f'areas[{i}][chapter_ids][]':area['chapter_ids']})
    app.request(route,fields)
    payload=app.db.execute("SELECT payload FROM cdc_reference WHERE category='MATH_AREAS' AND item_key=?",(f'{cls}:{sub}',)).fetchone()[0]
    assert json.loads(payload)[0]['name']=='Set Theory'
    app.request(route,dict(action='install_math_defaults'));assert app.db.execute("SELECT payload FROM cdc_reference WHERE category='MATH_AREAS' AND item_key=?",(f'{cls}:{sub}',)).fetchone()[0]==payload
    # A system default is immutable to school accounts; copies retain school ownership.
    app.login('school_admin');snapshot={table:app.snapshot(table) for table in ('chapters','exam_paper_grids','cdc_reference')}
    app.request(route,dict(action='install_math_defaults'),status=422);app.request(route,fields,status=422)
    assert all(app.snapshot(table)==rows for table,rows in snapshot.items())
    local_sub=app.db.execute("SELECT s.id FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=1 WHERE cs.class_id=? AND s.name_en='Mathematics' AND s.type='compulsory'",(f['class'],)).fetchone()[0]
    catalog=json.loads(app.request('/exam-questions'+f'?action=catalog&class_id={f["class"]}&subject_id={local_sub}'))
    assert any(chapter.get('area_name')=='Set Theory' for chapter in catalog['chapters'])
    assert len({chapter.get('area_key') for chapter in catalog['chapters'] if chapter.get('area_key')})==7
    app.login('admin');chapter=config['areas'][0]['chapter_ids'][0]
    topic=json.loads(app.request('/learning/curriculum',dict(action='add_topic',class_id=cls,subject_id=sub,chapter_id=chapter,name='CDC authoring fixture')))['id']
    chapter_name=app.db.execute('SELECT name FROM chapters WHERE id=?',(chapter,)).fetchone()[0]
    parts=[dict(label='a',en='<p>State the definition.</p>',np='',marks='1',level='K'),dict(label='b',en='<p>Explain the definition.</p>',np='',marks='1',level='U')]
    for bank_route,bank in (('/exam-questions','exam'),('/learning/question-bank','learning')):
      data=dict(action='add',bank_ui=1,class_id=cls,subject_id=sub,chapter=chapter_name,topic_id=topic,type='Short',required_minutes=2,difficulty='Medium',visibility='public',question_layout='structured',statement_en='<p>CDC rule fixture.</p>',mathematics_rule='cdc',**payload_parts(parts))
      app.request(bank_route+'?view=add',data)
      qid,en,np=app.db.execute('SELECT id,question_text,question_text_np FROM questions WHERE bank_kind=? ORDER BY id DESC LIMIT 1',(bank,)).fetchone()
      assert 'data-question-math="cdc"' in en and 'data-question-math="cdc"' in np
      edit=app.request(bank_route+f'?view=edit&question_id={qid}');assert re.search(r'value="cdc"\s+selected',edit)
      app.request(bank_route+'?view=add',{**data,'mathematics_rule':'strict'},status=422)
      app.request(bank_route+'?view=add',{**data,'mathematics_rule':'<script>'},status=422)
    # The real shared grid and area editor adapter must mount as an app fragment.
    headers,body,_=send(app,route+f'?class_id={cls}&subject_id={sub}',headers=HX);assert marked(headers,body)['adapterReady'] and b'data-math-areas-form' in body
    for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed'
    print(f'Mathematics HTTP passed: {app.count} requests; no GET writes, additive/idempotent setup, nine source grids, source issues, mapped school reads, admin-only changes, both-bank CDC mode and strict default.')

if __name__=='__main__':run()
