<?php
require_once __DIR__.'/learning_content.php';
require_once __DIR__.'/question_import.php';
require_once __DIR__.'/question_parts.php';
require_once __DIR__.'/password_security.php';

function learningWrite(PDO $db,callable $work):mixed {return curriculumWrite($db,$work);}
function learningId(mixed $value):int {if(!is_scalar($value)||!preg_match('/^[1-9][0-9]*$/',(string)$value))throw new InvalidArgumentException('Choose a valid record.');return (int)$value;}
function learningVisibility(mixed $value):string {if(!is_string($value)||!in_array($value,['private','public'],true))throw new InvalidArgumentException('Choose Private or Public within this school.');return $value;}
function learningDecimalUnits(mixed $value,int $maximum,string $label,bool $zero=false):int {
    if(!is_scalar($value)||!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/',(string)$value))throw new InvalidArgumentException($label.' must be a number with at most two decimals.');
    $parts=explode('.',(string)$value);$units=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    if($units<($zero?0:1)||$units>$maximum)throw new InvalidArgumentException($label.' is outside its permitted range.');return $units;
}
function learningDecimalString(int $units):string {return intdiv($units,100).'.'.str_pad((string)($units%100),2,'0',STR_PAD_LEFT);}
function learningAudit(PDO $db,array $actor,string $action,string $kind,int $id):void {
    $q=$db->prepare('INSERT INTO activity_logs(user_id,action,table_name,record_id,ip_address) VALUES(?,?,?,?,?)');$q->execute([$actor['id'],$action.' (school '.$actor['school_id'].')',$kind,$id,$_SERVER['REMOTE_ADDR']??'local']);
}
function learningMutableRecord(PDO $db,array $actor,string $kind,int $id,string $bankKind='learning'):array {
    if(!in_array($bankKind,['learning','exam'],true))throw new InvalidArgumentException('Invalid bank.');
    $record=learningContentRecord($db,$actor,$kind,$id,$kind==='questions'?($bankKind==='exam'?'exam_bank':'bank'):'study',true);
    if(!learningCanEdit($db,$actor,$record))throw new InvalidArgumentException('Only the creator may change this content, while assigned to its subject.');
    return $record;
}
function learningSaveQuestion(PDO $db,array $actor,array $input,?int $id=null,string $bankKind='learning',bool $allowCatalogCreate=true,bool $importContext=false):int {
    if(!in_array($bankKind,['learning','exam'],true))throw new InvalidArgumentException('Invalid bank.');
    return learningWrite($db,function()use($db,$actor,$input,$id,$bankKind,$allowCatalogCreate,$importContext){
        if(array_key_exists('bank_kind',$input))throw new InvalidArgumentException('The question bank is determined by its section.');
        if(array_key_exists('school_id',$input)||array_key_exists('created_by_user_id',$input))throw new InvalidArgumentException('Content school and creator are set by your account.');
        if($id){$old=learningMutableRecord($db,$actor,'questions',$id,$bankKind);if($old['archived_at'])throw new InvalidArgumentException('Restore archived content before editing it.');}
        if(!learningPurposeAllowed($db,$actor,$bankKind==='exam'?'exam_bank':'bank'))throw new InvalidArgumentException('This question bank is not in your package.');
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
        $subjectRow=$db->prepare('SELECT name_en,name_np FROM subjects WHERE id=?');$subjectRow->execute([$subject]);$subjectNames=$subjectRow->fetch(PDO::FETCH_ASSOC);
        $mathematics=false;foreach($subjectNames as $name)if(in_array(questionImportSubjectKey((string)$name),['mathematics','optional mathematics'],true))$mathematics=true;
        $layout=$input['question_layout']??'plain';if(!is_string($layout)||!in_array($layout,['plain','structured'],true))throw new InvalidArgumentException('Choose a valid question layout.');
        $structure=null;$existingStructure=isset($old)?questionPartsRead($old['question_text']??'',$old['question_text_np']??''):null;$allowImportedStructure=$importContext||(($existingStructure['math_rule']??'')==='import');
        if($layout==='structured'){
            $structure=questionPartsNormalize(['display'=>$input['part_display']??'both','math_rule'=>$input['mathematics_rule']??'strict','statement_en'=>$input['statement_en']??'','statement_np'=>$input['statement_np']??'','parts'=>$input['subquestions']??null],$mathematics&&$type!=='MCQ',$allowImportedStructure);
            $input['question_text']=questionPartsEncode($structure,'en');$input['question_text_np']=questionPartsEncode($structure,'np');
        }else{
            foreach(['question_text','question_text_np'] as $key)if(isset($input[$key])&&!is_string($input[$key]))throw new InvalidArgumentException('Enter valid question text.');
            $structure=questionPartsRead($input['question_text']??'',$input['question_text_np']??'',$mathematics&&$type!=='MCQ');
            if(($structure['math_rule']??'')==='import'&&!$allowImportedStructure)throw new InvalidArgumentException('Imported flexible mathematics structure can only be retained from an existing imported question.');
        }
        if($structure){if($type==='MCQ'&&count($structure['parts'])!==1)throw new InvalidArgumentException('A structured MCQ needs one prompt and one set of four options.');$input['marks']=questionPartNumber($structure['total_units']);$input['cognitive_domain']=QUESTION_PART_LEVELS[$structure['parts'][0]['levels'][0]];}
        $get=static function($key)use($input):string {$value=$input[$key]??'';if(!is_scalar($value))throw new InvalidArgumentException('Enter valid question content.');return (string)$value;};
        $content=questionImportRowContent($get,'html');
        if($structure&&$type!=='MCQ'&&$content['type']==='MCQ')throw new InvalidArgumentException('Written subquestions cannot contain MCQ options.');
        if($structure){$content['question_text']=questionPartsEncode($structure,'en');$content['question_text_np']=questionPartsEncode($structure,'np');}
        if($bankKind==='exam'||isset($input['marks']))$content['marks']=learningDecimalString(learningDecimalUnits($input['marks']??null,100000,'Question marks'));
        // Exam authoring is chapter-wide. Retain legacy topic links on an edit in
        // the same scope rather than silently detaching historical references.
        if($bankKind==='exam'&&isset($old)&&(int)$old['class_id']===$class&&(int)$old['subject_id']===$subject&&curriculumNameKey((string)($input['chapter']??''))===curriculumNameKey((string)$old['chapter'])){
            $supplied=false;foreach(['topic','topic_id','catalog_topic_id'] as $key)if(array_key_exists($key,$input))$supplied=true;
            if(!$supplied)foreach(['topic_id','catalog_topic_id'] as $key)$input[$key]=$old[$key]??null;
        }
        $catalog=learningCatalogSelection($db,$actor,$class,$subject,$input,$allowCatalogCreate&&learningCanManageCatalog($actor));
        $domain=$input['cognitive_domain']??'Knowledge';if(!is_string($domain)||!in_array($domain,array_values(QUESTION_PART_LEVELS),true))throw new InvalidArgumentException('Choose a valid cognitive level.');
        $values=$content+['class_id'=>$class,'subject_id'=>$subject,'chapter'=>$catalog['chapter'],'topic_id'=>$catalog['topic_id'],'catalog_chapter_id'=>$catalog['catalog_chapter_id'],'catalog_topic_id'=>$catalog['catalog_topic_id'],'visibility'=>$visibility,'cognitive_domain'=>$domain,'updated_at'=>date('Y-m-d H:i:s')];
        if($bankKind==='learning'){
            if(isset($input['difficulty'])){if(!is_string($input['difficulty'])||!in_array($input['difficulty'],['Easy','Medium','Hard','Challenging'],true))throw new InvalidArgumentException('Choose a valid difficulty.');$values['difficulty']=$input['difficulty'];}
            if(isset($input['required_minutes']))$values['required_minutes']=$input['required_minutes']===''?null:learningDecimalString(learningDecimalUnits($input['required_minutes'],60000,'Required minutes'));
        }
        if($bankKind==='exam'){
            $level=$input['difficulty']??'Medium';$minutes=$input['required_minutes']??null;
            if(!is_string($level)||!in_array($level,['Easy','Medium','Hard','Challenging'],true))throw new InvalidArgumentException('Choose Easy, Medium, Hard or Challenging.');
            $minutes=learningDecimalString(learningDecimalUnits($minutes,60000,'Required minutes'));
            $release=$input['student_visible']??'0';if(!is_scalar($release)||!in_array((string)$release,['0','1'],true))throw new InvalidArgumentException('Choose a valid student-view setting.');
            $values+=['difficulty'=>$level,'required_minutes'=>(string)$minutes,'student_visible'=>$visibility==='public'?(int)$release:0];
        }
        if($id){$q=$db->prepare('UPDATE questions SET '.implode(',',array_map(static fn($k)=>"$k=?",array_keys($values))).' WHERE id=?');$q->execute([...array_values($values),$id]);}
        else{$values+=['school_id'=>$actor['school_id'],'created_by_user_id'=>$actor['id'],'bank_kind'=>$bankKind];$id=insertRecord($db,'questions',$values);}
        learningAudit($db,$actor,isset($old)?'Edit question':'Create question','questions',$id);
        if($bankKind==='exam'&&(int)($old['student_visible']??0)!==(int)$values['student_visible'])learningAudit($db,$actor,$values['student_visible']?'Release exam question to students':'Revoke exam student access','questions',$id);
        return $id;
    });
}
function learningSetVisibility(PDO $db,array $actor,string $kind,int $id,string $visibility,string $bankKind='learning'):void {
    learningVisibility($visibility);learningWrite($db,function()use($db,$actor,$kind,$id,$visibility,$bankKind){$r=learningMutableRecord($db,$actor,$kind,$id,$bankKind);if($r['archived_at'])throw new InvalidArgumentException('Restore archived content before changing visibility.');$release=$kind==='questions'&&$bankKind==='exam'&&$visibility==='private'?',student_visible=0':'';$db->prepare("UPDATE $kind SET visibility=?,updated_at=CURRENT_TIMESTAMP$release WHERE id=?")->execute([$visibility,$id]);learningAudit($db,$actor,$visibility==='public'?'Publish content':'Make content private',$kind,$id);});
}
function learningSetArchived(PDO $db,array $actor,string $kind,int $id,bool $archived,string $bankKind='learning'):void {
    learningWrite($db,function()use($db,$actor,$kind,$id,$archived,$bankKind){$record=learningMutableRecord($db,$actor,$kind,$id,$bankKind);if(!$archived)curriculumAssertContentRestorable($db,$record);$db->prepare("UPDATE $kind SET archived_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$archived?date('Y-m-d H:i:s'):null,$id]);learningAudit($db,$actor,$archived?'Archive content':'Restore content',$kind,$id);});
}
/** Password failures must remain recorded even when the archive transaction rolls back. */
function learningBulkArchiveQuestions(PDO $db,array $actor,mixed $selection,mixed $password,string $bankKind='learning',string $section='all'):int {
    if(!in_array($bankKind,['learning','exam'],true)||!in_array($section,['all','written','mcq'],true))throw new InvalidArgumentException('Choose a valid question section.');
    if(!is_array($selection)||!array_is_list($selection)||!$selection||count($selection)>100)throw new InvalidArgumentException('Select between 1 and 100 questions on this page.');
    $ids=array_values(array_unique(array_map('learningId',$selection)));sort($ids,SORT_NUMERIC);
    $current=learningActor($db,(int)$actor['id'],(int)$actor['school_id'],(bool)$actor['read_only']);
    if($current['read_only']||$current['role']!==$actor['role']||!in_array($current['role'],['super_admin','school_admin','teacher'],true)||!learningPurposeAllowed($db,$current,$bankKind==='exam'?'exam_bank':'bank'))throw new InvalidArgumentException('Your account cannot delete these questions.');
    if(!schoolVerifySensitivePassword($db,$current['id'],$password))throw new InvalidArgumentException('Current password is incorrect or verification is temporarily locked. Try again later after repeated failures.');
    return learningWrite($db,function()use($db,$current,$ids,$bankKind,$section){
        // Check every selection before changing anything: foreign, shared, stale,
        // other-bank and other-section IDs cannot cause a partially applied batch.
        foreach($ids as $id){
            $record=learningMutableRecord($db,$current,'questions',$id,$bankKind);
            if($record['archived_at'])throw new InvalidArgumentException('A selected question is already archived. Refresh the list.');
            if($section!=='all'&&($record['type']==='MCQ')!==($section==='mcq'))throw new InvalidArgumentException('Select questions from this question section only.');
        }
        $update=$db->prepare('UPDATE questions SET archived_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NULL');$now=date('Y-m-d H:i:s');
        foreach($ids as $id){
            $update->execute([$now,$id]);if($update->rowCount()!==1)throw new InvalidArgumentException('A selected question changed. Refresh and try again.');
            learningAudit($db,$current,'Bulk archive question','questions',$id);
        }
        return count($ids);
    });
}
function learningSaveNote(PDO $db,array $actor,array $input,?int $id=null):int {
    return learningWrite($db,function()use($db,$actor,$input,$id){
        if(array_key_exists('school_id',$input)||array_key_exists('created_by_user_id',$input))throw new InvalidArgumentException('Content school and creator are set by your account.');
        if($id){$old=learningMutableRecord($db,$actor,'learning_notes',$id);if($old['archived_at'])throw new InvalidArgumentException('Restore archived content before editing it.');}
        $class=learningId($input['class_id']??null);$subject=learningId($input['subject_id']??null);
        if(!learningCanCreate($db,$actor,$class,$subject)||!learningPurposeAllowed($db,$actor,'study'))throw new InvalidArgumentException('Create notes only for your assigned class subjects.');
        $title=$input['title']??'';$raw=$input['content']??'';
        if(!is_string($title)||trim($title)===''||mb_strlen($title)>255||!is_string($raw))throw new InvalidArgumentException('Enter a note title and valid content.');
        $content=questionImportContent($raw,'html');if(!questionImportHasContent($content))throw new InvalidArgumentException('Note content is empty after checking its formatting.');
        if(isset($old)&&(int)$old['class_id']===$class&&(int)$old['subject_id']===$subject&&curriculumNameKey((string)($input['chapter']??''))===curriculumNameKey((string)$old['chapter'])&&curriculumNameKey((string)($input['topic']??''))===curriculumNameKey((string)($old['topic']??''))){
            // Old clients send labels only. A title/body edit is not an association transfer.
            $hasReference=false;foreach(['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'] as $key)if(!empty($input[$key]))$hasReference=true;
            if(!$hasReference)foreach(['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'] as $key)$input[$key]=$old[$key]??null;
        }
        $catalog=learningCatalogSelection($db,$actor,$class,$subject,$input,learningCanManageCatalog($actor));
        $c=$db->prepare('SELECT name FROM classes WHERE id=?');$c->execute([$class]);$s=$db->prepare('SELECT name_en FROM subjects WHERE id=?');$s->execute([$subject]);
        $catalog['topic']=$catalog['topic']??''; // Legacy label is NOT NULL; the relational topic remains optional.
        $values=$catalog+['class_id'=>$class,'subject_id'=>$subject,'class_level'=>$c->fetchColumn(),'subject_name'=>subjectCanonicalName($s->fetchColumn()),'title'=>trim($title),'content'=>$content,'visibility'=>learningVisibility($input['visibility']??($old['visibility']??($actor['school_id']===0?'public':'private'))),'updated_at'=>date('Y-m-d H:i:s')];
        if($id){$db->prepare('UPDATE learning_notes SET '.implode(',',array_map(static fn($k)=>"$k=?",array_keys($values))).' WHERE id=?')->execute([...array_values($values),$id]);}
        else{$values+=['school_id'=>$actor['school_id'],'created_by_user_id'=>$actor['id']];$id=insertRecord($db,'learning_notes',$values);}
        learningAudit($db,$actor,isset($old)?'Edit note':'Create note','learning_notes',$id);return $id;
    });
}
