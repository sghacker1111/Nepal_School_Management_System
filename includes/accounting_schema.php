<?php
/** Additive, portable schema. Money is exact integer paisa, never binary floating point. */
function migrateAccountingSchema(PDO $db): void {
    $pk=DB_TYPE==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':'INT AUTO_INCREMENT PRIMARY KEY';
    $school='school_id INTEGER NOT NULL';
    $tables=[
        'ac_settings'=>'school_id INTEGER PRIMARY KEY,pan_number VARCHAR(50),tax_registered INTEGER DEFAULT 0,invoice_prefix VARCHAR(30) DEFAULT \'INV\',receipt_prefix VARCHAR(30) DEFAULT \'RCP\',due_days INTEGER DEFAULT 30,discount_approval_minor BIGINT DEFAULT 0,prorate INTEGER DEFAULT 0,notification_templates TEXT,FOREIGN KEY(school_id) REFERENCES schools(id)',
        'ac_periods'=>"id $pk,$school,name VARCHAR(100) NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,is_closed INTEGER NOT NULL DEFAULT 0,closed_by INTEGER,UNIQUE(school_id,name)",
        'ac_accounts'=>"id $pk,$school,code VARCHAR(30) NOT NULL,name VARCHAR(120) NOT NULL,type VARCHAR(20) NOT NULL,control_key VARCHAR(30),is_active INTEGER DEFAULT 1,UNIQUE(school_id,code),UNIQUE(school_id,control_key)",
        'ac_sequences'=>'school_id INTEGER NOT NULL,kind VARCHAR(20) NOT NULL,year_key VARCHAR(20) NOT NULL,next_number INTEGER NOT NULL,PRIMARY KEY(school_id,kind,year_key),FOREIGN KEY(school_id) REFERENCES schools(id)',
        'ac_categories'=>"id $pk,$school,name VARCHAR(120) NOT NULL,kind VARCHAR(20) DEFAULT 'fee',is_active INTEGER DEFAULT 1,UNIQUE(school_id,name,kind)",
        'ac_items'=>"id $pk,$school,code VARCHAR(50) NOT NULL,name VARCHAR(200) NOT NULL,name_np VARCHAR(200),category_id INTEGER,description TEXT,kind VARCHAR(20) DEFAULT 'fee',unit VARCHAR(30) DEFAULT 'item',rate_minor BIGINT NOT NULL DEFAULT 0,frequency VARCHAR(20) DEFAULT 'manual',income_account_id INTEGER NOT NULL,tax_basis_points INTEGER DEFAULT 0,discount_eligible INTEGER DEFAULT 1,fine_eligible INTEGER DEFAULT 0,is_active INTEGER DEFAULT 1,created_by INTEGER,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,code),FOREIGN KEY(category_id) REFERENCES ac_categories(id),FOREIGN KEY(income_account_id) REFERENCES ac_accounts(id)",
        'ac_fee_rules'=>"id $pk,$school,item_id INTEGER NOT NULL,academic_year_id INTEGER NOT NULL,class_id INTEGER,section_id INTEGER,student_id INTEGER,exam_id INTEGER,subject_id INTEGER,stream VARCHAR(100),amount_minor BIGINT NOT NULL,discount_minor BIGINT DEFAULT 0,discount_basis_points INTEGER DEFAULT 0,is_exempt INTEGER DEFAULT 0,is_optional INTEGER DEFAULT 0,effective_from DATE NOT NULL,effective_to DATE,created_by INTEGER,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(item_id) REFERENCES ac_items(id),FOREIGN KEY(academic_year_id) REFERENCES academic_years(id),FOREIGN KEY(class_id) REFERENCES classes(id),FOREIGN KEY(section_id) REFERENCES sections(id),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(exam_id) REFERENCES exams(id),FOREIGN KEY(subject_id) REFERENCES subjects(id)",
        'ac_scholarships'=>"id $pk,$school,student_id INTEGER NOT NULL,item_id INTEGER,academic_year_id INTEGER NOT NULL,name VARCHAR(150) NOT NULL,amount_minor BIGINT DEFAULT 0,basis_points INTEGER DEFAULT 0,effective_from DATE NOT NULL,effective_to DATE,approved_by INTEGER NOT NULL,remarks TEXT,FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(item_id) REFERENCES ac_items(id),FOREIGN KEY(academic_year_id) REFERENCES academic_years(id)",
        'ac_subscriptions'=>"id $pk,$school,item_id INTEGER NOT NULL,student_id INTEGER,customer_name VARCHAR(200) NOT NULL,frequency VARCHAR(20) NOT NULL,amount_minor BIGINT NOT NULL,start_date DATE NOT NULL,end_date DATE,next_date DATE NOT NULL,status VARCHAR(20) DEFAULT 'active',renewal INTEGER DEFAULT 0,created_by INTEGER,FOREIGN KEY(item_id) REFERENCES ac_items(id),FOREIGN KEY(student_id) REFERENCES students(id)",
        'ac_invoices'=>"id $pk,$school,number VARCHAR(100),academic_year_id INTEGER,class_id INTEGER,section_id INTEGER,student_id INTEGER,customer_type VARCHAR(20) NOT NULL,customer_name VARCHAR(200) NOT NULL,guardian_name VARCHAR(200),phone VARCHAR(50),address TEXT,description TEXT,bill_date DATE NOT NULL,due_date DATE NOT NULL,billing_period VARCHAR(30),status VARCHAR(20) NOT NULL DEFAULT 'draft',subtotal_minor BIGINT NOT NULL,discount_minor BIGINT NOT NULL DEFAULT 0,tax_minor BIGINT NOT NULL DEFAULT 0,total_minor BIGINT NOT NULL,idempotency_key VARCHAR(150) NOT NULL,request_hash VARCHAR(64) NOT NULL,created_by INTEGER,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,issued_at TIMESTAMP NULL,UNIQUE(school_id,number),UNIQUE(school_id,idempotency_key),FOREIGN KEY(academic_year_id) REFERENCES academic_years(id),FOREIGN KEY(class_id) REFERENCES classes(id),FOREIGN KEY(section_id) REFERENCES sections(id),FOREIGN KEY(student_id) REFERENCES students(id)",
        'ac_invoice_items'=>"id $pk,invoice_id INTEGER NOT NULL,item_id INTEGER,particular VARCHAR(200) NOT NULL,quantity_milli BIGINT NOT NULL,rate_minor BIGINT NOT NULL,gross_minor BIGINT NOT NULL,discount_minor BIGINT NOT NULL,tax_basis_points INTEGER NOT NULL DEFAULT 0,tax_minor BIGINT NOT NULL,total_minor BIGINT NOT NULL,income_account_id INTEGER NOT NULL,category_id INTEGER,fine_eligible INTEGER DEFAULT 0,FOREIGN KEY(invoice_id) REFERENCES ac_invoices(id),FOREIGN KEY(item_id) REFERENCES ac_items(id),FOREIGN KEY(income_account_id) REFERENCES ac_accounts(id),FOREIGN KEY(category_id) REFERENCES ac_categories(id)",
        'ac_methods'=>"id $pk,$school,name VARCHAR(100) NOT NULL,account_id INTEGER NOT NULL,is_active INTEGER DEFAULT 1,requires_reference INTEGER DEFAULT 0,UNIQUE(school_id,name),FOREIGN KEY(account_id) REFERENCES ac_accounts(id)",
        'ac_payments'=>"id $pk,$school,number VARCHAR(100) NOT NULL,student_id INTEGER,customer_name VARCHAR(200) NOT NULL,payment_date DATE NOT NULL,amount_minor BIGINT NOT NULL,method_id INTEGER NOT NULL,account_id INTEGER NOT NULL,reference VARCHAR(150),remarks TEXT,status VARCHAR(20) NOT NULL DEFAULT 'posted',idempotency_key VARCHAR(150) NOT NULL,request_hash VARCHAR(64) NOT NULL,received_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,number),UNIQUE(school_id,idempotency_key),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(method_id) REFERENCES ac_methods(id),FOREIGN KEY(account_id) REFERENCES ac_accounts(id)",
        'ac_allocations'=>"id $pk,payment_id INTEGER NOT NULL,invoice_id INTEGER NOT NULL,amount_minor BIGINT NOT NULL,FOREIGN KEY(payment_id) REFERENCES ac_payments(id),FOREIGN KEY(invoice_id) REFERENCES ac_invoices(id),UNIQUE(payment_id,invoice_id)",
        'ac_credits'=>"id $pk,$school,number VARCHAR(100) NOT NULL,invoice_id INTEGER NOT NULL,credit_date DATE NOT NULL,amount_minor BIGINT NOT NULL,reason TEXT NOT NULL,kind VARCHAR(20) NOT NULL DEFAULT 'credit',created_by INTEGER NOT NULL,idempotency_key VARCHAR(150) NOT NULL,UNIQUE(school_id,number),UNIQUE(school_id,idempotency_key),FOREIGN KEY(invoice_id) REFERENCES ac_invoices(id)",
        'ac_advance_entries'=>"id $pk,$school,student_id INTEGER NOT NULL,payment_id INTEGER,invoice_id INTEGER,amount_minor BIGINT NOT NULL,entry_date DATE NOT NULL,kind VARCHAR(20) NOT NULL,reference_key VARCHAR(150) NOT NULL,created_by INTEGER NOT NULL,UNIQUE(school_id,reference_key),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(payment_id) REFERENCES ac_payments(id),FOREIGN KEY(invoice_id) REFERENCES ac_invoices(id)",
        'ac_refunds'=>"id $pk,$school,student_id INTEGER NOT NULL,amount_minor BIGINT NOT NULL,refund_date DATE NOT NULL,account_id INTEGER NOT NULL,reason TEXT NOT NULL,approved_by INTEGER NOT NULL,idempotency_key VARCHAR(150) NOT NULL,UNIQUE(school_id,idempotency_key),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(account_id) REFERENCES ac_accounts(id)",
        'ac_expenses'=>"id $pk,$school,number VARCHAR(100) NOT NULL,title VARCHAR(200) NOT NULL,payee VARCHAR(200),expense_date DATE NOT NULL,amount_minor BIGINT NOT NULL,expense_account_id INTEGER NOT NULL,payment_account_id INTEGER NOT NULL,category_id INTEGER,reference VARCHAR(150),remarks TEXT,attachment VARCHAR(200),status VARCHAR(20) DEFAULT 'draft',created_by INTEGER NOT NULL,approved_by INTEGER,idempotency_key VARCHAR(150) NOT NULL,UNIQUE(school_id,number),UNIQUE(school_id,idempotency_key),FOREIGN KEY(expense_account_id) REFERENCES ac_accounts(id),FOREIGN KEY(payment_account_id) REFERENCES ac_accounts(id),FOREIGN KEY(category_id) REFERENCES ac_categories(id)",
        'ac_journals'=>"id $pk,$school,number VARCHAR(100) NOT NULL,posting_date DATE NOT NULL,period_id INTEGER NOT NULL,reference_type VARCHAR(30) NOT NULL,reference_id INTEGER NOT NULL,description TEXT NOT NULL,status VARCHAR(20) DEFAULT 'posted',created_by INTEGER NOT NULL,reversal_of INTEGER,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,number),UNIQUE(school_id,reference_type,reference_id),FOREIGN KEY(period_id) REFERENCES ac_periods(id),FOREIGN KEY(reversal_of) REFERENCES ac_journals(id)",
        'ac_journal_lines'=>"id $pk,journal_id INTEGER NOT NULL,account_id INTEGER NOT NULL,student_id INTEGER,debit_minor BIGINT DEFAULT 0,credit_minor BIGINT DEFAULT 0,FOREIGN KEY(journal_id) REFERENCES ac_journals(id),FOREIGN KEY(account_id) REFERENCES ac_accounts(id),FOREIGN KEY(student_id) REFERENCES students(id)",
        'ac_runs'=>"id $pk,$school,kind VARCHAR(20) NOT NULL,period_key VARCHAR(30) NOT NULL,details TEXT NOT NULL,created_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        'ac_audit'=>"id $pk,$school,actor_id INTEGER NOT NULL,action VARCHAR(100) NOT NULL,entity VARCHAR(30) NOT NULL,record_id INTEGER,old_data TEXT,new_data TEXT,reason TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach($tables as $name=>$definition){if(!in_array($name,['ac_settings','ac_sequences','ac_invoice_items','ac_allocations','ac_journal_lines'],true))$definition.=',FOREIGN KEY(school_id) REFERENCES schools(id)';$db->exec("CREATE TABLE IF NOT EXISTS $name ($definition)".(DB_TYPE==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));}
    foreach(['ac_invoices'=>'school_id,status,bill_date,student_id','ac_payments'=>'school_id,payment_date,student_id','ac_fee_rules'=>'school_id,academic_year_id,item_id,effective_from','ac_journals'=>'school_id,posting_date','ac_journal_lines'=>'account_id,journal_id','ac_advance_entries'=>'school_id,student_id','ac_audit'=>'school_id,created_at'] as $table=>$fields){
        $name='idx_'.$table.'_scope';
        if(DB_TYPE==='sqlite')$db->exec("CREATE INDEX IF NOT EXISTS $name ON $table($fields)");
        elseif(!$db->query("SHOW INDEX FROM $table WHERE Key_name='$name'")->fetch())$db->exec("CREATE INDEX $name ON $table($fields)");
    }
}
function migrateSchoolExtensions(PDO $db):void {
    $version=$db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn();
    if(!ctype_digit((string)$version)||(int)$version>SCHOOL_SCHEMA_VERSION)throw new InvalidArgumentException('This database schema requires a newer application; no downgrade was performed.');
    if((int)$version===SCHOOL_SCHEMA_VERSION)return;
    require_once __DIR__.'/curriculum_catalog.php';
    if((int)$version<13)migrateSharedCurriculum($db);
    require_once __DIR__.'/routine_schema.php';
    migrateRoutineProfiles($db);
    require_once __DIR__.'/exam_template_schema.php';
    migrateExamTemplates($db);
    // Scheduling periods are independent of CDC grading credits. Existing rows stay intact.
    if(!in_array('weekly_periods',dbColumns($db,'class_subjects'),true))$db->exec('ALTER TABLE class_subjects ADD COLUMN weekly_periods INTEGER NULL');
    if(!in_array('exam_coordinator_name',dbColumns($db,'schools'),true))$db->exec('ALTER TABLE schools ADD COLUMN exam_coordinator_name VARCHAR(100) NULL');
    require_once __DIR__.'/packages.php';
    if((int)$version>=8){
        migrateSchoolSubscriptionSchema($db);
        $db->prepare('UPDATE app_meta SET meta_value=? WHERE meta_key=?')->execute([(string)SCHOOL_SCHEMA_VERSION,'workflow_schema']);
        return;
    }
    if((int)$version<7){
        foreach(['subjects','class_subjects','exam_subjects'] as $table)if(!in_array('internal_components',dbColumns($db,$table),true))$db->exec("ALTER TABLE $table ADD COLUMN internal_components TEXT");
        if(DB_TYPE==='mysql')$db->exec("ALTER TABLE users MODIFY role VARCHAR(50) NOT NULL");
        migrateAccountingSchema($db);
    }
    $columns=[
        'students'=>['admission_date'=>'DATE','withdrawal_date'=>'DATE','academic_stream'=>'VARCHAR(100)'],
        'ac_accounts'=>['cash_bank_kind'=>'VARCHAR(10)'],
        'ac_invoices'=>['revision'=>'INTEGER NOT NULL DEFAULT 1','snapshot_json'=>'TEXT','opening_reference'=>'VARCHAR(150)'],
        'ac_expenses'=>['revision'=>'INTEGER NOT NULL DEFAULT 1'],
        'ac_credits'=>['receivable_minor'=>'BIGINT NOT NULL DEFAULT 0','refund_minor'=>'BIGINT NOT NULL DEFAULT 0','tax_minor'=>'BIGINT NOT NULL DEFAULT 0','request_hash'=>'VARCHAR(64)'],
        'ac_subscriptions'=>['billing_calendar'=>"VARCHAR(2) NOT NULL DEFAULT 'AD'",'anchor_day'=>'INTEGER']
    ];
    foreach($columns as $table=>$fields){$existing=dbColumns($db,$table);foreach($fields as $name=>$type)if(!in_array($name,$existing,true))$db->exec("ALTER TABLE $table ADD COLUMN $name $type");}
    $pk=DB_TYPE==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':'INT AUTO_INCREMENT PRIMARY KEY';
    $tables=[
        'ac_charge_posts'=>"student_id INTEGER NOT NULL,item_id INTEGER,cycle_key VARCHAR(100) NOT NULL,business_key VARCHAR(190) NOT NULL,invoice_id INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,business_key),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(item_id) REFERENCES ac_items(id),FOREIGN KEY(invoice_id) REFERENCES ac_invoices(id)",
        'ac_generation_previews'=>"kind VARCHAR(30) NOT NULL,token VARCHAR(64) NOT NULL,actor_id INTEGER NOT NULL,input_json TEXT NOT NULL,payload_json TEXT NOT NULL,fingerprint VARCHAR(64) NOT NULL,expires_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,token)",
        'ac_credit_items'=>"credit_id INTEGER NOT NULL,invoice_item_id INTEGER NOT NULL,amount_minor BIGINT NOT NULL,tax_minor BIGINT NOT NULL DEFAULT 0,FOREIGN KEY(credit_id) REFERENCES ac_credits(id),FOREIGN KEY(invoice_item_id) REFERENCES ac_invoice_items(id),UNIQUE(credit_id,invoice_item_id)",
        'ac_credit_refunds'=>"credit_id INTEGER NOT NULL,number VARCHAR(100) NOT NULL,amount_minor BIGINT NOT NULL,refund_date DATE NOT NULL,account_id INTEGER NOT NULL,reason TEXT NOT NULL,approved_by INTEGER NOT NULL,idempotency_key VARCHAR(150) NOT NULL,request_hash VARCHAR(64) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,number),UNIQUE(school_id,idempotency_key),FOREIGN KEY(credit_id) REFERENCES ac_credits(id),FOREIGN KEY(account_id) REFERENCES ac_accounts(id)",
        'ac_fine_policies'=>"name VARCHAR(120) NOT NULL,grace_days INTEGER NOT NULL DEFAULT 0,amount_minor BIGINT NOT NULL DEFAULT 0,basis_points INTEGER NOT NULL DEFAULT 0,behavior VARCHAR(20) NOT NULL DEFAULT 'once',cap_minor BIGINT NOT NULL DEFAULT 0,item_id INTEGER,class_id INTEGER,student_id INTEGER,academic_year_id INTEGER,effective_from DATE NOT NULL,effective_to DATE,income_account_id INTEGER NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,created_by INTEGER NOT NULL,FOREIGN KEY(item_id) REFERENCES ac_items(id),FOREIGN KEY(class_id) REFERENCES classes(id),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(academic_year_id) REFERENCES academic_years(id),FOREIGN KEY(income_account_id) REFERENCES ac_accounts(id)",
        'ac_fine_posts'=>"policy_id INTEGER NOT NULL,source_invoice_id INTEGER NOT NULL,fine_invoice_id INTEGER NOT NULL,amount_minor BIGINT NOT NULL,as_of DATE NOT NULL,business_key VARCHAR(190) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE(school_id,business_key),FOREIGN KEY(policy_id) REFERENCES ac_fine_policies(id),FOREIGN KEY(source_invoice_id) REFERENCES ac_invoices(id),FOREIGN KEY(fine_invoice_id) REFERENCES ac_invoices(id)",
        'ac_expense_attachments'=>"expense_id INTEGER NOT NULL,private_name VARCHAR(100) NOT NULL,original_name VARCHAR(200) NOT NULL,mime_type VARCHAR(100) NOT NULL,size_bytes BIGINT NOT NULL,sha256 VARCHAR(64) NOT NULL,created_by INTEGER NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(expense_id) REFERENCES ac_expenses(id)",
        'ac_period_close_snapshots'=>"period_id INTEGER NOT NULL,payload_json TEXT NOT NULL,fingerprint VARCHAR(64) NOT NULL,closed_by INTEGER NOT NULL,reason TEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(period_id) REFERENCES ac_periods(id)"
    ];
    foreach($tables as $table=>$definition)$db->exec("CREATE TABLE IF NOT EXISTS $table (id $pk,school_id INTEGER NOT NULL,$definition,FOREIGN KEY(school_id) REFERENCES schools(id))".(DB_TYPE==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''));
    accountingIndex($db,'ac_invoices','idx_ac_opening_reference','school_id,opening_reference',true);
    accountingIndex($db,'ac_generation_previews','idx_ac_preview_actor','school_id,actor_id,kind,expires_at');
    accountingIndex($db,'ac_credit_refunds','idx_ac_credit_refund','school_id,credit_id,refund_date');
    accountingIndex($db,'ac_fine_posts','idx_ac_fine_invoice','school_id,source_invoice_id,policy_id');
    // Version7 credits were allowed only against unpaid dues; preserve their AR treatment.
    $db->exec('UPDATE ac_credits SET receivable_minor=amount_minor WHERE request_hash IS NULL AND receivable_minor=0 AND refund_minor=0');
    $db->exec('UPDATE ac_subscriptions SET anchor_day=CAST(SUBSTR(start_date,9,2) AS UNSIGNED) WHERE anchor_day IS NULL');
    foreach($db->query('SELECT id FROM schools')->fetchAll(PDO::FETCH_COLUMN) as $school)provisionSchoolAccounting($db,(int)$school);
    migrateSchoolSubscriptionSchema($db);
    $db->prepare('UPDATE app_meta SET meta_value=? WHERE meta_key=?')->execute([(string)SCHOOL_SCHEMA_VERSION,'workflow_schema']);
}
function accountingIndex(PDO $db,string $table,string $name,string $fields,bool $unique=false):void {
    if(DB_TYPE==='sqlite')$db->exec('CREATE '.($unique?'UNIQUE ':'')."INDEX IF NOT EXISTS $name ON $table($fields)");
    elseif(!$db->query("SHOW INDEX FROM $table WHERE Key_name='$name'")->fetch())$db->exec('CREATE '.($unique?'UNIQUE ':'')."INDEX $name ON $table($fields)");
}
/** Metadata provisioning only: no fees, invoices, journals or fabricated balances. */
function provisionSchoolAccounting(PDO $db,int $school):void {
    $q=$db->prepare('SELECT id FROM schools WHERE id=?');$q->execute([$school]);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an existing school for accounting provisioning.');
    $ignore=DB_TYPE==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
    $db->prepare("$ignore INTO ac_settings(school_id) VALUES(?)")->execute([$school]);
    $defaults=[['1000','Cash in hand','asset','cash'],['1010','Bank account','asset','bank'],['1100','Accounts receivable','asset','receivable'],['2000','Student advances','liability','advance'],['2100','Tax payable','liability','tax'],['2200','Expenses payable','liability','payable'],['2300','Credit refunds payable','liability','refund_payable'],['3000','Opening school fund','equity','equity'],['4000','School fee income','income','income'],['4010','Product / service income','income','sales'],['4090','Discounts / waivers','contra_income','discount'],['5000','School operating expense','expense','expense']];
    foreach($defaults as $d)$db->prepare("$ignore INTO ac_accounts(school_id,code,name,type,control_key) VALUES(?,?,?,?,?)")->execute([$school,...$d]);
    $db->prepare("UPDATE ac_accounts SET cash_bank_kind=control_key WHERE school_id=? AND control_key IN ('cash','bank') AND cash_bank_kind IS NULL")->execute([$school]);
    $q=$db->prepare("SELECT id,control_key FROM ac_accounts WHERE school_id=? AND control_key IN ('cash','bank')");$q->execute([$school]);$controls=array_column($q->fetchAll(),'id','control_key');
    foreach(['Cash'=>'cash','Bank Transfer'=>'bank','Cheque'=>'bank','ConnectIPS'=>'bank','eSewa'=>'bank','Khalti'=>'bank','Fonepay'=>'bank','Mobile Banking'=>'bank'] as $name=>$key)$db->prepare("$ignore INTO ac_methods(school_id,name,account_id,requires_reference) VALUES(?,?,?,?)")->execute([$school,$name,$controls[$key],$key==='bank'?1:0]);
}
