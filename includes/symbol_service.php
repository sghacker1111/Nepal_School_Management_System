<?php
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/previous_exam_service.php';
/** Never uses the legacy student exam-roll field as a symbol. Symbols belong to an examination. */
function examSymbolStudentQuery(PDO $db,int $school,array $exam,int $class,array $options=[]):PDOStatement{
 schoolRecord($db,'classes',$class,$school);
 $select=!empty($options['count'])?'COUNT(*)':'s.*,sy.symbol_no,sec.name section_name,y.year_bs,y.year_ad';
 $sql="SELECT $select FROM students s LEFT JOIN exam_symbols sy ON sy.student_id=s.id AND sy.exam_id=? AND sy.school_id=? LEFT JOIN sections sec ON sec.id=s.section_id JOIN academic_years y ON y.id=s.academic_year_id WHERE s.school_id=? AND s.class_id=? AND s.academic_year_id=? AND (s.status='active' OR EXISTS(SELECT 1 FROM exam_symbols historic WHERE historic.exam_id=? AND historic.student_id=s.id) OR EXISTS(SELECT 1 FROM marks m WHERE m.exam_id=? AND m.student_id=s.id))";
 $params=[$exam['id'],$school,$school,$class,$exam['academic_year_id'],$exam['id'],$exam['id']];
 if(!empty($options['search'])){
  $name=DB_TYPE==='mysql'?"CONCAT(s.first_name_en,' ',s.last_name_en)":"s.first_name_en || ' ' || s.last_name_en";
  $sql.=" AND (LOWER($name) LIKE ? OR s.roll_number LIKE ? OR LOWER(sy.symbol_no) LIKE ?)";
  $term='%'.strtolower($options['search']).'%';array_push($params,$term,$term,$term);
 }
 if(array_key_exists('ids',$options)){
  $ids=array_values(array_unique(array_map('intval',$options['ids'])));
  if(!$ids)$sql.=' AND 1=0';else{$sql.=' AND s.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($params,...$ids);}
 }
 if(empty($options['count']))$sql.=' ORDER BY LOWER(s.first_name_en),LOWER(s.last_name_en),s.id';
 if(isset($options['limit'])&&empty($options['count']))$sql.=' LIMIT '.max(1,min(100,(int)$options['limit'])).' OFFSET '.max(0,(int)($options['offset']??0));
 $q=$db->prepare($sql);$q->execute($params);return $q;
}
function examSymbolStudents(PDO $db,int $school,array $exam,int $class,array $options=[]):array{return examSymbolStudentQuery($db,$school,$exam,$class,$options)->fetchAll();}
function examSymbolStudentCount(PDO $db,int $school,array $exam,int $class,string $search=''):int{return (int)examSymbolStudentQuery($db,$school,$exam,$class,['count'=>true,'search'=>$search])->fetchColumn();}
function saveExamSymbols(PDO $db,int $school,int $examId,int $class,array $input,int $user=0):int{
 $db->beginTransaction();
 try{
  $exam=schoolRecord($db,'exams',$examId,$school,true);
  if($exam['is_locked']||$exam['is_published'])throw new InvalidArgumentException('Reopen this exam before changing its symbols.');
  $students=examSymbolStudents($db,$school,$exam,$class);if(!$students)throw new InvalidArgumentException('There are no students in this class for the exam year.');
  $mode=$input['symbol_mode']??'manual';$assign=[];
  if($mode==='alphabetical'){
   $first=$input['first_symbol']??'';if(!is_string($first)||!preg_match('/^[0-9]{1,15}$/',$first))throw new InvalidArgumentException('Enter a numeric first symbol of up to 15 digits.');
   $n=(int)$first;$width=strlen($first);
   foreach($students as $s)$assign[$s['id']]=str_pad((string)$n++,$width,'0',STR_PAD_LEFT);
  }elseif($mode==='previous'){
   $previous=schoolRecord($db,'exams',(int)($input['previous_exam_id']??0),$school);
   if(!examIsEarlier($previous,$exam))throw new InvalidArgumentException('Choose a previous exam, not this exam or a later exam.');
   $q=$db->prepare('SELECT student_id,symbol_no FROM exam_symbols WHERE school_id=? AND exam_id=?');$q->execute([$school,$previous['id']]);$previousSymbols=array_column($q->fetchAll(),'symbol_no','student_id');
   foreach($students as $s)if(isset($previousSymbols[$s['id']]))$assign[$s['id']]=$previousSymbols[$s['id']];
  }elseif($mode==='legacy'){
   // Legacy values have no exam association: copy only on the user's explicit selection.
   foreach($students as $s)if(trim((string)($s['exam_roll_number']??''))!=='')$assign[$s['id']]=trim($s['exam_roll_number']);
  }elseif($mode==='manual'){
   $allowed=array_column($students,null,'id');
   foreach(($input['symbols']??[]) as $id=>$value){
    if(!isset($allowed[$id])||!is_string($value))throw new InvalidArgumentException('Symbol entries include an invalid student.');
    $value=trim($value);if($value==='')continue;
    if(strlen($value)>100||preg_match('/[\x00-\x1f\x7f]/',$value))throw new InvalidArgumentException('Each symbol must be at most 100 characters and contain no control characters.');
    $assign[(int)$id]=$value;
   }
  }else throw new InvalidArgumentException('Choose a valid symbol assignment method.');
  if(!$assign)throw new InvalidArgumentException('No symbol numbers were supplied or found in the selected previous exam.');
  $q=$db->prepare('SELECT student_id,symbol_no FROM exam_symbols WHERE exam_id=?');$q->execute([$examId]);$current=array_column($q->fetchAll(),'symbol_no','student_id');
  foreach($assign as $id=>$value)if(isset($current[$id])&&$current[$id]!==$value&&empty($input['confirm_overwrite']))throw new InvalidArgumentException('Existing symbols would change. Confirm replacement after reviewing the student list.');
  foreach($assign as $value)if(strlen($value)>100||preg_match('/[\x00-\x1f\x7f]/',$value))throw new InvalidArgumentException('Each symbol must be at most 100 characters and contain no control characters.');
  $final=array_replace($current,$assign);$seen=[];
  foreach($final as $value){$key=strtolower($value);if(isset($seen[$key]))throw new InvalidArgumentException('Duplicate symbol numbers are not allowed within an examination, including other classes.');$seen[$key]=true;}
  // Delete/reinsert only the confirmed symbol assignments, so swaps can be atomic.
  $delete=$db->prepare('DELETE FROM exam_symbols WHERE exam_id=? AND student_id=? AND school_id=?');
  foreach($assign as $id=>$value)$delete->execute([$examId,$id,$school]);
  $insert=$db->prepare('INSERT INTO exam_symbols(exam_id,student_id,school_id,symbol_no) VALUES(?,?,?,?)');
  foreach($assign as $id=>$value)$insert->execute([$examId,$id,$school,$value]);
  $before=array_intersect_key($current,$assign);
  $db->prepare('INSERT INTO exam_symbol_history(school_id,exam_id,old_symbols,new_symbols,changed_by) VALUES(?,?,?,?,?)')->execute([$school,$examId,json_encode($before),json_encode($assign),$user?:null]);
  $db->commit();return count($assign);
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
