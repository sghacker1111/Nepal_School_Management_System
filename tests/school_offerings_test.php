<?php
require __DIR__.'/test_support.php';['db'=>$db,'fixture'=>$f]=erpTestFixture();
require_once __DIR__.'/../includes/school_offerings.php';require_once __DIR__.'/../includes/catalog_validation.php';
require_once __DIR__.'/../includes/exam_service.php';
$before=[];foreach(['marks','students','exam_subjects','users'] as $t)$before[$t]=$db->query("SELECT * FROM $t ORDER BY id")->fetchAll();
$gc=[];$local=[];foreach([9,10] as $grade){$gc[$grade]=(int)$db->query("SELECT id FROM classes WHERE school_id=0 AND name='Class $grade'")->fetchColumn();$local[$grade]=(int)$db->query("SELECT id FROM classes WHERE school_id=1 AND name='Class $grade'")->fetchColumn();}
$global=[];foreach([9,10] as $grade){$global[$grade]=insertRecord($db,'subjects',['name_en'=>'Legacy Shared Course','code'=>'SHARED-OLD','type'=>'compulsory','school_id'=>0]);insertRecord($db,'class_subjects',['class_id'=>$gc[$grade],'subject_id'=>$global[$grade],'school_id'=>0,'is_optional'=>0,'is_active'=>1]);}
$shared=insertRecord($db,'subjects',['name_en'=>'Legacy Shared Course','code'=>'SHARED-OLD','type'=>'compulsory','school_id'=>1,'source_id'=>$global[9]]);
foreach([9,10] as $grade)insertRecord($db,'class_subjects',['class_id'=>$local[$grade],'subject_id'=>$shared,'school_id'=>1,'is_optional'=>0,'is_active'=>1]);
$db->beginTransaction();syncAllSchoolOfferings($db);$db->commit();
$q=$db->prepare('SELECT subject_id FROM class_subjects WHERE school_id=1 AND class_id=? AND subject_id=?');foreach([9,10] as $grade){$q->execute([$local[$grade],$shared]);erpEqual(count($q->fetchAll()),1,'Legacy shared subject identity retained in grade '.$grade);}
$q=$db->query("SELECT COUNT(*) FROM subjects WHERE school_id=1 AND code='SHARED-OLD'");erpEqual((int)$q->fetchColumn(),1,'Synchronization never duplicates an older cross-grade identity');
$db->beginTransaction();offeringSetRequiredDefault($db,$gc[10],$global[10],false);syncAllSchoolOfferings($db);$db->commit();
saveSchoolOffering($db,1,$f['ids']['school_admin'],'school_admin',$local[10],$shared,'unassign');
erpCheck(!schoolSubjectAssigned($db,1,$local[10],$shared),'Class-specific school choice uses the right global mapping');
erpCheck(schoolSubjectAssigned($db,1,$local[9],$shared),'Other grade remains compulsory');
erpReject(fn()=>saveSchoolOffering($db,1,$f['ids']['school_admin'],'school_admin',$local[9],$shared,'unassign'),'School admin cannot hide compulsory default');
saveSchoolOffering($db,1,$f['ids']['school_admin'],'school_admin',$local[10],$shared,'assign');
$db->beginTransaction();$db->prepare('UPDATE subjects SET is_hidden=1 WHERE id=?')->execute([$global[9]]);syncAllSchoolOfferings($db);$db->commit();
erpCheck(!schoolSubjectAssigned($db,1,$local[9],$shared),'Global hiding revokes only its grade mapping');
erpCheck(schoolSubjectAssigned($db,1,$local[10],$shared),'Shared legacy ID does not hide another grade');
$db->beginTransaction();$db->prepare('UPDATE subjects SET is_hidden=0 WHERE id=?')->execute([$global[9]]);syncAllSchoolOfferings($db);$db->commit();
$snap=[];foreach(['subjects','classes','class_subjects','app_meta'] as $t)$snap[$t]=$db->query("SELECT * FROM $t ORDER BY ".($t==='app_meta'?'meta_key':'id'))->fetchAll();
$db->beginTransaction();syncAllSchoolOfferings($db);$db->commit();
foreach($snap as $t=>$rows)erpEqual($db->query("SELECT * FROM $t ORDER BY ".($t==='app_meta'?'meta_key':'id'))->fetchAll(),$rows,'Synchronization is idempotent for '.$t);
foreach($before as $t=>$rows)erpEqual($db->query("SELECT * FROM $t ORDER BY id")->fetchAll(),$rows,'Synchronization preserves '.$t);
// Lock records the current exam selection; unassignment never deletes historical schemes.
$scheme=insertRecord($db,'exam_subjects',['exam_id'=>$f['exam'],'class_id'=>$local[10],'subject_id'=>$shared,'theory_full'=>100,'theory_pass'=>35,'practical_full'=>0,'practical_pass'=>0,'credit_hours'=>4]);
saveSchoolOffering($db,1,$f['ids']['school_admin'],'school_admin',$local[10],$shared,'unassign');
changeExamState($db,1,$f['exam'],'lock');$exam=schoolRecord($db,'exams',$f['exam'],1);
$rows=$db->query('SELECT * FROM exam_subjects WHERE exam_id='.$f['exam'])->fetchAll();
erpCheck(!in_array($scheme,array_column(schoolExamOfferingRows($db,1,$exam,$rows),'id')),'Locked result retains the selected offering snapshot');
saveSchoolOffering($db,1,$f['ids']['school_admin'],'school_admin',$local[10],$shared,'assign');
erpCheck(!in_array($scheme,array_column(schoolExamOfferingRows($db,1,$exam,$rows),'id')),'Later assignment does not alter a locked result snapshot');
changeExamState($db,1,$f['exam'],'reopen');$exam=schoolRecord($db,'exams',$f['exam'],1);
erpCheck(in_array($scheme,array_column(schoolExamOfferingRows($db,1,$exam,$rows),'id')),'Reopening uses the current offerings');
erpEqual((int)$db->query('SELECT COUNT(*) FROM exam_subjects WHERE id='.$scheme)->fetchColumn(),1,'Excluded scheme remains stored');
$newSchool=insertRecord($db,'schools',['name_en'=>'New Offering Defaults School']);
$db->beginTransaction();provisionSchool($db,$newSchool);syncSchoolOfferings($db,$newSchool,true);$db->commit();
erpEqual((int)$db->query('SELECT COUNT(*) FROM class_subjects WHERE school_id='.$newSchool.' AND is_optional=1 AND is_active=1')->fetchColumn(),0,'New school optional offerings start unassigned');
$newClass=(int)$db->query('SELECT id FROM classes WHERE school_id='.$newSchool.' AND source_id='.$gc[9])->fetchColumn();$newSubject=(int)$db->query('SELECT id FROM subjects WHERE school_id='.$newSchool.' AND source_id='.$global[9])->fetchColumn();
erpCheck(schoolSubjectAssigned($db,$newSchool,$newClass,$newSubject),'New school receives the active compulsory catalog');
$duplicates=$db->query('SELECT class_id,subject_id,COUNT(*) copies FROM class_subjects WHERE school_id='.$newSchool.' GROUP BY class_id,subject_id HAVING COUNT(*)>1')->fetchAll();erpEqual($duplicates,[],'New school receives distinct source identities without duplicate mappings');
$db->beginTransaction();$db->prepare('UPDATE subjects SET name_en=? WHERE id=?')->execute(['Conflicting single-grade rename',$global[9]]);
erpReject(fn()=>syncAllSchoolOfferings($db),'Conflicting legacy aliases fail before rewriting school identities');$db->rollBack();
echo 'School offerings service passed: '.$GLOBALS['erp_checks']." checks.\n";
