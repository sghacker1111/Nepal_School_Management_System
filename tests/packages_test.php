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
echo 'Packages passed: '.$GLOBALS['erp_checks']." checks\n";
