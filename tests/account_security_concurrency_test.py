"""Parallel requests cannot lose failures or consume a recovery code twice."""
import json,subprocess,time
from concurrent.futures import ThreadPoolExecutor
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    uid=app.fixture['ids']['teacher']
    prefix='require "config/bootstrap.php";require "includes/database.php";require "includes/account_security.php";$db=getDB();'
    def php(code):
        result=subprocess.run([app.php,'-r',prefix+code],cwd=app.root,env=app.env,capture_output=True,text=True,check=True)
        assert not result.stderr,result.stderr
        return result.stdout
    def parallel(code,count):
        with ThreadPoolExecutor(max_workers=count) as pool:return list(pool.map(lambda _:php(code),range(count)))
    failure=f'echo schoolAuthenticateAccount($db,{uid},"IncorrectPassword!")?"yes":"no";'
    assert parallel(failure,7)==['no']*7
    key='account_security:'+str(uid)
    state=json.loads(app.db.execute('SELECT meta_value FROM app_meta WHERE meta_key=?',(key,)).fetchone()[0])
    assert state['retry'] and not state['blocked'] and state['failures']==0 and state['until']>time.time()
    state['until']=int(time.time())-1;app.db.execute('UPDATE app_meta SET meta_value=? WHERE meta_key=?',(json.dumps(state),key));app.db.commit()
    assert parallel(failure,3)==['no']*3
    state=json.loads(app.db.execute('SELECT meta_value FROM app_meta WHERE meta_key=?',(key,)).fetchone()[0]);assert state['blocked'] and state['failures']==2
    root=app.db.execute("SELECT id FROM users WHERE username='admin'").fetchone()[0]
    codes=json.loads(php(f'echo json_encode(schoolGenerateBackupCodes($db,{root},"TestPassword123!"));'))
    code='echo schoolRecoverAdministrator($db,"admin",'+json.dumps(codes[0])+',"NewPassword123!")?"yes":"no";'
    assert sorted(parallel(code,2))==['no','yes']
    assert app.db.execute("SELECT COUNT(*) FROM app_meta WHERE meta_key LIKE 'account_recovery:%'").fetchone()[0]==9
    print('Account security concurrency passed: serialized failures and exactly one recovery code consumption.')
