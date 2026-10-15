<?php
require_once __DIR__.'/learning_catalog.php';
require_once __DIR__.'/teaching_scope.php';
require_once __DIR__.'/student_learning.php';
require_once __DIR__.'/packages.php';

function learningActor(PDO $db,int $userId,int $school=0,bool $readOnly=false):array {
    $q=$db->prepare('SELECT id,role,school_id FROM users WHERE id=? AND status=1');$q->execute([$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user)throw new InvalidArgumentException('Your account is not active.');
    if($user['role']!=='super_admin'){
        if($school&&$school!==(int)$user['school_id'])throw new InvalidArgumentException('Invalid school context.');
        $school=(int)$user['school_id'];if($school<1)throw new InvalidArgumentException('Your account has no school assignment.');
    }elseif($school>0){$q=$db->prepare('SELECT id FROM schools WHERE id=?');$q->execute([$school]);if(!$q->fetchColumn())throw new InvalidArgumentException('Invalid school context.');}
    return ['id'=>(int)$user['id'],'role'=>$user['role'],'school_id'=>$school,'read_only'=>$readOnly];
}
function learningPurposeAllowed(PDO $db,array $actor,string $purpose):bool {
    if(!in_array($purpose,['bank','study','practice','paper','exam_bank','exam_view','exam_paper'],true))throw new InvalidArgumentException('Invalid learning purpose.');
    try{$current=learningActor($db,(int)$actor['id'],(int)$actor['school_id'],(bool)$actor['read_only']);}catch(InvalidArgumentException $e){return false;}
    if($current['role']!==$actor['role'])return false;
    if(str_starts_with($purpose,'exam_')&&!in_array($actor['role'],['super_admin','school_admin','teacher','student'],true))return false;
    if($actor['role']==='super_admin'&&$actor['school_id']===0)return true;
    if(in_array($actor['role'],['accountant','cashier','auditor'],true))return false;
    if($actor['role']==='student'&&!in_array($purpose,['study','practice','exam_view'],true))return false;
    $q=$db->prepare('SELECT * FROM schools WHERE id=?');$q->execute([$actor['school_id']]);$school=$q->fetch(PDO::FETCH_ASSOC);if(!$school)return false;
    return schoolSubscriptionAllows($school,match($purpose){'bank'=>'questions','paper'=>'paper_generator','exam_bank','exam_view'=>'exam_questions','exam_paper'=>'exam_paper_generator',default=>'learning'});
}
function learningReadClasses(PDO $db,array $actor):array {
    if(!learningPurposeAllowed($db,$actor,'study')&&!learningPurposeAllowed($db,$actor,'bank')&&!learningPurposeAllowed($db,$actor,'paper')&&!learningPurposeAllowed($db,$actor,'exam_view')&&!learningPurposeAllowed($db,$actor,'exam_paper'))return [];
    return learningClasses($db,$actor['school_id'],$actor['role']==='student'?'student':'school_admin',$actor['id']);
}
function learningReadSubjects(PDO $db,array $actor,int $class):array {
    if(!in_array($class,array_map('intval',array_column(learningReadClasses($db,$actor),'id')),true))return [];
    // Keep distinct subject identities even if legacy normalization makes labels equal.
    $q=$db->prepare('SELECT DISTINCT s.*,cs.is_optional mapping_optional FROM subjects s JOIN class_subjects cs ON cs.subject_id=s.id AND cs.school_id=s.school_id JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id WHERE s.school_id=? AND cs.class_id=? AND (cs.is_active=1 OR cs.is_optional=1) AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0 ORDER BY s.name_en,s.code,s.id');$q->execute([$actor['school_id'],$class]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if($actor['role']==='student'){
        try{$student=studentLearningRecord($db,$actor['school_id'],$actor['id']);}catch(InvalidArgumentException $e){return [];}
        $optional=array_map('intval',array_filter([$student['optional_subject_i'],$student['optional_subject_ii'],$student['optional_subject_iii']]));
        $rows=array_values(array_filter($rows,static fn($r)=>($r['type']!=='optional'&&!(int)$r['mapping_optional'])||in_array((int)$r['id'],$optional,true)));
    }
    $labels=[];foreach($rows as $r){$k=catalogNameKey(subjectCanonicalName($r['name_en']));$labels[$k]=($labels[$k]??0)+1;}
    foreach($rows as &$r){$r['name_en']=subjectCanonicalName($r['name_en']);$r['display_name']=$r['name_en'];if($labels[catalogNameKey($r['name_en'])]>1)$r['display_name'].=' ('.($r['code']?:$r['type'].' #'.$r['id']).')';}unset($r);
    return $rows;
}
/** Map a record's scope to a readable local scope, never by name substring. */
function learningRecordScope(PDO $db,array $actor,array $record):?array {
    $school=(int)($record['school_id']??-1);$class=(int)($record['class_id']??0);$subject=(int)($record['subject_id']??0);
    if($school!==0&&$school!==$actor['school_id'])return null;
    if($actor['role']==='super_admin'&&$actor['school_id']===0){if($school!==0)return null;return ['school_id'=>0,'class_id'=>$class,'subject_id'=>$subject];}
    if(!$class||!$subject)return null;
    if($school===$actor['school_id']){
        foreach(learningReadSubjects($db,$actor,$class) as $r)if((int)$r['id']===$subject)return ['school_id'=>$school,'class_id'=>$class,'subject_id'=>$subject];
        return null;
    }
    $q=$db->prepare('SELECT cs.id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE c.school_id=0 AND c.id=? AND s.id=? AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0 AND (cs.is_active=1 OR cs.is_optional=1)');$q->execute([$class,$subject]);if(!$q->fetchColumn())return null;
    foreach(learningReadClasses($db,$actor) as $c)foreach(learningReadSubjects($db,$actor,(int)$c['id']) as $s){
        try{$scope=learningCatalogScope($db,$actor['school_id'],(int)$c['id'],(int)$s['id']);}catch(InvalidArgumentException $e){continue;}
        if($scope['shared']&&$scope['shared']['class_id']===$class&&$scope['shared']['subject_id']===$subject)return $scope['local'];
    }
    return null;
}
function learningBankPurposeMatches(array $record,string $purpose):bool {return ($record['bank_kind']??'learning')===(str_starts_with($purpose,'exam_')?'exam':'learning');}
function learningCanView(PDO $db,array $actor,array $record,string $purpose='study'):bool {
    if(!learningBankPurposeMatches($record,$purpose)||!learningPurposeAllowed($db,$actor,$purpose)||!empty($record['archived_at']))return false;
    if($actor['role']==='student'&&$purpose==='exam_view'&&((int)($record['student_visible']??0)!==1||($record['visibility']??'private')!=='public'))return false;
    $scope=learningRecordScope($db,$actor,$record);if(!$scope)return false;
    return learningScopeCanView($db,$actor,$record,$scope);
}
/** A list resolves scopes once; public rows require no further database queries. */
function learningScopeCanView(PDO $db,array $actor,array $record,array $scope):bool {
    if($actor['role']==='super_admin'&&$actor['school_id']===0)return true;
    if(($record['visibility']??'private')==='public')return true;
    if((int)$record['school_id']===0)return $actor['role']==='super_admin';
    if((int)($record['created_by_user_id']??0)===$actor['id'])return true;
    if(in_array($actor['role'],['school_admin','super_admin'],true))return true;
    return $actor['role']==='teacher'&&teacherOwnsSubject($db,$actor['school_id'],$actor['id'],$scope['class_id'],$scope['subject_id']);
}
function learningCanCreate(PDO $db,array $actor,int $class,int $subject):bool {
    if($actor['read_only']||!in_array($actor['role'],['super_admin','school_admin','teacher'],true)||!learningPurposeAllowed($db,$actor,'study')&&!learningPurposeAllowed($db,$actor,'bank'))return false;
    if($actor['role']==='super_admin'&&$actor['school_id']>0)return false;
    $valid=false;foreach(learningReadSubjects($db,$actor,$class) as $r)if((int)$r['id']===$subject)$valid=true;
    return $valid&&($actor['role']!=='teacher'||teacherOwnsSubject($db,$actor['school_id'],$actor['id'],$class,$subject));
}
function learningCanEdit(PDO $db,array $actor,array $record):bool {
    if($actor['read_only'])return false;
    if($actor['role']==='super_admin'&&$actor['school_id']===0&&(int)($record['school_id']??-1)===0)return learningPurposeAllowed($db,$actor,'study');
    return (int)($record['school_id']??-1)===$actor['school_id']&&($record['created_by_user_id']??null)!==null&&(int)$record['created_by_user_id']===$actor['id']&&learningCanCreate($db,$actor,(int)$record['class_id'],(int)$record['subject_id']);
}
function learningCanPublish(PDO $db,array $actor,array $record):bool {return learningCanEdit($db,$actor,$record);}
function learningQuestionPagination(mixed $number=1,mixed $size=10):array {
    if(!is_scalar($size)||!in_array((string)$size,['10','20','30','50','100'],true)||!is_scalar($number)||!preg_match('/^[1-9][0-9]{0,6}$/',(string)$number))throw new InvalidArgumentException('Choose a valid page and 10, 20, 30, 50 or 100 items per page.');
    return ['page'=>(int)$number,'per_page'=>(int)$size,'total'=>0,'pages'=>1];
}
function learningContentList(PDO $db,array $actor,string $kind,array $filters,string $purpose='study',?array &$pagination=null):array {
    if(!in_array($kind,['questions','learning_notes'],true))throw new InvalidArgumentException('Invalid learning content type.');
    $paged=$pagination!==null;
    if($paged){
        if($kind!=='questions')throw new InvalidArgumentException('Pagination is available for questions only.');
        $pagination=learningQuestionPagination($pagination['page']??1,$pagination['per_page']??10);$offset=($pagination['page']-1)*$pagination['per_page'];
    }
    if(!learningPurposeAllowed($db,$actor,$purpose)){if($paged)$pagination['page']=1;return [];}
    $where=['school_id IN (0,?)'];$params=[$actor['school_id']];
    if(isset($filters['record_id'])&&$filters['record_id']!==''){
        if(!is_scalar($filters['record_id'])||!preg_match('/^[1-9][0-9]*$/',(string)$filters['record_id']))throw new InvalidArgumentException('Choose a valid content record.');
        $where[]='id=?';$params[]=(int)$filters['record_id'];
    }
    if($kind==='questions'){$where[]='bank_kind=?';$params[]=str_starts_with($purpose,'exam_')?'exam':'learning';}
    elseif(str_starts_with($purpose,'exam_'))return [];
    if($actor['role']==='student'&&$purpose==='exam_view'){$where[]="student_visible=1 AND visibility='public'";}
    if(!empty($filters['difficulty'])){if($kind!=='questions'||!in_array($filters['difficulty'],['Easy','Medium','Hard','Challenging'],true))throw new InvalidArgumentException('Choose a valid difficulty.');$where[]='difficulty=?';$params[]=$filters['difficulty'];}
    $source=$filters['source']??'all';if(!in_array($source,['all','shared','school','mine'],true))throw new InvalidArgumentException('Choose a valid content source.');
    if($source==='shared')$where[]='school_id=0';
    elseif($source==='school'){$where[]='school_id=?';$params[]=$actor['school_id'];}
    elseif($source==='mine'){$where[]='created_by_user_id=?';$params[]=$actor['id'];}
    foreach(['class_id','subject_id','topic_id','catalog_topic_id'] as $field)if(!empty($filters[$field])){
        if(!is_scalar($filters[$field])||!preg_match('/^[1-9][0-9]*$/',(string)$filters[$field]))throw new InvalidArgumentException('Choose a valid '.$field.'.');
        // Local selector IDs also include the validated matching shared scope.
        if(in_array($field,['class_id','subject_id'],true))continue;
        $where[]="$field=?";$params[]=(int)$filters[$field];
    }
    foreach(['chapter','type'] as $field)if(!empty($filters[$field])){if(!is_string($filters[$field]))throw new InvalidArgumentException('Invalid content filter.');$where[]="$field=?";$params[]=$filters[$field];}
    if(isset($filters['question_group'])){
        if($kind!=='questions'||!in_array($filters['question_group'],['mcq','written'],true))throw new InvalidArgumentException('Choose a valid question section.');
        $where[]=$filters['question_group']==='mcq'?"type='MCQ'":"type<>'MCQ'";
    }
    if(isset($filters['topic_alias_ids'])){
        $aliases=$filters['topic_alias_ids'];if(!is_array($aliases)||!$aliases||count($aliases)>100)throw new InvalidArgumentException('Choose a valid topic.');
        foreach($aliases as $alias)if(!is_scalar($alias)||!preg_match('/^[1-9][0-9]*$/',(string)$alias))throw new InvalidArgumentException('Choose a valid topic.');
        $slots=implode(',',array_fill(0,count($aliases),'?'));$where[]="(topic_id IN ($slots) OR catalog_topic_id IN ($slots))";array_push($params,...$aliases,...$aliases);
    }
    if($kind==='learning_notes'&&isset($filters['type']))throw new InvalidArgumentException('Notes have no question type.');
    $archived=$filters['archived']??false;if(!is_bool($archived))throw new InvalidArgumentException('Invalid archive filter.');$where[]=$archived?'archived_at IS NOT NULL':'archived_at IS NULL';
    if(!empty($filters['search'])){if(!is_string($filters['search']))throw new InvalidArgumentException('Invalid search.');$where[]=$kind==='questions'?'(question_text LIKE ? OR question_text_np LIKE ?)':'(title LIKE ? OR content LIKE ?)';$params[]='%'.$filters['search'].'%';$params[]='%'.$filters['search'].'%';}
    // Count authorized metadata first; load rich bodies only for the chosen page.
    $columns=$paged?'id,school_id,class_id,subject_id,created_by_user_id,visibility,archived_at,bank_kind,student_visible,topic_id,catalog_topic_id':'*';
    $q=$db->prepare("SELECT $columns FROM $kind WHERE ".implode(' AND ',$where).' ORDER BY id DESC');$q->execute($params);$rows=[];$scopeCache=[];$editCache=[];$viewCache=[];$total=0;$lastPage=[];
    while($r=$q->fetch(PDO::FETCH_ASSOC)){
        $key=$r['school_id'].':'.$r['class_id'].':'.$r['subject_id'];
        if(!array_key_exists($key,$scopeCache))$scopeCache[$key]=learningRecordScope($db,$actor,$r);
        $scope=$scopeCache[$key];if(!$scope)continue;
        if(!empty($filters['class_id'])&&$scope['class_id']!==(int)$filters['class_id'])continue;
        if(!empty($filters['subject_id'])&&$scope['subject_id']!==(int)$filters['subject_id'])continue;
        $editKey=$key.':'.($r['created_by_user_id']??0);$viewKey=$editKey.':'.($r['visibility']??'private');
        $canEdit=$editCache[$editKey]??=learningCanEdit($db,$actor,$r);
        if($archived){if(!$canEdit)continue;}elseif(!($viewCache[$viewKey]??=learningScopeCanView($db,$actor,$r,$scope)))continue;
        $r['can_edit']=$canEdit;$r['can_publish']=$canEdit;$r['source_scope']=(int)$r['school_id']===0?'shared':'school';
        if(!$paged){$rows[]=$r;continue;}
        if($total%$pagination['per_page']===0)$lastPage=[];$lastPage[]=$r;
        if($total>=$offset&&$total<$offset+$pagination['per_page'])$rows[]=$r;$total++;
    }
    if($paged){
        $pagination['total']=$total;$pagination['pages']=max(1,(int)ceil($total/$pagination['per_page']));
        if($pagination['page']>$pagination['pages']){$pagination['page']=$pagination['pages'];$rows=$lastPage;}
        if($rows){
            $metadata=array_column($rows,null,'id');$ids=array_keys($metadata);
            $q=$db->prepare('SELECT * FROM questions WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id DESC');$q->execute($ids);$rows=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$m=$metadata[$r['id']];$r['can_edit']=$m['can_edit'];$r['can_publish']=$m['can_publish'];$r['source_scope']=$m['source_scope'];$rows[]=$r;}
        }
    }
    return $rows;
}
function learningContentRecord(PDO $db,array $actor,string $kind,int $id,string $purpose='study',bool $includeArchived=false):array {
    if(!in_array($kind,['questions','learning_notes'],true)||$id<1)throw new InvalidArgumentException('Content not found.');
    $q=$db->prepare("SELECT * FROM $kind WHERE id=?");$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r||!learningBankPurposeMatches($r,$purpose)||(!learningCanView($db,$actor,$r,$purpose)&&!($includeArchived&&!empty($r['archived_at'])&&learningPurposeAllowed($db,$actor,$purpose)&&learningCanEdit($db,$actor,$r))))throw new InvalidArgumentException('Content not found.');
    $r['can_edit']=learningCanEdit($db,$actor,$r);$r['can_publish']=$r['can_edit'];$r['source_scope']=(int)$r['school_id']===0?'shared':'school';return $r;
}
function learningValidateQuestionIds(PDO $db,array $actor,array $ids,string $purpose):void {
    try{foreach($ids as $id){if(!is_scalar($id)||!preg_match('/^[1-9][0-9]*$/',(string)$id))throw new InvalidArgumentException('Invalid content.');learningContentRecord($db,$actor,'questions',(int)$id,$purpose);}}
    catch(InvalidArgumentException $e){throw new InvalidArgumentException('Some questions are no longer available. Please restart practice or reload the paper selection.');}
}
