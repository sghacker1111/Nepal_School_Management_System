<?php
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/grading.php';
function examChronology(array $exam):array{return [$exam['start_date']?:substr($exam['created_at']??'',0,10),(int)$exam['id']];}
function examIsEarlier(array $source,array $current):bool{
 if($source['id']==$current['id'])return false;
 if(!empty($source['start_date'])&&!empty($current['start_date']))return [$source['start_date'],(int)$source['id']]<[$current['start_date'],(int)$current['id']];
 if(empty($current['start_date'])&&!empty($source['start_date'])&&$source['start_date']>(new DateTimeImmutable('today',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d'))return false;
 // An undated exam has no invented examination date: creation sequence is the fallback.
 return (int)$source['id']<(int)$current['id'];
}
function previousAssessmentExams(PDO $db,int $school,array $current):array{
 $q=$db->prepare("SELECT * FROM exams WHERE school_id=? AND academic_year_id=? AND type IN ('unit','terminal') ORDER BY start_date,id");$q->execute([$school,$current['academic_year_id']]);
 $exams=array_values(array_filter($q->fetchAll(),fn($e)=>examIsEarlier($e,$current)));
 usort($exams,fn($a,$b)=>examChronology($a)<=>examChronology($b));return $exams;
}
function convertedPreviousMark(array $marks,float $maximum):?float{
 if(!$marks||$maximum<0)return null;$obtained=0.0;$full=0.0;
 foreach($marks as $m){
  $thFull=(float)$m['theory_full'];$inFull=!empty($m['has_internal'])&&$m['type']!=='unit'?(float)$m['practical_full']:0;
  if(($thFull>0&&$m['theory_obtained']===null)||($inFull>0&&$m['practical_obtained']===null))return null;
  $th=(float)($m['theory_obtained']??0);$in=$inFull>0?(float)$m['practical_obtained']:0;
  if($th<0||$th>$thFull||$in<0||$in>$inFull||$thFull+$inFull<=0)return null;
  $obtained+=$th+$in;$full+=$thFull+$inFull;
 }
 return $full>0?round($obtained/$full*$maximum,2):null;
}
/** Read-only preview. No mark is saved until the regular marks form is submitted. */
function previousContributionPreview(PDO $db,int $school,array $current,int $class,array $subjects,array $students,array $sourceIds):array{
 $eligible=array_column(previousAssessmentExams($db,$school,$current),null,'id');$sources=[];
 foreach(array_unique($sourceIds) as $id){if(!ctype_digit((string)$id)||!isset($eligible[(int)$id]))throw new InvalidArgumentException('Previous-mark sources must be earlier exams in this school and academic year.');$sources[]=$eligible[(int)$id];}
 if(!$sources)throw new InvalidArgumentException('Select at least one earlier examination to load.');
 usort($sources,fn($a,$b)=>examChronology($a)<=>examChronology($b));
 if(in_array($current['type'],['final','annual'],true)&&count($sources)>2)throw new InvalidArgumentException('Choose at most two previous terminal exams for the separate first/second trimester fields.');
 if(empty($current['has_internal'])||$current['type']==='unit')throw new InvalidArgumentException('Enable internal assessment before loading previous contributions.');
 $matrix=[];
 foreach($sources as $source){
  $q=$db->prepare('SELECT m.*,es.theory_full,es.practical_full,e.has_internal,e.type FROM marks m JOIN students s ON s.id=m.student_id JOIN exams e ON e.id=m.exam_id JOIN exam_subjects es ON es.exam_id=m.exam_id AND es.subject_id=m.subject_id AND es.class_id=s.class_id WHERE m.exam_id=? AND e.school_id=? AND s.school_id=? AND s.class_id=? AND s.academic_year_id=?');
  $q->execute([$source['id'],$school,$school,$class,$current['academic_year_id']]);
  foreach($q as $m)$matrix[$source['id']][$m['student_id']][$m['subject_id']]=$m;
 }
 $preview=[];$loaded=0;$skipped=0;
 foreach($students as $student)foreach($subjects as $scheme){
  $subject=$scheme['subject_id'];
  if(!empty($scheme['is_optional'])&&!in_array($subject,[$student['optional_subject_i'],$student['optional_subject_ii'],$student['optional_subject_iii']??null]))continue;
  $components=cdc2083InternalComponents($scheme,$current);
  if(isset($components['previous_exam'])){
   $rows=[];foreach($sources as $source){$m=$matrix[$source['id']][$student['id']][$subject]??null;if(!$m){$rows=[];break;}$rows[]=$m;}
   $value=convertedPreviousMark($rows,(float)$components['previous_exam']['max']);
   if($value===null){$skipped++;continue;}$preview[$student['id']][$subject]['previous_exam']=$value;$loaded++;
  }else{
   foreach(['first_trimester','second_trimester'] as $i=>$key){if(!isset($components[$key],$sources[$i]))continue;$m=$matrix[$sources[$i]['id']][$student['id']][$subject]??null;
    $value=convertedPreviousMark($m?[$m]:[],(float)$components[$key]['max']);
    if($value===null){$skipped++;continue;}$preview[$student['id']][$subject][$key]=$value;$loaded++;
   }
  }
 }
 return ['values'=>$preview,'loaded'=>$loaded,'skipped'=>$skipped,'sources'=>array_column($sources,'id')];
}
