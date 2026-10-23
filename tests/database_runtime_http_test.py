"""Normal runtime must ignore legacy SQLite defaults and HTTP test flags."""
import os, shutil, socket, subprocess, tempfile, time, urllib.request, urllib.error, re
from http.cookiejar import CookieJar
from urllib.parse import urlencode
from pathlib import Path

root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='erp-db-runtime-') as temp:
    sandbox=Path(temp)
    for name in ('includes','config','modules/auth'):
        shutil.copytree(root/name,sandbox/name)
    for name in ('app.php','index.php','router.php'):
        shutil.copy2(root/name,sandbox/name)
    # A persisted old FTP-excluded config must not control new shipped entrypoints.
    (sandbox/'config/config.php').write_text("<?php define('DB_TYPE','sqlite'); define('DB_SQLITE_PATH',dirname(__DIR__).'/database.sqlite');",encoding='utf-8')
    env={k:v for k,v in os.environ.items() if not k.startswith('ERP_')}
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    with (sandbox/'server.log').open('w',encoding='utf-8') as log:
        server=subprocess.Popen([os.environ.get('ERP_TEST_PHP','php'),'-S',f'127.0.0.1:{port}','router.php'],cwd=sandbox,env=env,stdout=log,stderr=log)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.05)
            req=urllib.request.Request(f'http://127.0.0.1:{port}/login?ERP_DB_TYPE=sqlite&ERP_RUNTIME=test&ERP_TEST_SQLITE=1',headers={'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','X-ERP-DB-TYPE':'sqlite'})
            try:
                response=urllib.request.urlopen(req);status=response.status;body=response.read().decode()
            except urllib.error.HTTPError as error:status=error.code;body=error.read().decode()
            assert status==503, f'Unconfigured MySQL must fail closed with 503, got {status}'
            assert 'MySQL' in body and 'database.sqlite' not in body,'Safe actionable error required'
            assert not list(sandbox.rglob('*.sqlite')),'HTTP/legacy config created a local database'
        finally:server.terminate();server.wait(timeout=10)
    print('Database runtime HTTP checks passed: legacy config ignored, spoofed flags denied, no SQLite created.')
    # A valid configuration with an unavailable server must also be a safe 503,
    # even after normal security headers install their exception handler.
    env.update({'ERP_DB_HOST':'127.0.0.1','ERP_DB_PORT':'1','ERP_DB_NAME':'erp_migration_test_unavailable','ERP_DB_USER':'synthetic','ERP_DB_PASS':'SENTINEL_PRIVATE_PASSWORD'})
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(CookieJar()))
    with (sandbox/'server.log').open('w',encoding='utf-8') as log:
        server=subprocess.Popen([os.environ.get('ERP_TEST_PHP','php'),'-S',f'127.0.0.1:{port}','router.php'],cwd=sandbox,env=env,stdout=log,stderr=log)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.05)
            html=opener.open(f'http://127.0.0.1:{port}/login').read().decode()
            token=re.search(r'name="csrf_token" value="([^"]+)"',html).group(1)
            data=urlencode({'csrf_token':token,'username':'synthetic','password':'not-a-real-password'}).encode()
            try:response=opener.open(f'http://127.0.0.1:{port}/login',data=data);status=response.status;body=response.read().decode()
            except urllib.error.HTTPError as error:status=error.code;body=error.read().decode()
            assert status==503, f'Unavailable MySQL must return 503, got {status}'
            assert 'SENTINEL_PRIVATE_PASSWORD' not in body
            assert not list(sandbox.rglob('*.sqlite'))
        finally:server.terminate();server.wait(timeout=10)
    assert 'SENTINEL_PRIVATE_PASSWORD' not in (sandbox/'server.log').read_text(encoding='utf-8')
    print('Unavailable MySQL login failed closed without credentials or SQLite fallback.')
    (sandbox/'config/database.local.php').write_text("<?php return ['password' => 'broken'SENTINEL_PRIVATE_PASSWORD'];",encoding='utf-8')
    with (sandbox/'invalid-private.log').open('w',encoding='utf-8') as log:
        server=subprocess.Popen([os.environ.get('ERP_TEST_PHP','php'),'-S',f'127.0.0.1:{port}','router.php'],cwd=sandbox,env=env,stdout=log,stderr=log)
        try:
            for _ in range(60):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.2):break
                except OSError:time.sleep(.05)
            try:response=opener.open(f'http://127.0.0.1:{port}/login');status=response.status;body=response.read().decode()
            except urllib.error.HTTPError as error:status=error.code;body=error.read().decode()
            assert status==503, 'Malformed private settings must be a safe configuration error'
            assert 'SENTINEL_PRIVATE_PASSWORD' not in body
        finally:server.terminate();server.wait(timeout=10)
    assert 'SENTINEL_PRIVATE_PASSWORD' not in (sandbox/'invalid-private.log').read_text(encoding='utf-8')
    print('Invalid private PHP configuration is sanitized in both HTTP and logs.')
