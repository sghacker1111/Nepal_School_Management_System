<?php
require_once __DIR__.'/routes.php';
/** Recoverable curriculum removal, with active-use checks under the catalog write lock. */
class CurriculumDeleteBlocked extends InvalidArgumentException {
    public function __construct(public readonly array $report){parent::__construct('Cannot delete: '.$report['total'].' active linked content item(s). Remove or reassign the content listed below, then try again.');}
}
function curriculumRequireDeletePassword(PDO $db,int $userId,mixed $password):void {
    if(!schoolVerifySensitivePassword($db,$userId,$password))throw new InvalidArgumentException('The current password is incorrect or verification is temporarily locked. Deletion was cancelled.');
}
function curriculumRequireDeleteAuthorization(PDO $db,int $school,string $kind,int $id,int $userId,mixed $password):void {
    $report=curriculumDependencyReport($db,$school,$kind,$id);
    if($report['total'])throw new CurriculumDeleteBlocked($report);
    curriculumRequireDeletePassword($db,$userId,$password);
}
function migrateCurriculumDeletion(PDO $db):void {
    foreach(['chapters'=>['archived_at'=>'TIMESTAMP NULL'],'topics'=>['archived_at'=>'TIMESTAMP NULL','deleted_with_chapter_id'=>'INTEGER NULL']] as $table=>$columns){
        $existing=dbColumns($db,$table);foreach($columns as $name=>$type)if(!in_array($name,$existing,true))$db->exec("ALTER TABLE $table ADD COLUMN $name $type");
    }
}
/** All same-name historical IDs in this exact school/class/subject are one visible entry. */
function curriculumIdentity(PDO $db,int $school,string $kind,int $id):array {
    if(!in_array($kind,['chapters','topics'],true))throw new InvalidArgumentException('Choose a chapter or topic.');
    $row=schoolRecord($db,$kind,$id,$school);
    $chapter=$kind==='chapters'?$row:schoolRecord($db,'chapters',(int)$row['chapter_id'],$school);
    $q=$db->prepare('SELECT * FROM chapters WHERE school_id=? AND class_id=? AND subject_id=?');$q->execute([$school,$chapter['class_id'],$chapter['subject_id']]);$chapters=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)if(curriculumNameKey((string)$r['name'])===curriculumNameKey((string)$chapter['name']))$chapters[]=$r;
    $chapterIds=array_map('intval',array_column($chapters,'id'));
    $q=$db->prepare('SELECT * FROM topics WHERE school_id=? AND chapter_id IN ('.implode(',',$chapterIds).')');$q->execute([$school]);$topics=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $t)if($kind==='chapters'||curriculumNameKey((string)$t['name'])===curriculumNameKey((string)$row['name']))$topics[]=$t;
    return ['row'=>$row,'chapter'=>$chapter,'chapters'=>$chapters,'chapter_ids'=>$chapterIds,'topics'=>$topics,'topic_ids'=>array_map('intval',array_column($topics,'id')),'ids'=>$kind==='chapters'?$chapterIds:array_map('intval',array_column($topics,'id'))];
}
function curriculumDependencyReport(PDO $db,int $school,string $kind,int $id):array {
    $identity=curriculumIdentity($db,$school,$kind,$id);$row=$identity['row'];$chapter=$identity['chapter'];$chapterIds=$identity['chapter_ids'];$topicIds=$identity['topic_ids'];
    $chapterKey=curriculumNameKey((string)$chapter['name']);$topicKey=$kind==='topics'?curriculumNameKey((string)$row['name']):'';
    $classRow=schoolRecord($db,'classes',(int)$chapter['class_id'],$school);$subjectRow=schoolRecord($db,'subjects',(int)$chapter['subject_id'],$school);
    $report=['kind'=>$kind,'id'=>$id,'name'=>curriculumName((string)$row['name']),'total'=>0,'groups'=>[]];
    $add=function(array $record,string $group,string $section,?string $url)use(&$report,$db,$school):void {
        if($url!==null){$route=schoolRouteForController(parse_url($url,PHP_URL_PATH)??'');if($route)$url=$route['path'].(($query=parse_url($url,PHP_URL_QUERY))!==null?'?'.$query:'');}
        if(!isset($report['groups'][$group]))$report['groups'][$group]=['section'=>$section,'count'=>0,'items'=>[]];
        $report['total']++;$report['groups'][$group]['count']++;
        if(count($report['groups'][$group]['items'])>=20)return;
        $lookup=static function(string $table,string $field,int $id)use($db):string {$q=$db->prepare("SELECT $field FROM $table WHERE id=?");$q->execute([$id]);return (string)($q->fetchColumn()?:'#'.$id);};
        $schoolId=(int)$record['school_id'];
        $report['groups'][$group]['items'][]=['id'=>(int)$record['id'],'school'=>$schoolId===0?'Shared catalog':$lookup('schools','name_en',$schoolId),'class'=>$lookup('classes','name',(int)$record['class_id']),'subject'=>$lookup('subjects','name_en',(int)$record['subject_id']),'owner'=>empty($record['created_by_user_id'])?'Legacy / administrator review':$lookup('users','full_name',(int)$record['created_by_user_id']),'url'=>$schoolId===$school?$url:null];
    };
    $topicSql=$topicIds?implode(',',$topicIds):'0';$chapterSql=implode(',',$chapterIds);
    foreach(['questions','learning_notes'] as $table){
        $direct=["topic_id IN ($topicSql)","catalog_topic_id IN ($topicSql)"];
        if($kind==='chapters'){$direct[]="catalog_chapter_id IN ($chapterSql)";if($table==='learning_notes')$direct[]="chapter_id IN ($chapterSql)";}
        elseif($table==='learning_notes'){$direct[]="catalog_chapter_id IN ($chapterSql)";$direct[]="chapter_id IN ($chapterSql)";}
        $scope=$table==='questions'?'(school_id=? AND class_id=? AND subject_id=?)':'(school_id=? AND ((class_id=? AND subject_id=?) OR class_id IS NULL OR subject_id IS NULL))';
        $columns=$table==='questions'?'id,school_id,class_id,subject_id,chapter,topic_id,catalog_topic_id,catalog_chapter_id,type,bank_kind,created_by_user_id':'id,school_id,class_id,subject_id,class_level,subject_name,chapter,topic,chapter_id,topic_id,catalog_topic_id,catalog_chapter_id,created_by_user_id';
        $q=$db->prepare("SELECT $columns FROM $table WHERE archived_at IS NULL AND (".implode(' OR ',[...$direct,$scope]).') ORDER BY id');$q->execute([$school,$chapter['class_id'],$chapter['subject_id']]);
        while($record=$q->fetch(PDO::FETCH_ASSOC)){
            $linked=in_array((int)($record['topic_id']??0),$topicIds,true)||in_array((int)($record['catalog_topic_id']??0),$topicIds,true);
            if($kind==='chapters')$linked=$linked||in_array((int)($record['catalog_chapter_id']??0),$chapterIds,true)||in_array((int)($record['chapter_id']??0),$chapterIds,true);
            $sameScope=(int)$record['school_id']===$school&&(int)$record['class_id']===(int)$chapter['class_id']&&(int)$record['subject_id']===(int)$chapter['subject_id'];
            $legacyNote=$table==='learning_notes'&&(!$record['class_id']||!$record['subject_id']);
            if($legacyNote&&(int)$record['school_id']===$school&&classNameKey((string)$record['class_level'])===classNameKey((string)$classRow['name'])&&catalogNameKey(subjectCanonicalName((string)$record['subject_name']))===catalogNameKey(subjectCanonicalName((string)$subjectRow['name_en'])))$sameScope=true;
            if(!$linked&&$sameScope&&curriculumNameKey((string)$record['chapter'])===$chapterKey){
                $linked=$kind==='chapters'||($table==='learning_notes'&&!$record['topic_id']&&!$record['catalog_topic_id']&&curriculumNameKey((string)$record['topic'])===$topicKey);
            }
            if(!$linked&&$kind==='topics'&&$table==='learning_notes'&&!$record['topic_id']&&!$record['catalog_topic_id']&&curriculumNameKey((string)$record['topic'])===$topicKey&&(in_array((int)$record['catalog_chapter_id'],$chapterIds,true)||in_array((int)$record['chapter_id'],$chapterIds,true)))$linked=true;
            if(!$linked)continue;
            if($table==='learning_notes'){$group='notes';$section='Learning Hub / Notes';$url='/modules/learning/index.php?'.http_build_query($legacyNote?['unassigned'=>1]:['class_id'=>$record['class_id'],'subject_id'=>$record['subject_id'],'chapter'=>$record['chapter']]);if($legacyNote){$record['class_id']=$chapter['class_id'];$record['subject_id']=$chapter['subject_id'];}}
            else{$exam=$record['bank_kind']==='exam';$mcq=$record['type']==='MCQ';$group=$exam?($mcq?'exam_mcqs':'exam_questions'):'question_bank';$section=$exam?($mcq?'Exam Multiple Choice Questions':'Exam Questions'):'Question Bank';$url='/modules/learning/'.($exam?($mcq?'exam_mcqs.php':'exam_questions.php'):'questions.php').'?'.http_build_query(['class_id'=>$record['class_id'],'subject_id'=>$record['subject_id'],'chapter'=>$record['chapter']]);}
            $url.='&record_id='.(int)$record['id'];$add($record,$group,$section,$url);
        }
    }
    foreach(['exam_paper_grids','specification_grids'] as $table){
        $q=$db->query('SELECT * FROM '.$table.($table==='exam_paper_grids'?' WHERE archived_at IS NULL':'').' ORDER BY id');
        while($grid=$q->fetch(PDO::FETCH_ASSOC)){
            $config=json_decode((string)$grid['config_json'],true);$sameScope=(int)$grid['school_id']===$school&&(int)$grid['class_id']===(int)$chapter['class_id']&&(int)$grid['subject_id']===(int)$chapter['subject_id'];
            $uses=function(mixed $value,?string $key=null)use(&$uses,$kind,$chapterIds,$topicIds,$sameScope,$chapterKey,$topicKey):bool {
                if(is_array($value)){foreach($value as $k=>$v)if($uses($v,is_string($k)?$k:$key))return true;return false;}
                if(!is_scalar($value))return false;
                if(in_array($key,['topic_id','topic_ids','catalog_topic_id'],true)&&in_array((int)$value,$topicIds,true))return true;
                if($kind==='chapters'&&in_array($key,['chapter_id','chapter_ids','catalog_chapter_id'],true)&&in_array((int)$value,$chapterIds,true))return true;
                if($sameScope&&$kind==='chapters'&&in_array($key,['chapter','chapters','chapter_names'],true)&&curriculumNameKey((string)$value)===$chapterKey)return true;
                return false;
            };
            if((!is_array($config)&&$sameScope)||$uses($config))$add($grid,$table,$table==='exam_paper_grids'?'Exam Paper Grids':'Specification Grids','/modules/learning/'.($table==='exam_paper_grids'?'exam_specification_grids.php?edit_grid_id=':'specification_grids.php?edit=').(int)$grid['id']);
        }
    }
    $report['groups']=array_values($report['groups']);return $report;
}
function curriculumArchiveUnused(PDO $db,int $school,string $kind,int $id):void {
    curriculumWrite($db,function()use($db,$school,$kind,$id){
        $identity=curriculumIdentity($db,$school,$kind,$id);if($identity['row']['archived_at'])throw new InvalidArgumentException('This entry is already deleted.');
        $report=curriculumDependencyReport($db,$school,$kind,$id);if($report['total'])throw new CurriculumDeleteBlocked($report);
        $ids=implode(',',$identity['ids']);$at=date('Y-m-d H:i:s');
        $db->prepare("UPDATE $kind SET archived_at=? WHERE school_id=? AND id IN ($ids) AND archived_at IS NULL")->execute([$at,$school]);
        if($kind==='chapters')$db->prepare('UPDATE topics SET archived_at=?,deleted_with_chapter_id=? WHERE school_id=? AND chapter_id IN ('.implode(',',$identity['chapter_ids']).') AND archived_at IS NULL')->execute([$at,$id,$school]);
    });
}
function curriculumRestore(PDO $db,int $school,string $kind,int $id):void {
    curriculumWrite($db,function()use($db,$school,$kind,$id){
        $identity=curriculumIdentity($db,$school,$kind,$id);if(!$identity['row']['archived_at'])throw new InvalidArgumentException('This entry is not deleted.');
        if($kind==='topics'&&$identity['chapter']['archived_at'])throw new InvalidArgumentException('Restore the parent chapter before restoring this topic.');
        $db->prepare("UPDATE $kind SET archived_at=NULL".($kind==='topics'?',deleted_with_chapter_id=NULL':'').' WHERE school_id=? AND id IN ('.implode(',',$identity['ids']).')')->execute([$school]);
        if($kind==='chapters')$db->prepare('UPDATE topics SET archived_at=NULL,deleted_with_chapter_id=NULL WHERE school_id=? AND deleted_with_chapter_id IN ('.implode(',',$identity['chapter_ids']).')')->execute([$school]);
    });
}
function curriculumDeletedList(PDO $db,int $school,int $class,int $subject):array {
    schoolRecord($db,'classes',$class,$school);schoolRecord($db,'subjects',$subject,$school);$result=[];
    foreach(['chapters','topics'] as $kind){
        $q=$db->prepare("SELECT * FROM $kind WHERE school_id=? AND class_id=? AND subject_id=? AND archived_at IS NOT NULL ORDER BY id");$q->execute([$school,$class,$subject]);$seen=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$chapter=$kind==='topics'?schoolRecord($db,'chapters',(int)$row['chapter_id'],$school):$row;$key=curriculumNameKey((string)$chapter['name']).':'.curriculumNameKey((string)$row['name']);if(isset($seen[$key]))continue;$seen[$key]=true;$result[]=['kind'=>$kind,'id'=>(int)$row['id'],'name'=>curriculumName((string)$row['name']),'chapter'=>curriculumName((string)$chapter['name']),'parent_deleted'=>$kind==='topics'&&(bool)$chapter['archived_at']];}
    }return $result;
}
function curriculumAssertContentRestorable(PDO $db,array $record):void {
    foreach(['chapter_id'=>'chapters','catalog_chapter_id'=>'chapters','topic_id'=>'topics','catalog_topic_id'=>'topics'] as $key=>$table)if(!empty($record[$key])){
        $q=$db->prepare("SELECT archived_at FROM $table WHERE id=?");$q->execute([(int)$record[$key]]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row&&$row['archived_at'])throw new InvalidArgumentException('Restore the linked chapter/topic in Deleted curriculum before restoring this content.');
    }
    $q=$db->prepare('SELECT name,archived_at FROM chapters WHERE school_id=? AND class_id=? AND subject_id=?');$q->execute([$record['school_id'],$record['class_id'],$record['subject_id']]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)if($row['archived_at']&&curriculumNameKey((string)$row['name'])===curriculumNameKey((string)$record['chapter']))throw new InvalidArgumentException('Restore the linked chapter in Deleted curriculum before restoring this content.');
    if((empty($record['class_id'])||empty($record['subject_id']))&&isset($record['class_level'],$record['subject_name'])){
        $q=$db->prepare('SELECT ch.name,c.name class_name,s.name_en FROM chapters ch JOIN classes c ON c.id=ch.class_id JOIN subjects s ON s.id=ch.subject_id WHERE ch.school_id=? AND ch.archived_at IS NOT NULL');$q->execute([$record['school_id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)if(curriculumNameKey((string)$r['name'])===curriculumNameKey((string)$record['chapter'])&&classNameKey((string)$r['class_name'])===classNameKey((string)$record['class_level'])&&catalogNameKey(subjectCanonicalName((string)$r['name_en']))===catalogNameKey(subjectCanonicalName((string)$record['subject_name'])))throw new InvalidArgumentException('Restore the linked chapter in Deleted curriculum before restoring this legacy note.');
    }
    if(!empty($record['topic'])){
        $q=$db->prepare('SELECT t.name,ch.name chapter_name,t.class_id,t.subject_id,c.name class_name,s.name_en FROM topics t JOIN chapters ch ON ch.id=t.chapter_id JOIN classes c ON c.id=t.class_id JOIN subjects s ON s.id=t.subject_id WHERE t.school_id=? AND (t.archived_at IS NOT NULL OR ch.archived_at IS NOT NULL)');$q->execute([$record['school_id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){$sameScope=(int)$r['class_id']===(int)$record['class_id']&&(int)$r['subject_id']===(int)$record['subject_id'];if((empty($record['class_id'])||empty($record['subject_id']))&&isset($record['class_level'],$record['subject_name']))$sameScope=classNameKey((string)$r['class_name'])===classNameKey((string)$record['class_level'])&&catalogNameKey(subjectCanonicalName((string)$r['name_en']))===catalogNameKey(subjectCanonicalName((string)$record['subject_name']));if($sameScope&&curriculumNameKey((string)$r['chapter_name'])===curriculumNameKey((string)$record['chapter'])&&curriculumNameKey((string)$r['name'])===curriculumNameKey((string)$record['topic']))throw new InvalidArgumentException('Restore the linked topic in Deleted curriculum before restoring this note.');}
    }
}
function curriculumAssertGridRestorable(PDO $db,array $config):void {
    $check=function(mixed $value,?string $key=null)use(&$check,$db):void {
        if(is_array($value)){foreach($value as $k=>$v)$check($v,is_string($k)?$k:$key);return;}
        $table=in_array($key,['chapter_id','chapter_ids'],true)?'chapters':(in_array($key,['topic_id','topic_ids'],true)?'topics':null);
        if(!$table||!is_scalar($value)||!preg_match('/^[1-9][0-9]*$/',(string)$value))return;
        $q=$db->prepare("SELECT archived_at FROM $table WHERE id=?");$q->execute([(int)$value]);$r=$q->fetch(PDO::FETCH_ASSOC);
        if($r&&$r['archived_at'])throw new InvalidArgumentException('Restore the linked chapter/topic in Deleted curriculum before restoring this exam grid.');
    };$check($config);
}
