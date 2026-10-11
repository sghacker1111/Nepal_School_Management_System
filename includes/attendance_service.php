<?php
require_once __DIR__.'/school_validation.php';
require_once __DIR__.'/nepali_dates.php';
require_once __DIR__.'/student_search.php';

function attendanceToday():string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d');}
function attendanceMonths(string $calendar):array {
    return $calendar==='BS'?['Baishak','Jestha','Asad','Shrawan','Bhadra','Asoj','Kartik','Mangsir','Paush','Magh','Falgun','Chaitra']:['January','February','March','April','May','June','July','August','September','October','November','December'];
}
/** Resolve calendar periods once. Database queries and form keys always use real AD dates. */
function attendancePeriod(array $input,?string $today=null):array {
    $today??=attendanceToday();$view=$input['view']??'daily';$calendar=$input['calendar']??'AD';
    if(!in_array($view,['daily','monthly','yearly','range'],true)||!in_array($calendar,['AD','BS'],true))throw new InvalidArgumentException('Choose a valid attendance view and AD or BS calendar.');
    foreach(['date_input','date','year','month','range_start','range_end'] as $field)if(isset($input[$field])&&!is_scalar($input[$field]))throw new InvalidArgumentException('Enter valid attendance filter values.');
    $current=$calendar==='BS'?schoolAdToBs($today):$today;
    $year=(int)schoolDateDigits((string)($input['year']??substr($current,0,4)));$month=(int)schoolDateDigits((string)($input['month']??substr($current,5,2)));
    $dateInput=$input['date_input']??($calendar==='AD'?($input['date']??$today):$current);
    $fromInput=$input['range_start']??$current;$toInput=$input['range_end']??$current;
    if($view==='daily'){$start=$end=schoolDateToAd($dateInput,$calendar);}
    elseif($view==='range'){$start=schoolDateToAd($fromInput,$calendar);$end=schoolDateToAd($toInput,$calendar);}
    else {
        if(!preg_match('/^\d{4}$/',schoolDateDigits((string)($input['year']??$year)))||!preg_match('/^\d{1,2}$/',schoolDateDigits((string)($input['month']??$month)))||$year<1900||$year>2100||$month<1||$month>12)throw new InvalidArgumentException('Choose a valid attendance year and month.');
        $first=$view==='yearly'?1:$month;$last=$view==='yearly'?12:$month;
        if($calendar==='BS'){
            $years=schoolCalendar()['years'];if(!isset($years[$year]))throw new InvalidArgumentException('BS attendance supports years 2000–2090.');
            $start=schoolDateToAd(sprintf('%04d-%02d-01',$year,$first),'BS');
            $end=schoolDateToAd(sprintf('%04d-%02d-%02d',$year,$last,$years[$year][$last-1]),'BS');
        }else {
            $start=sprintf('%04d-%02d-01',$year,$first);
            $end=(new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$last)))->modify('last day of this month')->format('Y-m-d');
        }
    }
    if(!$start||!$end||$end<$start)throw new InvalidArgumentException('Attendance end date must be on or after the start date.');
    $days=(int)(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->format('%a')+1;
    if($days>366)throw new InvalidArgumentException('Choose an attendance date range of at most 366 days.');
    return compact('view','calendar','year','month','start','end','days','dateInput','fromInput','toInput');
}
function attendanceWindow(array $period,int $page=1):array {
    $pages=(int)ceil($period['days']/32);$page=max(1,min($pages,$page));$dates=[];
    $first=(new DateTimeImmutable($period['start']))->modify('+'.(($page-1)*32).' days');
    for($i=0;$i<32;$i++){$date=$first->modify('+'.$i.' days')->format('Y-m-d');if($date>$period['end'])break;$dates[]=$date;}
    return ['page'=>$page,'pages'=>$pages,'dates'=>$dates];
}
function attendanceUrl(array $filters,array $change=[]):string {return APP_URL.'/modules/attendance/index.php?'.http_build_query(array_replace($filters,$change));}
function attendanceClass(PDO $db,int $school,int $actor,string $role,int $class,bool $write=false):array {
    $record=schoolRecord($db,'classes',$class,$school,$write);
    if(!$record['is_active'])throw new InvalidArgumentException('Choose an active attendance class.');
    if($role==='teacher'&&!teacherClassResultScope($db,$school,$actor,$class)['class_teacher'])throw new InvalidArgumentException('Only the assigned class teacher may access this class attendance.');
    if($write&&!in_array($role,['super_admin','school_admin','teacher'],true))throw new InvalidArgumentException('Your role can view attendance but cannot save changes.');
    return $record;
}
function attendanceStudents(PDO $db,int $school,int $class,int $section,string $search,array $period):array {
    $params=[$school,$class,$period['start'],$period['end']];
    $sql="SELECT s.id,s.student_id,s.first_name_en,s.last_name_en,s.roll_number,s.status,s.section_id FROM students s WHERE s.school_id=? AND s.class_id=? AND (s.status='active' OR EXISTS(SELECT 1 FROM attendances a WHERE a.student_id=s.id AND a.date_ad BETWEEN ? AND ?))";
    if($section){$sql.=' AND s.section_id=?';$params[]=$section;}
    $sql.=studentSearchWhere($search,$params);$sql.=' ORDER BY s.roll_number,s.first_name_en,s.id';
    $q=$db->prepare($sql);$q->execute($params);return $q->fetchAll();
}
/** Atomic daily/multi-day saves; a blank cell preserves history, never deletes it. */
function saveStudentAttendance(PDO $db,int $school,int $actor,string $role,array $input,array $period,array $visibleStudents,array $visibleDates):int {
    $db->beginTransaction();
    try {
        $class=(int)($input['class_id']??0);attendanceClass($db,$school,$actor,$role,$class,true);
        $section=(int)($input['section_id']??0);
        if($section&&schoolRecord($db,'sections',$section,$school)['class_id']!=$class)throw new InvalidArgumentException('Section does not belong to this class.');
        $students=array_column($visibleStudents,null,'id');
        $grid=$input['attendance_grid']??null;
        if($grid===null){
            $date=schoolDateToAd($input['date_ad']??null);$grid=[];
            if(!is_array($input['attendance']??null))throw new InvalidArgumentException('No attendance cells were submitted.');
            foreach($input['attendance'] as $id=>$status)$grid[$id]=[$date=>$status];
        }
        if(!is_array($grid))throw new InvalidArgumentException('Invalid attendance grid.');
        $cells=0;foreach($grid as $row){if(!is_array($row))throw new InvalidArgumentException('Invalid attendance row.');$cells+=count($row);}
        if(isset($input['expected_cells'])&&(int)$input['expected_cells']!==$cells)throw new InvalidArgumentException('The attendance form was incomplete. Reload the page and save again; no records were changed.');
        if($cells>320)throw new InvalidArgumentException('Save at most 10 students and 32 dates per page.');
        $check=$db->prepare('SELECT status FROM attendances WHERE student_id=? AND date_ad=?'.(DB_TYPE==='mysql'?' FOR UPDATE':''));$changes=[];$needsPassword=false;
        foreach($grid as $id=>$row){
            $student=$students[$id]??null;
            if(!$student||$student['status']!=='active'||($section&&(int)$student['section_id']!==$section))throw new InvalidArgumentException('Attendance includes a student outside the visible active class or section.');
            $fresh=schoolRecord($db,'students',(int)$id,$school,true);
            if((int)$fresh['class_id']!==$class||$fresh['status']!=='active'||($section&&(int)$fresh['section_id']!==$section))throw new InvalidArgumentException('Student enrollment changed. Reload attendance before saving.');
            foreach($row as $date=>$status){
                $date=schoolDateToAd($date);
                if($date<$period['start']||$date>$period['end']||!in_array($date,$visibleDates,true)||$date>attendanceToday())throw new InvalidArgumentException('Attendance date is outside the selected page or is in the future.');
                if(!is_string($status)||!in_array($status,['','P','A','L','H'],true))throw new InvalidArgumentException('Choose Present, Absent, Late or Holiday.');
                if($status==='')continue;
                $check->execute([$id,$date]);$old=$check->fetchColumn();
                if($old===$status)continue;
                if($old!==false)$needsPassword=true;
                try{$bs=schoolAdToBs($date);}catch(InvalidArgumentException $e){$bs=null;}
                $changes[]=[$status,$bs,(int)$id,$date,$old!==false];
            }
        }
        if($needsPassword){
            $q=$db->prepare('SELECT password FROM users WHERE id=? AND status=1');$q->execute([$actor]);$hash=$q->fetchColumn();
            if(!$hash||!is_string($input['edit_password']??null)||!password_verify($input['edit_password'],$hash))throw new InvalidArgumentException('Your current password is required to correct saved attendance; no records were changed.');
        }
        $update=$db->prepare('UPDATE attendances SET status=?,date_bs=? WHERE student_id=? AND date_ad=?');
        $insert=$db->prepare('INSERT INTO attendances(status,date_bs,student_id,date_ad) VALUES(?,?,?,?)');
        foreach($changes as $change){$exists=array_pop($change);($exists?$update:$insert)->execute($change);}
        $db->commit();return count($changes);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
