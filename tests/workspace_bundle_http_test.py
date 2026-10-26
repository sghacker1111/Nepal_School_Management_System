import json,subprocess,tempfile
from pathlib import Path
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX
with ERPHttpFixture() as app:
 app.login('school_admin');full=app.request('/');pages={}
 for path in ('/learning/paper-generator','/exam-questions'):
  pages[path]=send(app,path,headers=HX)[1].decode()
 with tempfile.TemporaryDirectory(prefix='erp-workspace-shell-') as directory:
  fixture=Path(directory)/'pages.json';fixture.write_text(json.dumps(dict(base=app.base,full=full,pages=pages)),encoding='utf8')
  subprocess.run(['node','tests/workspace_bundle_mounts.mjs',str(fixture)],cwd=app.root,check=True)
