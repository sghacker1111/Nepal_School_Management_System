<?php
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/grading.php';

function assessmentComponentsInput(array $input,float $total): array {
    if(!is_finite($total)||$total<=0||$total>1000||round($total,2)!==$total)throw new InvalidArgumentException('Internal full marks must be greater than zero, at most 1000, and have at most two decimals.');
    $parts=[];$sum=0;
    foreach($input as $row){
        $key=trim((string)($row['key']??''));$label=trim((string)($row['label']??''));
        if($key===''&&$label==='')continue;
        if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/',$key)||isset($parts[$key])||in_array($key,['theory','practical'],true))throw new InvalidArgumentException('Component keys must be distinct lowercase names; keep existing keys to preserve saved scores.');
        if($label===''||mb_strlen($label)>120||!is_numeric($row['max']??null)||!is_finite((float)$row['max'])||(float)$row['max']<0)throw new InvalidArgumentException('Every component needs a label and valid non-negative full marks.');
        $max=(float)$row['max'];if(round($max,2)!==$max)throw new InvalidArgumentException('Component full marks may have at most two decimals.');$sum+=$max;$parts[$key]=['label'=>$label,'max'=>$max];
    }
    if(!$parts||count($parts)>15||abs($sum-$total)>0.005)throw new InvalidArgumentException('Component full marks must add up exactly to the Internal (IN) full marks.');
    return $parts;
}
/** Revision of persisted configuration only; projections must not change the token. */
function assessmentRevision(array $row,bool $examScope):string {
    if($examScope){require_once __DIR__.'/exam_mapping_service.php';return examMappingRevision($row);}
    $config=[];
    foreach(['id','school_id','class_id','subject_id','credit_hours','th_credit_hours','in_credit_hours','th_full_marks','th_pass_marks','in_full_marks','in_pass_marks','is_optional','assessment_method','curriculum_version','internal_components'] as $key)$config[$key]=$row[$key]??null;
    return hash('sha256',json_encode($config,JSON_THROW_ON_ERROR));
}
/** Class changes affect only future mappings. Exam edits retain entered scores. */
function saveInternalAssessment(PDO $db,int $school,int $actor,string $role,int $mappingId,?int $examId,array $input):void {
    require_once __DIR__.'/exam_mapping_service.php';
    $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([$actor]);$user=$q->fetch();
    if(!$user||!(int)$user['status']||$user['role']!==$role||($role!=='super_admin'&&(int)$user['school_id']!==$school)||!in_array($role,['super_admin','school_admin','exam_officer'],true))throw new InvalidArgumentException('Your active account cannot edit this school assessment.');
    if(!$examId&&!in_array($role,['super_admin','school_admin'],true))throw new InvalidArgumentException('Only administrators can change future class defaults.');
    if(empty($input['confirm_assessment_change']))throw new InvalidArgumentException('Confirm the warning before editing internal assessment.');
    $exam=$examId?schoolRecord($db,'exams',$examId,$school):['type'=>'terminal'];
    if($examId)assertExamConfigurationEditable($exam);
    if($examId){$q=$db->prepare('SELECT * FROM exam_subjects WHERE id=? AND exam_id=?');$q->execute([$mappingId,$examId]);$row=$q->fetch();}
    else $row=schoolRecord($db,'class_subjects',$mappingId,$school);
    if(!$row)throw new InvalidArgumentException('The subject configuration no longer exists.');
    schoolRecord($db,'classes',(int)$row['class_id'],$school);schoolRecord($db,'subjects',(int)$row['subject_id'],$school);
    if(($row['assessment_method']??'')==='THEMATIC_CONTINUOUS'||($examId&&(empty($exam['has_internal'])||$exam['type']==='unit')))throw new InvalidArgumentException('This assessment has no separate IN components.');
    if(!hash_equals(assessmentRevision($row,(bool)$examId),(string)($input['revision']??'')))throw new InvalidArgumentException('This configuration changed. Reload and review it again.');
    if(!is_numeric($input['total']??null)||!is_numeric($input['pass']??null))throw new InvalidArgumentException('Enter valid IN full and pass marks.');
    $total=(float)$input['total'];$pass=(float)$input['pass'];
    if(!is_finite($pass)||$pass<0||$pass>$total||round($pass,2)!==$pass)throw new InvalidArgumentException('IN pass marks must be valid and cannot exceed IN full marks.');
    $kinds=$examId?[in_array($exam['type'],['annual','final'],true)?'annual':'terminal']:['terminal','annual'];$data=['total'=>$total];
    foreach($kinds as $kind)$data[$kind]=assessmentComponentsInput((array)($input['components'][$kind]??[]),$total);
    $encoded=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if($examId){$config=$row;$config['practical_full']=$total;$config['practical_pass']=$pass;$config['internal_components']=$encoded;saveExamMapping($db,$school,$examId,$config,$mappingId,$actor,['confirm_grade_change'=>1,'mapping_revision'=>$input['revision']]);return;}
    $db->beginTransaction();
    try{
        $current=schoolRecord($db,'class_subjects',$mappingId,$school,true);
        if(!hash_equals(assessmentRevision($current,false),(string)$input['revision']))throw new InvalidArgumentException('The class default changed; reload and review it again.');
        $db->prepare('UPDATE class_subjects SET internal_components=?,in_full_marks=?,in_pass_marks=? WHERE id=? AND school_id=?')->execute([$encoded,$total,$pass,$mappingId,$school]);
        $db->prepare('INSERT INTO activity_logs(user_id,action,table_name,record_id,ip_address) VALUES(?,?,?,?,?)')->execute([$actor,'Change internal assessment defaults','class_subjects',$mappingId,$_SERVER['REMOTE_ADDR']??'local']);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function assessmentProfile(array $scheme,array $exam): array {
    $data=json_decode($scheme['internal_components']??'null',true);
    $kind=in_array($exam['type']??'',['annual','final'],true)?'annual':'terminal';
    if(!is_array($data)||empty($data[$kind]))return [];
    $full=(float)($scheme['practical_full']??$scheme['in_full_marks']??0);$base=(float)($data['total']??0);
    if($full<=0||$base<=0)return [];
    $parts=$data[$kind];$sum=0;$last=array_key_last($parts);
    foreach($parts as $key=>&$part){$part['max']=$key===$last?round($full-$sum,2):round((float)$part['max']*$full/$base,2);$sum+=$part['max'];}unset($part);
    return $parts;
}
/** Explicit enrollment, never turn every offered elective into a required subject. */
function enrollOptionalForMarks(PDO $db,int $school,array &$student,array $scheme,array $input,array $class,int $user): void {
    $subject=(int)$scheme['subject_id'];
    if(in_array($subject,array_map('intval',[$student['optional_subject_i'],$student['optional_subject_ii'],$student['optional_subject_iii']??0]),true))return;
    if(empty($input['optional_enrollment'][$student['id']][$subject])||empty($input['confirm_optional_enrollment']))throw new InvalidArgumentException('Confirm the optional-subject selection before entering its marks.');
    $q=$db->prepare('SELECT cs.id FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND cs.class_id=? AND cs.subject_id=? AND cs.is_optional=1 AND COALESCE(s.is_hidden,0)=0');$q->execute([$school,$class['id'],$subject]);
    if(!$q->fetchColumn())throw new InvalidArgumentException('This elective is not offered by this class.');
    $slots=['optional_subject_i','optional_subject_ii'];if(in_array($class['name'],['Class 11','Class 12'],true))$slots[]='optional_subject_iii';
    $slot=null;foreach($slots as $field)if(empty($student[$field])){$slot=$field;break;}
    if(!$slot)throw new InvalidArgumentException('This student already has all optional-subject slots filled. Correct the selection in the student profile first.');
    $db->prepare("UPDATE students SET $slot=? WHERE id=? AND school_id=?")->execute([$subject,$student['id'],$school]);$student[$slot]=$subject;
    $q=$db->prepare('SELECT id FROM student_subjects WHERE student_id=? AND subject_id=?');$q->execute([$student['id'],$subject]);
    if(!$q->fetchColumn())$db->prepare('INSERT INTO student_subjects(student_id,subject_id) VALUES(?,?)')->execute([$student['id'],$subject]);
    $db->prepare('INSERT INTO activity_logs(user_id,action,table_name,record_id,ip_address) VALUES(?,?,?,?,?)')->execute([$user,'Select optional subject '.$subject.' during marks entry','students',$student['id'],$_SERVER['REMOTE_ADDR']??'local']);
}
