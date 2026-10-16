<?php
require_once __DIR__.'/learning_content_service.php';

class QuestionCorrectionConflict extends InvalidArgumentException {}

/** Fixed canonical content, sanitized even when historical input predates the editor. */
function questionCorrectionSnapshot(array $question):array {
    $out=[];
    foreach(['school_id','class_id','subject_id','topic_id','catalog_chapter_id','catalog_topic_id'] as $key)$out[$key]=isset($question[$key])?(int)$question[$key]:null;
    foreach(['bank_kind','type','chapter','difficulty','cognitive_domain','correct_option'] as $key)$out[$key]=(string)($question[$key]??'');
    foreach(['question_text','question_text_np','answer','answer_np'] as $key)$out[$key]=questionImportContent((string)($question[$key]??''),'html');
    foreach(['options','options_np'] as $key){
        $raw=$question[$key]??[];if(is_string($raw))$raw=json_decode($raw,true);$raw=is_array($raw)?$raw:[];$options=[];
        foreach(['A','B','C','D'] as $letter)$options[$letter]=questionImportContent(is_string($raw[$letter]??null)?$raw[$letter]:'','html');$out[$key]=$options;
    }
    foreach(['marks','required_minutes'] as $key)$out[$key]=isset($question[$key])&&$question[$key]!==''?learningDecimalString(learningDecimalUnits((string)$question[$key],100000000,$key,true)):null;
    return $out;
}
function questionCorrectionFingerprint(array $question):string {
    return hash('sha256',json_encode(questionCorrectionSnapshot($question),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}
/** Revalidate the current account and original school on every operation. */
function questionCorrectionActor(PDO $db,array $actor,bool $write=false):array {
    $current=learningActor($db,(int)$actor['id'],(int)$actor['school_id'],(bool)$actor['read_only']);
    if($current['role']!==$actor['role']||!in_array($current['role'],['super_admin','school_admin','teacher'],true)||($write&&$current['read_only']))throw new InvalidArgumentException('Correction requests are not available for this account.');
    if($current['role']==='super_admin'){
        if($current['school_id']!==0)throw new InvalidArgumentException('Return to system administration to review corrections.');
    }else{
        $q=$db->prepare('SELECT * FROM schools WHERE id=?');$q->execute([$current['school_id']]);$school=$q->fetch(PDO::FETCH_ASSOC);
        if(!$school||schoolSubscriptionState($school)!=='active')throw new InvalidArgumentException('An active school subscription is required.');
    }
    return $current;
}
function questionCorrectionCanSubmit(PDO $db,array $actor,array $question):bool {
    try{$actor=questionCorrectionActor($db,$actor,true);}catch(InvalidArgumentException $e){return false;}
    return in_array($actor['role'],['school_admin','teacher'],true)&&(int)($question['school_id']??-1)===0&&empty($question['archived_at'])&&learningCanView($db,$actor,$question,($question['bank_kind']??'learning')==='exam'?'exam_bank':'bank');
}
function questionCorrectionText(string $text,string $label):string {
    $text=trim($text);if($text===''||mb_strlen($text)>4000)throw new InvalidArgumentException($label.' must contain 1–4,000 characters.');return $text;
}
function questionCorrectionQuestion(PDO $db,int $id):?array {
    $q=$db->prepare('SELECT * FROM questions WHERE id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function questionCorrectionSubmit(PDO $db,array $actor,int $questionId,string $mistake,string $suggestion):int {
    $mistake=questionCorrectionText($mistake,'Mistake found');$suggestion=questionCorrectionText($suggestion,'Suggested correction');
    return learningWrite($db,function()use($db,$actor,$questionId,$mistake,$suggestion){
        $actor=questionCorrectionActor($db,$actor,true);$question=questionCorrectionQuestion($db,$questionId);
        if(!$question||!questionCorrectionCanSubmit($db,$actor,$question))throw new InvalidArgumentException('This shared question is not available for a correction request.');
        $key=hash('sha256',$actor['school_id'].':'.$actor['id'].':'.$questionId);
        $find=function()use($db,$key){$q=$db->prepare("SELECT id FROM question_correction_requests WHERE open_key=? AND status='pending'");$q->execute([$key]);return (int)$q->fetchColumn();};
        if($id=$find())return $id;
        try{
            $db->prepare('INSERT INTO question_correction_requests(question_id,bank_kind,question_type,requester_user_id,requester_school_id,mistake,suggested_correction,snapshot_json,snapshot_hash,open_key) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$questionId,$question['bank_kind'],$question['type'],$actor['id'],$actor['school_id'],$mistake,$suggestion,json_encode(questionCorrectionSnapshot($question),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),questionCorrectionFingerprint($question),$key]);
        }catch(PDOException $e){
            $duplicate=DB_TYPE==='sqlite'&&str_contains($e->getMessage(),'UNIQUE constraint failed: question_correction_requests.open_key')||DB_TYPE==='mysql'&&(int)($e->errorInfo[1]??0)===1062;
            if($duplicate&&($id=$find()))return $id;throw $e;
        }
        $id=(int)$db->lastInsertId();learningAudit($db,$actor,'Submit correction request','question_correction_requests',$id);return $id;
    });
}
function questionCorrectionRow(PDO $db,array $actor,int $id):array {
    $q=$db->prepare('SELECT r.*,u.full_name requester_name,s.name_en school_name FROM question_correction_requests r JOIN users u ON u.id=r.requester_user_id JOIN schools s ON s.id=r.requester_school_id WHERE r.id=?');$q->execute([$id]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!$r||($actor['role']!=='super_admin'&&((int)$r['requester_user_id']!==$actor['id']||(int)$r['requester_school_id']!==$actor['school_id'])))throw new InvalidArgumentException('Correction request not found.');return $r;
}
function questionCorrectionDetail(PDO $db,array $actor,int $id):array {
    $actor=questionCorrectionActor($db,$actor);$r=questionCorrectionRow($db,$actor,$id);$q=questionCorrectionQuestion($db,(int)$r['question_id']);
    $visible=$q&&(int)$q['school_id']===0&&($actor['role']==='super_admin'||questionCorrectionCanSubmit($db,$actor,$q));
    $r['snapshot']=$visible?json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR):null;
    $r['question']=$visible?$q:null;$r['current_hash']=$visible?questionCorrectionFingerprint($q):null;$r['content_changed']=$visible&&!hash_equals($r['snapshot_hash'],$r['current_hash']);
    // Do not accidentally leak the raw snapshot through list/detail consumers.
    unset($r['snapshot_json']);return $r;
}
function questionCorrectionList(PDO $db,array $actor,array $filters,array &$pagination):array {
    $actor=questionCorrectionActor($db,$actor);$pagination=learningQuestionPagination($pagination['page']??1,$pagination['per_page']??10);
    $where=[];$params=[];
    if($actor['role']!=='super_admin'){$where[]='r.requester_user_id=? AND r.requester_school_id=?';array_push($params,$actor['id'],$actor['school_id']);}
    foreach(['bank_kind'=>['learning','exam'],'status'=>['pending','corrected','rejected'],'question_type'=>['MCQ','Very Short','Short','Long','Reasoning','Difference Between']] as $key=>$values){
        if(($filters[$key]??'')==='')continue;if(!is_string($filters[$key])||!in_array($filters[$key],$values,true))throw new InvalidArgumentException('Choose a valid correction filter.');$where[]='r.'.$key.'=?';$params[]=$filters[$key];
    }
    if(($filters['question_group']??'')!==''){
        if(!in_array($filters['question_group'],['mcq','written'],true))throw new InvalidArgumentException('Choose MCQs or Written.');$where[]=$filters['question_group']==='mcq'?"r.question_type='MCQ'":"r.question_type<>'MCQ'";
    }
    foreach(['class_id'=>'q.class_id','subject_id'=>'q.subject_id','school_id'=>'r.requester_school_id'] as $key=>$column){if(($filters[$key]??'')==='')continue;$where[]=$column.'=?';$params[]=learningId($filters[$key]);}
    $from=' FROM question_correction_requests r JOIN questions q ON q.id=r.question_id JOIN users u ON u.id=r.requester_user_id JOIN schools s ON s.id=r.requester_school_id'.($where?' WHERE '.implode(' AND ',$where):'');
    $query=$db->prepare('SELECT count(*)'.$from);$query->execute($params);$pagination['total']=(int)$query->fetchColumn();$pagination['pages']=max(1,(int)ceil($pagination['total']/$pagination['per_page']));$pagination['page']=min($pagination['page'],$pagination['pages']);
    $query=$db->prepare('SELECT r.id,r.question_id,r.bank_kind,r.question_type,r.status,r.created_at,r.responded_at,r.response_read_at,u.full_name requester_name,s.name_en school_name'.$from.' ORDER BY r.id DESC LIMIT '.$pagination['per_page'].' OFFSET '.(($pagination['page']-1)*$pagination['per_page']));$query->execute($params);return $query->fetchAll(PDO::FETCH_ASSOC);
}
function questionCorrectionRespond(PDO $db,array $actor,int $id,int $revision,string $expectedQuestionHash,string $decision,string $reply):void {
    $reply=questionCorrectionText($reply,'Reply');if(!in_array($decision,['corrected','rejected'],true))throw new InvalidArgumentException('Choose Corrected or Rejected.');
    learningWrite($db,function()use($db,$actor,$id,$revision,$expectedQuestionHash,$decision,$reply){
        $actor=questionCorrectionActor($db,$actor,true);if($actor['role']!=='super_admin')throw new InvalidArgumentException('Only system administrators may resolve requests.');
        $r=questionCorrectionRow($db,$actor,$id);$q=questionCorrectionQuestion($db,(int)$r['question_id']);
        if($r['status']!=='pending'||(int)$r['revision']!==$revision||!$q||(int)$q['school_id']!==0||!hash_equals(questionCorrectionFingerprint($q),$expectedQuestionHash))throw new QuestionCorrectionConflict('The request or question changed. Reload it before responding.');
        if($decision==='corrected'&&(!empty($q['archived_at'])||hash_equals($r['snapshot_hash'],questionCorrectionFingerprint($q))))throw new InvalidArgumentException('Save an actual correction to the active shared question before marking it Corrected.');
        $update=$db->prepare("UPDATE question_correction_requests SET status=?,response_text=?,responded_by=?,responded_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP,revision=revision+1,open_key=NULL WHERE id=? AND revision=? AND status='pending'");$update->execute([$decision,$reply,$actor['id'],$id,$revision]);if($update->rowCount()!==1)throw new QuestionCorrectionConflict('This request changed. Reload before responding.');
        learningAudit($db,$actor,'Correction request '.$decision,'question_correction_requests',$id);
    });
}
function questionCorrectionMarkRead(PDO $db,array $actor,int $id):void {
    learningWrite($db,function()use($db,$actor,$id){$actor=questionCorrectionActor($db,$actor,true);$r=questionCorrectionRow($db,$actor,$id);
        if((int)$r['requester_user_id']!==$actor['id']||(int)$r['requester_school_id']!==$actor['school_id'])throw new InvalidArgumentException('Only the requester may mark a reply read.');
        if($r['status']==='pending'||$r['response_read_at']!==null)return;
        $db->prepare('UPDATE question_correction_requests SET response_read_at=CURRENT_TIMESTAMP WHERE id=? AND response_read_at IS NULL')->execute([$id]);learningAudit($db,$actor,'Read correction reply','question_correction_requests',$id);
    });
}
function questionCorrectionCounts(PDO $db,array $actor):array {
    $empty=['pending'=>0,'unread'=>0];try{$actor=questionCorrectionActor($db,$actor);}catch(InvalidArgumentException $e){return $empty;}
    if($actor['role']==='super_admin')return ['pending'=>(int)$db->query("SELECT count(*) FROM question_correction_requests WHERE status='pending'")->fetchColumn(),'unread'=>0];
    $q=$db->prepare("SELECT SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,SUM(CASE WHEN status<>'pending' AND response_read_at IS NULL THEN 1 ELSE 0 END) unread FROM question_correction_requests WHERE requester_school_id=? AND requester_user_id=?");$q->execute([$actor['school_id'],$actor['id']]);$r=$q->fetch(PDO::FETCH_ASSOC);return ['pending'=>(int)$r['pending'],'unread'=>(int)$r['unread']];
}
