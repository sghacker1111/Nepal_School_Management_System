<?php
/** One assignment policy for learning editors, selectors and marks entry. */
function teacherAssignmentContains($value,int $teacher):bool {
    return in_array((string)$teacher,array_map('trim',explode(',',(string)$value)),true);
}
function teacherTeachingAssignments(PDO $db,int $school,int $teacher):array {
    $q=$db->prepare("SELECT id FROM users WHERE id=? AND school_id=? AND role='teacher' AND status=1");$q->execute([$teacher,$school]);if(!$q->fetchColumn())return [];
    $q=$db->prepare('SELECT cs.* FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE cs.school_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0');$q->execute([$school]);
    return array_values(array_filter($q->fetchAll(),static fn($row)=>teacherAssignmentContains($row['teacher_id'],$teacher)));
}
function teacherOwnsSubject(PDO $db,int $school,int $teacher,int $class,int $subject,?int $section=null):bool {
    foreach(teacherTeachingAssignments($db,$school,$teacher) as $row)if((int)$row['class_id']===$class&&(int)$row['subject_id']===$subject&&($section===null||!$row['section_id']||(int)$row['section_id']===$section))return true;
    return false;
}
function learningClasses(PDO $db,int $school,string $role,int $actor):array {
    $q=$db->prepare('SELECT * FROM classes WHERE school_id=? AND is_active=1 ORDER BY level,name,id');$q->execute([$school]);$rows=$q->fetchAll();
    if($role==='student'){require_once __DIR__.'/student_learning.php';$student=studentLearningRecord($db,$school,$actor);$rows=array_values(array_filter($rows,static fn($row)=>(int)$row['id']===(int)$student['class_id']));}
    if($role==='teacher'){$ids=array_column(teacherTeachingAssignments($db,$school,$actor),'class_id');$rows=array_values(array_filter($rows,static fn($row)=>in_array($row['id'],$ids)));}
    return $rows;
}
function learningSubjects(PDO $db,int $school,int $class,string $role,int $actor):array {
    if(!$class)return [];
    $q=$db->prepare('SELECT DISTINCT s.*,cs.is_optional AS mapping_optional FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=s.school_id JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id WHERE s.school_id=? AND cs.class_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0 ORDER BY s.name_en,s.code,s.id');$q->execute([$school,$class]);$rows=$q->fetchAll();
    if($role==='student'){
        require_once __DIR__.'/student_learning.php';$student=studentLearningRecord($db,$school,$actor);
        if((int)$student['class_id']!==$class)return [];
        $electives=array_map('intval',array_filter([$student['optional_subject_i']??null,$student['optional_subject_ii']??null,$student['optional_subject_iii']??null]));
        $rows=array_values(array_filter($rows,static fn($row)=>($row['type']!=='optional'&&!(int)$row['mapping_optional'])||in_array((int)$row['id'],$electives,true)));
    }
    if($role==='teacher'){$ids=[];foreach(teacherTeachingAssignments($db,$school,$actor) as $row)if((int)$row['class_id']===$class)$ids[]=(int)$row['subject_id'];$rows=array_values(array_filter($rows,static fn($row)=>in_array((int)$row['id'],$ids,true)));}
    $unique=[];foreach($rows as $row){$key=mb_strtolower(trim($row['name_en']),'UTF-8');if(!isset($unique[$key]))$unique[$key]=$row;}
    return array_values($unique);
}
/** Validate both the saved target and submitted new scope to prevent moving another teacher's records. */
function validateTeacherLearningRequest(PDO $db,int $school,string $module,string $file,array $data,array $query):void {
    if($module!=='learning'||!in_array($file,['curriculum.php','specification_grids.php','questions.php','paper_generator.php','question_grid.php','index.php','practice_mcqs.php'],true))return;
    // Legacy pages dispatch both query-string and body actions. Never authorize one
    // action while another handler executes, or let body fields shadow a query scope.
    $queryActions=array_intersect_key($query,array_flip(['action','delete','action_get_chapters','action_get_topics']));
    $bodyActions=array_intersect_key($data,array_flip(['action','action_import_excel','action_quick_add_class','action_quick_add_subject']));
    if(count($queryActions)>1||count($bodyActions)>1||($queryActions&&$bodyActions))throw new InvalidArgumentException('Submit one learning action at a time. Reload the page and try again.');
    if($data&&$query)validateTeacherLearningRequest($db,$school,$module,$file,[],$query);
    if(!$data&&($file==='curriculum.php'||($file==='questions.php'&&(isset($query['action_get_chapters'])||isset($query['action_get_topics']))))){
        require_once __DIR__.'/learning_content.php';$actor=learningActor($db,(int)Auth::getUserId(),$school,Auth::isReadOnly());
        $class=(int)($query['class_id']??0);$subject=(int)($query['subject_id']??0);
        if(isset($query['chapter_id']))learningCatalogChapterScope($db,$actor,(int)$query['chapter_id']);
        if($class&&!in_array($class,array_map('intval',array_column(learningReadClasses($db,$actor),'id')),true))throw new InvalidArgumentException('Choose an available class.');
        if($subject&&!in_array($subject,array_map('intval',array_column(learningReadSubjects($db,$actor,$class),'id')),true))throw new InvalidArgumentException('Choose an available class subject.');
        if(($query['action']??'')==='get_all_subjects'&&$actor['role']!=='super_admin')throw new InvalidArgumentException('School curriculum is read-only.');
        return;
    }
    $role=Auth::getRole();$teacher=(int)Auth::getUserId();$values=array_replace($query,$data);$action=$data['action']??($query['action']??'');
    if($role==='teacher'&&$file==='curriculum.php'&&in_array($action,['map_subject','get_all_subjects'],true))throw new InvalidArgumentException('Only an administrator may map additional class subjects. Teachers can manage their assigned subjects only.');
    $scopes=[];
    $class=(int)($values['class_id']??$values['class']??$values['default_class_id']??0);$subject=(int)($values['subject_id']??$values['subject']??$values['default_subject_id']??0);
    if($role==='student'&&$class){
        if(!in_array($class,array_map('intval',array_column(learningClasses($db,$school,$role,$teacher),'id')),true))throw new InvalidArgumentException('Choose your enrolled class.');
        if($subject&&!in_array($subject,array_map('intval',array_column(learningSubjects($db,$school,$class,$role,$teacher),'id')),true))throw new InvalidArgumentException('Choose one of your enrolled class subjects.');
    }
    if($class&&$role==='teacher'&&!in_array($class,array_map('intval',array_column(learningClasses($db,$school,$role,$teacher),'id')),true))throw new InvalidArgumentException('Choose one of your teaching classes.');
    if($subject&&!$class)throw new InvalidArgumentException('Choose a class before selecting its subject.');
    if($class&&$subject)$scopes[]=[$class,$subject];
    $table=null;$id=0;
    if($file==='curriculum.php'){
        if(in_array($action,['edit_chapter','delete_chapter'],true)){$table='chapters';$id=(int)($data['id']??0);}
        elseif(in_array($action,['edit_topic','delete_topic'],true)){$table='topics';$id=(int)($data['id']??0);}
        elseif(in_array($action,['add_topic','get_topics'],true)){$table='chapters';$id=(int)($values['chapter_id']??0);}
    }elseif($file==='specification_grids.php'){
        if($action==='edit'){$table='specification_grids';$id=(int)($data['grid_id']??0);}
        elseif(isset($query['delete'])&&$role!=='super_admin'){$table='specification_grids';$id=(int)$query['delete'];}
    }
    if($file==='questions.php'){
        if(isset($data['action_quick_add_class'])||isset($data['action_quick_add_subject']))throw new InvalidArgumentException('Quick creation is removed. Administrators must use Class Management or Subject Management.');
        if(($data['action']??'')==='edit'){$table='questions';$id=(int)($data['question_id']??0);}
        elseif(isset($query['delete'])){$table='questions';$id=(int)$query['delete'];}
        elseif(!empty($values['chapter_id'])&&(int)$values['chapter_id']>0){$table='chapters';$id=(int)$values['chapter_id'];}
    }
    if($table){$record=schoolRecord($db,$table,$id,$school);$scopes[]=[(int)$record['class_id'],(int)$record['subject_id']];}
    foreach($scopes as [$class,$subject]){
        if($role==='teacher'&&!teacherOwnsSubject($db,$school,$teacher,$class,$subject))throw new InvalidArgumentException('Teachers may use only their assigned class subjects.');
        if(in_array($file,['questions.php','paper_generator.php','question_grid.php','index.php'],true)){
            $q=$db->prepare('SELECT cs.id FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE cs.school_id=? AND cs.class_id=? AND cs.subject_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND COALESCE(s.is_hidden,0)=0');
            $q->execute([$school,$class,$subject]);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose a visible subject mapped to this class.');
        }
        // A grid must use an active class mapping, not a random same-school catalogue subject.
        if($file==='specification_grids.php'&&$data&&in_array($action,['add','edit'],true)){
            $q=$db->prepare('SELECT id FROM class_subjects WHERE school_id=? AND class_id=? AND subject_id=? AND (is_active=1 OR is_optional=1)');$q->execute([$school,$class,$subject]);if(!$q->fetchColumn())throw new InvalidArgumentException('Choose an active subject mapped to the selected class.');
        }
    }
}
