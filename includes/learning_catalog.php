<?php
require_once __DIR__.'/subject_names.php';
require_once __DIR__.'/catalog_validation.php';
/** Identity resolution is independent of actor privileges and never uses substrings. */
function learningCatalogScope(PDO $db,int $school,int $class,int $subject):array {
    $q=$db->prepare('SELECT c.*,s.id subject_id,s.name_en,s.code,s.type,s.source_id subject_source_id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=c.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id WHERE c.school_id=? AND c.id=? AND s.id=?');
    $q->execute([$school,$class,$subject]);$local=$q->fetch(PDO::FETCH_ASSOC);
    if(!$local)throw new InvalidArgumentException('Choose a subject assigned to the selected class.');
    $scope=['school_id'=>$school,'class_id'=>$class,'subject_id'=>$subject];
    if($school===0)return ['local'=>$scope,'shared'=>$scope];
    $q=$db->query('SELECT DISTINCT c.id class_id,c.name,s.id subject_id,s.name_en,s.code,s.type FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE c.school_id=0');
    $candidates=[];$sources=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        if(classNameKey($row['name'])!==classNameKey($local['name'])||$row['type']!==$local['type'])continue;
        $nameMatch=catalogNameKey(subjectCanonicalName($row['name_en']))===catalogNameKey(subjectCanonicalName($local['name_en']));
        $codeMatch=trim((string)$local['code'])!==''&&catalogNameKey((string)$row['code'])===catalogNameKey((string)$local['code']);
        // Reused codes and stale source IDs are not proof of subject identity.
        if(!$nameMatch)continue;
        $mapped=['school_id'=>0,'class_id'=>(int)$row['class_id'],'subject_id'=>(int)$row['subject_id']];
        if((int)$local['subject_source_id']===(int)$row['subject_id'])$sources[]=$mapped;
        if($codeMatch||trim((string)$local['code'])===''||trim((string)$row['code'])==='')$candidates[]=$mapped;
    }
    $matches=$sources?:$candidates;
    if(count($matches)>1)throw new InvalidArgumentException('Shared subject identity is ambiguous. Ask the administrator to correct its mapping.');
    return ['local'=>$scope,'shared'=>$matches[0]??null];
}
function learningCanManageCatalog(array $actor):bool {return $actor['role']==='super_admin'&&!$actor['read_only'];}
function learningSharedScopeEligible(PDO $db,array $scope):bool {
    $q=$db->prepare('SELECT cs.id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id AND s.school_id=0 WHERE c.school_id=0 AND c.id=? AND s.id=? AND c.is_active=1 AND COALESCE(s.is_hidden,0)=0 AND (cs.is_active=1 OR cs.is_optional=1)');$q->execute([$scope['class_id'],$scope['subject_id']]);return (bool)$q->fetchColumn();
}
function learningCatalogChoices(PDO $db,array $actor,int $class,int $subject):array {
    require_once __DIR__.'/learning_content.php';
    $valid=false;foreach(learningReadSubjects($db,$actor,$class) as $s)if((int)$s['id']===$subject)$valid=true;
    if(!$valid)throw new InvalidArgumentException('Choose an available class subject.');
    $scopes=learningCatalogScope($db,$actor['school_id'],$class,$subject);$choices=[];
    foreach(['shared','local'] as $source){$scope=$scopes[$source];if(!$scope||($source==='local'&&$scope['school_id']===0)||($source==='shared'&&!learningSharedScopeEligible($db,$scope)))continue;
        $q=$db->prepare('SELECT * FROM chapters WHERE school_id=? AND class_id=? AND subject_id=? AND archived_at IS NULL ORDER BY sort_order,id');$q->execute(array_values($scope));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['name']=curriculumName((string)$r['name']);$r['name_np']=curriculumName((string)($r['name_np']??''));$r['scope']=$source;$key=curriculumNameKey($r['name']);
            if(!isset($choices[$key])){$r['alias_ids']=[];$r['aliases']=[];$choices[$key]=$r;}
            $choices[$key]['alias_ids'][]=(int)$r['id'];$choices[$key]['aliases'][]=['id'=>(int)$r['id'],'scope'=>$source]+$scope;
        }
    }
    return array_values($choices);
}
function learningCatalogChapters(PDO $db,array $actor,int $class,int $subject):array {
    require_once __DIR__.'/curriculum_catalog.php';return learningCatalogChoices($db,$actor,$class,$subject);
}
function learningCatalogTopics(PDO $db,array $actor,int $class,int $subject,int $chapter):array {
    $choice=null;foreach(learningCatalogChapters($db,$actor,$class,$subject) as $r)if(in_array($chapter,$r['alias_ids'],true)){$choice=$r;break;}
    if(!$choice)throw new InvalidArgumentException('Choose a chapter in this class subject.');
    $topics=[];
    foreach($choice['aliases'] as $alias){
        $q=$db->prepare('SELECT * FROM topics WHERE school_id=? AND class_id=? AND subject_id=? AND chapter_id=? AND archived_at IS NULL ORDER BY sort_order,id');$q->execute([$alias['school_id'],$alias['class_id'],$alias['subject_id'],$alias['id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$r['name']=curriculumName((string)$r['name']);$r['name_np']=curriculumName((string)($r['name_np']??''));$r['scope']=$alias['scope'];$key=curriculumNameKey($r['name']);
            if(!isset($topics[$key])){$r['alias_ids']=[];$r['aliases']=[];$topics[$key]=$r;}
            $topics[$key]['alias_ids'][]=(int)$r['id'];$topics[$key]['aliases'][]=['id'=>(int)$r['id'],'chapter_id'=>(int)$r['chapter_id'],'scope'=>$alias['scope'],'school_id'=>(int)$r['school_id']];
        }
    }
    return array_values($topics);
}
/** Resolve old topic-only AJAX requests through the dedicated read scope. */
function learningCatalogChapterScope(PDO $db,array $actor,int $chapter):array {
    require_once __DIR__.'/learning_content.php';
    $q=$db->prepare('SELECT * FROM chapters WHERE id=?');$q->execute([$chapter]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r||$r['archived_at'])throw new InvalidArgumentException('Chapter not found.');$scope=learningRecordScope($db,$actor,$r);
    if(!$scope)throw new InvalidArgumentException('Chapter not found.');return $scope;
}
function learningCatalogSelection(PDO $db,array $actor,int $class,int $subject,array $input,bool $allowCreate=false):array {
    $name=$input['chapter']??'';if(!is_string($name))throw new InvalidArgumentException('Choose a valid chapter.');
    $topicName=$input['topic']??'';if(!is_string($topicName))throw new InvalidArgumentException('Choose a valid topic.');
    foreach(['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'] as $idField)if(!empty($input[$idField])&&(!is_scalar($input[$idField])||!preg_match('/^[1-9][0-9]*$/',(string)$input[$idField])))throw new InvalidArgumentException('Choose a valid catalog reference.');
    $rows=learningCatalogChapters($db,$actor,$class,$subject);$chapter=null;$key=curriculumNameKey($name);
    foreach($rows as $r)if($key===curriculumNameKey($r['name'])){$chapter=$r;break;}
    if(!$chapter&&$allowCreate&&learningCanManageCatalog($actor)){$id=curriculumEnsureChapter($db,$actor['school_id'],$class,$subject,$name);$rows=learningCatalogChapters($db,$actor,$class,$subject);foreach($rows as $r)if(in_array($id,$r['alias_ids'],true))$chapter=$r;}
    if(!$chapter)throw new InvalidArgumentException('Chapter "'.mb_substr($name,0,100).'" is not in this class subject catalog. Ask super admin to add it.');
    $result=['chapter'=>$chapter['name'],'topic'=>null,'chapter_id'=>null,'topic_id'=>null,'catalog_chapter_id'=>null,'catalog_topic_id'=>null];
    $selectedAlias=$chapter['aliases'][0];
    foreach(['chapter_id'=>'local','catalog_chapter_id'=>'shared'] as $field=>$source)if(!empty($input[$field])){
        $found=false;foreach($chapter['aliases'] as $alias)if($alias['id']===(int)$input[$field]&&$alias['scope']===$source){$selectedAlias=$alias;$found=true;}
        if(!$found)throw new InvalidArgumentException('Chapter reference does not match the selected class subject.');
    }
    $topics=learningCatalogTopics($db,$actor,$class,$subject,(int)$chapter['id']);$selectedTopic=null;
    foreach(['topic_id'=>'local','catalog_topic_id'=>'shared'] as $field=>$source)if(!empty($input[$field])){
        if($selectedTopic){if($actor['school_id']===0&&$selectedTopic['alias']['id']===(int)$input[$field])continue;throw new InvalidArgumentException('Choose one topic reference.');}
        foreach($topics as $r)foreach($r['aliases'] as $alias)if($alias['id']===(int)$input[$field]&&($alias['scope']===$source||($field==='topic_id'&&$actor['school_id']===0)))$selectedTopic=['row'=>$r,'alias'=>$alias];
        if(!$selectedTopic)throw new InvalidArgumentException('Topic reference does not belong to this chapter.');
    }
    if(!$selectedTopic&&curriculumNameKey($topicName)!==''){
        foreach($topics as $r)if(curriculumNameKey($r['name'])===curriculumNameKey($topicName)){$selectedTopic=['row'=>$r,'alias'=>$r['aliases'][0]];break;}
        if(!$selectedTopic&&$allowCreate&&learningCanManageCatalog($actor)){
            $id=curriculumEnsureTopic($db,$actor['school_id'],$selectedAlias['id'],$topicName);foreach(learningCatalogTopics($db,$actor,$class,$subject,(int)$chapter['id']) as $r)foreach($r['aliases'] as $alias)if($alias['id']===$id)$selectedTopic=['row'=>$r,'alias'=>$alias];
        }
        if(!$selectedTopic)throw new InvalidArgumentException('Topic "'.mb_substr($topicName,0,100).'" is not in this chapter catalog. Ask super admin to add it.');
    }
    if($selectedTopic){$alias=$selectedTopic['alias'];$result[$alias['scope']==='shared'?'catalog_topic_id':'topic_id']=$alias['id'];$result['topic']=$selectedTopic['row']['name'];
        foreach($chapter['aliases'] as $ch)if($ch['id']===$alias['chapter_id']&&$ch['scope']===$alias['scope'])$selectedAlias=$ch;
    }
    if($actor['school_id']===0&&$selectedTopic)$result['topic_id']=$selectedTopic['alias']['id'];
    $result[$selectedAlias['scope']==='shared'?'catalog_chapter_id':'chapter_id']=$selectedAlias['id'];return $result;
}
/** Unresolved legacy note labels/references stay recoverable by super-admin review. */
function learningNoteNeedsCatalogReview(PDO $db,array $actor,array $note):bool {
    if(empty($note['class_id'])||empty($note['subject_id']))return true;
    try{
        $chapter=null;foreach(learningCatalogChapters($db,$actor,(int)$note['class_id'],(int)$note['subject_id']) as $r)if(curriculumNameKey($r['name'])===curriculumNameKey((string)$note['chapter'])){$chapter=$r;break;}
        if(!$chapter)return true;
        foreach(['chapter_id','catalog_chapter_id'] as $key)if(!empty($note[$key])&&!in_array((int)$note[$key],$chapter['alias_ids'],true))return true;
        $topicLabel=curriculumNameKey((string)($note['topic']??''));$topicId=(int)($note['catalog_topic_id']?:$note['topic_id']);
        if($topicLabel===''&&!$topicId)return false;
        foreach(learningCatalogTopics($db,$actor,(int)$note['class_id'],(int)$note['subject_id'],(int)$chapter['id']) as $r)if(($topicLabel===''||curriculumNameKey($r['name'])===$topicLabel)&&(!$topicId||in_array($topicId,$r['alias_ids'],true)))return false;
        return true;
    }catch(InvalidArgumentException $e){return true;}
}
