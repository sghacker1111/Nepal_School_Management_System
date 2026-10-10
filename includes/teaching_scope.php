<?php
/** One assignment policy for learning editors, selectors and marks entry. */
function teacherAssignmentContains($value,int $teacher):bool {
    return in_array((string)$teacher,array_map('trim',explode(',',(string)$value)),true);
}
function teacherTeachingAssignments(PDO $db,int $school,int $teacher):array {
    $q=$db->prepare("SELECT id FROM users WHERE id=? AND school_id=? AND role='teacher' AND status=1");$q->execute([$teacher,$school]);if(!$q->fetchColumn())return [];
    $q=$db->prepare('SELECT cs.* FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE cs.school_id=? AND cs.is_active=1 AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0');$q->execute([$school]);
    return array_values(array_filter($q->fetchAll(),static fn($row)=>teacherAssignmentContains($row['teacher_id'],$teacher)));
}
function teacherOwnsSubject(PDO $db,int $school,int $teacher,int $class,int $subject,?int $section=null):bool {
    foreach(teacherTeachingAssignments($db,$school,$teacher) as $row)if((int)$row['class_id']===$class&&(int)$row['subject_id']===$subject&&($section===null||!$row['section_id']||(int)$row['section_id']===$section))return true;
    return false;
}
function learningClasses(PDO $db,int $school,string $role,int $actor):array {
    $q=$db->prepare('SELECT * FROM classes WHERE school_id=? AND is_active=1 ORDER BY level,name,id');$q->execute([$school]);$rows=$q->fetchAll();
    if($role==='teacher'){$ids=array_column(teacherTeachingAssignments($db,$school,$actor),'class_id');$rows=array_values(array_filter($rows,static fn($row)=>in_array($row['id'],$ids)));}
    return $rows;
}
function learningSubjects(PDO $db,int $school,int $class,string $role,int $actor):array {
    $q=$db->prepare('SELECT DISTINCT s.id,s.name_en,s.code FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=s.school_id WHERE s.school_id=? AND cs.class_id=? AND cs.is_active=1 AND COALESCE(s.is_hidden,0)=0 ORDER BY s.name_en,s.code,s.id');$q->execute([$school,$class]);$rows=$q->fetchAll();
    if($role==='teacher'){$ids=[];foreach(teacherTeachingAssignments($db,$school,$actor) as $row)if((int)$row['class_id']===$class)$ids[]=(int)$row['subject_id'];$rows=array_values(array_filter($rows,static fn($row)=>in_array((int)$row['id'],$ids,true)));}
    return $rows;
}
/** Validate both the saved target and submitted new scope to prevent moving another teacher's records. */
function validateTeacherLearningRequest(PDO $db,int $school,string $module,string $file,array $data,array $query):void {
    if($module!=='learning'||!in_array($file,['curriculum.php','specification_grids.php'],true))return;
    $role=Auth::getRole();$teacher=(int)Auth::getUserId();$values=array_replace($query,$data);$action=$data['action']??($query['action']??'');
    if($role==='teacher'&&$file==='curriculum.php'&&in_array($action,['map_subject','get_all_subjects'],true))throw new InvalidArgumentException('Only an administrator may map additional class subjects. Teachers can manage their assigned subjects only.');
    $scopes=[];
    if(!empty($values['class_id'])&&!empty($values['subject_id']))$scopes[]=[(int)$values['class_id'],(int)$values['subject_id']];
    $table=null;$id=0;
    if($file==='curriculum.php'){
        if(in_array($action,['edit_chapter','delete_chapter'],true)){$table='chapters';$id=(int)($data['id']??0);}
        elseif(in_array($action,['edit_topic','delete_topic'],true)){$table='topics';$id=(int)($data['id']??0);}
        elseif(in_array($action,['add_topic','get_topics'],true)){$table='chapters';$id=(int)($values['chapter_id']??0);}
    }else{
        if($action==='edit'){$table='specification_grids';$id=(int)($data['grid_id']??0);}
        elseif(isset($query['delete'])&&$role!=='super_admin'){$table='specification_grids';$id=(int)$query['delete'];}
    }
    if($table){$record=schoolRecord($db,$table,$id,$school);$scopes[]=[(int)$record['class_id'],(int)$record['subject_id']];}
    foreach($scopes as [$class,$subject]){
        if($role==='teacher'&&!teacherOwnsSubject($db,$school,$teacher,$class,$subject))throw new InvalidArgumentException('Teachers may manage chapters, topics and specification grids only for their assigned class subjects.');
        // A grid must use an active class mapping, not a random same-school catalogue subject.
        if($file==='specification_grids.php'&&$data&&in_array($action,['add','edit'],true)){
            $q=$db->prepare('SELECT id FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? AND is_active=1');$q->execute([$school,$class,$subject]);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active subject mapped to the selected class.');
        }
    }
}
