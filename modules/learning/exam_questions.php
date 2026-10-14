<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/learning_content_service.php';
require_once __DIR__.'/../../includes/exam_question_import.php';
Auth::requireRole(['super_admin','school_admin','teacher','student']);Auth::requireAccess('exam_questions');
$db=getDB();$actor=learningActor($db,(int)Auth::getUserId(),(int)Auth::getSchoolId(),Auth::isReadOnly());
$isStudent=$actor['role']==='student';$purpose=$isStudent?'exam_view':'exam_bank';
$class=(int)($_POST['class_id']??$_GET['class_id']??0);$subject=(int)($_POST['subject_id']??$_GET['subject_id']??0);
$classes=learningReadClasses($db,$actor);if($isStudent&&!$class)$class=(int)($classes[0]['id']??0);
$subjects=learningReadSubjects($db,$actor,$class);$chapters=[];$topicRows=[];$error='';$edit=null;
$writerSubjects=examImportSubjects($db,$actor,$classes);
$canCreate=!$isStudent&&!$actor['read_only']&&Auth::canAddQuestions()&&(bool)$writerSubjects;
if($subject){try{$chapters=learningCatalogChapters($db,$actor,$class,$subject);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}}
if(($_GET['action']??'')==='catalog'){
    header('Content-Type: application/json');$allTopics=[];foreach($chapters as $c)$allTopics[(int)$c['id']]=learningCatalogTopics($db,$actor,$class,$subject,(int)$c['id']);
    echo json_encode(['chapters'=>$chapters,'topics'=>$allTopics]);exit;
}
if(($_GET['action']??'')==='download_template'){
    if(!$canCreate)denySchoolRequest('Your account cannot download exam question import samples.',403);
    try{
        $mode=questionImportFormat($_GET['content_mode']??'normal');$format=$_GET['format']??'xlsx';
        if(!is_string($format)||!in_array($format,['xlsx','csv'],true))throw new InvalidArgumentException('Choose Excel (.xlsx) or CSV.');
        [$headers,$sampleRows]=examImportSample($db,$actor,$mode,$class,$subject);
        $binary=$format==='xlsx'?XlsxHelper::generateXlsx($headers,$sampleRows,'Exam Questions',[8,9],true):XlsxHelper::generateCsv($headers,$sampleRows);
        header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/csv; charset=UTF-8'));
        header('Content-Disposition: attachment; filename="exam-questions-'.$mode.'-sample.'.$format.'"');
        header('Cache-Control: private, no-store');echo $binary;exit;
    }catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    catch(Throwable $e){denySchoolRequest(schoolPublicError($e),500);}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=$_POST['action']??'';if(!is_string($action)||!in_array($action,['add','edit','set_visibility','archive','restore','import'],true))throw new InvalidArgumentException('Choose one exam question action.');
        if($action==='import'){
            if(!$canCreate)denySchoolRequest('Your account cannot import exam questions.',403);
            $mode=questionImportFormat($_POST['import_content_mode']??'normal');
            $visibility=learningVisibility($_POST['import_visibility']??($actor['school_id']===0?'public':'private'));
            $result=examImportQuestions($db,$actor,examImportReadUpload($_FILES['excel_file']??[]),$mode,$visibility);
            $message='Successfully imported '.$result['saved'].' exam questions. Imported questions are staff-only.';
            if($result['skipped'])$message.=' '.$result['skipped'].' row(s) skipped. '.implode(' ',$result['errors']).($result['skipped']>count($result['errors'])?' Only the first 10 errors are shown.':'');
            redirect(APP_URL.'/modules/learning/exam_questions.php?'.http_build_query(['class_id'=>$class,'subject_id'=>$subject]),$message,$result['skipped']?'warning':'success');
        }
        $id=$action==='add'?null:learningId($_POST['question_id']??null);
        if(in_array($action,['add','edit'],true))learningSaveQuestion($db,$actor,$_POST,$id,'exam');
        elseif($action==='set_visibility')learningSetVisibility($db,$actor,'questions',$id,learningVisibility($_POST['visibility']??null),'exam');
        else learningSetArchived($db,$actor,'questions',$id,$action==='archive','exam');
        redirect(APP_URL.'/modules/learning/exam_questions.php?'.http_build_query(['class_id'=>$class,'subject_id'=>$subject]),'Exam question saved successfully.','success');
    }catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}
    catch(Throwable $e){http_response_code(500);$error=schoolPublicError($e);}
}
if(isset($_GET['edit_question_id'])){
    if($isStudent)denySchoolRequest('Only the creator can edit exam questions.',403);
    try{$edit=learningContentRecord($db,$actor,'questions',learningId($_GET['edit_question_id']),'exam_bank',true);if(!$edit['can_edit']||$edit['archived_at'])throw new InvalidArgumentException('Restore your archived question before editing, or choose your own question.');}
    catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}
    $class=(int)$edit['class_id'];$subject=(int)$edit['subject_id'];$subjects=learningReadSubjects($db,$actor,$class);$chapters=learningCatalogChapters($db,$actor,$class,$subject);
}
if($error&&($_POST['action']??'')==='edit'&&!empty($_POST['question_id'])){
    try{$edit=learningContentRecord($db,$actor,'questions',learningId($_POST['question_id']),'exam_bank',true);}catch(InvalidArgumentException $ignore){}
}
$level=$_GET['difficulty']??'';$chapterFilter=$_GET['chapter']??'';$topicFilter=$_GET['filter_topic']??'';$search=$_GET['search']??'';
foreach([$level,$chapterFilter,$topicFilter,$search] as $v)if(!is_string($v)||mb_strlen($v)>200)denySchoolRequest('Choose valid question filters.',422);
$filters=['source'=>$_GET['source']??'all','archived'=>!$isStudent&&($_GET['archived']??'')==='1','search'=>$search];
if($class)$filters['class_id']=$class;if($subject)$filters['subject_id']=$subject;if($level)$filters['difficulty']=$level;if($chapterFilter)$filters['chapter']=$chapterFilter;
try{$questions=learningContentList($db,$actor,'questions',$filters,$purpose);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
if($chapterFilter&&$subject){foreach($chapters as $c)if($c['name']===$chapterFilter)$topicRows=learningCatalogTopics($db,$actor,$class,$subject,(int)$c['id']);}
if($topicFilter){$aliases=null;foreach($topicRows as $t)if((string)$t['id']===$topicFilter)$aliases=$t['alias_ids'];if(!$aliases)denySchoolRequest('Choose a topic in the selected chapter.',404);$questions=array_values(array_filter($questions,static fn($q)=>in_array((int)($q['catalog_topic_id']?:$q['topic_id']),$aliases,true)));}
$form=$error&&in_array($_POST['action']??'',['add','edit'],true)?$_POST:($edit??[]);$form=array_filter($form,static fn($v)=>is_scalar($v)||$v===null);$formClass=(int)($form['class_id']??$class);$formSubject=(int)($form['subject_id']??$subject);
$form+=['class_id'=>$formClass,'subject_id'=>$formSubject,'visibility'=>$actor['school_id']===0?'public':'private'];$canCreate=$canCreate&&(bool)$writerSubjects;
$pageTitle='Exam Questions';$activePage='exam_questions';ob_start();
?>
<script>window.MathJax={tex:{inlineMath:[['\\(','\\)'],['$','$']],displayMath:[['\\[','\\]'],['$$','$$']],processEscapes:true}};</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2>Exam Questions</h2><p class="text-muted mb-0"><?= $isStudent?'Only questions explicitly released for your class and subjects.':'Separate exam bank: staff-only by default; normal papers and student practice never use these questions.' ?></p></div><?php if(!$isStudent): ?><a class="btn btn-outline-primary align-self-start" href="<?= APP_URL ?>/modules/learning/exam_paper_generator.php">Exam Paper Generator</a><?php endif; ?></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<form method="get" class="card card-body mb-3"><div class="row g-2">
<div class="col-md-3"><label class="form-label">Class</label><select name="class_id" class="form-select" onchange="this.form.subject_id.value='';this.form.chapter.value='';this.form.filter_topic.value='';this.form.submit()"><option value="">Choose class</option><?php foreach($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $class===$c['id']||$class===(int)$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Subject</label><select name="subject_id" class="form-select" <?= !$class?'disabled':'' ?> onchange="this.form.chapter.value='';this.form.filter_topic.value='';this.form.submit()"><option value="">All class subjects</option><?php foreach($subjects as $s): ?><option value="<?= $s['id'] ?>" <?= $subject===(int)$s['id']?'selected':'' ?>><?= sanitize($s['display_name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Chapter</label><select name="chapter" class="form-select" <?= !$subject?'disabled':'' ?> onchange="this.form.filter_topic.value='';this.form.submit()"><option value="">All chapters</option><?php foreach($chapters as $c): ?><option value="<?= sanitize($c['name']) ?>" <?= $chapterFilter===$c['name']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Topic</label><select name="filter_topic" class="form-select" <?= !$chapterFilter?'disabled':'' ?>><option value="">All topics</option><?php foreach($topicRows as $t): ?><option value="<?= $t['id'] ?>" <?= $topicFilter===(string)$t['id']?'selected':'' ?>><?= sanitize($t['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Difficulty</label><select name="difficulty" class="form-select"><option value="">All levels</option><?php foreach(['Easy','Medium','Hard','Challenging'] as $l): ?><option <?= $level===$l?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Source</label><select name="source" class="form-select"><?php foreach(['all'=>'All eligible','shared'=>'Shared catalog','school'=>'This school','mine'=>'My questions'] as $v=>$label): ?><option value="<?= $v ?>" <?= $filters['source']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Search</label><input name="search" type="search" maxlength="200" value="<?= sanitize($search) ?>" class="form-control" placeholder="Question text"></div>
<?php if(!$isStudent): ?><div class="col-md-2"><label class="form-label">Status</label><select name="archived" class="form-select"><option value="">Active</option><option value="1" <?= $filters['archived']?'selected':'' ?>>My archived</option></select></div><?php endif; ?><div class="col-md-1 align-self-end"><button class="btn btn-primary">Filter</button></div></div></form>
<?php if($canCreate): ?>
<details class="card card-body mb-3" id="examImportPanel" <?= $error&&($_POST['action']??'')==='import'?'open':'' ?>>
<summary class="fw-bold text-primary">Import Exam Questions — Excel / CSV</summary>
<div class="mt-3"><p>Choose <strong>Normal Text</strong> for plain questions, or <strong>HTML Source</strong> for formatted questions, answers and options (including LaTeX maths). Both methods support Excel (.xlsx) and UTF-8 CSV.</p>
<div class="row g-3 mb-3"><?php foreach(['normal'=>'Normal Text','html'=>'HTML Source'] as $mode=>$label): ?><div class="col-md-6"><div class="border rounded p-3 h-100"><h3 class="h6"><?= $label ?> samples</h3><div class="d-flex flex-wrap gap-2"><?php foreach(['xlsx'=>'Download Excel sample','csv'=>'Download CSV sample'] as $format=>$download): ?><a class="btn btn-sm btn-outline-primary" data-exam-import-mode="<?= $mode ?>" href="?<?= sanitize(http_build_query(['action'=>'download_template','format'=>$format,'content_mode'=>$mode,'class_id'=>$class,'subject_id'=>$subject])) ?>"><?= $download ?></a><?php endforeach; ?></div></div></div><?php endforeach; ?></div>
<p class="small text-muted">Samples use your selected writable class/subject and an existing chapter/topic, or the first eligible catalog if no filter is selected. Replace the examples with your own questions. Class, Subject, Chapter, Type, Difficulty, Cognitive Level, Marks, Required Minutes and at least one question language are required. Topic and bilingual model answers are optional. MCQs require all four options (in either language) and Correct Option A–D.</p>
<form method="post" enctype="multipart/form-data" id="examImportForm" class="row g-3"><input type="hidden" name="action" value="import">
<div class="col-md-3"><label for="examImportMode" class="form-label">Import method</label><select id="examImportMode" name="import_content_mode" class="form-select"><option value="normal" <?= ($_POST['import_content_mode']??'normal')==='normal'?'selected':'' ?>>Normal Text</option><option value="html" <?= ($_POST['import_content_mode']??'')==='html'?'selected':'' ?>>HTML Source</option></select></div>
<div class="col-md-5"><label for="examImportFile" class="form-label">Excel / CSV file</label><input id="examImportFile" type="file" name="excel_file" accept=".xlsx,.csv" class="form-control" required><div class="form-text">Maximum 5 MB and 2,000 questions per upload. Use the first worksheet and retain the sample headings.</div></div>
<div class="col-md-4"><label for="examImportVisibility" class="form-label">Staff visibility for imported questions</label><select id="examImportVisibility" name="import_visibility" class="form-select"><?php $importVisibility=$_POST['import_visibility']??($actor['school_id']===0?'public':'private'); ?><option value="private" <?= $importVisibility==='private'?'selected':'' ?>>Private — creator, assigned teachers and admins</option><option value="public" <?= $importVisibility==='public'?'selected':'' ?>><?= $actor['school_id']===0?'Shared catalog staff':'Staff within this school' ?></option></select></div>
<div class="col-12"><div class="alert alert-info py-2 mb-3">Source is assigned by your account: <?= $actor['school_id']===0?'Shared':'School' ?>. Leave the Source column blank or use Auto / <?= $actor['school_id']===0?'Shared':'School' ?>. School users must use existing catalog chapters/topics; only super admin can add missing catalog entries. Imported questions are <strong>staff-only</strong> and never enter student practice. Release to students later only through an individual question edit.</div><button class="btn btn-success" type="submit">Upload &amp; Import Exam Questions</button><span class="small text-muted ms-2">Valid rows save; skipped rows show a reason. Zero saved rows is an error.</span></div>
</form></div></details>
<details class="card card-body mb-3" <?= $edit||($error&&in_array($_POST['action']??'',['add','edit'],true))?'open':'' ?>><summary class="fw-bold text-primary"><?= $edit?'Edit exam question':'Add Exam Question' ?></summary>
<form method="post" id="examQuestionForm" class="mt-3"><input type="hidden" name="action" value="<?= $edit?'edit':'add' ?>"><?php if($edit): ?><input type="hidden" name="question_id" value="<?= $edit['id'] ?>"><?php endif; ?>
<div class="row g-3"><div class="col-md-3"><label class="form-label">Class</label><select id="examClass" name="class_id" class="form-select" required><?php foreach($classes as $c): if(empty($writerSubjects[$c['id']]))continue; ?><option value="<?= $c['id'] ?>" <?= $formClass===(int)$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Subject</label><select id="examSubject" name="subject_id" class="form-select" required></select></div>
<div class="col-md-3"><label class="form-label">Chapter</label><select id="examChapter" name="chapter" class="form-select" required></select><div class="form-text">Catalog changes are super-admin-only.</div></div>
<div class="col-md-3"><label class="form-label">Topic</label><select id="examTopic" class="form-select"></select><input type="hidden" id="examLocalTopic" name="topic_id"><input type="hidden" id="examCatalogTopic" name="catalog_topic_id"></div>
<div class="col-md-3"><label class="form-label">Type</label><select name="type" id="examType" class="form-select"><?php foreach(['MCQ','Very Short','Short','Long','Reasoning','Difference Between'] as $t): ?><option <?= ($form['type']??'Short')===$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Difficulty</label><select name="difficulty" class="form-select"><?php foreach(['Easy','Medium','Hard','Challenging'] as $l): ?><option <?= ($form['difficulty']??'Medium')===$l?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Cognitive level</label><select name="cognitive_domain" class="form-select"><?php foreach(['Knowledge'=>'K','Understanding'=>'U','Application'=>'A','Higher Ability'=>'HA'] as $v=>$label): ?><option value="<?= $v ?>" <?= ($form['cognitive_domain']??'Knowledge')===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-2"><label class="form-label">Marks</label><input type="number" name="marks" min="0.01" max="1000" step="0.01" required value="<?= sanitize((string)($form['marks']??1)) ?>" class="form-control"></div><div class="col-md-2"><label class="form-label">Required minutes</label><input type="number" name="required_minutes" min="0.01" max="600" step="0.01" required value="<?= sanitize((string)($form['required_minutes']??'')) ?>" class="form-control"></div>
<?php foreach(['question_text'=>'Question (English)','question_text_np'=>'Question (Nepali)','answer'=>'Model answer / explanation (English)','answer_np'=>'Model answer / explanation (Nepali)'] as $key=>$label): ?><div class="col-md-6"><label class="form-label"><?= $label ?></label><textarea id="exam_<?= $key ?>" class="form-control exam-rich <?= str_ends_with($key,'_np')?'font-nepali':'' ?>" name="<?= $key ?>" rows="3"><?= sanitize($form[$key]??'') ?></textarea></div><?php endforeach; ?>
<div class="col-12" id="examOptions"><div class="row g-2"><?php foreach(['A','B','C','D'] as $o): foreach([''=>'English','_np'=>'Nepali'] as $suffix=>$lang): $values=json_decode($form[$suffix?'options_np':'options']??'{}',true)?:[];$key='option_'.strtolower($o).$suffix; ?><div class="col-md-6"><label class="form-label">Option <?= $o ?> — <?= $lang ?></label><textarea id="exam_<?= $key ?>" name="<?= $key ?>" class="form-control exam-rich <?= $suffix?'font-nepali':'' ?>" rows="2"><?= sanitize($form[$key]??$values[$o]??'') ?></textarea></div><?php endforeach; endforeach; ?><div class="col-md-3"><label class="form-label">Correct option</label><select class="form-select" name="correct_option"><?php foreach(['A','B','C','D'] as $o): ?><option <?= ($form['correct_option']??'A')===$o?'selected':'' ?>><?= $o ?></option><?php endforeach; ?></select></div></div></div>
<div class="col-md-6"><label class="form-label">Staff visibility</label><select name="visibility" id="examVisibility" class="form-select"><option value="private" <?= ($form['visibility']??'private')==='private'?'selected':'' ?>>Private — creator, assigned teachers and school administrators</option><option value="public" <?= ($form['visibility']??'private')==='public'?'selected':'' ?>><?= $actor['school_id']===0?'Shared catalog for eligible schools':'Staff within this school' ?></option></select></div>
<div class="col-md-6 align-self-end"><label class="form-check"><input id="examRelease" class="form-check-input" type="checkbox" name="student_visible" value="1" <?= !empty($form['student_visible'])?'checked':'' ?>>Allow enrolled students to view</label><div class="form-text text-danger">Explicit release exposes the question, options and model answer. Private visibility always disables release.</div></div><div class="col-12"><button class="btn btn-success">Save Exam Question</button></div></div></form></details>
<script>window.examQuestionData=<?= json_encode(['subjects'=>$writerSubjects,'form'=>$form],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><?php include __DIR__.'/../../includes/question_editor.php'; ?><script src="<?= APP_URL ?>/assets/js/exam-questions.js?v=<?= (int)filemtime(__DIR__.'/../../assets/js/exam-questions.js') ?>"></script>
<?php endif; ?>
<p class="text-muted"><?= count($questions) ?> visible exam questions</p>
<?php if(!$questions): ?><div class="card card-body text-muted"><?= $isStudent?'No exam questions have been released for your class yet.':'No eligible exam questions match these filters.' ?></div><?php endif; ?>
<?php foreach($questions as $q): ?><article class="card card-body mb-3"><div class="d-flex flex-wrap justify-content-between gap-2"><div><span class="badge bg-primary"><?= sanitize($q['type']) ?></span> <span class="badge bg-secondary"><?= sanitize($q['difficulty']) ?></span> <?= sanitize($q['chapter']) ?> · <?= sanitize($q['cognitive_domain']) ?> · <?= sanitize((string)$q['marks']) ?> marks · <?= sanitize((string)$q['required_minutes']) ?> min <span class="badge bg-light text-dark"><?= (int)$q['student_visible']?'Released to students':'Staff-only' ?></span></div>
<?php if($q['can_edit']): ?><div><?php if(!$filters['archived']): ?><a class="btn btn-sm btn-outline-primary" href="?edit_question_id=<?= $q['id'] ?>">Edit</a><form method="post" class="d-inline"><input type="hidden" name="action" value="set_visibility"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><input type="hidden" name="visibility" value="<?= $q['visibility']==='private'?'public':'private' ?>"><button class="btn btn-sm btn-outline-secondary"><?= $q['visibility']==='private'?'Share with staff':'Make private' ?></button></form><?php endif; ?><form method="post" class="d-inline" onsubmit="return confirm('<?= $filters['archived']?'Restore this exam question?':'Archive this exam question? Its ID and content are retained.' ?>')"><input type="hidden" name="action" value="<?= $filters['archived']?'restore':'archive' ?>"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><button class="btn btn-sm btn-outline-danger"><?= $filters['archived']?'Restore':'Archive' ?></button></form></div><?php endif; ?></div>
<div class="mt-3"><?= safeRichHtml($q['question_text']) ?></div><?php if($q['question_text_np']): ?><div class="font-nepali"><?= safeRichHtml($q['question_text_np']) ?></div><?php endif; ?>
<?php if($q['type']==='MCQ'): $options=json_decode($q['options']??'{}',true)?:[];$np=json_decode($q['options_np']??'{}',true)?:[]; ?><div class="row g-2"><?php foreach(['A','B','C','D'] as $o): ?><div class="col-sm-6"><div class="border rounded p-2"><strong><?= $o ?>.</strong> <?= safeRichHtml($options[$o]??'') ?><span class="font-nepali"><?= safeRichHtml($np[$o]??'') ?></span></div></div><?php endforeach; ?></div><?php endif; ?>
<details class="mt-2"><summary>Model answer<?= $q['type']==='MCQ'?' / correct option':'' ?></summary><?php if($q['type']==='MCQ'): ?><strong>Correct: <?= sanitize($q['correct_option']) ?></strong><?php endif; ?><?= safeRichHtml($q['answer']??'') ?><div class="font-nepali"><?= safeRichHtml($q['answer_np']??'') ?></div></details></article><?php endforeach; ?>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php'; ?>
