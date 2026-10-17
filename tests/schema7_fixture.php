<?php
// Build a genuine pre-extension8 database, not merely a new database with an old label.
if(PHP_SAPI!=='cli'||!getenv('ERP_DB_PATH'))exit(1);
$target=getenv('ERP_DB_PATH');$parent=realpath(dirname($target));
if(!$parent||!str_starts_with(strtolower($parent.DIRECTORY_SEPARATOR),strtolower(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR))||file_exists($target))throw new RuntimeException('Version7 fixture requires a new OS-temp database.');
require __DIR__.'/../config/bootstrap.php';require __DIR__.'/../includes/schema.php';
$db=new PDO('sqlite:'.$target,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__.'/../database_sqlite.sql'));migrateSchoolSchema($db);
require __DIR__.'/../includes/accounting_schema.php';migrateAccountingSchema($db);
foreach(['subjects','class_subjects','exam_subjects'] as $table)$db->exec("ALTER TABLE $table ADD COLUMN internal_components TEXT");
$db->exec("INSERT INTO ac_settings(school_id) VALUES(1)");
$db->exec("INSERT INTO ac_accounts(id,school_id,code,name,type,control_key) VALUES (1,1,'1100','Accounts receivable','asset','receivable'),(2,1,'4000','School fee income','income','income')");
$db->exec("INSERT INTO ac_periods(id,school_id,name,start_date,end_date) VALUES (1,1,'Old 2026','2026-01-01','2026-12-31')");
$db->exec("INSERT INTO ac_invoices(id,school_id,number,customer_type,customer_name,bill_date,due_date,status,subtotal_minor,total_minor,idempotency_key,request_hash,created_by) VALUES (21,1,'OLD-INV-21','external','Synthetic old customer','2026-01-01','2026-01-31','issued',12345,12345,'old-invoice-21','old-hash',1)");
$db->exec("INSERT INTO ac_invoice_items(id,invoice_id,particular,quantity_milli,rate_minor,gross_minor,discount_minor,tax_minor,total_minor,income_account_id) VALUES (31,21,'Synthetic old fee',1000,12345,12345,0,0,12345,2)");
$db->exec("INSERT INTO ac_journals(id,school_id,number,posting_date,period_id,reference_type,reference_id,description,created_by) VALUES (41,1,'OLD-JRN-41','2026-01-01',1,'invoice',21,'Synthetic existing invoice',1)");
$db->exec('INSERT INTO ac_journal_lines(id,journal_id,account_id,debit_minor,credit_minor) VALUES (51,41,1,12345,0),(52,41,2,0,12345)');
$db->exec("UPDATE app_meta SET meta_value='7' WHERE meta_key='workflow_schema'");
echo "Version7 fixture created.\n";
