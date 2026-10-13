<?php
require_once __DIR__.'/learning_content.php';
require_once __DIR__.'/question_import.php';

function learningWrite(PDO $db,callable $work):mixed {return curriculumWrite($db,$work);}
function learningId(mixed $value):int {if(!is_scalar($value)||!preg_match('/^[1-9][0-9]*$/',(string)$value))throw new InvalidArgumentException('Choose a valid record.');return (int)$value;}
function learningVisibility(mixed $value):string {if(!is_string($value)||!in_array($value,['private','public'],true))throw new InvalidArgumentException('Choose Private or Public within this school.');return $value;}
function learningAudit(PDO $db,array $actor,string $action,string $kind,int $id):void {
    $q=$db->prepare('INSERT INTO activity_logs(user_id,action,table_name,record_id,ip_address) VALUES(?,?,?,?,?)');$q->execute([$actor['id'],$action.' (school '.$actor['school_id'].')',$kind,$id,$_SERVER['REMOTE_ADDR']??'local']);
}
function learningMutableRecord(PDO $db,array $actor,string $kind,int $id):array {
    $record=learningContentRecord($db,$actor,$kind,$id,$kind==='questions'?'bank':'study',true);
    if(!learningCanEdit($db,$actor,$record))throw new InvalidArgumentException('Only the creator may change this content, while assigned to its subject.');
    return $record;
}
function learningSaveQuestion(PDO $db,array $actor,array $input,?int $id=null):int {
    return learningWrite($db,function()use($db,$actor,$input,$id){
        if(array_key_exists('school_id',$input)||array_key_exists('created_by_user_id',$input))throw new InvalidArgumentException('Content school and creator are set by your account.');
        if($id){$old=learningMutableRecord($db,$actor,'questions',$id);if($old['archived_at'])throw new InvalidArgumentException('Restore archived content before editing it.');}
        $class=learningId($input['class_id']??null);$subject=learningId($input['subject_id']??null);
        if(!learningCanCreate($db,$actor,$class,$subject))throw new InvalidArgumentException('You may create content only for your assigned class subjects.');
        if($actor['school_id']>0){$q=$db->prepare('SELECT * FROM schools WHERE id=?');$q->execute([$actor['school_id']]);if(!schoolSubscriptionCanAddQuestions($q->fetch(PDO::FETCH_ASSOC)))throw new InvalidArgumentException('Your package does not allow question creation.');}
        $visibility=learningVisibility($input['visibility']??($old['visibility']??($actor['school_id']===0?'public':'private')));
        $type=$input['type']??'Short';if(!is_string($type)||!in_array($type,['MCQ','Very Short','Short','Long','Reasoning','Difference Between'],true))throw new InvalidArgumentException('Choose a valid question type.');
        foreach(['options','options_np'] as $field)if(!empty($input[$field])){
            $options=is_string($input[$field])?json_decode($input[$field],true,512,JSON_THROW_ON_ERROR):$input[$field];
            if(!is_array($options))throw new InvalidArgumentException('Invalid MCQ options.');
            foreach(['A','B','C','D'] as $key)$input['option_'.strtolower($key).($field==='options_np'?'_np':'')]=$options[$key]??'';
        }
        $get=static function($key)use($input):string {$value=$input[$key]??'';if(!is_scalar($value))throw new InvalidArgumentException('Enter valid question content.');return (string)$value;};
        $content=questionImportRowContent($get,'html');
        $catalog=learningCatalogSelection($db,$actor,$class,$subject,$input,learningCanManageCatalog($actor));
        $domain=$input['cognitive_domain']??'Knowledge';if(!is_string($domain)||!in_array($domain,['Knowledge','Understanding','Application','Higher Ability'],true))throw new InvalidArgumentException('Choose a valid cognitive level.');
        $values=$content+['class_id'=>$class,'subject_id'=>$subject,'chapter'=>$catalog['chapter'],'topic_id'=>$catalog['topic_id'],'catalog_chapter_id'=>$catalog['catalog_chapter_id'],'catalog_topic_id'=>$catalog['catalog_topic_id'],'visibility'=>$visibility,'cognitive_domain'=>$domain,'updated_at'=>date('Y-m-d H:i:s')];
        if($id){$q=$db->prepare('UPDATE questions SET '.implode(',',array_map(static fn($k)=>"$k=?",array_keys($values))).' WHERE id=?');$q->execute([...array_values($values),$id]);}
        else{$values+=['school_id'=>$actor['school_id'],'created_by_user_id'=>$actor['id']];$id=insertRecord($db,'questions',$values);}
        learningAudit($db,$actor,isset($old)?'Edit question':'Create question','questions',$id);return $id;
    });
}
function learningSetVisibility(PDO $db,array $actor,string $kind,int $id,string $visibility):void {
    learningVisibility($visibility);learningWrite($db,function()use($db,$actor,$kind,$id,$visibility){$r=learningMutableRecord($db,$actor,$kind,$id);if($r['archived_at'])throw new InvalidArgumentException('Restore archived content before changing visibility.');$db->prepare("UPDATE $kind SET visibility=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$visibility,$id]);learningAudit($db,$actor,$visibility==='public'?'Publish content':'Make content private',$kind,$id);});
}
function learningSetArchived(PDO $db,array $actor,string $kind,int $id,bool $archived):void {
    learningWrite($db,function()use($db,$actor,$kind,$id,$archived){learningMutableRecord($db,$actor,$kind,$id);$db->prepare("UPDATE $kind SET archived_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$archived?date('Y-m-d H:i:s'):null,$id]);learningAudit($db,$actor,$archived?'Archive content':'Restore content',$kind,$id);});
}
