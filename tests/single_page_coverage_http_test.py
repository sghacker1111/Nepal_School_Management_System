"""Finite controller/adapter coverage, clean-link and canonical authorization parity."""
import json,re,subprocess
from pathlib import Path
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX,marked
from learning_sharing_support import extend_learning
def run():
 root=Path(__file__).resolve().parents[1]
 routes=json.loads(subprocess.run(['php','-r','require "includes/routes.php";echo json_encode(schoolRoutes());'],cwd=root,capture_output=True,text=True,check=True).stdout)
 discovered={'index.php'}|{str(p.relative_to(root)).replace('\\','/') for p in root.glob('modules/*/*.php')}
 assert {r['controller'] for r in routes}==discovered
 native={'auth-security','auth-recovery','auth-login','auth-logout','auth-verify-password','super-admin-switch-school','accounting-search','students-admit-card','results-report-card','exams-admit-cards'}
 unready=[r['id'] for r in routes if not r['enhanced'] and r['id'] not in native];assert unready==[],unready
 adapters=set()
 for path in (root/'frontend/pages').glob('*.js'):
  for match in re.finditer(r'registerGroup\(\[([^\]]+)\]',path.read_text(encoding='utf8')):adapters.update(re.findall(r"'([a-z-]+)'",match[1]))
 assert {r['pageId'] for r in routes if r['enhanced']}<=adapters
 # All real suites, not just the two original UI suites, must run on release.
 package=json.loads((root/'package.json').read_text());assert '*_test.mjs' in package['scripts']['test:ui'],'Missing app lifecycle test discovery'
 assert "tests/*_test.mjs" in (root/'tools/verify-release.py').read_text(),'Full verification misses app suites'
 with ERPHttpFixture() as app:
  f=extend_learning(app);protected={table:app.snapshot(table) for table in ('students','users','questions','marks','ac_payments')}
  roles={
   'school_admin':[('/students',200),('/admin/users',403),('/accounting',200)],
   'teacher':[('/learning/curriculum',200),('/classes/add',403),('/accounting',403)],
   'principal':[('/results',200),('/settings/users',403)],
   'exam_officer':[('/results/marks-entry',200),('/teachers',403)],
   'cashier':[('/accounting',200),('/accounting/create-bill',403),('/students',403)],
   'auditor':[('/accounting/reports',200),('/students',403)],
   'student_login':[('/learning/practice-mcqs',200),('/students',403),('/accounting',403)],
   'other_admin':[(f'/students?class_id={f["class"]}',404)],
   'admin':[('/admin/users',200),('/admin/question-corrections',200)]}
  by_path={r['path']:r for r in routes}
  for username,checks in roles.items():
   app.login(username)
   for path,status in checks:
    base,separator,query=path.partition('?');route=by_path[base]
    for url in (path,'/'+route['controller']+('?' + query if separator else '')):
     h,b,_=send(app,url,headers={**HX,'X-ERP-Controller':'modules/super_admin/users.php'},status=status);marked(h,b)
     assert not re.findall(rb'(?:href|action)="[^"#]*\/modules\/[^"?]+\.php',b),url
  app.login('school_admin')
  # Plain native requests still save one record and preserve clean redirects.
  app.login('admin');old=len(app.snapshot('classes'));added=1+app.db.execute('SELECT COUNT(*) FROM schools').fetchone()[0];app.request('/classes/add',{'add_class':'1','name':'Native fallback test class'});assert len(app.snapshot('classes'))==old+added
  app.request('/classes/add',{'add_class':'1','name':'Native fallback test class'});assert len(app.snapshot('classes'))==old+added
  for table,rows in protected.items():assert app.snapshot(table)==rows,table
  app.login('school_admin')
  # Headers cannot turn a JSON/download mode into HTML, or weaken expiry.
  for clean,legacy in [('/students/import-export?action=template','/modules/students/import_export.php?action=template'),('/learning/curriculum?action=get_subjects&class_id='+str(f['class']),'/modules/learning/curriculum.php?action=get_subjects&class_id='+str(f['class']))]:
   h,a,_=send(app,clean,headers=HX);old,b,_=send(app,legacy,headers=HX);assert a==b and h['Content-Type']==old['Content-Type'] and not h.get('X-ERP-Fragment')
  app.db.execute("UPDATE schools SET subscription_mode='custom',subscription_start='2020-01-01',subscription_end='2020-12-31' WHERE id=1");app.db.commit()
  for path in ('/students','/modules/students/index.php'):
   h,b,_=send(app,path,headers=HX,status=403);marked(h,b);assert b'expired' in b.lower()
  app.db.execute("UPDATE schools SET subscription_mode='no_expiry' WHERE id=1");app.db.commit()
  # Generated GET filter forms without a JS-only auto-submit need a native submit.
  for path in ('/subjects','/students','/results','/classes/routine'):
   body=app.request(path)
   for form in re.findall(r'<form\b[^>]*method="get"[^>]*>.*?</form>',body,re.S|re.I):
    assert re.search(r'<button\b|type="submit"',form,re.I),path+' has no native filter button'
 print(f'Single-page coverage passed: {len(routes)} finite controllers, {len(routes)-len(native)} app adapters, native boundaries and role/clean/legacy parity.')
if __name__=='__main__':run()
