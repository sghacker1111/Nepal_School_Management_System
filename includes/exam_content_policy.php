<?php
/** Exam reads are stricter than the normal school's shared Learning library. */
function examContentScopeAllowed(PDO $db,array $actor,array $scope):bool {
    return $actor['role']!=='teacher'||teacherOwnsSubject($db,(int)$actor['school_id'],(int)$actor['id'],(int)$scope['class_id'],(int)$scope['subject_id']);
}
function examReadSubjects(PDO $db,array $actor,int $class):array {
    return array_values(array_filter(learningReadSubjects($db,$actor,$class),static fn($s)=>examContentScopeAllowed($db,$actor,['class_id'=>$class,'subject_id'=>(int)$s['id']])));
}
function examReadClasses(PDO $db,array $actor):array {
    $classes=learningReadClasses($db,$actor);
    if($actor['role']!=='teacher')return $classes;
    return array_values(array_filter($classes,static fn($c)=>(bool)examReadSubjects($db,$actor,(int)$c['id'])));
}
