<?php
require_once __DIR__.'/grading.php';
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/assessment_service.php';
function saveExamMarks(PDO $db,int $school,int $user,string $role,array $input): void {
    if(!in_array($role,['super_admin','school_admin','exam_officer','teacher'],true))throw new InvalidArgumentException('Your role cannot enter marks.');
    $db->beginTransaction();
    try{
    $exam=schoolRecord($db,'exams',(int)($input['exam_id']??0),$school,true);
    $class=schoolRecord($db,'classes',(int)($input['class_id']??0),$school);
    if($exam['is_locked']||$exam['is_published'])throw new InvalidArgumentException('This exam is locked or published. Marks cannot be changed.');
    $section=(int)($input['section_id']??0);
    if($section && schoolRecord($db,'sections',$section,$school)['class_id']!=$class['id'])throw new InvalidArgumentException('Section does not belong to this class.');
    $q=$db->prepare("SELECT es.*,COALESCE(es.is_optional,CASE WHEN s.type='optional' THEN 1 ELSE 0 END) is_optional FROM exam_subjects es JOIN subjects s ON s.id=es.subject_id WHERE es.exam_id=? AND es.class_id=?");
    $q->execute([$exam['id'],$class['id']]);$subjects=array_column($q->fetchAll(),null,'subject_id');
    $q=$db->prepare("SELECT * FROM students WHERE school_id=? AND class_id=? AND academic_year_id=? AND status='active'");
    $q->execute([$school,$class['id'],$exam['academic_year_id']]);$students=array_column($q->fetchAll(),null,'id');
    $allowed=[];
    $classTeacher=false;
    if($role==='teacher') {
        $resultScope=teacherClassResultScope($db,$school,$user,(int)$class['id']);$classTeacher=$resultScope['class_teacher'];$allowed=$resultScope['subjects'];
    }
    $changes=[];
    foreach(($input['marks']??[]) as $id=>$entries){
        $student=$students[$id]??null;
        if(!$student || ($section && $student['section_id']!=$section))throw new InvalidArgumentException('Marks include a student outside the selected school, class, section or academic year.');
        foreach($entries as $subjectId=>$fields){
            $scheme=$subjects[$subjectId]??null;if(!$scheme)throw new InvalidArgumentException('This subject is not configured for the selected exam and class.');
            if($role==='teacher'&&!$classTeacher) {
                $scopes=$allowed[$subjectId]??[];$permitted=false;
                foreach($scopes as $scope)if(!$scope||$scope==$student['section_id'])$permitted=true;
                if(!$permitted)throw new InvalidArgumentException('Subject teachers may enter marks only for their assigned subjects and sections; class teachers may correct all subjects in their own class.');
            }
            if(!empty($scheme['is_optional'])){enrollOptionalForMarks($db,$school,$student,$scheme,$input,$class,$user);$students[$id]=$student;}
            $theory=erpMarkValue($fields['theory']??null,(float)$scheme['theory_full']);
            $practical=0.0;$breakdown=null;
            if($exam['has_internal'] && $exam['type']!=='unit' && (float)$scheme['practical_full']>0){
                $parts=[];
                foreach(cdc2083InternalComponents($scheme,$exam) as $key=>$component)$parts[$key]=erpMarkValue($fields[$key]??null,(float)$component['max']);
                $practical=in_array(null,$parts,true)?null:erpMarkValue(array_sum($parts),(float)$scheme['practical_full']);
                $breakdown=json_encode($parts);
            }
            $result=erpCalculateSubjectResult($scheme,['theory_obtained'=>$theory,'practical_obtained'=>$practical],$exam);
            $grade=$result['grade'];$gp=$result['grade_point'];$remarks=$result['remarks'];
            if($exam['result_system']==='percentage'){$grade='';$gp=0;$remarks=$result['incomplete']?'Incomplete':($result['failed']?'Fail':'Pass');}
            $changes[]=[$theory,$practical,$breakdown,$grade,$gp,$remarks,$user,$exam['id'],(int)$id,(int)$subjectId];
        }
    }
    $attendance=[];
    foreach(($input['attendance']??[]) as $id=>$days){
        if(!isset($students[$id])||($section&&$students[$id]['section_id']!=$section))throw new InvalidArgumentException('Attendance contains an invalid student.');
        if($days==='')continue;
        if(filter_var($days,FILTER_VALIDATE_INT)===false || (int)$days<0 || (int)$days>366)throw new InvalidArgumentException('Attendance days must be a whole number from 0 to 366.');
        if($role==='teacher' && !$classTeacher)continue;
        $attendance[(int)$id]=(int)$days;
    }
        $check=$db->prepare('SELECT id FROM marks WHERE exam_id=? AND student_id=? AND subject_id=?');
        $update=$db->prepare('UPDATE marks SET theory_obtained=?,practical_obtained=?,internal_breakdown=?,grade=?,grade_point=?,remarks=?,entered_by=? WHERE exam_id=? AND student_id=? AND subject_id=?');
        $insert=$db->prepare('INSERT INTO marks (theory_obtained,practical_obtained,internal_breakdown,grade,grade_point,remarks,entered_by,exam_id,student_id,subject_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach($changes as $values){$check->execute(array_slice($values,7));($check->fetchColumn()?$update:$insert)->execute($values);}
        foreach($attendance as $id=>$days){$db->prepare('DELETE FROM exam_student_attendance WHERE exam_id=? AND student_id=?')->execute([$exam['id'],$id]);$db->prepare('INSERT INTO exam_student_attendance (exam_id,student_id,present_days) VALUES (?,?,?)')->execute([$exam['id'],$id,$days]);}
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
