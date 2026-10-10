<?php
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/grading.php';

function assessmentComponentsInput(array $input,float $total): array {
    if($total<=0||$total>1000)throw new InvalidArgumentException('Internal full marks must be greater than zero and at most 1000.');
    $parts=[];$sum=0;
    foreach($input as $row){
        $key=trim((string)($row['key']??''));$label=trim((string)($row['label']??''));
        if($key===''&&$label==='')continue;
        if(!preg_match('/^[a-z][a-z0-9_]{0,39}$/',$key)||isset($parts[$key])||in_array($key,['theory','practical'],true))throw new InvalidArgumentException('Component keys must be distinct lowercase names; keep existing keys to preserve saved scores.');
        if($label===''||mb_strlen($label)>120||!is_numeric($row['max']??null)||!is_finite((float)$row['max'])||(float)$row['max']<0)throw new InvalidArgumentException('Every component needs a label and valid non-negative full marks.');
        $max=round((float)$row['max'],2);$sum+=$max;$parts[$key]=['label'=>$label,'max'=>$max];
    }
    if(!$parts||count($parts)>15||abs($sum-$total)>0.005)throw new InvalidArgumentException('Component full marks must add up exactly to the Internal (IN) full marks.');
    return $parts;
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
