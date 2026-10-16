"""Full-page/HTMX parity, authorization, CSRF and data preservation on temp data."""
import re
from urllib.request import Request
from urllib.error import HTTPError
from http_support import ERPHttpFixture
from exam_chapter_paper_http_test import seed_chapter_papers

def hx(app,path,status=200,target='questionBankPanel'):
    request=Request(app.base+path,headers={'HX-Request':'true','HX-Target':target})
    try:response=app.opener.open(request,timeout=20)
    except HTTPError as error:response=error
    with response:
        body=response.read().decode();headers=response.headers
        assert response.code==status,(response.code,body[:300])
    return headers,body

def run():
  with ERPHttpFixture() as app:
    f,_,_,ids=seed_chapter_papers(app)
    tables=('questions','chapters','topics','students','marks','exams','ac_invoices','ac_payments','ac_journals')
    protected={table:app.snapshot(table) for table in tables}
    path=f'/modules/learning/exam_mcqs.php?class_id={f["class"]}&subject_id={f["subject"]}&per_page=10&language=ne'
    full=app.request(path)
    assert 'assets/dist/app.css?v=' in full and 'assets/dist/workspace.js?v=' in full
    assert 'bootstrap@' not in full and 'jquery-3.6' not in full
    assert 'x-data="workspace"' in full and 'hx-history="false"' in full
    headers,partial=hx(app,path)
    assert headers['X-ERP-Fragment']=='question-list'
    assert headers.get('Cache-Control')=='no-store, private'
    assert partial.startswith('<section id="questionBankPanel"') and '<!DOCTYPE' not in partial
    assert 'top-navbar' not in partial and '<script' not in partial
    full_ids=re.findall(r'data-question-id="(\d+)"',full)
    assert re.findall(r'data-question-id="(\d+)"',partial)==full_ids
    assert 'name="csrf_token"' in partial and app.token in partial
    assert 'hx-sync="#questionBankPanel:replace"' in partial
    headers,fallback=hx(app,path,target='main-content');assert headers['X-ERP-Fragment']=='app-page' and '<!DOCTYPE' not in fallback and 'data-erp-config=' in fallback
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    # HTMX headers never weaken mutation CSRF or teacher subject restrictions.
    app.request('/modules/learning/exam_mcqs.php',{'action':'delete','question_id':ids['MCQ'][0]},csrf=False,status=419)
    app.login('unrelated_teacher');headers,denied=hx(app,path,status=403);assert 'X-ERP-Fragment' not in headers
    app.login('other_admin');headers,empty=hx(app,'/modules/learning/exam_mcqs.php');assert not re.findall(r'data-question-id="(\d+)"',empty)
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    # Build sources, package metadata and node_modules must never be public.
    for private in ('/frontend/app.css','/tools/build-assets.mjs','/node_modules/jquery/package.json','/package-lock.json'):
        app.request(private,status=404)
    for asset in ('app.css','workspace.js','vendor.js','vendor.css','icons.css','ui-components.js'):
        headers,body=app.request_bytes('/assets/dist/'+asset);assert body and headers.get('Content-Type')
    print('Dynamic workspace HTTP passed: complete/partial parity, private cache, CSRF, scope, assets, data preservation.')
if __name__=='__main__':run()
