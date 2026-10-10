"""Native accounting UI against disposable SQLite only; never a live gateway."""
import json, re
from http_support import ERPHttpFixture

with ERPHttpFixture() as app:
    f=app.fixture
    app.login('school_admin')
    app.request('/modules/accounting/settings.php',{'action':'period','name':'Synthetic 2026','start_date':'2026-01-01','end_date':'2026-12-31'})
    create='/modules/accounting/create_bill.php'
    payload={'action':'preview','student_id':f['student'],'bill_date':'2026-10-01','due_date':'2026-10-31','bill_discount':'0.00','phone':'9800000000','address':'Synthetic test address','description':'Retained description','billing_period':'2026-10 AD','idempotency_key':'http-synthetic-bill-key'}
    for i,(name,rate) in enumerate([('Monthly Tuition Fee','2500.00'),('Examination Fee','500.00')]):
        for field,value in [('particular',name),('quantity','1'),('rate',rate),('discount','0.00'),('tax_percent','0.00')]:payload[f'items[{i}][{field}]']=value
    before=app.snapshot('ac_invoices');body=app.request(create,payload)
    assert 'Validated bill preview' in body and 'Rs. 3,000.00' in body
    assert app.snapshot('ac_invoices')==before,'Preview must not create invoices'
    bad={**payload,'items[1][quantity]':'1e3'};body=app.request(create,bad)
    assert 'non-negative number' in body
    restored=json.loads(re.search(r'<script id="acBillRows" type="application/json">(.*?)</script>',body).group(1))
    assert restored[1]['particular']=='Examination Fee' and restored[1]['quantity']=='1e3','Failed bill retains submitted rows'
    assert 'Retained description' in body and 'Synthetic test address' in body
    assert app.snapshot('ac_invoices')==before
    body=app.request(create,{**payload,'action':'draft'})
    draft=app.db.execute('SELECT id FROM ac_invoices WHERE idempotency_key=?',(payload['idempotency_key'],)).fetchone()[0]
    assert 'DRAFT' in body and 'Rs. 3,000.00' in body
    edit=f'{create}?id={draft}';body=app.request(edit)
    revision=re.search(r'name="revision" value="([^"]+)"',body).group(1)
    changed={**payload,'action':'update','revision':revision,'items[0][rate]':'2600.00'}
    body=app.request(edit,changed);assert 'Rs. 3,100.00' in body
    assert app.db.execute('SELECT status,total_minor FROM ac_invoices WHERE id=?',(draft,)).fetchone()==('draft',310000)
    before=app.snapshot('ac_invoices');body=app.request(edit,changed)
    assert 'draft changed' in body and app.snapshot('ac_invoices')==before,'Stale draft denied'
    view=f'/modules/accounting/invoice.php?id={draft}';review=app.request(view)
    issue_revision=re.search(r'name="revision" value="([^"]+)"',review).group(1)
    body=app.request(view,{'action':'issue','revision':issue_revision})
    assert 'INVOICE' in body and 'DRAFT' not in body
    assert app.db.execute("SELECT COUNT(*) FROM ac_journals WHERE reference_type='invoice' AND reference_id=?",(draft,)).fetchone()[0]==1
    original_class=app.db.execute('SELECT name FROM classes WHERE id=?',(f['class'],)).fetchone()[0]
    original_school=app.db.execute('SELECT name_en FROM schools WHERE id=1').fetchone()[0]
    app.db.execute("UPDATE students SET first_name_en='Changed HTTP Student',student_id='CHANGED-ID' WHERE id=?",(f['student'],))
    app.db.execute("UPDATE classes SET name='Changed HTTP Class' WHERE id=?",(f['class'],))
    app.db.execute("UPDATE schools SET name_en='Changed HTTP School' WHERE id=1");app.db.commit()
    body=app.request(view);article=body.split('<article class="ac-document">',1)[1].split('</article>',1)[0]
    assert original_class in article and original_school in article and 'FIXTURE-1' in article
    assert 'Changed HTTP' not in article and 'CHANGED-ID' not in article,'Print must use frozen identity'
    app.request(edit,status=400)
    app.request(view,{'action':'issue'})
    assert app.db.execute("SELECT COUNT(*) FROM ac_journals WHERE reference_type='invoice' AND reference_id=?",(draft,)).fetchone()[0]==1
    app.login('other_admin');app.request(view,status=404);app.request(edit,status=404)
    app.login('cashier');app.request(create,status=403)
    app.login('auditor');app.request(view);app.request(view,{'action':'issue'},status=403)
    app.login('school_admin');app.request(create,{**payload,'action':'draft'},status=419,csrf=False)
    print(f'Accounting HTTP passed: {app.count} requests; previews, retention, drafts, immutable print, roles, tenant isolation and CSRF.')
