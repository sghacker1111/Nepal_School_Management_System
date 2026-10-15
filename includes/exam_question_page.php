<?php
if(!isset($examSection)||!in_array($examSection,['written','mcq'],true)){http_response_code(404);exit;}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/learning_content_service.php';
require_once __DIR__.'/exam_question_import.php';
Auth::requireRole(['super_admin','school_admin','teacher','student']);Auth::requireAccess('exam_questions');
$db=getDB();$actor=learningActor($db,(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
$isStudent=$actor['role']==='student';$purpose=$isStudent?'exam_view':'exam_bank';
$route=$examSection==='mcq'?'exam_mcqs.php':'exam_questions.php';$sectionTitle=$examSection==='mcq'?'Multiple Choice Questions':'Exam Questions';$itemTitle=$examSection==='mcq'?'MCQ':'Exam Question';$importKind=$examSection==='mcq'?'mcq':'other';
$view=$_GET['view']??(isset($_GET['edit_question_id'])?'edit':'list');
if(!is_string($view)||!in_array($view,['list','add','edit','import'],true))denySchoolRequest('Choose a valid question page.',422);
try{$pagination=learningQuestionPagination($_GET['page']??1,$_GET['per_page']??10);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
$class=(int)($_POST['class_id']??$_GET['class_id']??0);$subject=(int)($_POST['subject_id']??$_GET['subject_id']??0);
$classes=learningReadClasses($db,$actor);if($isStudent&&!$class)$class=(int)($classes[0]['id']??0);
$subjects=learningReadSubjects($db,$actor,$class);$chapters=[];$topicRows=[];$error='';$edit=null;
$writerSubjects=examImportSubjects($db,$actor,$classes);$canCreate=!$isStudent&&!$actor['read_only']&&Auth::canAddQuestions()&&(bool)$writerSubjects;
if($subject){try{$chapters=learningCatalogChapters($db,$actor,$class,$subject);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}}
if(in_array($_GET['action']??'',['catalog','import_catalog'],true)){
    try{
        if(($_GET['action']??'')==='import_catalog'&&!$canCreate)denySchoolRequest('Your account cannot import exam questions.',403);
        $catalog=questionImportCatalog($db,$actor,$class,$subject);header('Content-Type: application/json');echo json_encode($catalog);exit;
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
}
if(($_GET['action']??'')==='download_template'){
    if(!$canCreate)denySchoolRequest('Your account cannot download exam question import samples.',403);
    try{
        $mode=questionImportFormat($_GET['content_mode']??'normal');$kind=examImportKind($_GET['import_kind']??null);$format=$_GET['format']??'xlsx';
        if($kind!==$importKind)throw new InvalidArgumentException('Use the matching import section: '.$sectionTitle.'.');
        if(!is_string($format)||!in_array($format,['xlsx','csv'],true))throw new InvalidArgumentException('Choose Excel (.xlsx) or CSV.');
        [$headers,$sampleRows]=examImportSample($db,$actor,$mode,$class,$subject,$kind,$_GET);
        $binary=$format==='xlsx'?XlsxHelper::generateXlsx($headers,$sampleRows,'Exam Questions',[8,9],true):XlsxHelper::generateCsv($headers,$sampleRows);
        header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=UTF-8'));
        header('Content-Disposition: attachment; filename="exam-questions-'.$kind.'-'.$mode.'-sample.'.$format.'"');
        header('Cache-Control: private, no-store');echo $binary;exit;
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    catch(Throwable $e){denySchoolRequest(schoolPublicError($e),500);}
}
$level=$_GET['difficulty']??'';$chapterFilter=$_GET['chapter']??'';$topicFilter=$_GET['filter_topic']??'';$search=$_GET['search']??'';
foreach([$level,$chapterFilter,$topicFilter,$search] as $v)if(!is_string($v)||mb_strlen($v)>200)denySchoolRequest('Choose valid question filters.',422);
$filters=['source'=>$_GET['source']??'all','archived'=>!$isStudent&&($_GET['archived']??'')==='1','search'=>$search,'question_group'=>$examSection];
$filters['record_id']=$_GET['record_id']??'';
$returnQuery=['class_id'=>$class,'subject_id'=>$subject,'chapter'=>$chapterFilter,'filter_topic'=>$topicFilter,'difficulty'=>$level,'search'=>$search,'source'=>$filters['source'],'archived'=>$filters['archived']?'1':'','per_page'=>$pagination['per_page'],'page'=>$pagination['page']];
if($filters['record_id']!=='')$returnQuery['record_id']=$filters['record_id'];
$url=static fn(array $changes=[])=>APP_URL.'/modules/learning/'.$route.'?'.http_build_query(array_replace($returnQuery,$changes));
$listUrl=$url();
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=$_POST['action']??'';if(!is_string($action)||!in_array($action,['add','edit','set_visibility','archive','delete','restore','import'],true))throw new InvalidArgumentException('Choose one exam question action.');
        if($action==='import'){
            $view='import';if(!$canCreate)denySchoolRequest('Your account cannot import exam questions.',403);
            $mode=questionImportFormat($_POST['import_content_mode']??'normal');$kind=examImportKind($_POST['import_kind']??null);
            if($kind!==$importKind)throw new InvalidArgumentException('Use the matching import section: '.$sectionTitle.'.');
            $visibility=learningVisibility($_POST['import_visibility']??($actor['school_id']===0?'public':'private'));
            $result=examImportQuestions($db,$actor,examImportReadUpload($_FILES['excel_file']??[]),$mode,$visibility,$kind,$_POST);
            $message='Successfully imported '.$result['saved'].' exam questions ('.($kind==='mcq'?'MCQs':'Other Questions').'). Imported questions are staff-only.';
            if($result['skipped'])$message.=' '.$result['skipped'].' row(s) skipped. '.implode(' ',$result['errors']).($result['skipped']>count($result['errors'])?' Only the first 10 errors are shown.':'');
            redirect($url(['class_id'=>(int)$_POST['import_class_id'],'subject_id'=>(int)$_POST['import_subject_id'],'page'=>1,'chapter'=>'','filter_topic'=>'']),$message,$result['skipped']?'warning':'success');
        }
        $id=$action==='add'?null:learningId($_POST['question_id']??null);
        if($id){
            $record=learningMutableRecord($db,$actor,'questions',$id,'exam');
            if(($record['type']==='MCQ')!==($examSection==='mcq'))throw new InvalidArgumentException('This question belongs to the other exam question section.');
        }
        if(in_array($action,['add','edit'],true)){
            $view=$action;
            $type=$_POST['type']??'';
            if(!is_string($type)||($examSection==='mcq'?$type!=='MCQ':!in_array($type,['Very Short','Short','Long','Reasoning','Difference Between'],true)))throw new InvalidArgumentException('Choose a question type for this section. MCQs and written questions are separate.');
            if($examSection==='written')foreach(['options','options_np','correct_option','option_a','option_b','option_c','option_d','option_a_np','option_b_np','option_c_np','option_d_np'] as $field)if(!empty($_POST[$field]))throw new InvalidArgumentException('Written questions cannot contain MCQ options. Use Multiple Choice Questions.');
            learningSaveQuestion($db,$actor,$_POST,$id,'exam');
        }elseif($action==='set_visibility')learningSetVisibility($db,$actor,'questions',$id,learningVisibility($_POST['visibility']??null),'exam');
        else learningSetArchived($db,$actor,'questions',$id,in_array($action,['archive','delete'],true),'exam');
        $message=$action==='delete'?$itemTitle.' deleted successfully. You can restore it using the Deleted / archived status filter.':($action==='restore'?$itemTitle.' restored successfully.':$itemTitle.' saved successfully.');
        redirect($listUrl,$message,'success');
    }catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
    catch(Throwable $e){http_response_code(500);$error=schoolPublicError($e);}
}
if($view!=='list'&&!$canCreate)denySchoolRequest('Your account can view questions but cannot open authoring pages.',403);
if($view==='edit'){
    $editId=$_POST['question_id']??$_GET['question_id']??$_GET['edit_question_id']??null;
    try{
        $edit=learningContentRecord($db,$actor,'questions',learningId($editId),'exam_bank',true);
        if(!$edit['can_edit']||$edit['archived_at'])throw new InvalidArgumentException('Restore your archived question before editing, or choose your own question.');
        if(($edit['type']==='MCQ')!==($examSection==='mcq')){
            if(isset($_GET['edit_question_id'])&&$_SERVER['REQUEST_METHOD']==='GET')redirect(APP_URL.'/modules/learning/'.($edit['type']==='MCQ'?'exam_mcqs.php':'exam_questions.php').'?view=edit&question_id='.$edit['id']);
            throw new InvalidArgumentException('This question belongs to the other exam question section.');
        }
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}
    $class=(int)$edit['class_id'];$subject=(int)$edit['subject_id'];$subjects=learningReadSubjects($db,$actor,$class);$chapters=learningCatalogChapters($db,$actor,$class,$subject);
}
$questions=[];
if($view==='list'){
    if($class)$filters['class_id']=$class;if($subject)$filters['subject_id']=$subject;if($level)$filters['difficulty']=$level;if($chapterFilter)$filters['chapter']=$chapterFilter;
    if($chapterFilter&&$subject){foreach($chapters as $c)if($c['name']===$chapterFilter)$topicRows=learningCatalogTopics($db,$actor,$class,$subject,(int)$c['id']);}
    if($topicFilter){$aliases=null;foreach($topicRows as $t)if((string)$t['id']===$topicFilter)$aliases=$t['alias_ids'];if(!$aliases)denySchoolRequest('Choose a topic in the selected chapter.',404);$filters['topic_alias_ids']=$aliases;}
    try{$questions=learningContentList($db,$actor,'questions',$filters,$purpose,$pagination);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    $returnQuery['page']=$pagination['page'];
}
$form=$error&&in_array($_POST['action']??'',['add','edit'],true)?$_POST:($edit??[]);$form=array_filter($form,static fn($v)=>is_scalar($v)||$v===null);
$formClass=(int)($form['class_id']??$class);$formSubject=(int)($form['subject_id']??$subject);
$form+=['class_id'=>$formClass,'subject_id'=>$formSubject,'visibility'=>$actor['school_id']===0?'public':'private','type'=>$examSection==='mcq'?'MCQ':'Short'];
$examImportKinds=[$importKind=>$examSection==='mcq'?'MCQs':'Other Questions'];
$pageTitle=($view==='list'?'':ucfirst($view).' ').$sectionTitle;$activePage=$examSection==='mcq'?'exam_mcqs':'exam_questions';ob_start();
?>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/exam-question-bank.css?v=<?= (int)filemtime(__DIR__.'/../assets/css/exam-question-bank.css') ?>">
<script>window.MathJax={tex:{inlineMath:[['\\(','\\)'],['$','$']],displayMath:[['\\[','\\]'],['$$','$$']],processEscapes:true}};</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h1 class="h2"><?= $sectionTitle ?></h1><p class="text-muted mb-0"><?= $isStudent?'Only questions explicitly released for your class and subjects.':'Exam bank: staff-only by default. Normal Question Bank and student practice remain separate.' ?></p></div>
<div class="d-flex flex-wrap align-items-start gap-2">
<?php if($view!=='list'): ?><a class="btn btn-outline-secondary" href="<?= sanitize($listUrl) ?>">Back to <?= $sectionTitle ?></a>
<?php elseif($canCreate): ?><a class="btn btn-primary" href="<?= sanitize($url(['view'=>'add'])) ?>">Add <?= $itemTitle ?></a><a class="btn btn-outline-primary" href="<?= sanitize($url(['view'=>'import'])) ?>">Import <?= $examSection==='mcq'?'MCQs':'Questions' ?></a><?php endif; ?>
<?php if(!$isStudent&&$view==='list'): ?><a class="btn btn-outline-secondary" href="<?= APP_URL ?>/modules/learning/exam_paper_generator.php">Exam Paper Generator</a><?php endif; ?></div></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<?php if(in_array($view,['add','edit'],true)): ?>
<?php include __DIR__.'/exam_question_editor_form.php'; ?>
<?php elseif($view==='import'): ?>
<?php include __DIR__.'/exam_question_import_form.php'; ?>
<script src="<?= APP_URL ?>/assets/js/exam-questions.js?v=<?= (int)filemtime(__DIR__.'/../assets/js/exam-questions.js') ?>"></script>
<?php else: ?>
<?php include __DIR__.'/exam_question_filters.php'; ?>
<?php if($filters['record_id']!==''): ?><p class="small">Viewing a linked record. <a href="<?= sanitize($url(['record_id'=>''])) ?>">Show all questions</a></p><?php endif; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><p class="text-muted mb-0" id="examQuestionCount"><?= $pagination['total'] ?> visible <?= $examSection==='mcq'?'MCQs':'exam questions' ?> · Showing <?= $pagination['total']?($pagination['page']-1)*$pagination['per_page']+1:0 ?>–<?= min($pagination['page']*$pagination['per_page'],$pagination['total']) ?></p><span class="small text-muted">Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?></span></div>
<?php if(!$questions): ?><div class="card card-body text-muted"><?= $isStudent?'No questions have been released for your class in this section yet.':'No eligible questions match these filters.' ?></div><?php endif; ?>
<section class="exam-question-list" aria-label="<?= $sectionTitle ?> list">
<?php foreach($questions as $q): ?><article class="card card-body mb-4 exam-question-card" data-question-id="<?= $q['id'] ?>"><div class="d-flex flex-wrap justify-content-between gap-2"><div class="exam-question-meta"><span class="badge bg-primary"><?= sanitize($q['type']) ?></span> <span class="badge bg-secondary"><?= sanitize($q['difficulty']) ?></span> <?= sanitize($q['chapter']) ?> · <?= sanitize($q['cognitive_domain']) ?> · <?= sanitize((string)$q['marks']) ?> marks · <?= sanitize((string)$q['required_minutes']) ?> min <span class="badge bg-light text-dark"><?= (int)$q['student_visible']?'Released to students':'Staff-only' ?></span></div>
<?php if($q['can_edit']): ?><div class="d-flex flex-wrap gap-2"><?php if(!$filters['archived']): ?><a class="btn btn-sm btn-outline-primary" href="<?= sanitize($url(['view'=>'edit','question_id'=>$q['id']])) ?>">Edit</a><form method="post" class="d-inline"><input type="hidden" name="action" value="set_visibility"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><input type="hidden" name="visibility" value="<?= $q['visibility']==='private'?'public':'private' ?>"><button class="btn btn-sm btn-outline-secondary"><?= $q['visibility']==='private'?'Share with staff':'Make private' ?></button></form><?php endif; ?><form method="post" class="d-inline" onsubmit="return confirm('<?= $filters['archived']?'Restore this question?':'Delete this question? It will be removed from lists and paper generation. You can restore it from Deleted / archived.' ?>')"><input type="hidden" name="action" value="<?= $filters['archived']?'restore':'delete' ?>"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><button class="btn btn-sm <?= $filters['archived']?'btn-outline-success':'btn-outline-danger' ?>"><?= $filters['archived']?'Restore':'Delete' ?></button></form></div><?php endif; ?></div>
<div class="exam-bank-rich exam-bank-question mt-3" lang="en"><?= safeRichHtml($q['question_text']) ?></div><?php if($q['question_text_np']): ?><div class="exam-bank-rich exam-bank-nepali exam-bank-question font-nepali" lang="ne"><?= safeRichHtml($q['question_text_np']) ?></div><?php endif; ?>
<?php if($q['type']==='MCQ'): $options=json_decode($q['options']??'{}',true)?:[];$np=json_decode($q['options_np']??'{}',true)?:[]; ?><div class="row g-3 mt-1"><?php foreach(['A','B','C','D'] as $o): ?><div class="col-lg-6"><div class="exam-bank-option border rounded p-3"><strong><?= $o ?>.</strong><div class="exam-bank-rich" lang="en"><?= safeRichHtml($options[$o]??'') ?></div><?php if(!empty($np[$o])): ?><div class="exam-bank-rich exam-bank-nepali font-nepali" lang="ne"><?= safeRichHtml($np[$o]) ?></div><?php endif; ?></div></div><?php endforeach; ?></div><?php endif; ?>
<details class="exam-bank-answer mt-3"><summary>Model answer<?= $q['type']==='MCQ'?' / correct option':'' ?></summary><?php if($q['type']==='MCQ'): ?><p class="fw-bold mt-2">Correct: <?= sanitize($q['correct_option']) ?></p><?php endif; ?><div class="exam-bank-rich" lang="en"><?= safeRichHtml($q['answer']??'') ?></div><?php if(!empty($q['answer_np'])): ?><div class="exam-bank-rich exam-bank-nepali font-nepali" lang="ne"><?= safeRichHtml($q['answer_np']) ?></div><?php endif; ?></details></article><?php endforeach; ?>
</section>
<?php if($pagination['pages']>1): ?><nav aria-label="Question pages"><ul class="pagination flex-wrap gap-1">
<?php if($pagination['page']>1): ?><li class="page-item"><a class="page-link" href="<?= sanitize($url(['page'=>1])) ?>">First</a></li><li class="page-item"><a class="page-link" rel="prev" href="<?= sanitize($url(['page'=>$pagination['page']-1])) ?>">Previous</a></li><?php endif; ?>
<?php for($p=max(1,$pagination['page']-2);$p<=min($pagination['pages'],$pagination['page']+2);$p++): ?><li class="page-item <?= $p===$pagination['page']?'active':'' ?>"><?php if($p===$pagination['page']): ?><span class="page-link" aria-current="page"><?= $p ?></span><?php else: ?><a class="page-link" aria-label="Page <?= $p ?>" href="<?= sanitize($url(['page'=>$p])) ?>"><?= $p ?></a><?php endif; ?></li><?php endfor; ?>
<?php if($pagination['page']<$pagination['pages']): ?><li class="page-item"><a class="page-link" rel="next" href="<?= sanitize($url(['page'=>$pagination['page']+1])) ?>">Next</a></li><li class="page-item"><a class="page-link" href="<?= sanitize($url(['page'=>$pagination['pages']])) ?>">Last</a></li><?php endif; ?></ul></nav><?php endif; ?>
<?php endif; ?>
<?php $content=ob_get_clean();include __DIR__.'/header.php'; ?>
