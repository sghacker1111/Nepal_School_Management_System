"""Synthetic accounting app saves preserve service idempotency and all other schools."""
import json,re,subprocess,tempfile
from pathlib import Path
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX,marked
def run():
 with ERPHttpFixture() as app:
  f=app.fixture;app.login('school_admin')
  tables=[row[0] for row in app.db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ac_%' ORDER BY name")]
  before={table:app.snapshot(table) for table in tables};pages=[]
  for path in ('/accounting','/accounting/fees','/accounting/create-bill','/accounting/invoices','/accounting/generate','/accounting/payments',f'/accounting/student?student={f["student"]}','/accounting/subscriptions','/accounting/expenses','/accounting/journals','/accounting/reports','/accounting/settings'):
   h,b,_=send(app,path,headers=HX);data=marked(h,b);assert data['adapterReady'],path
   assert not re.search(rb'<script(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit)=',b,re.I),path
   pages.append({'url':app.base+path,'html':b.decode(),'config':data})
  with tempfile.TemporaryDirectory(prefix='erp-finance-mounts-') as directory:
   fixture=Path(directory)/'pages.json';fixture.write_text(json.dumps(pages),encoding='utf8');subprocess.run(['node','tests/school_page_mounts.mjs',str(fixture)],cwd=app.root,check=True)
  for table,rows in before.items():assert app.snapshot(table)==rows,table
  other={table:app.db.execute('SELECT * FROM '+table+' WHERE school_id=2 ORDER BY id').fetchall() for table in ('ac_invoices','ac_payments','ac_journals')}
  app.request('/accounting/settings',{'action':'period','name':'App synthetic period','start_date':'2026-01-01','end_date':'2026-12-31'})
  bill={'action':'preview','student_id':f['student'],'bill_date':'2026-10-01','due_date':'2026-10-31','items[0][particular]':'Synthetic fee','items[0][rate]':'100','idempotency_key':'app-only-bill-key'}
  h,b,_=send(app,'/accounting/create-bill',data=bill,headers=HX);assert marked(h,b)['mutation']['outcome']=='preview' and b'Rs. 100.00' in b
  h,b,_=send(app,'/accounting/create-bill',data={**bill,'action':'issue'},headers=HX,status=204);assert h['HX-Location'] and not b
  invoice=app.db.execute('SELECT id FROM ac_invoices WHERE idempotency_key=?',(bill['idempotency_key'],)).fetchone()[0];cash=app.db.execute("SELECT id FROM ac_methods WHERE school_id=1 AND name='Cash'").fetchone()[0]
  payment={'action':'payment','student_id':f['student'],'method_id':cash,'payment_date':'2026-10-03','amount':'100',f'allocations[{invoice}]':'100','idempotency_key':'app-only-payment-key'}
  before_payment=len(app.snapshot('ac_payments'));before_journal=len(app.snapshot('ac_journals'))
  for attempt in range(2):h,b,_=send(app,'/accounting/payments',data=payment,headers=HX,status=204);assert h['HX-Location']
  assert len(app.snapshot('ac_payments'))==before_payment+1 and len(app.snapshot('ac_journals'))==before_journal+1
  receipt=app.db.execute('SELECT id FROM ac_payments WHERE idempotency_key=?',(payment['idempotency_key'],)).fetchone()[0]
  for path,label in [(f'/accounting/invoice?id={invoice}',b'INVOICE'),(f'/accounting/receipt?id={receipt}',b'PAYMENT RECEIPT')]:
   h,b,_=send(app,path,headers=HX);assert marked(h,b)['adapterReady'] and b'Rs. 100.00' in b and label in b
  credit={'action':'credit','amount':'100','date':'2026-10-04','reason':'Synthetic app correction','confirm':'1','idempotency_key':'app-only-credit-key'}
  h,b,_=send(app,f'/accounting/invoice?id={invoice}',data=credit,headers=HX,status=204);assert h['HX-Location']
  credit_id=app.db.execute('SELECT id FROM ac_credits WHERE invoice_id=?',(invoice,)).fetchone()[0]
  account=app.db.execute("SELECT id FROM ac_accounts WHERE school_id=1 AND control_key='cash'").fetchone()[0]
  refund={'action':'refund','amount':'100','date':'2026-10-05','reason':'Synthetic app refund','confirm':'1','account_id':account,'idempotency_key':'app-only-refund-key'}
  h,b,_=send(app,f'/accounting/credit?id={credit_id}',data=refund,headers=HX,status=204);assert h['HX-Location']
  h,b,_=send(app,f'/accounting/credit?id={credit_id}',headers=HX);marked(h,b);assert b'RFN-' in b and b'Rs. 100.00' in b
  source='require "config/config.php"; require "includes/database.php"; require "includes/accounting_service.php"; $ac=new SchoolAccounting(getDB(),1,(int)$argv[1]);echo json_encode($ac->reconcile());'
  reconciliation=json.loads(subprocess.run([app.php,'-r',source,str(f['ids']['school_admin'])],cwd=app.root,env=app.env,capture_output=True,text=True,check=True).stdout);assert reconciliation['ok'] is True
  for table,rows in other.items():assert app.db.execute('SELECT * FROM '+table+' WHERE school_id=2 ORDER BY id').fetchall()==rows,table
  for username,denied in [('cashier','/accounting/create-bill'),('auditor','/accounting/payments')]:
   app.login(username)
   for path in (denied,denied.replace('/accounting/','/modules/accounting/').replace('-','_')+'.php'):
    h,b,_=send(app,path,data=payment if username=='auditor' else None,headers=HX,status=403);marked(h,b)
  print('Accounting app HTTP passed: inert pages, preview, one payment/journal per key, role denial and unchanged other school.')
if __name__=='__main__':run()
