"""Clean/legacy route parity and real subdirectory dispatch on disposable records."""
import json,re,shutil,socket,subprocess,tempfile,time
from pathlib import Path
from urllib.request import Request
from urllib.error import HTTPError,URLError
from urllib.parse import urlencode,urlparse
from http_support import ERPHttpFixture

def send(app,path,data=None,headers=None,method=None,status=200):
    if isinstance(data,dict):data=urlencode({'csrf_token':app.token,**data},doseq=True).encode()
    request=Request(app.base+path,data=data,headers=headers or {},method=method)
    try:response=app.opener.open(request,timeout=25)
    except HTTPError as error:response=error
    with response:
        body=response.read();actual=response.code;result=(response.headers,body,response.geturl())
    assert actual==status,(path,actual,body[:500]);return result

def run():
  with ERPHttpFixture() as app:
    app.login('school_admin');protected={t:app.snapshot(t) for t in ('students','marks','exams','questions','ac_payments')}
    clean=app.request('/students');legacy=app.request('/modules/students/index.php')
    assert 'Test Student' in clean and 'Test Student' in legacy
    assert 'href="'+app.base+'/students/add"' in clean
    assert not re.findall(r'href="[^"#]*\/modules\/[^"?]*\.php',clean)
    _,_,final=send(app,'/modules/students/index.php',headers={'Accept':'text/html'});assert urlparse(final).path=='/students'
    headers,body,_=send(app,'/students',method='HEAD');assert not body and 'text/html' in headers.get('Content-Type','')
    before=app.db.execute('SELECT COUNT(*) FROM academic_years').fetchone()[0]
    app.request('/modules/settings/years.php',{'action':'add','year_bs':'2090','year_ad':'2033'})
    assert app.db.execute('SELECT COUNT(*) FROM academic_years').fetchone()[0]==before+1
    # A file remains a file even when the caller asks for document HTML.
    legacy_headers,expected,_=send(app,'/modules/students/import_export.php?action=template',headers={'Accept':'text/html'})
    headers,downloaded,_=send(app,'/students/import-export?action=template',headers={'Accept':'text/html'})
    assert 'text/csv' in headers['Content-Type'] and downloaded==expected and 'attachment' in headers['Content-Disposition']
    lookup='?student_lookup=1&lookup_school=1&search=Test'
    h,old,_=send(app,'/modules/settings/users.php'+lookup,headers={'Accept':'application/json'})
    h,new,_=send(app,'/settings/users'+lookup,headers={'Accept':'application/json'})
    assert 'application/json' in h['Content-Type'] and json.loads(old)==json.loads(new)
    for private in ('/frontend/app.css','/includes/auth.php','/config/config.php','/node_modules/jquery/package.json','/missing','/students%2fedit'):
        send(app,private,status=404)
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    # Multipart data must reach the same native upload handler without a redirect.
    boundary='ErpRouteUploadBoundary'
    csv='First Name,Last Name,Gender,Class,EMIS Registration Number\r\nRoute Import,Student,Female,Class 9,00000089\r\n'
    upload=(f'--{boundary}\r\nContent-Disposition: form-data; name="csrf_token"\r\n\r\n{app.token}\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="csv_file"; filename="route-test.csv"\r\nContent-Type: text/csv\r\n\r\n{csv}\r\n--{boundary}--\r\n').encode()
    send(app,'/students/import-export',data=upload,headers={'Content-Type':'multipart/form-data; boundary='+boundary})
    imported=app.db.execute("SELECT emis_registration_number FROM students WHERE first_name_en='Route Import'").fetchall()
    assert imported==[('00000089',)],imported
    app.login('teacher')
    for path in ('/modules/super_admin/users.php','/admin/users'):
        send(app,path,headers={'HX-Request':'true','HX-Target':'main-content','X-ERP-Controller':'index.php'},status=403)
    # Copy application code only; the second server uses the same disposable DB.
    with tempfile.TemporaryDirectory(prefix='erp-route-mount-') as mount_root:
        mount=Path(mount_root)/'erp';mount.mkdir()
        for folder in ('includes','modules','config','assets'):
            shutil.copytree(app.root/folder,mount/folder)
        for name in ('index.php','router.php','app.php'):shutil.copyfile(app.root/name,mount/name)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        log=open(Path(mount_root)/'php.log','w+',encoding='utf8')
        server=subprocess.Popen([app.php,'-S',f'127.0.0.1:{port}','-t',mount_root,str(mount/'router.php')],cwd=mount_root,env=app.env,stdout=log,stderr=log)
        original_base=app.base;app.base=f'http://127.0.0.1:{port}/erp'
        try:
            for _ in range(100):
                try:send(app,'/login');break
                except (URLError,ConnectionError):time.sleep(.05)
            app.login('school_admin')
            page=app.request('/students');assert 'Test Student' in page and f'href="{app.base}/students/add"' in page
            _,_,final=send(app,'/modules/students/index.php',headers={'Accept':'text/html'});assert urlparse(final).path=='/erp/students'
            send(app,'/includes/auth.php',status=404)
            send(app,'/assets/dist/app.css')
        finally:
            server.terminate();server.wait(timeout=10);app.base=original_base;log.close()
    print('Clean routes HTTP passed: URLs, native POST/multipart, JSON/file parity, scope, HEAD, private paths and subdirectory hosting.')
if __name__=='__main__':run()
