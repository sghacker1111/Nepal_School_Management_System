"""Authenticated fragment, error and POST redirect contracts, using disposable data."""
import json,re
from html import unescape
from http.cookiejar import CookieJar
from urllib.request import build_opener,HTTPCookieProcessor
from http_support import ERPHttpFixture
from routes_http_test import send

HX={'HX-Request':'true','HX-Target':'main-content','Accept':'text/html'}
def config(body):
    return json.loads(unescape(re.search(r'data-erp-config="([^"]+)"',body.decode()).group(1)))
def marked(headers,body):
    assert headers['X-ERP-Fragment']=='app-page'
    assert headers['Content-Type'].startswith('text/html')
    assert headers['Cache-Control']=='no-store, private'
    assert 'HX-Target' in headers['Vary']
    assert not re.search(rb'<[^>]+class="[^"]*\btop-navbar\b',body) and b'<!DOCTYPE' not in body
    return config(body)
def run():
  with ERPHttpFixture() as app:
    app.login('school_admin');tables=('students','marks','exams','questions','ac_payments')
    before={table:app.snapshot(table) for table in tables}
    full=app.request('/students');assert full.count('class="top-navbar"')==1 and 'data-erp-config=' in full
    h,b,_=send(app,'/students',headers=HX);c=marked(h,b)
    assert c['canonicalUrl']==app.base+'/students' and c['csrfToken']==app.token and c['navigation']
    assert b'Test Student' in b and b'<script>window.APP_URL' not in b
    h,b,_=send(app,'/students/add',headers=HX);marked(h,b);assert app.token.encode() in b and b'name="csrf_token"' in b
    for path,status in (('/students?class_id=999999',404),('/admin/users',403)):
        h,b,_=send(app,path,headers=HX,status=status);marked(h,b)
    h,b,_=send(app,'/classes/add',data={'add_section':'1','section_name':'App response test section','class_id':app.fixture['class'],'teacher_id':''},headers=HX,status=204)
    location=json.loads(h['HX-Location']);assert location=={'path':app.base+'/classes','target':'#main-content','swap':'innerHTML'} and not b
    assert app.db.execute("SELECT COUNT(*) FROM sections WHERE name='App response test section'").fetchone()[0]==1
    h,b,_=send(app,'/settings/years',data=b'action=add',headers={**HX,'Content-Type':'application/x-www-form-urlencoded'},status=419);marked(h,b)
    h,b,_=send(app,'/students/add',data={'class_id':999999},headers=HX,status=422);marked(h,b)
    # Destructive legacy GETs remain confirmation forms, never mutations.
    h,b,_=send(app,'/settings/years?delete=999999',headers=HX);marked(h,b)
    assert b'Confirm this change' in b and b'method="post"' in b
    for table,rows in before.items():assert app.snapshot(table)==rows,table
    # A server exception is generic, marked and does not disclose SQL/schema details.
    app.db.execute('ALTER TABLE academic_years RENAME TO fixture_missing_years');app.db.commit()
    try:
        h,b,_=send(app,'/students',headers=HX,status=500);marked(h,b)
        assert not any(secret in b for secret in (b'PDOException',b'SQLSTATE',b'no such table',b'fixture_missing_years'))
    finally:
        app.db.execute('ALTER TABLE fixture_missing_years RENAME TO academic_years');app.db.commit()
    app.opener=build_opener(HTTPCookieProcessor(CookieJar()))
    h,b,_=send(app,'/students',headers=HX,status=401)
    assert h['HX-Redirect']==app.base+'/login' and b'Test Student' not in b
    print('App response HTTP passed: shell/fragment parity, CSRF, statuses, one POST, confirmation and session expiry.')
if __name__=='__main__':run()
