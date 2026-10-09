<?php
require_once __DIR__.'/school_validation.php';
function routineTeacherAvailable(PDO $db,int $school,int $class,int $day,int $period,?int $teacher): bool {
    if(!$teacher)return true;
    $q=$db->prepare('SELECT id FROM class_routines WHERE school_id=? AND class_id<>? AND day_of_week=? AND period_number=? AND teacher_id=?');
    $q->execute([$school,$class,$day,$period,$teacher]);return !$q->fetchColumn();
}
function buildClassRoutine(PDO $db,int $school,array $class,array $subjects,array $days,int $periods): array {
    $remaining=[];$grid=[];$teachers=[];
    foreach($subjects as $subject){
        $id=(int)$subject['subject_id'];$remaining[$id]=max(0,(int)round((float)$subject['credit_hours']));
        $teacherIds=!empty($class['is_grading_teaching'])?[(int)($class['class_teacher_id']??0)]:array_map('intval',explode(',',(string)$subject['teacher_id']));
        $teachers[$id]=[];
        foreach($teacherIds as $teacherId){if(!$teacherId)continue;$teacher=schoolRecord($db,'users',$teacherId,$school);if($teacher['role']==='teacher'&&$teacher['status'])$teachers[$id][]=$teacherId;}
    }
    // Prefer the subjects with the most remaining weekly periods. Never over-allocate credits.
    for($period=1;$period<=$periods;$period++)foreach($days as $day){
        arsort($remaining);$slot=['subject_id'=>null,'teacher_id'=>null];
        foreach($remaining as $subjectId=>$left){
            if($left<=0)continue;
            foreach($teachers[$subjectId]?:[null] as $teacherId){
                if(!routineTeacherAvailable($db,$school,(int)$class['id'],$day,$period,$teacherId))continue;
                $slot=['subject_id'=>$subjectId,'teacher_id'=>$teacherId];$remaining[$subjectId]--;break 2;
            }
        }
        $grid[$day][$period]=$slot;
    }
    return ['grid'=>$grid,'unplaced'=>array_sum($remaining)];
}
function validateRoutineSlot(PDO $db,int $school,int $class,int $day,int $period,?int $subject,?int $teacher): void {
    schoolRecord($db,'classes',$class,$school);
    if(!in_array($day,[1,2,3,4,5,7],true)||$period<1||$period>10)throw new InvalidArgumentException('Invalid school day or period.');
    if($subject){
        schoolRecord($db,'subjects',$subject,$school);
        $q=$db->prepare('SELECT id FROM class_subjects WHERE class_id=? AND subject_id=? AND school_id=? AND is_active=1');$q->execute([$class,$subject,$school]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Choose a subject assigned to this class.');
    }
    if($teacher){$row=schoolRecord($db,'users',$teacher,$school);if($row['role']!=='teacher'||!$row['status'])throw new InvalidArgumentException('Choose an active school teacher.');}
    if(!routineTeacherAvailable($db,$school,$class,$day,$period,$teacher))throw new InvalidArgumentException('This teacher is already scheduled in another class for this period.');
}
