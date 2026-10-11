<?php
require_once __DIR__.'/teaching_scope.php';
/** Class-teacher result authority is separate from curriculum/subject ownership. */
function teacherClassResultScope(PDO $db,int $school,int $teacher,int $class):array {
    $q=$db->prepare("SELECT c.class_teacher_id FROM classes c JOIN users u ON u.id=? AND u.school_id=c.school_id AND u.role='teacher' AND u.status=1 WHERE c.id=? AND c.school_id=? AND c.is_active=1");
    $q->execute([$teacher,$class,$school]);$owner=$q->fetchColumn();
    if($owner!==false&&(int)$owner===$teacher)return ['class_teacher'=>true,'subjects'=>[]];
    $subjects=[];
    foreach(teacherTeachingAssignments($db,$school,$teacher) as $row)if((int)$row['class_id']===$class)$subjects[(int)$row['subject_id']][]=(int)($row['section_id']??0);
    return ['class_teacher'=>false,'subjects'=>$subjects];
}
function resultClasses(PDO $db,int $school,string $role,int $actor,bool $viewAll=false):array {
    $q=$db->prepare('SELECT * FROM classes WHERE school_id=? ORDER BY id');$q->execute([$school]);$classes=$q->fetchAll();
    if($role!=='teacher')return $classes;
    $q=$db->prepare("SELECT id FROM users WHERE id=? AND school_id=? AND role='teacher' AND status=1");$q->execute([$actor,$school]);if(!$q->fetchColumn())return [];
    if($viewAll)return $classes;
    $assigned=array_column(teacherTeachingAssignments($db,$school,$actor),'class_id');
    return array_values(array_filter($classes,static fn($class)=>$class['is_active']&&((int)($class['class_teacher_id']??0)===$actor||in_array($class['id'],$assigned))));
}
function teacherResultCellAllowed(array $scope,int $subject,int $section):bool {
    return $scope['class_teacher']||in_array(0,$scope['subjects'][$subject]??[],true)||in_array($section,$scope['subjects'][$subject]??[],true);
}
function teacherResultSubjects(array $subjects,array $scope,int $section=0):array {
    if($scope['class_teacher'])return $subjects;
    return array_values(array_filter($subjects,static fn($subject)=>isset($scope['subjects'][(int)$subject['subject_id']])&&(!$section||teacherResultCellAllowed($scope,(int)$subject['subject_id'],$section))));
}
function validateTeacherResultRequest(PDO $db,int $school,string $module,string $file,array $data,array $query):void {
    if($module!=='results'||!in_array($file,['marks_entry.php','ledger.php'],true)||Auth::getRole()!=='teacher')return;
    // School-scoped ledger viewing is deliberately broader than marks-entry authority.
    if($file==='ledger.php')return;
    $class=(int)($data['class_id']??$query['class']??0);if(!$class)return;
    $scope=teacherClassResultScope($db,$school,(int)Auth::getUserId(),$class);
    if($scope['class_teacher'])return;
    if(!$scope['subjects'])throw new InvalidArgumentException('You may access results only for classes you teach or supervise as class teacher.');
    $section=(int)($data['section_id']??$query['section']??0);$subject=(int)($query['subject']??0);
    if($subject&&!isset($scope['subjects'][$subject]))throw new InvalidArgumentException('Subject teachers may access only their assigned subject ledger and marks.');
    if($section){$permitted=false;foreach($scope['subjects'] as $id=>$sections)if((!$subject||$subject===$id)&&teacherResultCellAllowed($scope,$id,$section))$permitted=true;
        if(!$permitted)throw new InvalidArgumentException('This section is not assigned to you for the selected subject.');}
}
