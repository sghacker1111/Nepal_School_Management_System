<?php
require __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f,'directory'=>$directory]=erpTestFixture();
require __DIR__.'/../includes/accounting_service.php';
function financeSnapshot(PDO $db):array {
    $result=[];foreach(['ac_settings','ac_accounts','ac_methods','ac_invoices','ac_invoice_items','ac_journals','ac_journal_lines'] as $table)$result[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();return $result;
}
$before=financeSnapshot($db);new SchoolAccounting($db,1,$f['ids']['auditor']);
erpEqual(financeSnapshot($db),$before,'Auditor construction creates no records');
new SchoolAccounting($db,1,(int)$db->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn(),true);
erpEqual(financeSnapshot($db),$before,'Preview construction creates no records');
erpEqual(defined('SCHOOL_SCHEMA_VERSION')?SCHOOL_SCHEMA_VERSION:null,8,'Schema version contract');
erpEqual($db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn(),'8','Fresh schema8');
foreach(['admission_date','withdrawal_date','academic_stream'] as $field)erpCheck(in_array($field,dbColumns($db,'students'),true),'Student field '.$field);
erpEqual(schoolRecord($db,'students',$f['student'],1)['admission_date'],null,'Imported admission date stays unknown');
foreach(['ac_charge_posts','ac_generation_previews','ac_credit_items','ac_credit_refunds','ac_fine_policies','ac_fine_posts','ac_expense_attachments','ac_period_close_snapshots'] as $table)erpCheck(count(dbColumns($db,$table))>0,'Schema table '.$table);
$db->exec("INSERT INTO ac_periods(school_id,name,start_date,end_date) VALUES (1,'Test 2026','2026-01-01','2026-12-31')");$period=(int)$db->lastInsertId();
$account=(int)$db->query("SELECT id FROM ac_accounts WHERE school_id=1 AND control_key='cash'")->fetchColumn();
$equity=(int)$db->query("SELECT id FROM ac_accounts WHERE school_id=1 AND control_key='equity'")->fetchColumn();
$db->prepare("INSERT INTO ac_journals(school_id,number,posting_date,period_id,reference_type,reference_id,description,created_by) VALUES(1,'TEST-PRESERVE','2026-01-01',?,'test',1,'Synthetic existing journal',?)")->execute([$period,$f['ids']['school_admin']]);$journal=(int)$db->lastInsertId();
$db->prepare('INSERT INTO ac_journal_lines(journal_id,account_id,debit_minor,credit_minor) VALUES (?,?,12345,0),(?,?,0,12345)')->execute([$journal,$account,$journal,$equity]);
$snap=financeSnapshot($db);$classCount=(int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn();
foreach(['6','7'] as $version){
    $copy=$directory.'/upgrade-'.$version.'.sqlite';$db->exec('VACUUM INTO '.$db->quote($copy));
    $copied=new PDO('sqlite:'.$copy);$copied->exec("UPDATE app_meta SET meta_value='$version' WHERE meta_key='workflow_schema'");$copied=null;
    $envPath=getenv('ERP_DB_PATH');putenv('ERP_DB_PATH='.$copy);
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/upgrade_test.php'),$output,$rc);putenv('ERP_DB_PATH='.$envPath);
    erpEqual($rc,0,'Version'.$version.' copy upgrade');
    $copied=new PDO('sqlite:'.$copy,null,null,[PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);erpEqual(financeSnapshot($copied),$snap,'Version'.$version.' IDs and amounts preserved');$copied=null;
}
migrateSchoolSchema($db);migrateSchoolExtensions($db);provisionSchoolAccounting($db,1);
erpEqual(financeSnapshot($db),$snap,'Repeated migration/provision preserves money and IDs');
erpEqual((int)$db->query('SELECT COUNT(*) FROM classes')->fetchColumn(),$classCount,'No duplicate ERP masters');
$oldPath=getenv('ERP_DB_PATH');$legacy=$directory.'/genuine7.sqlite';putenv('ERP_DB_PATH='.$legacy);
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/schema7_fixture.php'),$legacyOutput,$legacyRc);erpEqual($legacyRc,0,'Genuine version7 fixture');
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/upgrade_test.php'),$legacyOutput,$legacyRc);putenv('ERP_DB_PATH='.$oldPath);erpEqual($legacyRc,0,'Genuine version7 upgrade');
$legacyDb=new PDO('sqlite:'.$legacy);
erpEqual($legacyDb->query('SELECT id,total_minor FROM ac_invoices')->fetchAll(PDO::FETCH_NUM),[[21,12345]],'Old invoice ID/total preserved');
erpEqual($legacyDb->query('SELECT id,invoice_id,total_minor FROM ac_invoice_items')->fetchAll(PDO::FETCH_NUM),[[31,21,12345]],'Old item IDs/total preserved');
erpEqual($legacyDb->query('SELECT id,reference_id FROM ac_journals')->fetchAll(PDO::FETCH_NUM),[[41,21]],'Old source journal preserved');
erpEqual($legacyDb->query('SELECT id,account_id,debit_minor,credit_minor FROM ac_journal_lines ORDER BY id')->fetchAll(PDO::FETCH_NUM),[[51,1,12345,0],[52,2,0,12345]],'Old journal money preserved');$legacyDb=null;
$db->exec("INSERT INTO schools(name_en) VALUES ('Provision Test')");$newSchool=(int)$db->lastInsertId();provisionSchool($db,$newSchool);
erpEqual((int)$db->query('SELECT COUNT(*) FROM ac_settings WHERE school_id='.$newSchool)->fetchColumn(),1,'New school finance provisions');
erpEqual((int)$db->query('SELECT COUNT(*) FROM ac_journals WHERE school_id='.$newSchool)->fetchColumn(),0,'Provisioning invents no transactions');
$db->exec("UPDATE app_meta SET meta_value='999' WHERE meta_key='workflow_schema'");
$snap=financeSnapshot($db);erpReject(fn()=>migrateSchoolExtensions($db),'Unsupported future extension rejected');erpReject(fn()=>migrateSchoolSchema($db),'Unsupported future core rejected');
erpEqual($db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn(),'999','Future schema not downgraded');erpEqual(financeSnapshot($db),$snap,'Future-schema rejection does not write finance');
echo 'Accounting schema passed: '.$GLOBALS['erp_checks']." checks\n";
