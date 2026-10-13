<?php
require_once __DIR__.'/subject_names.php';
/** Versioned factual workbook data; spreadsheet prose is never executed. */
function cdc2083Data(): array {
    static $data;
    return $data ??= json_decode(file_get_contents(__DIR__.'/../config/data/cdc_2083.json'), true, 512, JSON_THROW_ON_ERROR);
}
function cdc2083LegacyCode(array $row): ?string {
    $grade=(int)$row['class_grade']; $order=(int)$row['subject_order'];
    if($grade<=3)return ['NEP-123','ENG-123','MAT-123','SER-123','LOC-123'][$order-1]??null;
    if($grade<=8)return ($grade<=5?['NEP-45','ENG-45','MAT-45','SCI-45','SOC-45','HPC-45','LOC-45']:['NEP-68','ENG-68','MAT-68','SCI-68','SOC-68','HPE-68','LOC-68'])[$order-1]??null;
    if($grade<=10)return ['NEP-910','ENG-910','MAT-910','SCI-910','SOC-910','OPT-MAT',null][$order-1]??null;
    return ['NEP-1112','ENG-1112','SOL-1112','OPT-PHY','OPT-BIO','OPT-CHE'][$order-1]??null;
}
function cdc2083Key(array $row): string {return $row['class_grade'].':'.$row['subject_order'];}
function cdc2083Update(PDO $db,string $table,int $id,array $values): void {
    $db->prepare("UPDATE `$table` SET ".implode(',',array_map(static fn($k)=>"`$k`=?",array_keys($values))).' WHERE id=?')->execute([...array_values($values),$id]);
}
function cdc2083Evaluation(array $row): array {
    $thematic=$row['method_id']==='THEMATIC_CONTINUOUS';
    return ['credit_hours'=>$row['total_credit_hours'],'th_credit_hours'=>$row['th_credit_hours'],'in_credit_hours'=>$row['in_credit_hours'],
        'th_full_marks'=>$row['th_marks_max'],'in_full_marks'=>$row['in_marks_max'],
        'th_pass_marks'=>$thematic?null:$row['th_marks_max']*0.35,'in_pass_marks'=>$thematic?null:$row['in_marks_max']*0.4,
        'assessment_method'=>$row['method_id'],'curriculum_version'=>cdc2083Data()['version']];
}
/** Reuse IDs; never modify saved exam schemes, student choices, teachers or marks. */
function importCdc2083Scope(PDO $db,int $school,bool $approve=false,?string $onlyKey=null,?string $codeOverride=null): array {
    $counts=['classes_added'=>0,'subjects_added'=>0,'mappings_added'=>0,'mappings_updated'=>0,'review_rows'=>0];
    foreach(cdc2083Data()['CLASS_SUBJECTS'] as $row){
        $key=cdc2083Key($row); if($onlyKey!==null&&$key!==$onlyKey)continue;
        $q=$db->prepare('SELECT id FROM classes WHERE school_id=? AND LOWER(TRIM(name))=LOWER(?) ORDER BY id LIMIT 1');$q->execute([$school,'Class '.$row['class_grade']]);$class=(int)$q->fetchColumn();
        if(!$class){$class=insertRecord($db,'classes',['name'=>'Class '.$row['class_grade'],'name_np'=>'कक्षा '.$row['class_grade'],'level'=>$row['class_grade']>=9?'Secondary':'Basic','grading_type'=>'gpa','school_id'=>$school,'is_active'=>1]);$counts['classes_added']++;}
        $ready=in_array($row['import_flag'],['YES','REVIEW_LOCAL'],true)||$approve;
        if(!$ready)$counts['review_rows']++;
        $q=$db->prepare('SELECT s.*,cs.id mapping_id FROM subjects s LEFT JOIN class_subjects cs ON cs.subject_id=s.id AND cs.class_id=? AND cs.school_id=? WHERE s.school_id=? AND (cs.curriculum_key=? OR s.code=? OR (cs.id IS NOT NULL AND LOWER(TRIM(s.name_en))=LOWER(?))) ORDER BY CASE WHEN cs.id IS NULL THEN 1 ELSE 0 END,s.id LIMIT 1');
        $q->execute([$class,$school,$school,$key,cdc2083LegacyCode($row),subjectCanonicalName($row['subject_name_en'])]);$subject=$q->fetch();
        // The source code alone is not an identity (e.g. illustrative Class 12 repeats Class 11 codes).
        if(!$subject&&$school>0){
            $q=$db->prepare('SELECT s.* FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.source_id JOIN classes c ON c.id=cs.class_id WHERE s.school_id=? AND cs.school_id=0 AND c.name=? AND cs.curriculum_key=? ORDER BY s.id LIMIT 1');$q->execute([$school,'Class '.$row['class_grade'],$key]);$subject=$q->fetch();
        }
        $isOptional=str_starts_with($row['subject_type'],'OPTIONAL');
        $evaluation=cdc2083Evaluation($row);
        $sourceCode=$codeOverride===null?$row['source_subject_code']:($codeOverride===''?null:$codeOverride);
        $subjectValues=$evaluation+['code'=>$sourceCode,'name_en'=>subjectCanonicalName($row['subject_name_en']),'type'=>$isOptional?'optional':'compulsory','full_marks'=>$row['achievement_percent_scale']??($row['th_marks_max']+$row['in_marks_max']),'pass_marks'=>$row['method_id']==='THEMATIC_CONTINUOUS'?35:($row['th_marks_max']*0.35+$row['in_marks_max']*0.4)];
        if(!$subject){
            // Staged courses are visible in the review page, not active exam offerings.
            $subjectId=insertRecord($db,'subjects',$subjectValues+['name_np'=>'','school_id'=>$school,'is_hidden'=>0]);$counts['subjects_added']++;
        }else{
            $subjectId=(int)$subject['id'];
            if($ready)cdc2083Update($db,'subjects',$subjectId,$subjectValues);
        }
        $q=$db->prepare('SELECT * FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? ORDER BY id');$q->execute([$school,$class,$subjectId]);$mappings=$q->fetchAll();
        $meta=['curriculum_key'=>$key,'curriculum_status'=>$approve?'SCHOOL_CONFIRMED':$row['import_flag'],'source_notes'=>$row['verification_notes'],'subject_order'=>$row['subject_order']];
        if(!$mappings){
            insertRecord($db,'class_subjects',$evaluation+$meta+['class_id'=>$class,'subject_id'=>$subjectId,'school_id'=>$school,'is_optional'=>$isOptional?1:0,'is_active'=>$ready?1:0]);$counts['mappings_added']++;
        }else foreach($mappings as $mapping){
            cdc2083Update($db,'class_subjects',(int)$mapping['id'],$meta+($ready?$evaluation+['is_optional'=>$isOptional?1:0]:[])+($approve?['is_active'=>1]:[]));$counts['mappings_updated']++;
        }
    }
    return $counts;
}
function importCdc2083(PDO $db): void {
    $data=cdc2083Data();
    $q=$db->prepare("SELECT meta_value FROM app_meta WHERE meta_key='cdc_2083_dataset'");$q->execute();
    if($q->fetchColumn()===$data['version'])return;
    $ownTransaction=!$db->inTransaction();
    if($ownTransaction)$db->beginTransaction();
    try {
    $db->exec('DELETE FROM cdc_reference');
    $insert=$db->prepare('INSERT INTO cdc_reference (category,item_key,payload) VALUES (?,?,?)');
    foreach($data as $category=>$rows)if(is_array($rows))foreach($rows as $row)$insert->execute([$category,(string)$row['source_row'],json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    importCdc2083Scope($db,0);
    foreach($db->query('SELECT id FROM schools')->fetchAll(PDO::FETCH_COLUMN) as $id){provisionSchool($db,(int)$id);importCdc2083Scope($db,(int)$id);}
    // Keep the public scale table synchronized; historical result records remain untouched.
    $db->exec('DELETE FROM grading_scales');
    foreach($data['GRADE_2083'] as $band)insertRecord($db,'grading_scales',['min_percentage'=>$band['minimum_percent_inclusive'],'max_percentage'=>$band['maximum_percent_exclusive']??100,'grade'=>$band['letter_grade'],'grade_point'=>$band['grade_point']??0,'remark'=>$band['english_descriptor']]);
    $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute(['cdc_2083_dataset']);
    $db->prepare('INSERT INTO app_meta (meta_key,meta_value) VALUES (?,?)')->execute(['cdc_2083_dataset',$data['version']]);
    $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute(['cdc_2083_source_sha256']);
    $db->prepare('INSERT INTO app_meta (meta_key,meta_value) VALUES (?,?)')->execute(['cdc_2083_source_sha256',$data['source_sha256']]);
    if($ownTransaction)$db->commit();
    }catch(Throwable $e){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
}
/** Annual has FOUR entries. Trimester has three; values entered are scaled contributions. */
function cdc2083InternalComponents(array $scheme,array $exam): array {
    require_once __DIR__.'/assessment_service.php';
    $custom=assessmentProfile($scheme,$exam);if($custom)return $custom;
    $total=(float)($scheme['practical_full']??0);if($total<=0)return [];
    if(empty($scheme['curriculum_version']))return ['participation'=>['label'=>'Participation','max'=>$total],'project_work'=>['label'=>'Project work','max'=>$total],'previous_exam'=>['label'=>'Previous assessment','max'=>$total]];
    $annual=in_array($exam['type']??'',['final','annual'],true);$basic=($scheme['assessment_method']??'')==='DUAL_50_50';$base=$basic?50:25;
    $components=['participation'=>['label'=>'Participation','max'=>$basic?4:3],'project_work'=>['label'=>'Practical / project work','max'=>$basic?36:16]];
    if($annual){$components['first_trimester']=['label'=>'First trimester contribution','max'=>$basic?5:3];$components['second_trimester']=['label'=>'Second trimester contribution','max'=>$basic?5:3];}
    else $components['previous_exam']=['label'=>'Trimester written-test contribution','max'=>$basic?10:6];
    foreach($components as &$part)$part['max']=round($part['max']*$total/$base,2);unset($part);
    return $components;
}
