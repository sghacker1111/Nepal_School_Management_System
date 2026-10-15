<?php
/** Shared chapter/topic identity. Existing IDs and question contents are never replaced. */
require_once __DIR__.'/school_validation.php';
function curriculumName(string $name):string {return trim(html_entity_decode($name,ENT_QUOTES|ENT_HTML5,'UTF-8'));}
function curriculumNameKey(string $name):string {return mb_strtolower(preg_replace('/\s+/u',' ',curriculumName($name)),'UTF-8');}
function curriculumWrite(PDO $db,callable $work):mixed {
    $owned=!$db->inTransaction();if($owned)$db->beginTransaction();
    try{
        // Serialize catalog inserts/renames, including first-time import, across both editors.
        $db->exec("UPDATE app_meta SET meta_value=meta_value WHERE meta_key='workflow_schema'");
        $result=$work();if($owned)$db->commit();return $result;
    }catch(Throwable $e){if($owned&&$db->inTransaction())$db->rollBack();throw $e;}
}
function curriculumChapters(PDO $db,int $school,int $class,int $subject,bool $includeDeleted=false):array {
    schoolRecord($db,'classes',$class,$school);schoolRecord($db,'subjects',$subject,$school);
    $q=$db->prepare('SELECT * FROM chapters WHERE school_id=? AND class_id=? AND subject_id=?'.($includeDeleted?'':' AND archived_at IS NULL').' ORDER BY sort_order,id');$q->execute([$school,$class,$subject]);
    $unique=[];foreach($q->fetchAll() as $row){$row['name']=curriculumName((string)$row['name']);$row['name_np']=curriculumName((string)($row['name_np']??''));$key=curriculumNameKey($row['name']);if(!isset($unique[$key]))$unique[$key]=$row;}
    return array_values($unique);
}
function curriculumEnsureChapter(PDO $db,int $school,int $class,int $subject,string $name,string $nepali='',int $order=0):int {
    $name=curriculumName($name);$nepali=curriculumName($nepali);
    if($name===''||mb_strlen($name)>255||mb_strlen($nepali)>255)throw new InvalidArgumentException('Enter a chapter name of 1 to 255 characters.');
    return curriculumWrite($db,function()use($db,$school,$class,$subject,$name,$nepali,$order){
        foreach(curriculumChapters($db,$school,$class,$subject,true) as $row)if(curriculumNameKey($row['name'])===curriculumNameKey($name)){if($row['archived_at'])throw new InvalidArgumentException('This chapter is deleted. Restore it from Deleted curriculum instead of creating a duplicate.');return (int)$row['id'];}
        $db->prepare('INSERT INTO chapters(school_id,class_id,subject_id,name,name_np,sort_order) VALUES(?,?,?,?,?,?)')->execute([$school,$class,$subject,$name,$nepali,$order]);return (int)$db->lastInsertId();
    });
}
function curriculumTopics(PDO $db,int $school,int $chapter,bool $includeDeleted=false):array {
    $ch=schoolRecord($db,'chapters',$chapter,$school);
    if(!$includeDeleted&&$ch['archived_at'])return [];
    // Preserve topics linked to historical duplicate chapter IDs in the unified display.
    $q=$db->prepare('SELECT id,name FROM chapters WHERE school_id=? AND class_id=? AND subject_id=?');$q->execute([$school,$ch['class_id'],$ch['subject_id']]);$ids=[];
    foreach($q->fetchAll() as $r)if(curriculumNameKey((string)$r['name'])===curriculumNameKey((string)$ch['name']))$ids[]=(int)$r['id'];
    $q=$db->prepare('SELECT * FROM topics WHERE school_id=? AND chapter_id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($includeDeleted?'':' AND archived_at IS NULL').' ORDER BY sort_order,id');$q->execute([$school,...$ids]);
    $rows=$q->fetchAll();foreach($rows as &$row){$row['name']=curriculumName((string)$row['name']);$row['name_np']=curriculumName((string)($row['name_np']??''));}unset($row);return $rows;
}
/** Unique choices retain every historical ID so existing questions can keep their links. */
function curriculumTopicChoices(PDO $db,int $school,int $chapter):array {
    $choices=[];
    foreach(curriculumTopics($db,$school,$chapter) as $row){
        $key=curriculumNameKey($row['name']);
        if(!isset($choices[$key])){$row['alias_ids']=[];$choices[$key]=$row;}
        $choices[$key]['alias_ids'][]=(int)$row['id'];
    }
    return array_values($choices);
}
function curriculumEnsureTopic(PDO $db,int $school,int $chapter,string $name,string $nepali='',int $order=0):int {
    $name=curriculumName($name);$nepali=curriculumName($nepali);
    if($name===''||mb_strlen($name)>255||mb_strlen($nepali)>255)throw new InvalidArgumentException('Enter a topic name of 1 to 255 characters.');
    return curriculumWrite($db,function()use($db,$school,$chapter,$name,$nepali,$order){
        $ch=schoolRecord($db,'chapters',$chapter,$school);
        if($ch['archived_at'])throw new InvalidArgumentException('Restore the deleted chapter before adding topics.');
        foreach(curriculumTopics($db,$school,$chapter,true) as $row)if(curriculumNameKey($row['name'])===curriculumNameKey($name)){if($row['archived_at'])throw new InvalidArgumentException('This topic is deleted. Restore it from Deleted curriculum instead of creating a duplicate.');return (int)$row['id'];}
        $db->prepare('INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name,name_np,sort_order) VALUES(?,?,?,?,?,?,?)')->execute([$school,$ch['class_id'],$ch['subject_id'],$chapter,$name,$nepali,$order]);return (int)$db->lastInsertId();
    });
}
function curriculumQuestionChapter(PDO $db,int $school,int $class,int $subject,string $name,?int $topic):string {
    $name=curriculumName($name);
    if($topic){$t=schoolRecord($db,'topics',$topic,$school);$ch=schoolRecord($db,'chapters',(int)$t['chapter_id'],$school);
        if((int)$t['class_id']!==$class||(int)$t['subject_id']!==$subject||curriculumNameKey((string)$ch['name'])!==curriculumNameKey($name))throw new InvalidArgumentException('Choose a topic in the selected chapter, class and subject.');
    }
    $id=curriculumEnsureChapter($db,$school,$class,$subject,$name);return curriculumName((string)schoolRecord($db,'chapters',$id,$school)['name']);
}
function curriculumRenameChapter(PDO $db,int $school,int $id,string $name,string $nepali,int $order):void {
    $name=curriculumName($name);$nepali=curriculumName($nepali);if($name===''||mb_strlen($name)>255||mb_strlen($nepali)>255)throw new InvalidArgumentException('Enter a valid chapter name.');
    curriculumWrite($db,function()use($db,$school,$id,$name,$nepali,$order){
        $old=schoolRecord($db,'chapters',$id,$school);
        if($old['archived_at'])throw new InvalidArgumentException('Restore this chapter before editing it.');
        $oldKey=curriculumNameKey((string)$old['name']);
        $q=$db->prepare('SELECT id,name FROM chapters WHERE school_id=? AND class_id=? AND subject_id=?');$q->execute([$school,$old['class_id'],$old['subject_id']]);$aliases=[];
        foreach($q->fetchAll() as $ch){
            $key=curriculumNameKey((string)$ch['name']);
            if($key===$oldKey)$aliases[]=(int)$ch['id'];
            elseif($key===curriculumNameKey($name))throw new InvalidArgumentException('Another chapter already uses that name.');
        }
        $q=$db->prepare('SELECT id,chapter FROM questions WHERE school_id=? AND class_id=? AND subject_id=?');$q->execute([$school,$old['class_id'],$old['subject_id']]);
        foreach($q->fetchAll() as $question)if(curriculumNameKey($question['chapter'])===curriculumNameKey((string)$old['name']))$db->prepare('UPDATE questions SET chapter=? WHERE id=? AND school_id=?')->execute([$name,$question['id'],$school]);
        // Cross-school shared references use their dedicated catalog IDs.
        if(in_array('catalog_chapter_id',dbColumns($db,'questions'),true))foreach(['questions','learning_notes'] as $table){
            $field=$school===0?'catalog_chapter_id':($table==='learning_notes'?'chapter_id':null);
            if($field)$db->prepare("UPDATE $table SET chapter=? WHERE $field IN (".implode(',',array_fill(0,count($aliases),'?')).')')->execute([$name,...$aliases]);
        }
        $update=$db->prepare('UPDATE chapters SET name=?,name_np=?,sort_order=? WHERE id=? AND school_id=?');
        foreach($aliases as $alias)$update->execute([$name,$nepali,$order,$alias,$school]);
    });
}
function curriculumRenameTopic(PDO $db,int $school,int $id,string $name,string $nepali,int $order):void {
    $name=curriculumName($name);$nepali=curriculumName($nepali);
    if($name===''||mb_strlen($name)>255||mb_strlen($nepali)>255)throw new InvalidArgumentException('Enter a topic name of 1 to 255 characters.');
    curriculumWrite($db,function()use($db,$school,$id,$name,$nepali,$order){
        $old=schoolRecord($db,'topics',$id,$school);
        if($old['archived_at'])throw new InvalidArgumentException('Restore this topic before editing it.');
        foreach(curriculumTopics($db,$school,(int)$old['chapter_id'],true) as $topic){
            if((int)$topic['id']!==$id&&curriculumNameKey($topic['name'])===curriculumNameKey($name))throw new InvalidArgumentException('Another topic in this chapter already uses that name.');
        }
        $db->prepare('UPDATE topics SET name=?,name_np=?,sort_order=? WHERE id=? AND school_id=?')->execute([$name,$nepali,$order,$id,$school]);
        if(in_array('catalog_topic_id',dbColumns($db,'learning_notes'),true))$db->prepare('UPDATE learning_notes SET topic=? WHERE '.($school===0?'catalog_topic_id':'topic_id').'=?')->execute([$name,$id]);
    });
}
function curriculumDelete(PDO $db,int $school,string $kind,int $id):void {
    curriculumArchiveUnused($db,$school,$kind,$id);
}
/** Additive schema upgrade: persist the existing fallback catalog and legacy question chapter names. */
function migrateSharedCurriculum(PDO $db):void {
    curriculumWrite($db,function()use($db){
        $catalog=require __DIR__.'/../config/cdc_curriculum.php';
        $pairs=$db->query('SELECT DISTINCT cs.school_id,c.id class_id,c.name class_name,s.id subject_id,s.name_en subject_name FROM class_subjects cs JOIN classes c ON c.id=cs.class_id AND c.school_id=cs.school_id JOIN subjects s ON s.id=cs.subject_id AND s.school_id=cs.school_id')->fetchAll();
        // The former Question Bank fallback was available to schools too. Persist it
        // in each mapped school scope; never point school questions at global IDs.
        foreach($pairs as $p)foreach($catalog[$p['subject_name']][$p['class_name']]??[] as $i=>$name)curriculumEnsureChapter($db,(int)$p['school_id'],(int)$p['class_id'],(int)$p['subject_id'],$name,'',$i+1);
        $q=$db->query("SELECT DISTINCT q.school_id,q.class_id,q.subject_id,q.chapter FROM questions q JOIN classes c ON c.id=q.class_id AND c.school_id=q.school_id JOIN subjects s ON s.id=q.subject_id AND s.school_id=q.school_id WHERE q.chapter IS NOT NULL AND TRIM(q.chapter)<>''");
        foreach($q->fetchAll() as $row)curriculumEnsureChapter($db,(int)$row['school_id'],(int)$row['class_id'],(int)$row['subject_id'],$row['chapter']);
    });
}
require_once __DIR__.'/curriculum_deletion.php';
