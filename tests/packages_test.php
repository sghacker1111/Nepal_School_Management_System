<?php
require __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();
require __DIR__.'/../includes/packages.php';require __DIR__.'/../includes/auth.php';
$matrix=[
 'full'=>['students','teachers','attendance','exams','results','reports','learning','curriculum','specification_grids','paper_generator','questions','ocr_scanner','accounting'],
 'result_system'=>['students','teachers','attendance','exams','results','reports'],
 'learning_system'=>['learning','curriculum','specification_grids'],
 'paper_generator'=>['paper_generator','questions','specification_grids','ocr_scanner'],
 'question_view'=>['questions'],'question_add'=>['questions'],'accounting'=>['accounting','students'],
 'exam_generator'=>['learning','curriculum','paper_generator','questions','specification_grids','ocr_scanner']
];
foreach($matrix as $package=>$allowed)foreach(['students','teachers','attendance','exams','results','reports','learning','curriculum','specification_grids','paper_generator','questions','ocr_scanner','accounting'] as $module)erpEqual(schoolPackageAllows($package,$module),in_array($module,$allowed,true),$package.' / '.$module);
Auth::startSession();$_SESSION=['user_id'=>$f['ids']['school_admin'],'role'=>'school_admin','school_id'=>1,'school_subscription_type'=>'full'];
$db->exec("UPDATE schools SET subscription_type='result_system' WHERE id=1");
erpEqual(Auth::hasAccess('accounting'),false,'Stale session cannot grant accounting');
$db->exec('ALTER TABLE schools RENAME TO unavailable_schools');
try{erpEqual(Auth::hasAccess('results'),false,'Entitlement lookup failure denies access');}finally{$db->exec('ALTER TABLE unavailable_schools RENAME TO schools');}
$_SESSION['school_id']=999999;erpEqual(Auth::hasAccess('accounting'),false,'Missing school denies access');
unset($_SESSION['school_id']);erpEqual(Auth::hasAccess('accounting'),false,'Unassigned account denies access');
foreach(['results','accounting','learning','classes','subjects','settings'] as $module)erpEqual(schoolPackageAllows('unrecognized',$module),false,'Unknown package denies '.$module);
require __DIR__.'/../includes/accounting_service.php';require __DIR__.'/../includes/request_guard.php';require __DIR__.'/../includes/user_management.php';
$forged=['full_name'=>'Forged User','username'=>'forged-admin','role'=>'school_admin','password'=>'TestPassword123!','status'=>1];
erpReject(fn()=>saveSchoolUser($db,$f['ids']['cashier'],'school_admin',1,$forged),'Crafted actor role cannot manage users');
$actions=['view','catalog','fees','invoice','payment','advance','expense','subscription','settings','approve','credit','refund','reverse','period','opening','unrecognized_action'];
$roles=['school_admin'=>$actions,'accountant'=>['view','catalog','fees','invoice','payment','advance','expense','subscription'],'cashier'=>['view','payment','advance'],'auditor'=>['view']];
foreach($roles as $role=>$permitted){$finance=new SchoolAccounting($db,1,$f['ids'][$role]);foreach($actions as $action)erpEqual($finance->can($action),$action!=='unrecognized_action'&&in_array($action,$permitted,true),$role.' finance '.$action);}
erpEqual(schoolRoleCanWrite('auditor','accounting','payments.php'),false,'Auditor write denied');
erpEqual(schoolRoleCanWrite('cashier','accounting','create_bill.php'),false,'Cashier invoice route denied');
erpEqual(schoolRoleCanWrite('accountant','accounting','settings.php'),false,'Accountant settings route denied');
$adminFinance=new SchoolAccounting($db,1,$f['ids']['school_admin']);$db->exec('UPDATE users SET status=0 WHERE id='.$f['ids']['school_admin']);
erpReject(fn()=>$adminFinance->saveCategory(['name'=>'Denied inactive mutation']),'Existing service rechecks inactive actor');
$db->exec('UPDATE users SET status=1 WHERE id='.$f['ids']['school_admin']);
erpReject(fn()=>new SchoolAccounting($db,1,$f['otherUser']),'Foreign actor cannot open finance');
$preview=new SchoolAccounting($db,1,(int)$db->query("SELECT id FROM users WHERE role='super_admin' LIMIT 1")->fetchColumn(),true);
erpEqual($preview->can('invoice'),false,'Preview forbids invoice service mutation');
echo 'Packages passed: '.$GLOBALS['erp_checks']." checks\n";
