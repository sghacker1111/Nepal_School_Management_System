"""Real sign-in, role scoped unlock and one-use administrator recovery requests."""
import json, re, time
from http.cookiejar import CookieJar
from urllib.request import build_opener, HTTPCookieProcessor
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX

with ERPHttpFixture() as app:
    ids=app.fixture['ids'];root=app.db.execute("SELECT id FROM users WHERE username='admin'").fetchone()[0]
    def state(uid):
        row=app.db.execute('SELECT meta_value FROM app_meta WHERE meta_key=?',('account_security:'+str(uid),)).fetchone()
        return json.loads(row[0]) if row else {}
    def expire(uid):
        value=state(uid);value['until']=int(time.time())-1
        app.db.execute('UPDATE app_meta SET meta_value=? WHERE meta_key=?',(json.dumps(value),'account_security:'+str(uid)));app.db.commit()
    def anonymous():
        app.opener=build_opener(HTTPCookieProcessor(CookieJar()))
        body=app.request('/login');app.token=re.search(r'name="csrf_token" value="([^"]+)"',body).group(1)
    def attempt(username,password='IncorrectPassword!'):
        return app.request('/login',{'username':username,'password':password})
    app.login('teacher');old=app.opener
    anonymous()
    app.request('/login',{'username':'teacher','password':'wrong'},csrf=False)
    assert state(ids['teacher']).get('failures',0)==0,'Missing CSRF cannot consume attempts'
    for i in range(5):
        anonymous();assert 'Sign-in failed' in attempt('TEACHER' if i%2 else 'teacher')
    suspended=state(ids['teacher']);assert suspended['retry'] and suspended['until']>=int(time.time())+1795
    assert 'Sign-in failed' in attempt('teacher','TestPassword123!')
    assert state(ids['teacher'])==suspended
    current=app.opener;app.opener=old;send(app,'/account/profile',headers=HX,status=401);app.opener=current
    expire(ids['teacher']);anonymous();attempt('teacher');assert not state(ids['teacher'])['blocked']
    attempt('teacher');assert state(ids['teacher'])['blocked']
    assert 'Sign-in failed' in attempt('teacher','TestPassword123!')
    app.login('other_admin');body=app.request('/settings/users',{'action':'unlock_account','user_id':ids['teacher'],'current_password':'TestPassword123!'})
    assert 'cannot unlock this account' in body and state(ids['teacher'])['blocked']
    app.login('school_admin');body=app.request('/settings/users');assert 'Locked — administrator required' in body and 'Confirm unlock' in body
    app.request('/settings/users',{'action':'unlock_account','user_id':ids['teacher'],'current_password':'TestPassword123!'},csrf=False,status=419)
    assert state(ids['teacher'])['blocked']
    body=app.request('/settings/users',{'action':'unlock_account','user_id':ids['teacher'],'current_password':'TestPassword123!'})
    assert 'Account unlocked' in body and not state(ids['teacher'])['blocked'];app.login('teacher')
    app.request('/account/security',status=403)
    anonymous()
    for _ in range(5):attempt('school_admin')
    expire(ids['school_admin']);attempt('school_admin');attempt('school_admin')
    app.login('admin');body=app.request('/admin/users',{'action':'unlock_account','user_id':ids['school_admin'],'current_password':'TestPassword123!'})
    assert 'Account unlocked' in body
    headers,body=app.request_bytes('/account/security');assert 'no-store' in headers['Cache-Control']
    body=app.request('/account/security',{'action':'generate_backup_codes','current_password':'wrong'});assert 'backupCodes' not in body
    body=app.request('/account/security',{'action':'generate_backup_codes','current_password':'TestPassword123!'})
    codes=re.findall(r'<code>([A-F0-9-]+)</code>',body);assert len(codes)==10 and len(set(codes))==10
    assert all(code not in app.request('/account/security') for code in codes),'Codes never redisplayed'
    values=app.db.execute("SELECT meta_value FROM app_meta WHERE meta_key LIKE 'account_recovery:%'").fetchall()
    assert len(values)==10 and all(re.fullmatch('[a-f0-9]{64}',r[0]) for r in values)
    root_browser=app.opener;anonymous()
    for _ in range(5):attempt('admin')
    expire(root);attempt('admin');attempt('admin');assert state(root)['blocked']
    recovery={'username':'admin','backup_code':codes[0],'new_password':'RecoveredPassword123!','confirm_password':'RecoveredPassword123!'}
    body=app.request('/account/recovery',recovery,csrf=False);assert 'form expired' in body and state(root)['blocked']
    body=app.request('/account/recovery',recovery);assert 'Password reset and account unlocked' in body
    assert not state(root)['blocked'] and app.db.execute("SELECT COUNT(*) FROM app_meta WHERE meta_key LIKE 'account_recovery:%'").fetchone()[0]==9
    current=app.opener;app.opener=root_browser;send(app,'/account/profile',headers=HX,status=401);app.opener=current
    anonymous();body=app.request('/account/recovery',recovery);assert 'Recovery failed' in body
    assert 'Sign-in failed' in attempt('admin','TestPassword123!')
    assert 'Control center' in attempt('admin','RecoveredPassword123!')
    anonymous()
    for _ in range(5):app.request('/account/recovery',{**recovery,'backup_code':'0000-'*8})
    body=app.request('/account/recovery',{**recovery,'backup_code':codes[1]});assert 'Recovery failed' in body
    assert app.db.execute("SELECT COUNT(*) FROM app_meta WHERE meta_key LIKE 'account_recovery:%'").fetchone()[0]==9
    app.db.execute('UPDATE login_attempts SET attempted_at=?',(int(time.time())-1801,));app.db.commit()
    assert 'Password reset and account unlocked' in app.request('/account/recovery',{**recovery,'backup_code':codes[1]})
    print(f'Account security HTTP passed: {app.count} requests; staged lockout, CSRF, session revocation, role boundaries and backup recovery.')
