"""Package checks apply to direct URLs and POSTs, not only sidebar links."""
import re
from http_support import ERPHttpFixture

labels={'full':'Full ERP','result_system':'Result System','learning_system':'Learning System','paper_generator':'Paper Generator System','question_view':'Question View System','question_add':'Question Add System','accounting':'Accounting & Billing System','exam_generator':'Legacy Exam Generator Package'}
with ERPHttpFixture() as app:
    app.login('school_admin')
    app.request('/modules/accounting/invoices.php')
    app.db.execute("UPDATE schools SET subscription_type='result_system' WHERE id=1");app.db.commit()
    app.request('/modules/accounting/invoices.php',status=403)
    app.request('/modules/learning/index.php',status=403)
    for package,path,forbidden in [
        ('full','/modules/accounting/invoices.php',None),
        ('result_system','/modules/results/marks_entry.php','/modules/learning/questions.php'),
        ('learning_system','/modules/learning/index.php','/modules/results/marks_entry.php'),
        ('paper_generator','/modules/learning/paper_generator.php','/modules/learning/index.php'),
        ('question_view','/modules/learning/questions.php','/modules/learning/paper_generator.php'),
        ('question_add','/modules/learning/questions.php','/modules/accounting/index.php'),
        ('accounting','/modules/accounting/invoices.php','/modules/results/marks_entry.php'),
        ('exam_generator','/modules/learning/paper_generator.php','/modules/results/marks_entry.php')]:
        app.db.execute('UPDATE schools SET subscription_type=? WHERE id=1',(package,));app.db.commit()
        app.request(path)
        if forbidden:app.request(forbidden,status=403)
        if package in ('question_view','paper_generator'):app.request('/modules/learning/questions.php',{'question_text':'Denied mutation'},status=403)
    for package in labels:
        app.db.execute('INSERT INTO schools(name_en,subscription_type) VALUES (?,?)',('LABEL-'+package,package))
    app.db.commit();app.login('admin')
    body=app.request('/modules/super_admin/schools.php')
    for package,label in labels.items():
        row=re.search(r'<tr[^>]*>(?:(?!</tr>).)*LABEL-'+package+r'(?:(?!</tr>).)*</tr>',body,re.S).group(0)
        badges=re.findall(r'<span class="badge[^\"]*">([^<]+)</span>',row)
        assert label in [value.replace('&amp;','&') for value in badges],(package,badges)
    app.db.execute("UPDATE schools SET subscription_type='full' WHERE id=1");app.db.commit()
    tables=[row[0] for row in app.db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ac_%' ORDER BY name")]
    app.login('auditor');before={table:app.snapshot(table) for table in tables}
    for path in ['/modules/accounting/invoices.php','/modules/accounting/fees.php','/modules/accounting/payments.php','/modules/accounting/settings.php']:app.request(path)
    assert {table:app.snapshot(table) for table in tables}==before,'Auditor views must not write accounting tables'
    app.request('/modules/accounting/payments.php',{},status=403)
    for role in ['accountant','cashier','auditor']:
        app.login(role)
        app.request('/modules/students/index.php',status=403);app.request('/modules/settings/users.php',status=403)
    app.login('cashier')
    app.request('/modules/accounting/create_bill.php',{},status=403)
    app.request('/modules/accounting/expenses.php',{'action':'approve'},status=403)
    app.request('/modules/accounting/payments.php',{},status=419,csrf=False)
    app.login('accountant')
    app.request('/modules/accounting/settings.php',{'action':'period_state'},status=403)
    app.request('/modules/accounting/expenses.php',{'action':'approve'},status=403)
    app.request('/modules/accounting/student.php?student=1',{'action':'refund'},status=403)
    data={'action':'create','idempotency_key':'package-accountant-bill-1','customer_name':'Synthetic finance customer','customer_type':'external','bill_date':'2026-10-10','due_date':'2026-10-31','items[0][particular]':'Monthly Tuition Fee','items[0][quantity]':'1','items[0][rate]':'2500.00','items[1][particular]':'Examination Fee','items[1][quantity]':'1','items[1][rate]':'500.00'}
    body=app.request('/modules/accounting/create_bill.php',data)
    assert 'Rs. 3,000.00' in body
    assert app.db.execute('SELECT COUNT(*) FROM ac_invoices WHERE total_minor=300000').fetchone()[0]==1
    app.db.execute("INSERT INTO ac_periods(school_id,name,start_date,end_date) VALUES(1,'Test 2026','2026-01-01','2026-12-31')");app.db.commit()
    method=app.db.execute("SELECT id FROM ac_methods WHERE school_id=1 AND name='Cash'").fetchone()[0]
    app.login('cashier')
    body=app.request('/modules/accounting/payments.php',{'action':'payment','idempotency_key':'package-cashier-advance-1','student_id':1,'payment_date':'2026-10-10','amount':'1.00','method_id':method,'allow_advance':1})
    assert 'Rs. 1.00' in body
    assert app.db.execute('SELECT SUM(amount_minor) FROM ac_advance_entries WHERE student_id=1').fetchone()[0]==100
    app.login('admin');app.request('/modules/super_admin/switch_school.php?school_id=1')
    before={table:app.snapshot(table) for table in tables}
    app.request('/modules/accounting/invoices.php');app.request('/modules/accounting/fees.php')
    assert {table:app.snapshot(table) for table in tables}==before,'School preview creates no finance configuration'
    app.request('/modules/accounting/create_bill.php',{},status=403)
    print(f'Package HTTP passed: {app.count} requests')
