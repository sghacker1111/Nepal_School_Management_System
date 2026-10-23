<?php
// Every write goes to a disposable database, never a real school database.
$path=tempnam(sys_get_temp_dir(),'cdc-2083-test-');putenv('ERP_RUNTIME=test');putenv('ERP_TEST_SQLITE=1');putenv('ERP_DB_TYPE=sqlite');putenv('ERP_DB_PATH='.$path);
require_once __DIR__.'/../config/bootstrap.php';require_once __DIR__.'/../includes/database.php';require_once __DIR__.'/../includes/grading.php';
$db=getDB();$checks=0;
function cdcCheck(bool $ok,string $message): void {global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
cdcCheck((int)$db->query("SELECT COUNT(*) FROM cdc_reference WHERE category='CLASS_SUBJECTS'")->fetchColumn()===76,'All workbook rows, including uncertain rows, must be stored');
foreach(cdc2083Data()['CLASS_SUBJECTS'] as $row){
    $q=$db->prepare('SELECT cs.*,s.code,s.name_en FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=1 AND cs.curriculum_key=?');$q->execute([cdc2083Key($row)]);$mapping=$q->fetch();
    cdcCheck((bool)$mapping,'Every source row is matched or staged for review');
    if(in_array($row['import_flag'],['YES','REVIEW_LOCAL'],true)){
        foreach(cdc2083Evaluation($row) as $key=>$value)cdcCheck($mapping[$key]===$value||($value!==null&&is_numeric($value)&&is_numeric($mapping[$key])&&abs((float)$mapping[$key]-(float)$value)<0.00001),'Mismatch '.$row['class_grade'].':'.$row['subject_order'].' '.$key);
        cdcCheck($mapping['code']===$row['source_subject_code'],'Official code must stay textual, including leading zero / blank');
    }
}
foreach(cdc2083Data()['BOUNDARY_TESTS'] as $test){[$grade,$gp]=erpGradeForPercentage((float)$test['achievement_pct_input']);cdcCheck($grade===$test['expected_letter'],'Exact percentage boundary');cdcCheck($gp===(float)($test['expected_gp']??0),'Exact GP boundary');}
$groups=[];
foreach(cdc2083Data()['EXAMPLES'] as $example){
    $thematic=$example['continuous_percent_example']!==null;
    $scheme=['theory_full'=>$thematic?100:$example['TH_max'],'theory_pass'=>$thematic?35:$example['TH_max']*0.35,'practical_full'=>$thematic?0:$example['IN_max'],'practical_pass'=>$thematic?0:$example['IN_max']*0.4,'credit_hours'=>$example['total_CH'],'external_credit_hours'=>$example['TH_CH'],'internal_credit_hours'=>$example['IN_CH'],'curriculum_version'=>cdc2083Data()['version'],'assessment_method'=>$thematic?'THEMATIC_CONTINUOUS':'DUAL_25_75'];
    $groups[$example['grade']][]=array_merge($scheme,['theory_obtained'=>$thematic?$example['continuous_percent_example']:$example['TH_marks_example'],'practical_obtained'=>$thematic?0:$example['IN_marks_example']]);
}
foreach([1=>3.46,4=>3.60,10=>3.66,11=>3.64] as $grade=>$expected){$result=erpReportSummary($groups[$grade],['has_internal'=>1,'type'=>'final'],'gpa');cdcCheck(abs($result['average_gpa']-$expected)<0.000001,'Workbook example Class '.$grade.' GPA expected '.$expected.' got '.$result['average_gpa']);}
$scheme=['practical_full'=>50,'assessment_method'=>'DUAL_50_50','curriculum_version'=>cdc2083Data()['version']];
$parts=cdc2083InternalComponents($scheme,['type'=>'final']);cdcCheck(array_column($parts,'max')===[4.0,36.0,5.0,5.0],'Annual basic split is 4 + 36 + 5 + 5');
$scheme['practical_full']=25;$scheme['assessment_method']='DUAL_25_75';$parts=cdc2083InternalComponents($scheme,['type'=>'terminal']);cdcCheck(array_column($parts,'max')===[3.0,16.0,6.0],'Trimester secondary split is 3 + 16 + 6');
$counts=[];foreach(['classes','subjects','class_subjects','cdc_reference'] as $table)$counts[$table]=$db->query("SELECT COUNT(*) FROM $table")->fetchColumn();
importCdc2083($db);migrateSchoolSchema($db);foreach($counts as $table=>$count)cdcCheck($count==$db->query("SELECT COUNT(*) FROM $table")->fetchColumn(),'Import is idempotent '.$table);
importCdc2083Scope($db,1,true,'11:4','101');$q=$db->query("SELECT is_active,curriculum_status FROM class_subjects WHERE school_id=1 AND curriculum_key='11:4'")->fetch();cdcCheck($q['is_active']==1&&$q['curriculum_status']==='SCHOOL_CONFIRMED','Review confirmation is school scoped');
cdcCheck($db->query("SELECT curriculum_status FROM class_subjects WHERE school_id=0 AND curriculum_key='11:4'")->fetchColumn()==='REVIEW_11','School confirmation does not affect the global template');
$db->exec("INSERT INTO schools (name_en) VALUES ('CDC second school')");$id=(int)$db->lastInsertId();provisionSchool($db,$id);cdcCheck((int)$db->query("SELECT COUNT(*) FROM class_subjects WHERE school_id=$id AND curriculum_key IS NOT NULL")->fetchColumn()===76,'New schools inherit source-aware mappings');
echo "CDC workbook import, examples, boundaries, components and idempotency passed ($checks checks)\n";
