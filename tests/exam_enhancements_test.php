<?php
if(PHP_SAPI!=='cli')exit(1);
$preview=in_array('--preview',$argv,true);
if($preview){
    $testPath=realpath(dirname(getenv('ERP_DB_PATH')?:''));
    if(!$testPath||!str_starts_with(strtolower($testPath.DIRECTORY_SEPARATOR),strtolower(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)))throw new RuntimeException('Preview requires a temporary database.');
}else{putenv('ERP_RUNTIME=test');putenv('ERP_TEST_SQLITE=1');putenv('ERP_DB_TYPE=sqlite');putenv('ERP_DB_PATH='.tempnam(sys_get_temp_dir(),'erp-enhancements-'));}
ob_start();require __DIR__.'/http_fixture.php';$f=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
require_once __DIR__.'/../includes/exam_service.php';
require_once __DIR__.'/../includes/marks_service.php';
$checks=0;
function enhancementCheck(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function enhancementReject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException $e){enhancementCheck(true,$message);return;}throw new RuntimeException($message);}
$eid=$f['exam'];$sid=$f['student'];$subject=$f['subject'];
$db->prepare('UPDATE exams SET has_internal=0 WHERE id=?')->execute([$eid]);
$db->prepare('UPDATE exam_subjects SET practical_full=0,practical_pass=0,internal_credit_hours=NULL WHERE exam_id=?')->execute([$eid]);
$input=['exam_id'=>$eid,'class_id'=>$f['class'],'section_id'=>$f['section'],'marks'=>[$sid=>[$subject=>['theory'=>60]]]];
saveExamMarks($db,1,$f['ids']['school_admin'],'school_admin',$input);
$theoryBefore=$db->query('SELECT id,student_id,subject_id,theory_obtained,entered_by FROM marks')->fetchAll();
$edit=['name'=>'Regression Exam','type'=>'terminal','result_system'=>'gpa','has_internal'=>1,'issue_date_mode'=>'manual','issue_date'=>'2026-10-01'];
enhancementReject(fn()=>updateExamDetails($db,$f['otherSchool'],$eid,$edit),'Cross-school edits must be rejected');
enhancementCheck(updateExamDetails($db,1,$eid,$edit),'IN can be enabled after TH entry');
enhancementCheck($db->query('SELECT id,student_id,subject_id,theory_obtained,entered_by FROM marks')->fetchAll()===$theoryBefore,'TH values, IDs and entered-by survive');
$row=$db->query('SELECT * FROM exam_subjects WHERE exam_id='.$eid)->fetch();
enhancementCheck((float)$row['theory_full']===75.0&&(float)$row['theory_pass']===26.25,'TH limits stay unchanged');
enhancementCheck((float)$row['practical_full']===25.0&&(float)$row['practical_pass']===10.0,'Class-specific IN limits are restored');
enhancementCheck((float)$row['external_credit_hours']===3.0&&(float)$row['internal_credit_hours']===1.25,'Saved TH credit retained, missing IN credit restored');
$mark=$db->query('SELECT * FROM marks')->fetch();
enhancementCheck($mark['practical_obtained']===null&&$mark['remarks']==='Incomplete','Absent IN is unknown, not zero or a pass');
enhancementCheck(!updateExamDetails($db,1,$eid,$edit),'Repeating an edit does not reinitialize assessment');
enhancementCheck((int)$db->query('SELECT COUNT(*) FROM exam_subjects WHERE exam_id='.$eid)->fetchColumn()===1,'No duplicate subjects');
enhancementReject(fn()=>updateExamDetails($db,1,$eid,array_replace($edit,['has_internal'=>0])),'Removing IN after marks is forbidden');
enhancementReject(fn()=>updateExamDetails($db,1,$eid,array_replace($edit,['result_system'=>'percentage'])),'Changing result system after marks is forbidden');
enhancementReject(fn()=>updateExamDetails($db,1,$eid,array_replace($edit,['type'=>'final'])),'Changing exam type after marks is forbidden');
enhancementReject(fn()=>updateExamDetails($db,1,$eid,array_replace($edit,['issue_date'=>'2026-02-30'])),'Invalid manual dates are rejected');
$input['marks'][$sid][$subject]+=['participation'=>3,'project_work'=>12,'previous_exam'=>5];
saveExamMarks($db,1,$f['ids']['school_admin'],'school_admin',$input);
enhancementCheck((float)$db->query('SELECT practical_obtained FROM marks')->fetchColumn()===20.0,'IN can be entered normally after enabling');
$db->prepare('UPDATE exams SET has_internal=0 WHERE id=?')->execute([$eid]);
updateExamDetails($db,1,$eid,$edit);
enhancementCheck((float)$db->query('SELECT practical_obtained FROM marks')->fetchColumn()===20.0,'Already recorded genuine IN marks are preserved');
changeExamState($db,1,$eid,'lock');
enhancementReject(fn()=>updateExamDetails($db,1,$eid,$edit),'Locked exam cannot be edited');
changeExamState($db,1,$eid,'reopen');
// Failed additions roll back metadata, not just the flag.
$db->prepare('UPDATE exams SET has_internal=0 WHERE id=?')->execute([$eid]);
$db->prepare('UPDATE exam_subjects SET practical_full=0 WHERE exam_id=?')->execute([$eid]);
$db->prepare('UPDATE subjects SET in_full_marks=NULL WHERE id=?')->execute([$subject]);
$db->prepare('UPDATE class_subjects SET in_full_marks=NULL WHERE school_id=1 AND class_id=? AND subject_id=?')->execute([$f['class'],$subject]);
enhancementReject(fn()=>updateExamDetails($db,1,$eid,array_replace($edit,['name'=>'Must roll back'])),'Unspecified IN maxima cannot be invented');
enhancementCheck($db->query('SELECT name,has_internal FROM exams WHERE id='.$eid)->fetch()===['name'=>'Regression Exam','has_internal'=>0],'A failed addition is atomic');
$db->prepare('UPDATE subjects SET in_full_marks=25 WHERE id=?')->execute([$subject]);
$db->prepare('UPDATE class_subjects SET in_full_marks=25 WHERE school_id=1 AND class_id=? AND subject_id=?')->execute([$f['class'],$subject]);
foreach(['2026-02-30','2026-02-29','2026-1-01','',[],"2026-10-01<script>"] as $bad)enhancementReject(fn()=>reportIssueDate([],['issue_date_mode'=>'manual','issue_date'=>$bad]),'Invalid date must be rejected');
enhancementCheck(reportIssueDate([],['issue_date_mode'=>'manual','issue_date'=>'2028-02-29'])['date']==='2028-02-29','Valid leap day allowed');
enhancementReject(fn()=>reportIssueDate([],['issue_date_mode'=>'other']),'Invalid date mode rejected');
date_default_timezone_set('America/Los_Angeles');
$nepalToday=(new DateTimeImmutable('today',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d');
enhancementCheck(reportIssueDate([])['date']===$nepalToday,'Automatic issue date uses Nepal time, not host/browser time');
date_default_timezone_set('Asia/Kathmandu');
enhancementCheck(studentEmisRegistrationNumber(' 00123-2083 ',true)==='00123-2083','Registration number preserves leading zeros');
enhancementCheck(studentEmisRegistrationNumber('')===null,'Legacy records may have no registration number');
foreach(['',str_repeat('X',101),"ABC\n123",[]] as $bad)enhancementReject(fn()=>studentEmisRegistrationNumber($bad,true),'Required registration values must be valid');
if($preview){
    $db->prepare('UPDATE exams SET issue_date_mode=\'auto\',issue_date=NULL WHERE id=?')->execute([$eid]);
    $db->prepare('UPDATE marks SET practical_obtained=0,internal_breakdown=NULL WHERE exam_id=?')->execute([$eid]);
    $db->prepare('UPDATE students SET emis_registration_number=? WHERE id=?')->execute(['001234-2083',$sid]);
    echo json_encode($f);
}else echo "Exam enhancements passed ($checks checks): IN additions, preservation, rollback, issue dates and EMIS validation.\n";
