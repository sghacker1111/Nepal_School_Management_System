"""Permission-aware feature discovery and native, CSRF-protected sign out."""
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX,marked,config

def run():
 with ERPHttpFixture() as app:
  app.db.execute("INSERT INTO users(username,password,role,full_name,school_id,status,student_record_id) SELECT 'student',password,'student','Test student',1,1,? FROM users WHERE username='teacher'",(app.fixture['student'],));app.db.commit()
  for role,path,required,forbidden in (
   ('school_admin','/',('Import questions','Enter marks','Add Student','Unlock school'),('School packages',)),
   ('teacher','/',('Import questions','Enter marks'),('Add Student','Unlock school','School packages')),
   ('student','/learning',('My profile',),('Import questions','Enter marks','Archived questions','Add / edit study')),
   ('admin','/admin',('School packages','backup codes','Individual school'),('Enter marks',)),
   ('accountant','/accounting',('Income, expenses','My profile'),('Import questions','Enter marks','Add Student')),
   ('cashier','/accounting',('My profile',),('Import questions','Enter marks','Add Student')),
   ('auditor','/accounting',('My profile',),('Import questions','Enter marks','Add Student')),
  ):
   app.login(role);h,b,_=send(app,path,headers=HX);data=marked(h,b);features=data['searchFeatures'];labels=' | '.join(item['label'] for item in features)
   for term in required:assert term in labels,(role,term,labels)
   for term in forbidden:assert term not in labels,(role,term)
   for feature in features:
    assert feature['url'].startswith(app.base+'/')
    send(app,feature['url'][len(app.base):])
   full=app.request(path);assert 'id="navigationToggle"' in full and 'id="navigationFeatureList"' in full
   assert config(full.encode())['searchFeatures']==features
  app.login('school_admin');h,b,_=send(app,'/logout');assert b'signout-card' in b and b'linear-gradient(135deg,#667eea' in b and b'method="post"' in b;assert h['Cache-Control']=='no-store, private'
  send(app,'/logout',data=b'',headers={'Content-Type':'application/x-www-form-urlencoded'},status=419)
  send(app,'/',headers=HX)
  app.request('/logout',data={})
  h,b,_=send(app,'/',headers=HX,status=401);assert h['HX-Redirect']==app.base+'/login'
  print('Workspace HTTP passed: internal feature roles, full/fragment parity, styled logout and CSRF.')
if __name__=='__main__':run()
