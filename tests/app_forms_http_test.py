"""Dynamic/native save parity; all records belong to a disposable fixture."""
from app_response_http_test import HX,marked
from routes_http_test import send
from http_support import ERPHttpFixture
def run():
  with ERPHttpFixture() as app:
    app.login('school_admin');protected={table:app.snapshot(table) for table in ('students','marks','questions','ac_payments','users')}
    h,b,_=send(app,'/classes/add',data={'add_class':'1','name':''},headers=HX,status=422)
    assert marked(h,b)['mutation']['outcome']=='invalid' and b'Class name is required' in b
    h,b,_=send(app,'/settings/years',data={'action':'add','year_bs':'2092','year_ad':'2035'},headers=HX)
    assert marked(h,b)['mutation']['outcome']=='saved'
    assert app.db.execute("SELECT COUNT(*) FROM academic_years WHERE year_bs='2092'").fetchone()[0]==1
    # Failed guard validation must neither write nor turn into a repost/redirect.
    h,b,_=send(app,'/students/add',data={'class_id':999999},headers=HX,status=422);marked(h,b)
    h,b,_=send(app,'/settings/years',data=b'action=add',headers={**HX,'Content-Type':'application/x-www-form-urlencoded'},status=419);marked(h,b)
    for table,rows in protected.items():assert app.snapshot(table)==rows,table
    print('App forms HTTP passed: native domain validation, explicit save outcome, CSRF, no rejected writes and preserved records.')
if __name__=='__main__':run()
