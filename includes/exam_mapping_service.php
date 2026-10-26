<?php
require_once __DIR__.'/grading.php';
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/exam_template_service.php';
function examMappingRevision(array $mapping):string{return hash('sha256',json_encode($mapping));}
/** Changes limits, not entered scores. Confirmation and revision guard prevent stale edits. */
function saveExamMapping(PDO $db,int $school,int $examId,array $config,int $mappingId,int $user,array $confirmation=[]):int{
 $db->beginTransaction();
 try{
  $exam=schoolRecord($db,'exams',$examId,$school,true);
  assertExamConfigurationEditable($exam);
  if($exam['is_locked']||$exam['is_published'])throw new InvalidArgumentException('Reopen this locked or published exam before changing subjects.');
  schoolRecord($db,'classes',(int)$config['class_id'],$school);schoolRecord($db,'subjects',(int)$config['subject_id'],$school);
  if(!schoolSubjectAssigned($db,$school,(int)$config['class_id'],(int)$config['subject_id']))throw new InvalidArgumentException('Assign this subject to the school class before configuring its exam.');
  $old=null;$marks=[];
  if($mappingId){
   $q=$db->prepare('SELECT * FROM exam_subjects WHERE id=? AND exam_id=?');$q->execute([$mappingId,$examId]);$old=$q->fetch();
   if(!$old)throw new InvalidArgumentException('The subject mapping no longer exists.');
   if(array_key_exists('mapping_revision',$confirmation)&&!hash_equals(examMappingRevision($old),(string)$confirmation['mapping_revision']))throw new InvalidArgumentException('This mapping changed since you opened it. Reload and review the warning again.');
   if(!array_key_exists('internal_components',$config))$config['internal_components']=$old['internal_components']??null;
   if($config['class_id']==$old['class_id']&&$config['subject_id']==$old['subject_id']){
    // Editing marks/credits is not an implicit migration to another assessment profile.
    foreach(['assessment_method','curriculum_version','is_optional'] as $key)$config[$key]=$old[$key]??null;
   }
   $q=$db->prepare('SELECT m.* FROM marks m JOIN students s ON s.id=m.student_id WHERE m.exam_id=? AND m.subject_id=? AND s.class_id=? AND s.school_id=?');
   $q->execute([$examId,$old['subject_id'],$old['class_id'],$school]);$marks=$q->fetchAll();
   if($marks){
    if(empty($confirmation['confirm_grade_change']))throw new InvalidArgumentException('Confirm the warning before changing a mapping with saved marks.');
    if(!hash_equals(examMappingRevision($old),(string)($confirmation['mapping_revision']??'')))throw new InvalidArgumentException('This mapping changed since you opened it. Reload and review the warning again.');
    if($config['class_id']!=$old['class_id']||$config['subject_id']!=$old['subject_id'])throw new InvalidArgumentException('A mapping with marks cannot be moved to another class or subject. Add a separate mapping instead.');
   }
  }
  foreach(['theory_full','theory_pass','practical_full','practical_pass'] as $key){if(!is_numeric($config[$key])||!is_finite((float)$config[$key])||(float)$config[$key]<0)throw new InvalidArgumentException('Full and pass marks must be valid non-negative numbers.');}
  if($config['theory_full']+$config['practical_full']<=0||$config['theory_pass']>$config['theory_full']||$config['practical_pass']>$config['practical_full'])throw new InvalidArgumentException('Pass marks cannot exceed full marks; the total full marks must be positive.');
  foreach(['credit_hours','internal_credit_hours','external_credit_hours'] as $key)if($config[$key]!==null&&(!is_finite((float)$config[$key])||(float)$config[$key]<0))throw new InvalidArgumentException('Credit hours must be non-negative.');
  $q=$db->prepare('SELECT id FROM exam_subjects WHERE exam_id=? AND class_id=? AND subject_id=? AND id<>?');$q->execute([$examId,$config['class_id'],$config['subject_id'],$mappingId]);if($q->fetch())throw new InvalidArgumentException('This subject is already configured for this exam and class.');
  foreach($marks as $m){
   erpMarkValue($m['theory_obtained'],$config['theory_full']);erpMarkValue($m['practical_obtained'],$config['practical_full']);
   $parts=json_decode($m['internal_breakdown']??'null',true);
   if(is_array($parts))foreach($parts as $key=>$value)if($value!==null&&!array_key_exists($key,cdc2083InternalComponents($config,$exam)))throw new InvalidArgumentException('A component with saved marks cannot be removed. Keep its key and edit its label or limit instead.');
   if(is_array($parts))foreach(cdc2083InternalComponents($config,$exam) as $key=>$component)erpMarkValue($parts[$key]??null,$component['max']);
  }
  $keys=['class_id','subject_id','theory_full','theory_pass','practical_full','practical_pass','credit_hours','internal_credit_hours','external_credit_hours','is_optional','assessment_method','curriculum_version','internal_components'];
  $values=array_map(fn($k)=>$config[$k]??null,$keys);
  if($old){$db->prepare('UPDATE exam_subjects SET '.implode(',',array_map(fn($k)=>"$k=?",$keys)).' WHERE id=? AND exam_id=?')->execute([...$values,$mappingId,$examId]);}
  else{$db->prepare('INSERT INTO exam_subjects ('.implode(',',$keys).',exam_id) VALUES ('.implode(',',array_fill(0,count($keys)+1,'?')).')')->execute([...$values,$examId]);$mappingId=(int)$db->lastInsertId();}
  if($old)$db->prepare('INSERT INTO exam_scheme_history(school_id,exam_id,mapping_id,old_config,new_config,changed_by) VALUES(?,?,?,?,?,?)')->execute([$school,$examId,$mappingId,json_encode($old),json_encode($config),$user]);
  foreach($marks as $m){
   $result=erpCalculateSubjectResult($config,$m,$exam);$grade=$result['grade'];$gp=$result['grade_point'];$remarks=$result['remarks'];
   if($exam['result_system']==='percentage'){$grade='';$gp=0;$remarks=$result['incomplete']?'Incomplete':($result['failed']?'Fail':'Pass');}
   $db->prepare('UPDATE marks SET grade=?,grade_point=?,remarks=? WHERE id=?')->execute([$grade,$gp,$remarks,$m['id']]);
  }
  $db->commit();return $mappingId;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
