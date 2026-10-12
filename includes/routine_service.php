<?php
require_once __DIR__.'/school_validation.php';
function routineTeacherAvailable(PDO $db,int $school,int $class,int $day,int $period,?int $teacher,?string $start=null,?string $end=null):bool {
    if(!$teacher)return true;
    $q=$db->prepare('SELECT r.period_number,r.start_time,r.end_time FROM class_routines r JOIN classes c ON c.id=r.class_id AND c.school_id=r.school_id AND c.is_active=1 WHERE r.school_id=? AND r.class_id<>? AND r.day_of_week=? AND r.teacher_id=?');
    $q->execute([$school,$class,$day,$teacher]);
    foreach($q as $slot){
        if($start&&$end&&$slot['start_time']&&$slot['end_time']){
            if($start<$slot['end_time']&&$end>$slot['start_time'])return false;
        }elseif((int)$slot['period_number']===$period)return false;
    }
    return true;
}
function routineSubjects(PDO $db,int $school,int $class):array {
    $q=$db->prepare('SELECT cs.*,s.name_en,s.name_np,sc.name section_name FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id LEFT JOIN sections sc ON sc.id=cs.section_id WHERE cs.school_id=? AND cs.class_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND COALESCE(s.is_hidden,0)=0 ORDER BY CASE WHEN cs.is_optional=1 THEN 1 ELSE 0 END,cs.subject_order,s.id,cs.id');
    $q->execute([$school,$class]);return $q->fetchAll();
}
function routineWeeklyPeriods(array $subject):int {
    // Backward-compatible suggestion only; a saved override never changes grading credit hours.
    return $subject['weekly_periods']!==null?(int)$subject['weekly_periods']:max(0,(int)round((float)($subject['credit_hours']??0)));
}
function buildClassRoutine(PDO $db,int $school,array $class,array $subjects,array $days,int $periods,?array $layout=null,bool $checkAvailability=true):array {
    if(!$days||array_diff($days,[1,2,3,4,5])||$periods<1||$periods>10)throw new InvalidArgumentException('School days are Monday to Friday, with 1–10 periods per day.');
    $remaining=[];$grid=[];$teachers=[];$ct=(int)($class['class_teacher_id']??0);
    foreach($subjects as $subject){
        $id=(int)$subject['subject_id'];$weekly=isset($subject['weekly_periods'])?(int)$subject['weekly_periods']:max(0,(int)round((float)$subject['credit_hours']));
        $remaining[$id]=max($remaining[$id]??0,$weekly);
        $ids=!empty($class['is_grading_teaching'])?[$ct]:array_map('intval',explode(',',(string)$subject['teacher_id']));
        foreach($ids as $idTeacher){if(!$idTeacher)continue;$teacher=schoolRecord($db,'users',$idTeacher,$school);if($teacher['role']==='teacher'&&$teacher['status'])$teachers[$id][$idTeacher]=$idTeacher;}
    }
    $capacity=$layout?array_sum(array_column($layout,'count')):count($days)*$periods;
    if(array_sum($remaining)>$capacity)throw new InvalidArgumentException('Weekly subject periods exceed the available Monday–Friday timetable slots.');
    if($ct){
        $ctSubjects=array_keys(array_filter($teachers,static fn($ids)=>isset($ids[$ct])));
        if(array_sum(array_intersect_key($remaining,array_flip($ctSubjects)))<count($days))throw new InvalidArgumentException('Assign the class teacher to a class subject and give their subjects at least five weekly periods for daily first periods.');
        foreach($days as $day){
            $time=$layout[$day]['times'][1]??[];
            if($checkAvailability&&!routineTeacherAvailable($db,$school,(int)$class['id'],$day,1,$ct,$time['start']??null,$time['end']??null))throw new InvalidArgumentException('The class teacher is already booked in another class during first period. Resolve that conflict before generating.');
            arsort($remaining);foreach($remaining as $id=>$left)if($left>0&&in_array($id,$ctSubjects,true)){$grid[$day][1]=['subject_id'=>$id,'teacher_id'=>$ct];$remaining[$id]--;break;}
        }
    }
    for($period=1;$period<=$periods;$period++)foreach($days as $day){
        if($layout&&$period>$layout[$day]['count'])continue;
        if(isset($grid[$day][$period]))continue;
        arsort($remaining);$slot=['subject_id'=>null,'teacher_id'=>null];
        foreach($remaining as $id=>$left){
            if($left<=0)continue;
            foreach($teachers[$id]??[] as $teacher){
                $time=$layout[$day]['times'][$period]??[];
                if($checkAvailability&&!routineTeacherAvailable($db,$school,(int)$class['id'],$day,$period,$teacher,$time['start']??null,$time['end']??null))continue;
                $slot=['subject_id'=>$id,'teacher_id'=>$teacher];$remaining[$id]--;break 2;
            }
        }
        $grid[$day][$period]=$slot;
    }
    return ['grid'=>$grid,'unplaced'=>array_sum($remaining)];
}
function validateRoutineSlot(PDO $db,int $school,int $class,int $day,int $period,?int $subject,?int $teacher,?string $start=null,?string $end=null,bool $checkAvailability=true):void {
    $classRow=schoolRecord($db,'classes',$class,$school);
    if(!in_array($day,[1,2,3,4,5],true)||$period<1||$period>10)throw new InvalidArgumentException('Choose Monday–Friday and a valid period.');
    if((bool)$subject!==(bool)$teacher)throw new InvalidArgumentException('Choose both a subject and its teacher, or leave both blank for a free period.');
    if($subject){
        schoolRecord($db,'subjects',$subject,$school);
        $rows=array_filter(routineSubjects($db,$school,$class),static fn($r)=>(int)$r['subject_id']===$subject);
        if(!$rows)throw new InvalidArgumentException('Choose a visible subject assigned to this class.');
        $row=schoolRecord($db,'users',(int)$teacher,$school);
        if($row['role']!=='teacher'||!$row['status'])throw new InvalidArgumentException('Choose an active school teacher.');
        $allowed=!empty($classRow['is_grading_teaching'])&&(int)$classRow['class_teacher_id']===$teacher;
        foreach($rows as $mapping)if(teacherAssignmentContains($mapping['teacher_id'],(int)$teacher))$allowed=true;
        if(!$allowed)throw new InvalidArgumentException('This teacher is not assigned to this class subject. Update weekly subject requirements first.');
    }
    if($period===1){
        if(empty($classRow['class_teacher_id']))throw new InvalidArgumentException('Assign a class teacher before creating the first period.');
        if($teacher!==(int)$classRow['class_teacher_id'])throw new InvalidArgumentException('First period must use this class teacher and one of their assigned subjects.');
    }
    if($checkAvailability&&!routineTeacherAvailable($db,$school,$class,$day,$period,$teacher,$start,$end))throw new InvalidArgumentException('This teacher is already scheduled in another class for this period or overlapping time.');
}
function routineArchive(PDO $db,int $school,int $actor,int $class,?int $day=null):void {
    $q=$db->prepare('SELECT COALESCE(MAX(version_id),0)+1 FROM class_routines_history WHERE school_id=?');$q->execute([$school]);$version=(int)$q->fetchColumn();
    $where='school_id=? AND class_id=?';$params=[$school,$class];if($day!==null){$where.=' AND day_of_week=?';$params[]=$day;}
    $db->prepare('INSERT INTO class_routines_history(version_id,class_id,day_of_week,period_number,subject_id,teacher_id,school_id,changed_by_user_id,start_time,end_time) SELECT ?,class_id,day_of_week,period_number,subject_id,teacher_id,school_id,?,start_time,end_time FROM class_routines WHERE '.$where)->execute([$version,$actor,...$params]);
    $db->prepare('DELETE FROM class_routines WHERE '.$where)->execute($params);
}
function saveRoutineRequirements(PDO $db,int $school,int $class,array $input):void {
    $rows=routineSubjects($db,$school,$class);$ids=array_column($rows,'id');
    if(array_diff(array_keys($input),$ids))throw new InvalidArgumentException('Weekly requirements include an invalid class subject.');
    foreach($rows as $row){
        $value=$input[$row['id']]??null;if(!is_array($value))throw new InvalidArgumentException('Submit every displayed subject requirement.');
        $weekly=$value['weekly']??'';if(!is_scalar($weekly)||!preg_match('/^\d{1,2}$/',(string)$weekly)||(int)$weekly>50)throw new InvalidArgumentException('Weekly periods must be a whole number from 0 to 50.');
        $teachers=$value['teachers']??[];if(!is_array($teachers))throw new InvalidArgumentException('Choose valid subject teachers.');
        foreach($teachers as $id)if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/',(string)$id))throw new InvalidArgumentException('Choose valid subject teachers.');
        $teachers=array_unique(array_map('intval',$teachers));
        foreach($teachers as $teacher){$user=schoolRecord($db,'users',$teacher,$school);if($user['role']!=='teacher'||!$user['status'])throw new InvalidArgumentException('Choose active teachers from this school.');}
        if((int)$weekly>0&&!$teachers)throw new InvalidArgumentException('Assign a teacher to every subject with weekly periods.');
        $db->prepare('UPDATE class_subjects SET weekly_periods=?,teacher_id=? WHERE id=? AND school_id=? AND class_id=?')->execute([(int)$weekly,implode(',',$teachers),$row['id'],$school,$class]);
    }
}
function saveRoutineDay(PDO $db,int $school,int $actor,int $class,int $day,int $periods,array $grid):void {
    if(count($grid)!==$periods||array_diff(array_keys($grid),range(1,$periods)))throw new InvalidArgumentException('Submit every period. Reload the routine before trying again.');
    $counts=[];$subjects=routineSubjects($db,$school,$class);$limits=[];
    foreach($subjects as $subject)$limits[$subject['subject_id']]=max($limits[$subject['subject_id']]??0,routineWeeklyPeriods($subject));
    $q=$db->prepare('SELECT subject_id,COUNT(*) n FROM class_routines WHERE school_id=? AND class_id=? AND day_of_week<>? AND day_of_week BETWEEN 1 AND 5 AND subject_id IS NOT NULL GROUP BY subject_id');$q->execute([$school,$class,$day]);foreach($q as $r)$counts[$r['subject_id']]=(int)$r['n'];
    for($p=1;$p<=$periods;$p++){
        $cell=$grid[$p]??[];
        if(!is_array($cell)||!array_key_exists('subject',$cell)||!array_key_exists('teacher',$cell))throw new InvalidArgumentException('Submit every subject and teacher field.');
        foreach(['subject','teacher'] as $key)if(!is_scalar($cell[$key])||($cell[$key]!==''&&!preg_match('/^[1-9][0-9]*$/',(string)$cell[$key])))throw new InvalidArgumentException('Choose valid period subjects and teachers.');
        $subject=empty($cell['subject'])?null:(int)$cell['subject'];$teacher=empty($cell['teacher'])?null:(int)$cell['teacher'];
        validateRoutineSlot($db,$school,$class,$day,$p,$subject,$teacher);
        if($subject&&($counts[$subject]=($counts[$subject]??0)+1)>($limits[$subject]??0))throw new InvalidArgumentException('This change exceeds a subject’s total weekly periods. Update its weekly requirements first.');
    }
    routineArchive($db,$school,$actor,$class,$day);
    $q=$db->prepare('INSERT INTO class_routines(class_id,day_of_week,period_number,subject_id,teacher_id,school_id) VALUES(?,?,?,?,?,?)');
    for($p=1;$p<=$periods;$p++)$q->execute([$class,$day,$p,empty($grid[$p]['subject'])?null:(int)$grid[$p]['subject'],empty($grid[$p]['teacher'])?null:(int)$grid[$p]['teacher'],$school]);
}
