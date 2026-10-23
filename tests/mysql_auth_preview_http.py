"""Native MySQL login, expired session and read-only super-admin school preview."""
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX

with ERPHttpFixture() as app:
    assert app.mysql, 'This acceptance check requires native MySQL.'
    app.login('admin')
    school={'action':'add','name_en':'Native New School','name_np':'Synthetic School NP','emis_code':'NATIVE-SYNTHETIC','address':'Test address','phone':'9800000000','email':'synthetic@example.test','academic_year_current':'2083/2026','principal_name':'Test Principal','principal_mobile':'9800000001','technical_person':'Test Contact','admin_full_name':'Test Admin','admin_username':'native_new_school_admin','admin_password':'TestPassword123!','packages[]':['full'],'subscription_mode':'no_expiry','subscription_enabled':'1'}
    body=app.request('/modules/super_admin/schools.php',school)
    assert 'School registered and administrator account created successfully' in body
    new_school=app.db.execute("SELECT id FROM schools WHERE name_en='Native New School'").fetchone()[0]
    assert app.db.execute('SELECT COUNT(*) FROM ac_settings WHERE school_id=?',(new_school,)).fetchone()[0]==1,'New schools need explicit accounting provisioning on MySQL'
    assert app.db.execute('SELECT COUNT(*) FROM ac_methods WHERE school_id=?',(new_school,)).fetchone()[0]>0
    tables=('students','marks','questions','ac_settings','ac_accounts','ac_methods','ac_invoices','ac_payments','ac_journals','ac_journal_lines')
    before={table:app.snapshot(table) for table in tables}
    app.request('/modules/super_admin/switch_school.php?school_id=1')
    app.request('/modules/accounting/invoices.php');app.request('/modules/accounting/fees.php')
    app.request('/modules/accounting/create_bill.php',{},status=403)
    assert all(app.snapshot(table)==rows for table,rows in before.items())
    app.expire_session()
    headers,body,_=send(app,'/students',headers=HX,status=401)
    assert headers['HX-Redirect']==app.base+'/login' and b'Test Student' not in body
    print('Native MySQL login, session expiry and read-only school preview passed.')
