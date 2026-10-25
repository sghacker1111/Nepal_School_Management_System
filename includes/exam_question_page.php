<?php
$bankKind=$questionBankKind??'exam';$isExamBank=$bankKind==='exam';$needsTopics=!$isExamBank;
if(!in_array($bankKind,['exam','learning'],true)||!isset($examSection)||!in_array($examSection,$isExamBank?['written','mcq']:['all','written','mcq'],true)){http_response_code(404);exit;}
require_once __DIR__.'/../config/bootstrap.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/learning_content_service.php';
require_once __DIR__.'/exam_question_import.php';
require_once __DIR__.'/exam_question_language.php';
require_once __DIR__.'/frontend.php';
Auth::requireRole($isExamBank?['super_admin','school_admin','teacher','student']:['super_admin','school_admin','principal','teacher','exam_officer']);Auth::requireAccess($isExamBank?'exam_questions':'questions');
$db=getDB();$actor=learningActor($db,(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
$isStudent=$actor['role']==='student';$purpose=$isExamBank?($isStudent?'exam_view':'exam_bank'):'bank';
$route=$isExamBank?($examSection==='mcq'?'exam_mcqs.php':'exam_questions.php'):'questions.php';$sectionTitle=$isExamBank?($examSection==='mcq'?'Multiple Choice Questions':'Exam Questions'):'Question Bank';$itemTitle=$examSection==='mcq'?'MCQ':($isExamBank?'Exam Question':'Question');$importKind=$examSection==='mcq'?'mcq':'other';
$view=$_GET['view']??(isset($_GET['edit_question_id'])?'edit':'list');
if(!is_string($view)||!in_array($view,['list','add','edit','import'],true))denySchoolRequest('Choose a valid question page.',422);
try{$pagination=learningQuestionPagination($_GET['page']??1,$_GET['per_page']??10);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
try{$questionLanguage=examQuestionLanguage($_GET['language']??'both');}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
$class=(int)($_POST['class_id']??$_GET['class_id']??0);$subject=(int)($_POST['subject_id']??$_GET['subject_id']??0);
$classes=$isExamBank?examReadClasses($db,$actor):learningReadClasses($db,$actor);if($isStudent&&!$class)$class=(int)($classes[0]['id']??0);
$subjects=$isExamBank?examReadSubjects($db,$actor,$class):learningReadSubjects($db,$actor,$class);$chapters=[];$topicRows=[];$error='';$edit=null;$catalogRepairNotice='';
if($isExamBank&&$actor['role']==='teacher'){
    foreach([[$class,$subject],[(int)($_POST['import_class_id']??$_GET['import_class_id']??0),(int)($_POST['import_subject_id']??$_GET['import_subject_id']??0)]] as [$scopeClass,$scopeSubject]){
        if(($scopeClass&&!in_array($scopeClass,array_map('intval',array_column($classes,'id')),true))||($scopeClass&&$scopeSubject&&!examContentScopeAllowed($db,$actor,['class_id'=>$scopeClass,'subject_id'=>$scopeSubject])))denySchoolRequest('You may access exam questions only for your assigned class subjects.',403);
    }
}
$writerSubjects=examImportSubjects($db,$actor,$classes);$canCreate=in_array($actor['role'],['super_admin','school_admin','teacher'],true)&&!$actor['read_only']&&Auth::canAddQuestions()&&(bool)$writerSubjects;
if($subject){try{$chapters=learningCatalogChapters($db,$actor,$class,$subject);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}}
if(in_array($_GET['action']??'',['catalog','import_catalog'],true)){
    try{
        if(($_GET['action']??'')==='import_catalog'&&!$canCreate)denySchoolRequest('Your account cannot import questions.',403);
        $catalog=questionImportCatalog($db,$actor,$class,$subject);header('Content-Type: application/json');echo json_encode($catalog);exit;
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
}
if(($_GET['action']??'')==='download_template'){
    if(!$canCreate)denySchoolRequest('Your account cannot download question import samples.',403);
    try{
        $mode=questionImportFormat($_GET['content_mode']??'normal');$kind=examImportKind($_GET['import_kind']??$_GET['type']??null);$format=$_GET['format']??'xlsx';
        if($examSection!=='all'&&$kind!==$importKind)throw new InvalidArgumentException('Use the matching import section: '.$sectionTitle.'.');
        if(!is_string($format)||!in_array($format,['xlsx','csv'],true))throw new InvalidArgumentException('Choose Excel (.xlsx) or CSV.');
        [$headers,$sampleRows]=examImportSample($db,$actor,$mode,$class,$subject,$kind,$_GET,$bankKind);
        $binary=$format==='xlsx'?XlsxHelper::generateXlsx($headers,$sampleRows,$sectionTitle,[array_search('Marks',$headers,true),array_search('Required Minutes',$headers,true)],true):XlsxHelper::generateCsv($headers,$sampleRows);
        header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=UTF-8'));
        header('Content-Disposition: attachment; filename="'.($isExamBank?'exam-questions':'question-bank').'-'.$kind.'-'.$mode.'-sample.'.$format.'"');
        header('Cache-Control: private, no-store');echo $binary;exit;
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    catch(Throwable $e){denySchoolRequest(schoolPublicError($e),500);}
}
$level=$_GET['difficulty']??'';$chapterFilter=$_GET['chapter']??'';$topicFilter=$_GET['filter_topic']??'';$search=$_GET['search']??'';
foreach([$level,$chapterFilter,$topicFilter,$search] as $v)if(!is_string($v)||mb_strlen($v)>200)denySchoolRequest('Choose valid question filters.',422);
$filters=['source'=>$_GET['source']??'all','archived'=>!$isStudent&&($_GET['archived']??'')==='1','search'=>$search];
if($examSection!=='all')$filters['question_group']=$examSection;
if(!$isExamBank&&!empty($_GET['cognitive_domain']))$filters['cognitive_domain']=$_GET['cognitive_domain'];
$filters['record_id']=$_GET['record_id']??'';
$returnQuery=['class_id'=>$class,'subject_id'=>$subject,'chapter'=>$chapterFilter,'filter_topic'=>$topicFilter,'difficulty'=>$level,'search'=>$search,'source'=>$filters['source'],'archived'=>$filters['archived']?'1':'','per_page'=>$pagination['per_page'],'page'=>$pagination['page']];
$returnQuery['language']=$questionLanguage;
if(!$isExamBank)$returnQuery+=['category'=>$examSection==='mcq'?'mcq':($examSection==='written'?'other':'all'),'bank_ui'=>'1','cognitive_domain'=>$_GET['cognitive_domain']??''];
if($filters['record_id']!=='')$returnQuery['record_id']=$filters['record_id'];
$url=static fn(array $changes=[])=>schoolRouteUrl('modules/learning/'.$route,array_replace($returnQuery,$changes));
$listUrl=$url();
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=$_POST['action']??'';if(!is_string($action)||!in_array($action,['add','edit','set_visibility','archive','delete','restore','import','bulk_delete'],true))throw new InvalidArgumentException('Choose one question action.');
        if($action==='bulk_delete'){
            $count=learningBulkArchiveQuestions($db,$actor,$_POST['question_ids']??null,$_POST['current_password']??null,$bankKind,$examSection);
            redirect($listUrl,$count.' question(s) archived. Restore them using the Deleted / archived status filter.','success');
        }
        if($action==='import'){
            $view='import';if(!$canCreate)denySchoolRequest('Your account cannot import questions.',403);
            $mode=questionImportFormat($_POST['import_content_mode']??'normal');$kind=examImportKind($_POST['import_kind']??null);
            if($examSection!=='all'&&$kind!==$importKind)throw new InvalidArgumentException('Use the matching import section: '.$sectionTitle.'.');
            $visibility=learningVisibility($_POST['import_visibility']??($actor['school_id']===0?'public':'private'));
            $result=examImportQuestions($db,$actor,examImportReadUpload($_FILES['excel_file']??[]),$mode,$visibility,$kind,$_POST,$bankKind);
            $message='Successfully imported '.$result['saved'].' '.($isExamBank?'exam questions':'questions').' ('.($kind==='mcq'?'MCQs':'Other Questions').').'.($isExamBank?' Imported questions are staff-only.':'');
            if($result['skipped'])$message.=' '.$result['skipped'].' row(s) skipped. '.implode(' ',$result['errors']).($result['skipped']>count($result['errors'])?' Only the first 10 errors are shown.':'');
            redirect($url(['class_id'=>(int)$_POST['import_class_id'],'subject_id'=>(int)$_POST['import_subject_id'],'page'=>1,'chapter'=>'','filter_topic'=>'']),$message,$result['skipped']?'warning':'success');
        }
        $id=$action==='add'?null:learningId($_POST['question_id']??null);
        if($id){
            $record=learningMutableRecord($db,$actor,'questions',$id,$bankKind);
            if($examSection!=='all'&&($record['type']==='MCQ')!==($examSection==='mcq'))throw new InvalidArgumentException('This question belongs to the other question section.');
        }
        if(in_array($action,['add','edit'],true)){
            $view=$action;
            if($needsTopics&&$action==='add'&&empty($_POST['topic_id'])&&empty($_POST['catalog_topic_id']))throw new InvalidArgumentException('Select an existing Topic for a new Question Bank item. Exam questions use chapters only.');
            $type=$_POST['type']??'';
            if(!is_string($type)||($examSection==='mcq'?$type!=='MCQ':!in_array($type,$examSection==='all'?['MCQ','Very Short','Short','Long','Reasoning','Difference Between']:['Very Short','Short','Long','Reasoning','Difference Between'],true)))throw new InvalidArgumentException('Choose a question type for this section. MCQs and written questions are separate.');
            if($type!=='MCQ')foreach(['options','options_np','correct_option','option_a','option_b','option_c','option_d','option_a_np','option_b_np','option_c_np','option_d_np'] as $field)if(!empty($_POST[$field]))throw new InvalidArgumentException('Written questions cannot contain MCQ options. Use Multiple Choice Questions.');
            learningSaveQuestion($db,$actor,$_POST,$id,$bankKind);
        }elseif($action==='set_visibility')learningSetVisibility($db,$actor,'questions',$id,learningVisibility($_POST['visibility']??null),$bankKind);
        else learningSetArchived($db,$actor,'questions',$id,in_array($action,['archive','delete'],true),$bankKind);
        $message=$action==='delete'?$itemTitle.' deleted successfully. You can restore it using the Deleted / archived status filter.':($action==='restore'?$itemTitle.' restored successfully.':$itemTitle.' saved successfully.');
        redirect($listUrl,$message,'success');
    }catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
    catch(Throwable $e){http_response_code(500);$error=schoolPublicError($e);}
}
if($view!=='list'&&!$canCreate)denySchoolRequest('Your account can view questions but cannot open authoring pages.',403);
if($view==='edit'){
    $editId=$_POST['question_id']??$_GET['question_id']??$_GET['edit_question_id']??null;
    try{
        $edit=learningContentRecord($db,$actor,'questions',learningId($editId),$isExamBank?'exam_bank':'bank',true);
        if(!$edit['can_edit']||$edit['archived_at'])throw new InvalidArgumentException('Restore your archived question before editing, or choose your own question.');
        if($examSection!=='all'&&($edit['type']==='MCQ')!==($examSection==='mcq')){
            if(isset($_GET['edit_question_id'])&&$_SERVER['REQUEST_METHOD']==='GET')redirect(schoolRouteUrl('modules/learning/'.($edit['type']==='MCQ'?'exam_mcqs.php':'exam_questions.php'),['view'=>'edit','question_id'=>$edit['id']]));
            throw new InvalidArgumentException('This question belongs to the other question section.');
        }
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}
    $class=(int)$edit['class_id'];$subject=(int)$edit['subject_id'];
    try{$subjects=$isExamBank?examReadSubjects($db,$actor,$class):learningReadSubjects($db,$actor,$class);$chapters=learningCatalogChapters($db,$actor,$class,$subject);}
    catch(InvalidArgumentException $e){
        // Shared records can outlive an inactive or removed class-subject mapping.
        // Super-admins may repair the scope in the editor; saves still pass the
        // normal active class/subject/chapter validation in learningSaveQuestion().
        if($actor['role']!=='super_admin'||$actor['school_id']!==0)denySchoolRequest($e->getMessage(),422);
        $catalogRepairNotice='This question’s saved class or subject is no longer active in the curriculum. Choose a valid class, subject, chapter'.($needsTopics?' and topic':'').' before saving. The question text and answers are preserved.';
        $class=0;$subject=0;$subjects=[];$chapters=[];
    }
}
$questions=[];
if($view==='list'){
    if($class)$filters['class_id']=$class;if($subject)$filters['subject_id']=$subject;if($level)$filters['difficulty']=$level;if($chapterFilter)$filters['chapter']=$chapterFilter;
    if($needsTopics&&$chapterFilter&&$subject){foreach($chapters as $c)if($c['name']===$chapterFilter)$topicRows=learningCatalogTopics($db,$actor,$class,$subject,(int)$c['id']);}
    if($needsTopics&&$topicFilter){$aliases=null;foreach($topicRows as $t)if((string)$t['id']===$topicFilter)$aliases=$t['alias_ids'];if(!$aliases)denySchoolRequest('Choose a topic in the selected chapter.',404);$filters['topic_alias_ids']=$aliases;}
    try{$questions=learningContentList($db,$actor,'questions',$filters,$purpose,$pagination);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    $returnQuery['page']=$pagination['page'];
}
$form=$error&&in_array($_POST['action']??'',['add','edit'],true)?$_POST:($edit??[]);
if($catalogRepairNotice!==''){$form['class_id']=0;$form['subject_id']=0;$form['chapter']='';$form['topic_id']='';$form['catalog_topic_id']='';}
$form=array_filter($form,static fn($v)=>is_scalar($v)||$v===null);
$formClass=(int)($form['class_id']??$class);$formSubject=(int)($form['subject_id']??$subject);
$form+=['class_id'=>$formClass,'subject_id'=>$formSubject,'visibility'=>$actor['school_id']===0?'public':'private','type'=>$examSection==='mcq'?'MCQ':'Short'];
$examImportKinds=$examSection==='all'?['mcq'=>'MCQs','other'=>'Other Questions']:[$importKind=>$examSection==='mcq'?'MCQs':'Other Questions'];
$pageTitle=($view==='list'?'':ucfirst($view).' ').$sectionTitle;$activePage=$isExamBank?($examSection==='mcq'?'exam_mcqs':'exam_questions'):'questions';ob_start();
?>
<?php $pageStyles[]="/assets/css/exam-question-bank.css"; ?>
<?php $pageStyles[]='/assets/css/exam-question-language.css?v='.(int)filemtime(__DIR__.'/../assets/css/exam-question-language.css'); ?>
<?php $pageAssets[]="mathjax"; ?>
<?php $pageAssets[]="mathjax"; ?>
<?php if($view==='list')ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3 exam-bank-toolbar"><div><h1 class="h2"><?= $sectionTitle ?></h1><p class="text-muted mb-0"><?= $isExamBank?($isStudent?'Only questions explicitly released for your class and subjects.':'Chapter-wide exam bank: staff-only by default. Normal Question Bank and student practice remain separate.'):'Topic-wise questions and MCQs for learning, practice and unit-test papers. School content keeps its existing private/public access rules.' ?></p></div>
<div class="d-flex flex-wrap align-items-start gap-2">
<?php if($view!=='list'): ?><a class="btn btn-outline-secondary" href="<?= sanitize($listUrl) ?>">Back to <?= $sectionTitle ?></a>
<?php elseif($canCreate): ?><a class="btn btn-primary" href="<?= sanitize($url(['view'=>'add'])) ?>">Add <?= $itemTitle ?></a><a class="btn btn-outline-primary" href="<?= sanitize($url(['view'=>'import'])) ?>">Import <?= $examSection==='mcq'?'MCQs':'Questions' ?></a><?php endif; ?>
<?php if(!$isStudent&&$view==='list'): ?><a class="btn btn-outline-secondary" href="<?= schoolRouteUrl('modules/learning/'.($isExamBank?'exam_paper_generator.php':'paper_generator.php')) ?>"><?= $isExamBank?'Exam Paper Generator':'Test Paper Generator' ?></a><?php endif; ?></div></div>
<?php if(!$isExamBank): ?><nav class="nav nav-pills gap-2 mb-3 no-print" aria-label="Question bank categories"><?php foreach(['all'=>'All questions','mcq'=>'Multiple Choice Questions','other'=>'Written questions'] as $categoryKey=>$categoryTitle): ?><a class="nav-link <?= ($returnQuery['category']??'all')===$categoryKey?'active':'' ?>" href="<?= sanitize($url(['category'=>$categoryKey,'view'=>'list','page'=>1])) ?>"><?= $categoryTitle ?></a><?php endforeach; ?></nav><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<?php if(in_array($view,['add','edit'],true)): ?>
<?php if($catalogRepairNotice!==''): ?><div class="alert alert-warning" role="alert"><strong>Curriculum mapping needs repair.</strong> <?= sanitize($catalogRepairNotice) ?></div><?php endif; ?>
<?php include __DIR__.'/exam_question_editor_form.php'; ?>
<?php elseif($view==='import'): ?>
<?php include __DIR__.'/exam_question_import_form.php'; ?>
<?php schoolPageModule("external-exam-questions"); ?>
<?php else: ?>
<?php include __DIR__.'/exam_question_filters.php'; ?>
<?php if($filters['record_id']!==''): ?><p class="small">Viewing a linked record. <a href="<?= sanitize($url(['record_id'=>''])) ?>">Show all questions</a></p><?php endif; ?>
<div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><p class="text-muted mb-0" id="examQuestionCount"><?= $pagination['total'] ?> visible <?= $examSection==='mcq'?'MCQs':($isExamBank?'exam questions':'questions') ?> · Showing <?= $pagination['total']?($pagination['page']-1)*$pagination['per_page']+1:0 ?>–<?= min($pagination['page']*$pagination['per_page'],$pagination['total']) ?></p><span class="small text-muted">Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?></span></div>
<?php if($questions): ?><div class="no-print d-flex flex-wrap align-items-center gap-3 mb-3"><button type="button" class="btn btn-outline-primary" data-exam-print="bank">Print this page</button><span class="small text-muted">Print language: <?= sanitize(examQuestionLanguages()[$questionLanguage]) ?>. Use the Language filter above to change it. Model answers are not printed.</span></div><?php endif; ?>
<?php $canBulkDelete=!$filters['archived']&&(bool)array_filter($questions,static fn($row)=>$row['can_edit']);if($canBulkDelete): ?>
<form method="post" id="questionBulkArchive" class="no-print border rounded p-3 mb-3" data-question-bulk-form>
<input type="hidden" name="bank_ui" value="1"><input type="hidden" name="action" value="bulk_delete">
<div class="d-flex flex-wrap gap-3 align-items-center"><label><input type="checkbox" data-question-select-all> Select all editable questions on this page</label><span data-question-selection-count role="status">0 selected</span><button type="button" class="btn btn-outline-danger" data-question-bulk-open disabled>Delete selected</button></div>
<div data-question-bulk-confirm hidden class="mt-3"><p class="alert alert-warning">Selected questions will be removed from lists and paper generation and saved in Deleted / archived for restoration. Enter your current account password to confirm this bulk deletion.</p><label for="questionBulkPassword" class="form-label">Current password</label><input id="questionBulkPassword" type="password" name="current_password" autocomplete="current-password" maxlength="1024" class="form-control w-auto mb-2" required disabled><button type="submit" class="btn btn-danger">Confirm and archive selected questions</button> <button type="button" class="btn btn-outline-secondary" data-question-bulk-cancel>Cancel</button></div>
</form>
<?php endif; ?>
<header class="exam-bank-print-heading"><h2><?= $sectionTitle ?></h2><p><?= sanitize(examQuestionLanguages()[$questionLanguage]) ?> · Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?></p></header>
<?php if(!$questions): ?><div class="card card-body text-muted"><?= $isStudent?'No questions have been released for your class in this section yet.':'No eligible questions match these filters.' ?></div><?php endif; ?>
<section class="exam-question-list" aria-label="<?= $sectionTitle ?> list">
<?php foreach($questions as $q): ?><article class="card card-body mb-4 exam-question-card" data-question-id="<?= $q['id'] ?>"><div class="d-flex flex-wrap justify-content-between gap-2"><div class="exam-question-meta"><?php if($canBulkDelete&&$q['can_edit']): ?><label class="no-print me-2"><input type="checkbox" name="question_ids[]" value="<?= (int)$q['id'] ?>" form="questionBulkArchive" data-question-select aria-label="Select question <?= (int)$q['id'] ?>"></label><?php endif; ?><span class="badge bg-primary"><?= sanitize($q['type']) ?></span> <span class="badge bg-secondary"><?= sanitize($q['difficulty']) ?></span> #<?= (int)$q['id'] ?> · <?= sanitize($q['chapter']) ?> · <?= sanitize(questionCognitiveSummary($q)) ?> · <?= sanitize((string)$q['marks']) ?> marks<?= $q['required_minutes']!==null?' · '.sanitize((string)$q['required_minutes']).' min':'' ?> <span class="badge bg-light text-dark"><?= $isExamBank?((int)$q['student_visible']?'Released to students':'Staff-only'):sanitize(ucfirst($q['visibility'])) ?></span></div>
<?php include __DIR__.'/question_bank_actions.php'; ?></div>
<div class="exam-bank-rich exam-bank-question mt-3"><?= examQuestionRich($q['question_text'],$q['question_text_np']??'',$questionLanguage,true) ?></div>
<?php if($q['type']==='MCQ'): $options=json_decode($q['options']??'{}',true)?:[];$np=json_decode($q['options_np']??'{}',true)?:[]; ?><div class="row g-3 mt-1"><?php foreach(['A','B','C','D'] as $o): ?><div class="col-lg-6"><div class="exam-bank-option border rounded p-3"><strong><?= $o ?>.</strong><div class="exam-bank-rich"><?= examQuestionRich($options[$o]??'',$np[$o]??'',$questionLanguage) ?></div></div></div><?php endforeach; ?></div><?php endif; ?>
<details class="exam-bank-answer mt-3"><summary>Model answer<?= $q['type']==='MCQ'?' / correct option':'' ?></summary><?php if($q['type']==='MCQ'): ?><p class="fw-bold mt-2">Correct: <?= sanitize($q['correct_option']) ?></p><?php endif; ?><div class="exam-bank-rich"><?= examQuestionRich($q['answer']??'',$q['answer_np']??'',$questionLanguage) ?></div><?= examQuestionAnswerMarks($q) ?></details></article><?php endforeach; ?>
</section>
<?php if($pagination['pages']>1): ?><nav aria-label="Question pages" hx-boost="true" hx-target="#questionBankPanel" hx-swap="outerHTML" hx-indicator="#erpProgress" hx-sync="#questionBankPanel:replace"><ul class="pagination flex-wrap gap-1">
<?php if($pagination['page']>1): ?><li class="page-item"><a class="page-link" href="<?= sanitize($url(['page'=>1])) ?>">First</a></li><li class="page-item"><a class="page-link" rel="prev" href="<?= sanitize($url(['page'=>$pagination['page']-1])) ?>">Previous</a></li><?php endif; ?>
<?php for($p=max(1,$pagination['page']-2);$p<=min($pagination['pages'],$pagination['page']+2);$p++): ?><li class="page-item <?= $p===$pagination['page']?'active':'' ?>"><?php if($p===$pagination['page']): ?><span class="page-link" aria-current="page"><?= $p ?></span><?php else: ?><a class="page-link" aria-label="Page <?= $p ?>" href="<?= sanitize($url(['page'=>$p])) ?>"><?= $p ?></a><?php endif; ?></li><?php endfor; ?>
<?php if($pagination['page']<$pagination['pages']): ?><li class="page-item"><a class="page-link" rel="next" href="<?= sanitize($url(['page'=>$pagination['page']+1])) ?>">Next</a></li><li class="page-item"><a class="page-link" href="<?= sanitize($url(['page'=>$pagination['pages']])) ?>">Last</a></li><?php endif; ?></ul></nav><?php endif; ?>
<?php
$panel='<section id="questionBankPanel" aria-label="Filtered questions">'.schoolProtectForms(ob_get_clean(),Auth::generateCSRFToken()).'</section>';
if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'&&($_SERVER['HTTP_HX_REQUEST']??'')==='true'&&($_SERVER['HTTP_HX_TARGET']??'')==='questionBankPanel'){
    ob_end_clean();
    header('Content-Type: text/html; charset=UTF-8');header('X-ERP-Fragment: question-list');header('X-ERP-Context: '.schoolAppContextVersion());header('Vary: HX-Request, HX-Target');
    header('Cache-Control: no-store, private');echo $panel;exit;
}
echo $panel;
?>

<?php endif; ?>
<?php $content=ob_get_clean();include __DIR__.'/header.php'; ?>
