<?php
/** Synthetic browser fixture; requires an explicitly supplied disposable database. */
if(!getenv('ERP_DB_PATH')||getenv('ERP_DB_TYPE')!=='sqlite')throw new RuntimeException('Set a disposable SQLite path before creating this fixture.');
ob_start();require __DIR__.'/http_fixture.php';$fixture=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
require __DIR__.'/../includes/routine_profiles.php';require __DIR__.'/../includes/catalog_validation.php';
$class=$fixture['class'];$teacher=$fixture['ids']['teacher'];$subject=$fixture['subject'];$actor=$fixture['ids']['school_admin'];
$db->prepare("UPDATE users SET full_name='Sunil Gotame' WHERE id=?")->execute([$teacher]);
$db->prepare('UPDATE class_subjects SET weekly_periods=0 WHERE class_id=?')->execute([$class]);
$db->prepare('UPDATE class_subjects SET weekly_periods=5 WHERE class_id=? AND subject_id=?')->execute([$class,$subject]);
$layout=routineDefaultLayout(5);$layout[2]=routineDefaultLayout(4)[2];
$grid=buildClassRoutine($db,1,schoolRecord($db,'classes',$class,1),routineSubjects($db,1,$class),[1,2,3,4,5],5,$layout)['grid'];
$db->beginTransaction();catalogWriteLock($db,1);
$profile=routineSaveProfile($db,1,$class,$actor,['name'=>'Summer 2083','mode'=>'manual','layout'=>$layout,'grid'=>$grid]);
routinePublishWeek($db,1,$class,$actor,$layout,$grid,$profile);
routineSaveProfile($db,1,$class,$actor,['name'=>'Winter 2083','mode'=>'automatic','layout'=>routineDefaultLayout(4)]);
$db->commit();
$db->prepare('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,?,?)')->execute([$fixture['exam'],$fixture['student'],$subject,60,20]);
$fixture['profile']=$profile;echo json_encode($fixture);
