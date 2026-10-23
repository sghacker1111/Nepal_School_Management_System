<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('ERP_TEST_MYSQL')!=='1'||getenv('ERP_DB_HOST')!=='127.0.0.1'||!str_starts_with(getenv('ERP_DB_NAME')?:'','erp_migration_test_'))exit(1);
require __DIR__.'/test_support.php';
$action=$argv[1]??'';
if($action==='setup'){
    ['db'=>$db,'fixture'=>$f]=erpTestFixture();
}else{
    require_once __DIR__.'/../config/bootstrap.php';require_once __DIR__.'/../includes/database.php';
    $db=getDB();$f=json_decode($argv[2]??'',true,512,JSON_THROW_ON_ERROR);
}
require_once __DIR__.'/../includes/accounting_service.php';
$ac=new SchoolAccounting($db,1,$f['ids']['school_admin']);
if($action==='setup'){
    $ac->savePeriod(['name'=>'Concurrent synthetic 2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
    $f['invoice']=$ac->createInvoice(['student_id'=>$f['student'],'bill_date'=>'2026-10-01','due_date'=>'2026-10-31','items'=>[['particular'=>'Tuition','rate'=>'2500'],['particular'=>'Exam','rate'=>'500']],'idempotency_key'=>'concurrent-bill'],true);
    $f['method']=(int)$db->query("SELECT id FROM ac_methods WHERE school_id=1 AND name='Cash'")->fetchColumn();
    echo json_encode($f,JSON_THROW_ON_ERROR);exit;
}
$payment=['student_id'=>$f['student'],'method_id'=>$f['method'],'payment_date'=>'2026-10-05','amount'=>'3000.00','allocations'=>[$f['invoice']=>'3000.00'],'idempotency_key'=>'concurrent-payment'];
if($action==='pay'){
    echo "READY\n";flush();if(trim(fgets(STDIN)?:'')!=='GO')exit(1);
    echo json_encode(['payment'=>$ac->recordPayment($payment)],JSON_THROW_ON_ERROR),"\n";exit;
}
if($action!=='verify')exit(1);
erpEqual((int)$ac->record('ac_invoices',$f['invoice'])['total_minor'],300000,'Exact invoice paisa');
$stmt=$db->query("SELECT id FROM ac_payments WHERE school_id=1 AND idempotency_key='concurrent-payment'");$payments=$stmt->fetchAll(PDO::FETCH_COLUMN);
erpEqual(count($payments),1,'One payment for concurrent replay');$pid=(int)$payments[0];
erpEqual((int)$db->query("SELECT COUNT(*) FROM ac_journals WHERE reference_type='payment' AND reference_id=$pid")->fetchColumn(),1,'One journal for concurrent payment');
erpEqual($ac->invoiceBalance($f['invoice']),0,'Invoice exactly settled');erpCheck($ac->reconcile()['ok'],'Concurrent replay reconciles');
$tables=['ac_payments','ac_allocations','ac_journals','ac_journal_lines'];
$snapshot=static function()use($db,$tables):array{$out=[];foreach($tables as $table)$out[$table]=$db->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll();return $out;};
$before=$snapshot();erpReject(fn()=>$ac->recordPayment(array_replace($payment,['amount'=>'2999.99'])),'Changed replay denied');erpEqual($snapshot(),$before,'Changed replay no writes');
$foreign=new SchoolAccounting($db,$f['otherSchool'],$f['otherUser']);$foreign->savePeriod(['name'=>'Other concurrent 2026','start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
$foreignInvoice=$foreign->createInvoice(['student_id'=>$f['otherStudent'],'bill_date'=>'2026-10-01','due_date'=>'2026-10-31','items'=>[['particular'=>'Other fee','rate'=>'100']],'idempotency_key'=>'foreign-concurrent-bill'],true);
$before=$snapshot();erpReject(fn()=>$ac->recordPayment(array_replace($payment,['idempotency_key'=>'foreign-concurrent-allocation','amount'=>'1','allocations'=>[$foreignInvoice=>'1']])),'Cross-school allocation denied');erpEqual($snapshot(),$before,'Cross-school allocation no writes');
echo json_encode(['ok'=>true,'checks'=>$GLOBALS['erp_checks'],'payment_count'=>count($payments),'balance_minor'=>$ac->invoiceBalance($f['invoice'])],JSON_THROW_ON_ERROR);
