"""School/admin fragments expose inert data and static adapters without changing records."""
import re,json,tempfile,subprocess
from pathlib import Path
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX,marked
def run():
  with ERPHttpFixture() as app:
    app.login('school_admin');f=app.fixture
    tables=('students','users','classes','subjects','class_subjects','exams','marks','ac_invoices','ac_payments')
    snapshots={table:app.snapshot(table) for table in tables}
    pages=[]
    school_paths=('/','/students','/students/add',f'/students/edit?id={f["student"]}',f'/students/view?id={f["student"]}','/students/import-export','/teachers','/teachers/add',f'/teachers/edit?id={f["ids"]["teacher"]}','/teachers/import-export','/classes','/classes/add',f'/classes/edit?class_id={f["class"]}',f'/classes/subjects?class_id={f["class"]}','/classes/routine','/subjects','/exams','/exams/add',f'/exams/edit?id={f["exam"]}',f'/exams/view?id={f["exam"]}',f'/exams/symbols?exam_id={f["exam"]}&class_id={f["class"]}','/settings/users','/settings/years','/settings/school','/settings/cdc','/account/profile','/account/change-password')
    requests=[('school_admin',school_paths),('admin',('/admin','/admin/users','/admin/schools','/admin/subjects','/admin/exam-templates','/admin/offerings?school_id=1','/subjects/add',f'/subjects/edit?id={app.db.execute("SELECT source_id FROM subjects WHERE id=?",(f["subject"],)).fetchone()[0]}'))]
    for username,paths in requests:
      app.login(username)
      for path in paths:
        headers,body,_=send(app,path,headers=HX);data=marked(headers,body)
        assert data['adapterReady'] is True,(path,data['pageId'])
        assert not re.search(rb'<script(?![^>]*type="application/json")[^>]*>',body,re.I),path
        assert not re.search(rb'\son(?:click|change|input|submit|keyup)=',body,re.I),path
        assert any(route['enhanced'] and route['url']==data['canonicalUrl'].split('?')[0] for route in data['routes']),path
        pages.append({'url':app.base+path,'html':body.decode(),'config':data})
    with tempfile.TemporaryDirectory(prefix='erp-page-mounts-') as directory:
        fixture=Path(directory)/'pages.json';fixture.write_text(json.dumps(pages),encoding='utf8')
        subprocess.run(['node','tests/school_page_mounts.mjs',str(fixture)],cwd=app.root,check=True)
    for table,rows in snapshots.items():assert app.snapshot(table)==rows,table
    print('School app HTTP passed: verified adapters, inert data, canonical routes, no executable attributes and preserved records.')
if __name__=='__main__':run()
