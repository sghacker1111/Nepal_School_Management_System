<?php
require_once __DIR__.'/routine_service.php';

function routineTeacherInitials(string $name):string {
    $letters=[];
    foreach(preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY) as $part){
        preg_match('/^./u',$part,$match);$letters[]=$match[0]??'';
    }
    return function_exists('mb_strtoupper')?mb_strtoupper(implode('',$letters),'UTF-8'):strtoupper(implode('',$letters));
}
function routineDefaultLayout(int $periods):array {
    $layout=[];
    for($d=1;$d<=5;$d++){
        $time=9*60;$times=[];
        for($p=1;$p<=$periods;$p++){
            if($p===3)$time+=15;
            if($p===5)$time+=30;
            $times[$p]=['start'=>sprintf('%02d:%02d',intdiv($time,60),$time%60),'end'=>sprintf('%02d:%02d',intdiv($time+45,60),($time+45)%60)];$time+=45;
        }
        $layout[$d]=['count'=>$periods,'times'=>$times,'breaks'=>['short'=>['start'=>$periods>2?'10:30':'','end'=>$periods>2?'10:45':''],'tiffin'=>['start'=>$periods>4?'12:15':'','end'=>$periods>4?'12:45':'']]];
    }
    return $layout;
}
function routineTime($value):string {
    if(!is_string($value)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$value))throw new InvalidArgumentException('Enter each period and break time in HH:MM format.');
    return $value;
}
function routineIdentifier($value):int {
    if((!is_string($value)&&!is_int($value))||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/',(string)$value)||(float)$value>2147483647)throw new InvalidArgumentException('Choose a valid routine profile and revision.');
    return (int)$value;
}
function routineNormalizeLayout(array $input):array {
    if(array_diff(array_keys($input),[1,2,3,4,5])||count($input)!==5)throw new InvalidArgumentException('Submit settings for all five school days.');
    $layout=[];
    foreach([1,2,3,4,5] as $day){
        $row=$input[$day]??null;
        if(!is_array($row)||!is_scalar($row['count']??null)||!preg_match('/^(?:[1-9]|10)$/',(string)$row['count']))throw new InvalidArgumentException('Each day needs 1–10 periods.');
        if(!is_array($row['times']??null)||!is_array($row['breaks']??[]))throw new InvalidArgumentException('Enter valid period and break settings.');
        $n=(int)$row['count'];$times=[];$intervals=[];$previous='00:00';
        for($p=1;$p<=$n;$p++){
            $time=$row['times'][$p]??[];if(!is_array($time))throw new InvalidArgumentException('Enter valid period times.');
            $start=routineTime($time['start']??null);$end=routineTime($time['end']??null);
            if($start>=$end||$start<$previous)throw new InvalidArgumentException('Period times must be in order, with an end after the start and no overlaps.');
            $times[$p]=['start'=>$start,'end'=>$end];$intervals[]=[$start,$end];$previous=$end;
        }
        $breaks=[];
        foreach(['short','tiffin'] as $kind){
            $break=$row['breaks'][$kind]??[];if(!is_array($break))throw new InvalidArgumentException('Enter valid break times.');
            $start=$break['start']??'';$end=$break['end']??'';
            if($start===''&&$end===''){$breaks[$kind]=['start'=>'','end'=>''];continue;}
            $start=routineTime($start);$end=routineTime($end);
            if($start>=$end||$start<$times[1]['start']||$end>$times[$n]['end'])throw new InvalidArgumentException('A break must fit inside the school day, with its end after its start.');
            foreach($intervals as [$a,$b])if($start<$b&&$end>$a)throw new InvalidArgumentException('Breaks cannot overlap periods or another break. Adjust the period times to leave a break.');
            $breaks[$kind]=['start'=>$start,'end'=>$end];$intervals[]=[$start,$end];
        }
        $layout[$day]=['count'=>$n,'times'=>$times,'breaks'=>$breaks];
    }
    return $layout;
}
function routineActiveLayout(PDO $db,int $school,int $class):?array {
    $q=$db->prepare('SELECT layout_json FROM class_routine_layouts WHERE school_id=? AND class_id=?');$q->execute([$school,$class]);$json=$q->fetchColumn();
    return $json?json_decode($json,true,512,JSON_THROW_ON_ERROR):null;
}
function routineActiveGrid(PDO $db,int $school,int $class):array {
    $q=$db->prepare('SELECT * FROM class_routines WHERE school_id=? AND class_id=? AND day_of_week BETWEEN 1 AND 5 ORDER BY day_of_week,period_number');$q->execute([$school,$class]);$grid=[];
    foreach($q as $r)$grid[$r['day_of_week']][$r['period_number']]=['subject_id'=>$r['subject_id']===null?null:(int)$r['subject_id'],'teacher_id'=>$r['teacher_id']===null?null:(int)$r['teacher_id']];
    return $grid;
}
/** Recover form values only; semantic validation still runs before every save. */
function routineRecoverEditorInput(array $input,array $layout,array $grid,array $subjectIds,array $teacherIds):array {
    $subjects=array_fill_keys($subjectIds,true);$teachers=array_fill_keys($teacherIds,true);
    $rawLayout=is_array($input['layout']??null)?$input['layout']:[];
    $rawGrid=is_array($input['grid']??null)?$input['grid']:[];
    $defaults=routineDefaultLayout(10);
    $clock=static fn($value)=>is_string($value)&&($value===''||preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/',$value));
    for($day=1;$day<=5;$day++){
        $row=is_array($rawLayout[$day]??null)?$rawLayout[$day]:[];
        if(is_scalar($row['count']??null)&&preg_match('/^(?:[1-9]|10)$/',(string)$row['count']))$layout[$day]['count']=(int)$row['count'];
        $times=is_array($row['times']??null)?$row['times']:[];
        $cells=is_array($rawGrid[$day]??null)?$rawGrid[$day]:[];
        for($period=1;$period<=10;$period++){
            $time=is_array($times[$period]??null)?$times[$period]:[];
            $layout[$day]['times'][$period]=$layout[$day]['times'][$period]??$defaults[$day]['times'][$period];
            foreach(['start','end'] as $key)if($clock($time[$key]??null))$layout[$day]['times'][$period][$key]=$time[$key];
            $cell=is_array($cells[$period]??null)?$cells[$period]:[];
            foreach(['subject_id'=>$subjects,'teacher_id'=>$teachers] as $key=>$allowed){
                if(!array_key_exists($key,$cell))continue;
                $value=$cell[$key];
                $grid[$day][$period][$key]=(is_string($value)||is_int($value))&&preg_match('/^[1-9][0-9]{0,9}$/',(string)$value)&&isset($allowed[(int)$value])?(int)$value:null;
            }
        }
        $breaks=is_array($row['breaks']??null)?$row['breaks']:[];
        foreach(['short','tiffin'] as $kind){
            $break=is_array($breaks[$kind]??null)?$breaks[$kind]:[];
            foreach(['start','end'] as $key)if($clock($break[$key]??null))$layout[$day]['breaks'][$kind][$key]=$break[$key];
        }
    }
    return ['layout'=>$layout,'grid'=>$grid];
}
function routineValidateWeek(PDO $db,int $school,int $class,array $layout,array $grid,bool $activate):array {
    $limits=[];$counts=[];$week=[];$names=[];
    foreach(routineSubjects($db,$school,$class) as $r){$limits[$r['subject_id']]=max($limits[$r['subject_id']]??0,routineWeeklyPeriods($r));$names[$r['subject_id']]=$r['name_en'];}
    if(array_diff(array_keys($grid),[1,2,3,4,5]))throw new InvalidArgumentException('Choose Monday–Friday only.');
    for($d=1;$d<=5;$d++)for($p=1;$p<=$layout[$d]['count'];$p++){
        $cell=$grid[$d][$p]??null;
        if(!is_array($cell)||!array_key_exists('subject_id',$cell)||!array_key_exists('teacher_id',$cell))throw new InvalidArgumentException('Submit every subject and teacher field.');
        $ids=[];
        foreach(['subject_id','teacher_id'] as $key){
            $value=$cell[$key];
            if($value===null||$value==='')$ids[$key]=null;
            elseif(is_scalar($value)&&preg_match('/^[1-9][0-9]*$/',(string)$value))$ids[$key]=(int)$value;
            else throw new InvalidArgumentException('Choose valid subjects and teachers for each period.');
        }
        $time=$layout[$d]['times'][$p];
        validateRoutineSlot($db,$school,$class,$d,$p,$ids['subject_id'],$ids['teacher_id'],$time['start'],$time['end'],$activate);
        $subject=$ids['subject_id'];
        if($subject&&($counts[$subject]=($counts[$subject]??0)+1)>($limits[$subject]??0))throw new InvalidArgumentException('This routine exceeds a subject’s weekly periods. Update weekly requirements first.');
        $week[$d][$p]=$ids;
    }
    if($activate)foreach($limits as $subject=>$required){
        $scheduled=$counts[$subject]??0;
        if($scheduled<$required)throw new InvalidArgumentException('Weekly periods incomplete for '.$names[$subject].': '.$scheduled.' of '.$required.' scheduled. Complete the weekly allocation before activation, or save a draft.');
    }
    return $week;
}
function routineProfile(PDO $db,int $school,int $class,int $id):array {
    $q=$db->prepare('SELECT * FROM routine_profiles WHERE school_id=? AND class_id=? AND id=?');$q->execute([$school,$class,$id]);$profile=$q->fetch();
    if(!$profile)throw new InvalidArgumentException('Choose a routine profile belonging to this class and school.');
    $profile['payload']=json_decode($profile['payload_json'],true,512,JSON_THROW_ON_ERROR);return $profile;
}
/** Caller holds the school's schedule write lock and transaction. */
function routineSaveProfile(PDO $db,int $school,int $class,int $actor,array $input):int {
    $name=$input['name']??'';
    if(!is_string($name)||!preg_match('/^.{1,100}$/us',trim($name)))throw new InvalidArgumentException('Enter a routine profile name of up to 100 characters.');
    $name=trim(preg_replace('/\s+/u',' ',$name));if($name==='')throw new InvalidArgumentException('Enter a routine profile name.');
    $key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
    $mode=$input['mode']??'';if(!in_array($mode,['manual','automatic'],true))throw new InvalidArgumentException('Choose manual or automatic routine mode.');
    if(!is_array($input['layout']??null))throw new InvalidArgumentException('Enter a valid weekly layout.');
    $layout=routineNormalizeLayout($input['layout']);
    $row=schoolRecord($db,'classes',$class,$school);
    if($mode==='automatic'){
        $built=buildClassRoutine($db,$school,$row,routineSubjects($db,$school,$class),[1,2,3,4,5],max(array_column($layout,'count')),$layout,false);
        if($built['unplaced'])throw new InvalidArgumentException($built['unplaced'].' periods could not fit. Assign teachers and adjust weekly requirements or available periods.');
        $grid=$built['grid'];
    }else{
        if(!is_array($input['grid']??null))throw new InvalidArgumentException('Submit the weekly period entries.');$grid=$input['grid'];
    }
    $grid=routineValidateWeek($db,$school,$class,$layout,$grid,false);
    $payload=json_encode(['layout'=>$layout,'grid'=>$grid],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $id=routineIdentifier($input['profile_id']??0);
    $q=$db->prepare('SELECT id FROM routine_profiles WHERE school_id=? AND class_id=? AND name_key=? AND id<>?');$q->execute([$school,$class,$key,$id]);
    if($q->fetchColumn())throw new InvalidArgumentException('A profile with this name already exists. Load it to update it, or choose a different name.');
    if($id){
        $old=routineProfile($db,$school,$class,$id);
        if(routineIdentifier($input['revision']??0)!==(int)$old['revision'])throw new InvalidArgumentException('This profile changed in another session. Reload it before saving.');
        $db->prepare('INSERT INTO routine_profile_history(school_id,class_id,profile_id,revision,payload_json,name,changed_by) VALUES(?,?,?,?,?,?,?)')->execute([$school,$class,$id,$old['revision'],$old['payload_json'],$old['name'],$actor]);
        $db->prepare('UPDATE routine_profiles SET name=?,name_key=?,payload_json=?,mode=?,revision=revision+1,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND school_id=? AND class_id=?')->execute([$name,$key,$payload,$mode,$actor,$id,$school,$class]);
    }else{
        $db->prepare('INSERT INTO routine_profiles(school_id,class_id,name,name_key,payload_json,mode,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?)')->execute([$school,$class,$name,$key,$payload,$mode,$actor,$actor]);$id=(int)$db->lastInsertId();
    }
    return $id;
}
function routinePublishWeek(PDO $db,int $school,int $class,int $actor,array $layout,array $grid,?int $profile=null):void {
    $layout=routineNormalizeLayout($layout);$grid=routineValidateWeek($db,$school,$class,$layout,$grid,true);
    routineArchive($db,$school,$actor,$class);
    $db->prepare('INSERT INTO routine_layout_history(school_id,class_id,layout_json,profile_id,changed_by) SELECT school_id,class_id,layout_json,profile_id,? FROM class_routine_layouts WHERE school_id=? AND class_id=?')->execute([$actor,$school,$class]);
    $db->prepare('DELETE FROM class_routine_layouts WHERE school_id=? AND class_id=?')->execute([$school,$class]);
    $db->prepare('INSERT INTO class_routine_layouts(school_id,class_id,layout_json,profile_id,updated_by) VALUES(?,?,?,?,?)')->execute([$school,$class,json_encode($layout,JSON_THROW_ON_ERROR),$profile,$actor]);
    $q=$db->prepare('INSERT INTO class_routines(class_id,day_of_week,period_number,subject_id,teacher_id,school_id,start_time,end_time) VALUES(?,?,?,?,?,?,?,?)');
    foreach($grid as $d=>$slots)foreach($slots as $p=>$slot)$q->execute([$class,$d,$p,$slot['subject_id'],$slot['teacher_id'],$school,$layout[$d]['times'][$p]['start'],$layout[$d]['times'][$p]['end']]);
}
