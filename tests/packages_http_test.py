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
    print(f'Package HTTP passed: {app.count} requests')
