"""Astra regressions through real HTTP routes, with disposable SQLite only."""
import io, json, re, zipfile
from html.parser import HTMLParser
from http_support import ERPHttpFixture

class MarksFields(HTMLParser):
    def __init__(self, body):
        super().__init__(); self.active=False; self.fields={}; self.feed(body)
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='fieldset' and a.get('id')=='marksFields': self.active='disabled' not in a
        if self.active and tag=='input' and a.get('name') and 'disabled' not in a and a.get('type')!='checkbox':
            self.fields[a['name']]=a.get('value','')
    def handle_endtag(self,tag):
        if tag=='fieldset': self.active=False

with ERPHttpFixture() as app:
    f=app.fixture; cls=f['class']; own=f['subject']
    other=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND subject_id<>? LIMIT 1',(cls,own)).fetchone()[0]
    for subject in [own,other]:
        app.db.execute("INSERT INTO specification_grids(school_id,class_id,subject_id,name,config_json) VALUES(1,?,?,?,'{}')",(cls,subject,f'Scope grid {subject}'))
        app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,type,question_text) VALUES(1,?,?,'Scope chapter','Short',?)",(cls,subject,f'Scope question {subject}'))
    app.db.commit(); app.login('teacher')
    for path,table,key in [('/modules/learning/specification_grids.php','specification_grids','grid_id'),('/modules/learning/questions.php','questions','question_id')]:
        own_id=app.db.execute(f'SELECT id FROM {table} WHERE subject_id=? ORDER BY id DESC',(own,)).fetchone()[0]
        other_id=app.db.execute(f'SELECT id FROM {table} WHERE subject_id=? ORDER BY id DESC',(other,)).fetchone()[0]
        before=app.snapshot(table)
        app.request(f'{path}?delete={other_id}',{'action':'edit',key:own_id,'class_id':cls,'subject_id':own,'name':'Forged','chapter':'Scope chapter','type':'Short','question_text':'Forged'},status=422)
        assert app.snapshot(table)==before,'Mixed actions must not change either record'
    app.request(f'/modules/learning/questions.php?action_get_chapters=1&class_id={cls}&subject_id={other}',{'class_id':cls,'subject_id':own},status=422)

    # 40 students x 8 subjects used to exceed max_input_vars and silently save nothing.
    subjects=[r[0] for r in app.db.execute('SELECT DISTINCT subject_id FROM class_subjects WHERE class_id=? ORDER BY subject_id LIMIT 8',(cls,))]
    assert len(subjects)==8
    for sub in subjects:
        app.db.execute('INSERT OR IGNORE INTO exam_subjects(exam_id,class_id,subject_id,theory_full,theory_pass,practical_full,practical_pass,credit_hours,is_optional) VALUES(?,?,?,75,26.25,25,10,4,0)',(f['exam'],cls,sub))
    for n in range(39):
        app.db.execute("INSERT INTO students(student_id,academic_year_id,class_id,section_id,first_name_en,last_name_en,gender,school_id,status,roll_number) VALUES(?,1,?,?,?,'Review','Male',1,'active',?)",(f'REVIEW-{n:02d}',cls,f['section'],f'Learner{n:02d}',n+1))
    app.db.commit(); app.login('school_admin')
    path=f'/modules/results/marks_entry.php?exam={f["exam"]}&class={cls}'
    seen=set()
    for page in range(1,5):
        body=app.request(path+f'&page={page}'); fields=MarksFields(body).fields
        assert len(fields)<950 and fields.get('marks_complete')=='1'
        ids=set()
        for name in list(fields):
            match=re.fullmatch(r'marks\[(\d+)\]\[(\d+)\]\[(\w+)\]',name)
            if match:
                ids.add(int(match[1])); fields[name]={'theory':'50','participation':'3','project_work':'12','previous_exam':'5'}.get(match[3],'0')
        assert len(ids)==10 and not seen.intersection(ids);seen.update(ids)
        app.request(path+f'&page={page}',fields)
    assert len(seen)==40
    assert app.db.execute('SELECT COUNT(*) FROM marks WHERE exam_id=?',(f['exam'],)).fetchone()[0]==320
    before=app.snapshot('marks');fields.pop('marks_complete')
    body=app.request(path,fields)
    assert 'Nothing was saved' in body and app.snapshot('marks')==before
    fields=MarksFields(app.request(path+'&student_search=REVIEW-17')).fields
    assert len({re.search(r'marks\[(\d+)\]',name)[1] for name in fields if name.startswith('marks[')})==1

    app.request('/modules/accounting/settings.php',{'action':'period','name':'Review 2026','start_date':'2026-01-01','end_date':'2026-12-31'})
    fee={'action':'item','name':'Review monthly fee','code':'REVIEW-HTTP','kind':'fee','frequency':'monthly','rate':'100','is_active':1,'discount_eligible':1}
    app.request('/modules/accounting/fees.php',fee)
    item=app.db.execute("SELECT id FROM ac_items WHERE code='REVIEW-HTTP'").fetchone()[0]
    app.request('/modules/accounting/fees.php',{'action':'rule','item_id':item,'academic_year_id':1,'class_id':cls,'amount':'100','effective_from':'2026-10-01'})
    assert app.db.execute('SELECT COUNT(*) FROM ac_fee_rules WHERE item_id=?',(item,)).fetchone()[0]==1,'Fee assignment really saves'
    app.db.execute("UPDATE students SET admission_date='2026-01-01' WHERE school_id=1");app.db.commit()
    filters={'action':'preview','academic_year_id':1,'class_id':cls,'student_id':f['student'],'billing_calendar':'AD','billing_period':'2026-10','frequency':'monthly'}
    body=app.request('/modules/accounting/generate.php',filters)
    token=re.search(r'name="preview_token" value="([^"]+)"',body)[1]
    app.request('/modules/accounting/generate.php',{**filters,'action':'generate','confirm':1,'preview_token':token})
    assert app.db.execute('SELECT COUNT(*) FROM ac_charge_posts').fetchone()[0]==1

    # A million-rupee category report must work in all three output formats.
    bill={'action':'issue','student_id':f['student'],'bill_date':'2026-10-01','due_date':'2026-10-31','items[0][particular]':'Large report fee','items[0][rate]':'1000000','idempotency_key':'review-http-large-bill'}
    app.request('/modules/accounting/create_bill.php',bill)
    invoice=app.db.execute('SELECT id FROM ac_invoices WHERE idempotency_key=?',(bill['idempotency_key'],)).fetchone()[0]
    for kind in ['cash','bank']:
        app.db.execute("INSERT INTO ac_accounts(school_id,code,name,type,cash_bank_kind) VALUES(1,?,?,'asset',?)",(f'REVIEW-{kind}',f'Review custom {kind}',kind))
        account=app.db.execute('SELECT last_insert_rowid()').fetchone()[0];app.db.commit()
        app.request('/modules/accounting/settings.php',{'action':'method','name':f'Review {kind}','account_id':account,'is_active':1})
    cash=app.db.execute("SELECT id FROM ac_methods WHERE name='Review cash'").fetchone()[0]
    bank=app.db.execute("SELECT id FROM ac_methods WHERE name='Review bank'").fetchone()[0]
    payment={'student_id':f['student'],'method_id':cash,'payment_date':'2026-10-02','amount':'1000000','allocations['+str(invoice)+']':'1000000','idempotency_key':'review-http-large-payment'}
    app.request('/modules/accounting/payments.php',payment)
    pid=app.db.execute('SELECT id FROM ac_payments WHERE idempotency_key=?',(payment['idempotency_key'],)).fetchone()[0]
    report='/modules/accounting/reports.php?type=category&from=2026-10-01&to=2026-10-31'
    assert '1000000.00' in app.request(report)
    assert '1000000.00' in app.request(report+'&export=csv')
    _,xlsx=app.request_bytes(report+'&export=xlsx')
    with zipfile.ZipFile(io.BytesIO(xlsx)) as archive:
        assert '1000000.00' in archive.read('xl/worksheets/sheet1.xml').decode()
    app.request('/modules/accounting/payments.php',{'student_id':f['student'],'method_id':bank,'payment_date':'2026-10-03','amount':'10','allow_advance':1,'reference':'SYNTHETIC-BANK-REF','externally_confirmed':1,'idempotency_key':'review-http-bank-payment'})
    for kind in ['cash','bank']:
        assert f'Review custom {kind}' in app.request(f'/modules/accounting/reports.php?type={kind}&from=2026-10-01&to=2026-10-31')
    receipt=f'/modules/accounting/receipt.php?id={pid}';reverse={'action':'reverse','date':'2026-10-04','reason':'Synthetic receipt correction','confirm':1}
    app.request(receipt,reverse,status=419,csrf=False)
    for role in ['cashier','accountant','auditor']:
        app.login(role);app.request(receipt,reverse,status=403)
    app.login('school_admin');body=app.request(receipt,reverse)
    assert 'REVERSED PAYMENT' in body and app.db.execute('SELECT status FROM ac_payments WHERE id=?',(pid,)).fetchone()[0]=='reversed'
    print(f'Review HTTP passed: {app.count} requests; mixed-action security, 320 marks, fee assignment, reviewed billing, reports, custom cashbooks and reversal roles.')
