<?php
require_once __DIR__.'/../config/config.php';require_once __DIR__.'/database.php';require_once __DIR__.'/auth.php';require_once __DIR__.'/functions.php';
require_once __DIR__.'/question_correction_service.php';require_once __DIR__.'/question_correction_views.php';
Auth::requireRole($correctionAdmin?['super_admin']:['school_admin','teacher']);
$db=getDB();$actor=learningActor($db,(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
try{questionCorrectionActor($db,$actor);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),403);}
$route=schoolRouteUrl('modules/'.($correctionAdmin?'super_admin':'learning').'/question_corrections.php');
$pageTitle=$correctionAdmin?'Correction Questions':'My correction requests';$activePage=$correctionAdmin?'super_admin_corrections':'question_corrections';
$error='';$detail=null;$question=null;$rows=[];
$view=$_GET['view']??'list';if(!is_string($view)||!in_array($view,$correctionAdmin?['list','detail']:['list','request','detail'],true))denySchoolRequest('Choose a valid correction page.',422);
$filterKeys=['bank_kind','question_group','question_type','status','class_id','subject_id','school_id'];$filters=[];
foreach($filterKeys as $key){$v=$_GET[$key]??($key==='status'&&$correctionAdmin?'pending':'');if(!is_string($v)||strlen($v)>100)denySchoolRequest('Choose valid correction filters.',422);$filters[$key]=$v;}
try{$pagination=learningQuestionPagination($_GET['page']??1,$_GET['per_page']??10);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
$query=$filters+['per_page'=>$pagination['per_page']];$url=static fn(array $changes=[])=>$route.'?'.http_build_query(array_replace($query,$changes));
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        learningSingleAction($_POST,$_GET);$action=$_POST['action']??'';
        if(!is_string($action)||!in_array($action,$correctionAdmin?['respond']:['submit','mark_read'],true))throw new InvalidArgumentException('Choose one correction action.');
        foreach(['school_id','requester_school_id','requester_user_id','user_id','created_by_user_id','bank_kind','question_type'] as $key)if(array_key_exists($key,$_POST))denySchoolRequest('Correction identity is determined by your account and the selected question.',403);
        $allowed=match($action){'submit'=>['question_id','mistake','suggestion'],'respond'=>['request_id','revision','expected_hash','decision','reply'],default=>['request_id']};
        if(array_diff(array_keys($_POST),[CSRF_TOKEN_NAME,'action',...$allowed]))throw new InvalidArgumentException('Submit only the fields for one correction action.');
        foreach($_POST as $v)if(!is_string($v))throw new InvalidArgumentException('Enter valid correction fields.');
        if($action==='submit'){
            $view='request';$id=questionCorrectionSubmit($db,$actor,learningId($_POST['question_id']??null),$_POST['mistake']??'',$_POST['suggestion']??'');
            redirect($url(['view'=>'detail','request_id'=>$id]),'Correction request saved successfully.','success');
        }
        $view='detail';$id=learningId($_POST['request_id']??null);
        if($action==='mark_read'){questionCorrectionMarkRead($db,$actor,$id);redirect($url(['view'=>'detail','request_id'=>$id]),'Reply marked read.','success');}
        $revision=learningId($_POST['revision']??null);$hash=$_POST['expected_hash']??'';if(!preg_match('/^[a-f0-9]{64}$/',$hash))throw new InvalidArgumentException('Reload the question before responding.');
        questionCorrectionRespond($db,$actor,$id,$revision,$hash,$_POST['decision']??'',$_POST['reply']??'');redirect($url(['view'=>'detail','request_id'=>$id]),'Correction response saved successfully.','success');
    }catch(QuestionCorrectionConflict $e){http_response_code(409);$error=$e->getMessage();}
    catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
    catch(Throwable $e){http_response_code(500);$error=schoolPublicError($e);}
}
try{
    if($view==='request'){
        $question=questionCorrectionQuestion($db,learningId($_POST['question_id']??$_GET['question_id']??null));
        if(!$question||!questionCorrectionCanSubmit($db,$actor,$question))denySchoolRequest('This shared question is not available for correction.',404);
    }elseif($view==='detail')$detail=questionCorrectionDetail($db,$actor,learningId($_POST['request_id']??$_GET['request_id']??null));
    else $rows=questionCorrectionList($db,$actor,$filters,$pagination);
}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),$view==='list'?422:404);}
$counts=questionCorrectionCounts($db,$actor);$formValues=array_filter($_POST,'is_string');ob_start();
?>
<?php $pageStyles[]="/assets/css/exam-question-bank.css"; ?>
<?php $pageStyles[]="/assets/css/exam-question-language.css"; ?>
<?php $pageAssets[]="mathjax"; ?><?php $pageAssets[]="mathjax"; ?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h1 class="h2"><?= sanitize($pageTitle) ?></h1><p class="text-muted">Shared questions stay read-only. Reports are reviewed by the system administrator.</p></div><a class="btn btn-outline-secondary align-self-start" href="<?= sanitize($url()) ?>">All requests · <?= $counts['pending'] ?> pending<?= !$correctionAdmin?' · '.$counts['unread'].' unread replies':'' ?></a></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<?php if($view==='request'): ?>
<?php questionCorrectionRichCard($db,$question,'Selected shared question'); ?>
<form method="post" class="card card-body gap-3"><input type="hidden" name="action" value="submit"><input type="hidden" name="question_id" value="<?= (int)$question['id'] ?>"><div><label class="form-label" for="mistake">Mistake found</label><textarea class="form-control" name="mistake" id="mistake" maxlength="4000" rows="4" required><?= sanitize($formValues['mistake']??'') ?></textarea></div><div><label class="form-label" for="suggestion">Suggested correction</label><textarea class="form-control" name="suggestion" id="suggestion" maxlength="4000" rows="4" required><?= sanitize($formValues['suggestion']??'') ?></textarea></div><p class="small text-muted">1–4,000 characters each. Your report does not change the question.</p><button class="btn btn-primary align-self-start">Send correction request</button></form>
<?php elseif($view==='detail'): ?>
<section class="card card-body mb-3"><h2 class="h4">Request #<?= (int)$detail['id'] ?> <span class="badge bg-secondary"><?= sanitize(ucfirst($detail['status'])) ?></span></h2><p><?= sanitize($detail['school_name'].' · '.$detail['requester_name'].' · '.$detail['question_type']) ?></p><h3 class="h6">Mistake found</h3><p style="white-space:pre-wrap"><?= sanitize($detail['mistake']) ?></p><h3 class="h6">Suggested correction</h3><p style="white-space:pre-wrap"><?= sanitize($detail['suggested_correction']) ?></p>
<?php if($detail['response_text']!==null): ?><h3 class="h6">Administrator reply</h3><p style="white-space:pre-wrap"><?= sanitize($detail['response_text']) ?></p><p class="small text-muted"><?= sanitize($detail['responded_at']) ?></p><?php if(!$correctionAdmin&&!$detail['response_read_at']): ?><form method="post"><input type="hidden" name="action" value="mark_read"><input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>"><button class="btn btn-outline-primary">Mark reply read</button></form><?php endif; ?><?php endif; ?></section>
<?php if($detail['question']): ?>
<p class="alert <?= $detail['content_changed']?'alert-info':'alert-light' ?>"><?= $detail['content_changed']?'The question content has changed since this report.':'The question content is unchanged since this report.' ?></p>
<div class="row g-3"><div class="col-xl-6"><?php questionCorrectionRichCard($db,$detail['snapshot'],'Original submission'); ?></div><div class="col-xl-6"><?php questionCorrectionRichCard($db,$detail['question'],'Current shared question'); ?></div></div>
<?php if($correctionAdmin&&empty($detail['question']['archived_at'])): ?><p><a class="btn btn-outline-primary" href="<?= sanitize(questionCorrectionContentUrl($detail['question'],true)) ?>" target="_blank" rel="noopener">Open shared question editor</a> <span class="small text-muted">Save a correction there, then reload this request before resolving it.</span></p><?php endif; ?>
<?php elseif(!$correctionAdmin): ?><p class="alert alert-warning">Original question is no longer available to your account. Your own report and reply remain available.</p><?php endif; ?>
<?php if($correctionAdmin&&$detail['status']==='pending'&&$detail['question']): ?><form method="post" class="card card-body gap-3"><input type="hidden" name="action" value="respond"><input type="hidden" name="request_id" value="<?= (int)$detail['id'] ?>"><input type="hidden" name="revision" value="<?= (int)$detail['revision'] ?>"><input type="hidden" name="expected_hash" value="<?= sanitize($detail['current_hash']) ?>"><div><label class="form-label" for="decision">Decision</label><select class="form-select" id="decision" name="decision" required><option value="">Choose decision</option><option value="corrected">Corrected — actual shared content changed</option><option value="rejected">Rejected — already correct / other reason</option></select></div><div><label class="form-label" for="reply">Reply to requester</label><textarea class="form-control" id="reply" name="reply" rows="4" maxlength="4000" required><?= sanitize($formValues['reply']??'') ?></textarea></div><button class="btn btn-primary align-self-start">Save response</button></form><?php endif; ?>
<?php else: ?>
<?php questionCorrectionFilters($db,$correctionAdmin,$filters,$pagination,$route,$url); ?>
<p><?= $pagination['total'] ?> requests · Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?></p>
<div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>Request</th><th>Bank / type</th><?php if($correctionAdmin): ?><th>School / requester</th><?php endif; ?><th>Status</th><th>Submitted</th><th>Review</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td>#<?= (int)$r['id'] ?></td><td><?= $r['bank_kind']==='exam'?'Exam Questions':'Question Bank' ?><br><?= sanitize($r['question_type']) ?></td><?php if($correctionAdmin): ?><td><?= sanitize($r['school_name']) ?><br><?= sanitize($r['requester_name']) ?></td><?php endif; ?><td><?= sanitize(ucfirst($r['status'])) ?><?= !$correctionAdmin&&$r['status']!=='pending'&&!$r['response_read_at']?' · Unread reply':'' ?></td><td><?= sanitize($r['created_at']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= sanitize($url(['view'=>'detail','request_id'=>$r['id']])) ?>">View request #<?= (int)$r['id'] ?></a></td></tr><?php endforeach; ?></tbody></table><?php if(!$rows): ?><p class="text-muted">No correction requests match these filters.</p><?php endif; ?></div>
<?php if($pagination['pages']>1): ?><nav class="d-flex flex-wrap gap-2" aria-label="Correction pages"><?php foreach(array_unique([1,max(1,$pagination['page']-1),$pagination['page'],min($pagination['pages'],$pagination['page']+1),$pagination['pages']]) as $p): ?><a class="btn btn-sm <?= $p===$pagination['page']?'btn-primary':'btn-outline-primary' ?>" href="<?= sanitize($url(['page'=>$p])) ?>" <?= $p===$pagination['page']?'aria-current="page"':'' ?>><?= $p ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php endif; ?>
<?php $content=ob_get_clean();include __DIR__.'/header.php'; ?>
