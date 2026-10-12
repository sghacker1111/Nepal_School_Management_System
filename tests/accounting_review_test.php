<?php
/** Astra findings: disposable financial regression tests, never a production database. */
require __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();
require __DIR__.'/../includes/accounting_service.php';
$ac=new SchoolAccounting($db,1,$f['ids']['school_admin']);
foreach([2026,2027,2028,2029] as $year)$ac->savePeriod(['name'=>"Test $year",'start_date'=>"$year-01-01",'end_date'=>"$year-12-31"]);
$student=$f['student'];$cash=(int)$db->query("SELECT id FROM ac_methods WHERE school_id=1 AND name='Cash'")->fetchColumn();
function acReviewSnapshot(PDO $db):array {$out=[];foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'ac_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table)$out[$table]=$db->query('SELECT * FROM '.$table)->fetchAll();return $out;}
function acReviewReject(PDO $db,callable $fn,string $label):void {$before=acReviewSnapshot($db);erpReject($fn,$label);erpEqual(acReviewSnapshot($db),$before,$label.' leaves every finance table unchanged');}
$receive=['student_id'=>$student,'method_id'=>$cash,'payment_date'=>'2026-10-20','amount'=>'200','allow_advance'=>1,'idempotency_key'=>'review-advance-receive'];
$payment=$ac->recordPayment($receive);
$invoice=$ac->createInvoice(['student_id'=>$student,'bill_date'=>'2026-10-15','due_date'=>'2026-10-31','items'=>[['particular'=>'Test fee','rate'=>'100']],'idempotency_key'=>'review-advance-invoice'],true);
$apply=['invoice_id'=>$invoice,'amount'=>'100','date'=>'2026-10-01','idempotency_key'=>'review-advance-apply'];
acReviewReject($db,fn()=>$ac->applyAdvance($apply),'Apply before invoice denied');
acReviewReject($db,fn()=>$ac->applyAdvance(array_replace($apply,['date'=>'2026-10-16'])),'Apply before receipt denied');
$refund=['student_id'=>$student,'account_id'=>$ac->control('cash'),'amount'=>'100','date'=>'2026-10-01','reason'=>'Test refund','confirm'=>1,'idempotency_key'=>'review-advance-refund'];
acReviewReject($db,fn()=>$ac->refundAdvance($refund),'Refund before receipt denied');
$ac->applyAdvance(array_replace($apply,['date'=>'2026-10-25']));
$ac->recordPayment(array_replace($receive,['payment_date'=>'2026-11-01','idempotency_key'=>'review-future-advance']));
acReviewReject($db,fn()=>$ac->refundAdvance(array_replace($refund,['amount'=>'200','date'=>'2026-10-22'])),'Backdated refund cannot consume a later reserved balance');
acReviewReject($db,fn()=>$ac->reversePayment($payment,'2026-10-22','Backdated reversal',true),'Backdated reversal cannot consume later reserved funds');
$refund['date']='2026-10-22';$refundId=$ac->refundAdvance($refund);erpEqual($ac->refundAdvance($refund),$refundId,'Valid dated refund is replay safe');
erpCheck($ac->reconcile()['ok'],'Dated advances reconcile');
erpEqual(accountingRouteAction('receipt.php',['action'=>'reverse']),'reverse','Receipt reversal action mapped explicitly');
erpEqual(accountingRouteAction('receipt.php',['action'=>'anything']),null,'Unknown receipt action denied');

// Both refund streams are part of the selected dashboard period.
$paid=$ac->createInvoice(['student_id'=>$student,'bill_date'=>'2026-10-20','due_date'=>'2026-10-31','items'=>[['particular'=>'Refundable fee','rate'=>'50']],'idempotency_key'=>'review-paid-invoice'],true);
$ac->recordPayment(['student_id'=>$student,'method_id'=>$cash,'payment_date'=>'2026-10-21','amount'=>'50','allocations'=>[$paid=>'50'],'idempotency_key'=>'review-paid-payment']);
$credit=$ac->creditInvoice(['invoice_id'=>$paid,'amount'=>'50','date'=>'2026-10-22','reason'=>'Test correction','confirm'=>1,'idempotency_key'=>'review-paid-credit']);
$ac->refundCredit(['credit_id'=>$credit,'amount'=>'50','date'=>'2026-10-23','reason'=>'Test payout','account_id'=>$ac->control('cash'),'confirm'=>1,'idempotency_key'=>'review-credit-refund']);
erpEqual($ac->dashboard('2026-10-01','2026-10-31')['refunds'],15000,'Dashboard counts advance and paid-invoice refunds');
erpEqual($ac->dashboard('2026-10-23','2026-10-23')['refunds'],5000,'Refund range respected');

$item=$ac->saveItem(['name'=>'Review service','code'=>'REVIEW-SERVICE','kind'=>'service','frequency'=>'monthly','rate'=>'100','is_active'=>1,'discount_eligible'=>1]);
$subscription=['item_id'=>$item,'customer_name'=>'Synthetic subscriber','frequency'=>'monthly','amount'=>'100','start_date'=>'2026-01-31'];
$sid=$ac->saveSubscription($subscription);$run=$ac->generateSubscriptions('2026-03-31');
erpEqual(count($run['issued']),3,'Three monthly cycles issued');
$dates=$db->query("SELECT bill_date FROM ac_invoices WHERE idempotency_key LIKE 'subscription:$sid:%' ORDER BY bill_date")->fetchAll(PDO::FETCH_COLUMN);
erpEqual($dates,['2026-01-31','2026-02-28','2026-03-31'],'Month-end anchor restored after February');
erpEqual($ac->record('ac_subscriptions',$sid)['next_date'],'2026-04-30','Next short month clipped');
$ac->changeSubscription($sid,'paused','2026-04-01');
$annual=$ac->saveSubscription(array_replace($subscription,['frequency'=>'annual','start_date'=>'2028-02-29']));
$ac->generateSubscriptions('2029-02-28');erpEqual($ac->record('ac_subscriptions',$annual)['next_date'],'2030-02-28','Leap annual cycle anchored');
$ac->changeSubscription($annual,'paused','2029-03-01');
$quarter=$ac->saveSubscription(array_replace($subscription,['frequency'=>'quarterly','start_date'=>'2026-01-31']));
$ac->generateSubscriptions('2026-07-31');erpEqual($ac->record('ac_subscriptions',$quarter)['next_date'],'2026-10-31','Quarterly anchor survives April');
$ac->changeSubscription($quarter,'paused','2026-08-01');

// Deterministic interleaving: cancellation wins at acquisition of the shared posting lock.
$cancel=$ac->saveSubscription(array_replace($subscription,['start_date'=>'2026-09-01']));
$db->exec("CREATE TRIGGER review_cancel BEFORE UPDATE ON ac_settings WHEN NEW.school_id=1 BEGIN UPDATE ac_subscriptions SET status='cancelled' WHERE id=$cancel; END");
$before=(int)$db->query('SELECT COUNT(*) FROM ac_invoices')->fetchColumn();$run=$ac->generateSubscriptions('2026-09-01');
erpEqual(count($run['issued']),0,'Revalidate cancellation after acquiring school lock');erpEqual((int)$db->query('SELECT COUNT(*) FROM ac_invoices')->fetchColumn(),$before,'Cancelled subscription is not billed');
erpEqual($ac->record('ac_subscriptions',$cancel)['status'],'cancelled','Cancellation never reactivated by billing');$db->exec('DROP TRIGGER review_cancel');
$atomic=$ac->saveSubscription(array_replace($subscription,['start_date'=>'2026-09-01']));
$db->exec("CREATE TRIGGER review_fail BEFORE UPDATE OF next_date ON ac_subscriptions WHEN OLD.id=$atomic BEGIN SELECT RAISE(ABORT,'Synthetic cycle failure'); END");
$before=acReviewSnapshot($db);$run=$ac->generateSubscriptions('2026-09-01');erpEqual(count($run['errors']),1,'Cycle update failure reported');erpEqual(acReviewSnapshot($db),$before,'Invoice, journals and cycle roll back together');
$db->exec('DROP TRIGGER review_fail');$ac->changeSubscription($atomic,'cancelled','2026-09-01');

// Monthly rate changes, explicit admission/withdrawal and missing-item-only retries.
$db->prepare("UPDATE students SET admission_date='2026-10-05',withdrawal_date='2026-10-25',created_at='2026-11-01' WHERE id=?")->execute([$student]);
$db->exec('UPDATE ac_settings SET prorate=1 WHERE school_id=1');$ac=new SchoolAccounting($db,1,$f['ids']['school_admin']);
$fee=$ac->saveItem(['name'=>'Segmented tuition','code'=>'REVIEW-FEE','kind'=>'fee','frequency'=>'monthly','rate'=>'3100','is_active'=>1,'discount_eligible'=>1]);
$rule=['item_id'=>$fee,'academic_year_id'=>1,'class_id'=>$f['class'],'amount'=>'3100','effective_from'=>'2026-10-01'];
$ac->saveFeeRule($rule);$ac->saveFeeRule(array_replace($rule,['amount'=>'6200','effective_from'=>'2026-10-16']));
$filters=['academic_year_id'=>1,'class_id'=>$f['class'],'billing_calendar'=>'AD','billing_period'=>'2026-10','frequency'=>'monthly'];
$preview=$ac->recurringPreview($filters);erpEqual($preview[0]['total_minor'],310000,'11 days old rate plus 10 days new rate; admission/withdrawal override import timestamp');
acReviewReject($db,fn()=>$ac->generateRecurring($filters+['confirm'=>1]),'Direct generation without reviewed token denied');
$review=$ac->reviewRecurring($filters);$request=$filters+['confirm'=>1,'preview_token'=>$review['token']];
$ac->saveFeeRule(array_replace($rule,['amount'=>'9300','effective_from'=>'2026-10-20']));
acReviewReject($db,fn()=>$ac->generateRecurring($request),'Changed fees after preview denied');
$review=$ac->reviewRecurring($filters);$request['preview_token']=$review['token'];$issued=$ac->generateRecurring($request);
erpEqual(count($issued['issued']),1,'Reviewed fees posted once');$before=acReviewSnapshot($db);erpEqual($ac->generateRecurring($request),$issued,'Completed preview safely replays');erpEqual(acReviewSnapshot($db),$before,'Replay has no new writes');
$review=$ac->reviewRecurring($filters);$run=$ac->generateRecurring($filters+['confirm'=>1,'preview_token'=>$review['token']]);erpEqual(count($run['issued']),0,'Fresh retry does not bill an existing fee item');
$extra=$ac->saveItem(['name'=>'Later assigned fee','code'=>'REVIEW-EXTRA','kind'=>'fee','frequency'=>'monthly','rate'=>'310','is_active'=>1,'discount_eligible'=>1]);
$ac->saveFeeRule(array_replace($rule,['item_id'=>$extra,'amount'=>'310']));
$review=$ac->reviewRecurring($filters);erpEqual($review['rows'][0]['total_minor'],21000,'Only missing fee is previewed on retry');
$run=$ac->generateRecurring($filters+['confirm'=>1,'preview_token'=>$review['token']]);erpEqual(count($run['issued']),1,'Missing fee billed without repeating tuition');
erpEqual((int)$db->query('SELECT COUNT(*) FROM ac_charge_posts WHERE school_id=1')->fetchColumn(),2,'One charge identity per student, cycle and item');
$review=$ac->reviewRecurring($filters);$db->exec("UPDATE ac_generation_previews SET expires_at='2020-01-01' WHERE token='".$review['token']."'");
acReviewReject($db,fn()=>$ac->generateRecurring($filters+['confirm'=>1,'preview_token'=>$review['token']]),'Expired review denied');
erpCheck($ac->reconcile()['ok'],'All reviewed workflows reconcile');
echo 'Accounting review passed: '.$GLOBALS['erp_checks']." checks\n";
