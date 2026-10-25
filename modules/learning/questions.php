<?php
// New shared, paginated bank UI. Keep old API/import clients working while
// all new add/edit/import forms use the same validated workflow as exam banks.
$legacyBankRequest=($_SERVER['REQUEST_METHOD']==='POST'&&!isset($_POST['bank_ui']))||isset($_GET['action_get_chapters'])||isset($_GET['action_get_topics'])||in_array($_GET['action']??'',['request_permission','request_view_permission'],true)||(($_GET['action']??'')==='download_template'&&!isset($_GET['bank_ui']))||isset($_GET['make_universal']);
if(!$legacyBankRequest){
    foreach(['class','subject','class_id','subject_id'] as $field)if(isset($_GET[$field])&&(!is_scalar($_GET[$field])||!preg_match('/^\d*$/',(string)$_GET[$field]))) {http_response_code(404);exit('Choose a valid class or subject.');}
    $_GET['class_id']=$_GET['class_id']??$_GET['class']??0;$_GET['subject_id']=$_GET['subject_id']??$_GET['subject']??0;
    $questionBankKind='learning';$category=$_GET['category']??'all';$examSection=$category==='mcq'?'mcq':($category==='other'?'written':'all');
    include __DIR__.'/../../includes/exam_question_page.php';return;
}
/**
 * Learning Portal - Question Bank CRUD
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/xlsx_helper.php';
require_once __DIR__.'/../../includes/curriculum_catalog.php';
require_once __DIR__.'/../../includes/question_import.php';
require_once __DIR__.'/../../includes/learning_content.php';
require_once __DIR__.'/../../includes/question_import_context.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('questions');

$db = getDB();
$school_id = Auth::getSchoolId();
$is_admin = (Auth::getRole() === 'super_admin');
$error = '';
$success = '';
$learningRole=(string)Auth::getRole();$learningActor=(int)Auth::getUserId();
$contentActor=learningActor($db,$learningActor,(int)$school_id,Auth::isReadOnly());
$canManageCatalog=learningCanManageCatalog($contentActor);
$allowedClasses=learningReadClasses($db,$contentActor);
$importWriterSubjects=questionImportWriterSubjects($db,$contentActor,$allowedClasses);


// Fetch school subscription type and permissions
$permission_status = 'none';
$view_permission_status = 'none';

if ($is_admin) {
    $permission_status = 'approved';
    $view_permission_status = 'approved';
} elseif ($school_id) {
    $permission_status = Auth::canAddQuestions()?'approved':'none';
    $view_permission_status = Auth::hasAccess('questions')?'approved':'none';
}

// Handle Request Question Creation Permission (School Users only)
if (isset($_GET['action']) && $_GET['action'] === 'request_permission' && $school_id && !$is_admin) {
    if ($permission_status === 'none') {
        $stmt = $db->prepare("UPDATE schools SET question_permission_status = 'requested' WHERE id = ?");
        $stmt->execute([$school_id]);
        Auth::logActivity(Auth::getUserId(), 'Request Question Creation Permission', 'schools', $school_id);
        redirect(schoolRouteUrl('modules/learning/questions.php'), 'Question creation permission requested successfully.', 'success');
    }
}

// Handle Request Question View Permission (School Users only)
if (isset($_GET['action']) && $_GET['action'] === 'request_view_permission' && $school_id && !$is_admin) {
    if ($view_permission_status === 'none') {
        $stmt = $db->prepare("UPDATE schools SET question_view_permission = 'requested' WHERE id = ?");
        $stmt->execute([$school_id]);
        Auth::logActivity(Auth::getUserId(), 'Request Question View Permission', 'schools', $school_id);
        redirect(schoolRouteUrl('modules/learning/questions.php'), 'Question view permission requested successfully.', 'success');
    }
}

// School content is never promoted by changing its school ID alone.
if(isset($_GET['make_universal']))denySchoolRequest('School content cannot be transferred to the shared catalog.',403);

// Handle Download Sample Excel Template
if(($_GET['action']??'')==='import_catalog'){
    try{$catalog=questionImportCatalog($db,$contentActor,(int)($_GET['class_id']??0),(int)($_GET['subject_id']??0));header('Content-Type: application/json');echo json_encode($catalog);exit;}
    catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
}
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $type = strtolower($_GET['type'] ?? 'mcq');
    $format = strtolower($_GET['format'] ?? 'xlsx');
    try{$contentMode=questionImportFormat($_GET['content_mode']??'normal');}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    
    if ($type === 'mcq') {
        $sample = XlsxHelper::getMcqSample();
        $filename = 'sample_mcq_questions_template';
    } else {
        $sample = XlsxHelper::getOtherSample();
        $filename = 'sample_other_questions_template';
    }
    if($contentMode==='html'){$sample=XlsxHelper::getHtmlSample($type);$filename.='_html_source';}
    try{$sample=questionImportSampleContext($sample,questionImportContext($db,$contentActor,$_GET));}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
    
    if ($format === 'csv') {
        $content = XlsxHelper::generateCsv($sample['headers'], $sample['rows']);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo $content;
        exit;
    } else {
        $content = XlsxHelper::generateXlsx($sample['headers'], $sample['rows'], ucfirst($type) . ' Questions');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: max-age=0');
        echo $content;
        exit;
    }
}

// Handle Bulk Excel / CSV Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_import_excel'])) {
    if (!$is_admin && $permission_status !== 'approved') {
        $error = "Your school does not have permission to import questions.";
    } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please choose a valid Excel (.xlsx) or CSV file to upload.";
    } else {
        $fileTmp = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($fileExt, ['xlsx', 'csv', 'txt'])) {
            $error = "Only .xlsx and .csv files are supported.";
        } else {
            $importTransactionOwned=false;
            try {
                $importContentMode=questionImportFormat($_POST['import_content_mode']??'normal');
                $rows = XlsxHelper::read($fileTmp);
                if (empty($rows) || count($rows) < 2) {
                    $error = "Uploaded file contains no data or only the header row.";
                } else {
                    $headerRow = $rows[0];
                    $colMap = [];
                    
                    // Column normalization mapping (strip non-alphanumeric chars)
                    $aliasMap = [
                        'class' => ['class', 'classname', 'grade', 'classlevel', 'standard'],
                        'subject' => ['subject', 'subjectname', 'course', 'sub'],
                        'chapter' => ['chapter', 'unit', 'lesson', 'chaptername', 'chapterunit'],
                        'topic' => ['topic', 'topicname', 'subtopic'],
                        'type' => ['type', 'questiontype', 'qtype'],
                        'cognitive_domain' => ['cognitivelevel', 'cognitivedomain', 'cognitive', 'domain', 'bloom', 'bloomslevel', 'kuaha', 'level', 'taxonomylevel'],
                        'question_text' => ['question', 'questiontext', 'questionenglish', 'questionen', 'qenglish', 'qtext', 'englishquestion'],
                        'question_text_np' => ['questionnepali', 'questionnp', 'qnepali', 'qtextnp', 'nepaliquestion', 'nepali'],
                        'marks' => ['marks', 'mark', 'weightage', 'point', 'points'],
                        'answer' => ['answer', 'answeren', 'answerenglish', 'answermodelen', 'modelanswer', 'modelanswerenglish', 'expectedanswer', 'expectedansweren'],
                        'answer_np' => ['answernp', 'answernepali', 'answermodelnp', 'modelanswernepali', 'expectedanswernp', 'expectedanswernepali'],
                        'option_a' => ['optiona', 'optionaen', 'optionaenglish', 'opta', 'optaen', 'optaenglish', 'choicea', 'choiceaen', 'a'],
                        'option_a_np' => ['optionanp', 'optionanepali', 'optanp', 'optanepali', 'choiceanp', 'choiceanepali', 'anp'],
                        'option_b' => ['optionb', 'optionben', 'optionbenglish', 'optb', 'optben', 'optbenglish', 'choiceb', 'choiceben', 'b'],
                        'option_b_np' => ['optionbnp', 'optionbnepali', 'optbnp', 'optbnepali', 'choicebnp', 'choicebnepali', 'bnp'],
                        'option_c' => ['optionc', 'optioncen', 'optioncenglish', 'optc', 'optcen', 'optcenglish', 'choicec', 'choicecen', 'c'],
                        'option_c_np' => ['optioncnp', 'optioncnepali', 'optcnp', 'optcnepali', 'choicecnp', 'choicecnepali', 'cnp'],
                        'option_d' => ['optiond', 'optionden', 'optiondenglish', 'optd', 'optden', 'optdenglish', 'choiced', 'choiceden', 'd'],
                        'option_d_np' => ['optiondnp', 'optiondnepali', 'optdnp', 'optdnepali', 'choicednp', 'choicednepali', 'dnp'],
                        'correct_option' => ['correctoption', 'correct', 'answerkey', 'key', 'correctanswer', 'correctchoice']
                    ];

                    $colPriority=[];
                    foreach ($headerRow as $idx => $headerText) {
                        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$headerText));
                        $sourceHeader=(str_contains($clean,'html')||str_contains($clean,'source'))&&preg_match('/question|answer|option|choice/',$clean);
                        if($sourceHeader)$clean=str_replace(['html','source'],'',$clean);
                        foreach ($aliasMap as $targetKey => $aliases) {
                            if (in_array($clean, $aliases)) {
                                if(in_array($targetKey,['class','subject','chapter','topic'],true)&&isset($colMap[$targetKey]))throw new InvalidArgumentException('Use one '.$targetKey.' column only.');
                                $priority=$sourceHeader?($importContentMode==='html'?2:0):1;
                                if(!isset($colPriority[$targetKey])||$priority>$colPriority[$targetKey]){$colMap[$targetKey]=$idx;$colPriority[$targetKey]=$priority;}
                                break;
                            }
                        }
                    }

                    $importedCount = 0;
                    $skippedCount = 0;
                    $skippedReasons = [];
                    $q_school_id = $is_admin ? 0 : $school_id;
                    $db->beginTransaction();
                    $importTransactionOwned=true;
                    $importContext=questionImportContext($db,$contentActor,$_POST);
                    questionImportMatchContext($rows,$colMap,$importContext);
                    $resolvedClassId=$importContext['class_id'];$resolvedSubjectId=$importContext['subject_id'];$catalog=$importContext['catalog'];

                    for ($r = 1; $r < count($rows); $r++) {
                        $row = $rows[$r];
                        $rowNum = $r + 1; // Excel row number (1-indexed)

                        // Helper to get mapped value
                        $getVal = function($key) use ($colMap, $row) {
                            if (isset($colMap[$key]) && isset($row[$colMap[$key]])) {
                                return trim((string)$row[$colMap[$key]]);
                            }
                            return '';
                        };

                        try{$content=questionImportRowContent($getVal,$importContentMode);}catch(InvalidArgumentException $e){$skippedCount++;$skippedReasons[]="Row {$rowNum}: ".$e->getMessage();continue;}

                        // Resolve Cognitive Level (K, U, A, HA)
                        $cdRaw = $getVal('cognitive_domain');
                        $cdMap = [
                            'k' => 'Knowledge', 'knowledge' => 'Knowledge', 'knowledgek' => 'Knowledge',
                            'u' => 'Understanding', 'understanding' => 'Understanding', 'understandingu' => 'Understanding',
                            'a' => 'Application', 'application' => 'Application', 'applicationa' => 'Application',
                            'ha' => 'Higher Ability', 'higherability' => 'Higher Ability', 'higherabilityha' => 'Higher Ability',
                            'ku'=>'Knowledge and Understanding', 'knowledgeandunderstanding'=>'Knowledge and Understanding', 'knowledgeandunderstandingku'=>'Knowledge and Understanding'
                        ];
                        $cdClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $cdRaw));
                        $cognitiveDomain = $cdMap[$cdClean] ?? 'Knowledge';

                        require_once __DIR__.'/../../includes/learning_content_service.php';
                        learningSaveQuestion($db,$contentActor,$content+[
                            'class_id'=>$resolvedClassId,'subject_id'=>$resolvedSubjectId,'chapter'=>$catalog['chapter'],
                            'topic_id'=>$catalog['topic_id'],'catalog_chapter_id'=>$catalog['catalog_chapter_id'],'catalog_topic_id'=>$catalog['catalog_topic_id'],
                            'cognitive_domain'=>$cognitiveDomain,'visibility'=>learningVisibility($_POST['import_visibility']??($is_admin?'public':'private'))
                        ],null,'learning',false);
                        $importedCount++;
                    }

                    $skipDetails=$skippedCount>0?' '.$skippedCount.' row(s) skipped: '.implode('; ',array_slice($skippedReasons,0,3)):'';
                    if($importedCount===0){
                        $db->rollBack();$importTransactionOwned=false;
                        $error='No questions were imported.'.$skipDetails;
                    }else{
                        Auth::logActivity(Auth::getUserId(), "Bulk Imported $importedCount Questions from Excel", 'questions', 0);
                        $db->commit();$importTransactionOwned=false;
                        // One import outcome, and GET after POST prevents refresh from importing again.
                        unset($_SESSION['action_notice']);
                        redirect(schoolRouteUrl('modules/learning/questions.php') . '?category=all','Successfully imported '.$importedCount.' questions into the Question Bank.'.$skipDetails,$skippedCount>0?'warning':'success');
                    }
                }
            } catch (Throwable $e) {
                if($importTransactionOwned&&$db->inTransaction())$db->rollBack();
                unset($_SESSION['action_notice']);
                $error = 'Error importing Excel file: '.schoolPublicError($e);
            }
        }
    }
}

// Class and subject creation belongs to administrator management pages only.
// Handle Get Chapters AJAX Request
if (isset($_GET['action_get_chapters'])) {
    header('Content-Type: application/json');
    $c_id = (int)($_GET['class_id'] ?? 0);
    $s_id = (int)($_GET['subject_id'] ?? 0);
    
    $chapters=learningCatalogChapters($db,$contentActor,$c_id,$s_id);
    
    echo json_encode($chapters);
    exit;
}

// Handle Get Topics AJAX Request
if (isset($_GET['action_get_topics'])) {
    header('Content-Type: application/json');
    $chapter_id = (int)($_GET['chapter_id'] ?? 0);
    $chapter_name = sanitize($_GET['chapter_name'] ?? '');
    
    $topics=[];
    if($chapter_id>0){$scope=learningCatalogChapterScope($db,$contentActor,$chapter_id);$topics=learningCatalogTopics($db,$contentActor,$scope['class_id'],$scope['subject_id'],$chapter_id);}
    elseif($chapter_name!==''){
        foreach(learningCatalogChapters($db,$contentActor,(int)($_GET['class_id']??0),(int)($_GET['subject_id']??0)) as $chapter)
            if(curriculumNameKey($chapter['name'])===curriculumNameKey($chapter_name)){$topics=learningCatalogTopics($db,$contentActor,(int)($_GET['class_id']??0),(int)($_GET['subject_id']??0),(int)$chapter['id']);break;}
    }
    
    echo json_encode($topics);
    exit;
}

// All manual/scanner saves and ownership transitions use the same service.
if($_SERVER['REQUEST_METHOD']==='POST'&&(isset($_POST['action'])||isset($_GET['delete']))){
    require_once __DIR__.'/../../includes/learning_content_service.php';
    try {
        $action=$_POST['action']??'archive';
        if(!is_string($action)||!in_array($action,['add','edit','set_visibility','archive','restore'],true))throw new InvalidArgumentException('Choose one valid question action.');
        $id=$action==='add'?null:learningId($_POST['question_id']??$_GET['delete']??null);
        if(in_array($action,['add','edit'],true))learningSaveQuestion($db,$contentActor,$_POST,$id);
        elseif($action==='set_visibility')learningSetVisibility($db,$contentActor,'questions',$id,learningVisibility($_POST['visibility']??null));
        else learningSetArchived($db,$contentActor,'questions',$id,$action==='archive');
        $success=match($action){'add'=>'Question saved successfully.','edit'=>'Question updated successfully.','set_visibility'=>'Question visibility saved successfully.','archive'=>'Question archived. It can be restored.',default=>'Question restored successfully.'};
        redirect($_SERVER['REQUEST_URI'],$success,'success');
    }catch(InvalidArgumentException $e){if(in_array($action??'',['set_visibility','archive','restore'],true))denySchoolRequest($e->getMessage(),422);$error=$e->getMessage();}
    catch(Throwable $e){$error=schoolPublicError($e);}
}

// Fetch Filters
$filter_class = $_GET['class'] ?? '';
$filter_subject = $_GET['subject'] ?? '';
$filter_category = sanitize($_GET['category'] ?? 'all'); // 'all', 'mcq', or 'other'
$filter_cognitive = sanitize($_GET['cognitive_domain'] ?? '');

// Lists, search and counts are filtered by the backend access policy.
$questionSearch=$_GET['search']??'';
if(!is_string($questionSearch)||mb_strlen($questionSearch)>100)denySchoolRequest('Question search must be at most 100 characters.',422);
$questionSearch=trim($questionSearch);
$filter_source=$_GET['source']??'all';$filter_archived=($_GET['archived']??'')==='1';
$filters=['source'=>$filter_source,'archived'=>$filter_archived,'search'=>$questionSearch];
$filters['record_id']=$_GET['record_id']??'';
if($filter_class)$filters['class_id']=$filter_class;if($filter_subject)$filters['subject_id']=$filter_subject;
if($filter_category==='mcq')$filters['type']='MCQ';
try{$questions=learningContentList($db,$contentActor,'questions',$filters,'bank');}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
if($filter_category==='other')$questions=array_values(array_filter($questions,static fn($q)=>$q['type']!=='MCQ'));
if($filter_cognitive)$questions=array_values(array_filter($questions,static fn($q)=>$q['cognitive_domain']===$filter_cognitive));
foreach($questions as &$q){
    $classRow=$db->prepare('SELECT name FROM classes WHERE id=?');$classRow->execute([$q['class_id']]);$q['class_name']=$classRow->fetchColumn();
    $subRow=$db->prepare('SELECT name_en FROM subjects WHERE id=?');$subRow->execute([$q['subject_id']]);$q['subject_name']=$subRow->fetchColumn();
}unset($q);
$classes=$allowedClasses;$subjects=$filter_class?learningReadSubjects($db,$contentActor,(int)$filter_class):[];
$class_subjects_map=[];
foreach($classes as $c)foreach(learningReadSubjects($db,$contentActor,(int)$c['id']) as $s){
    if(!learningCanCreate($db,$contentActor,(int)$c['id'],(int)$s['id']))continue;
    $class_subjects_map[]=['class_id'=>$c['id'],'subject_id'=>$s['id'],'name_en'=>$s['display_name'],'name_np'=>$s['name_np'],'code'=>$s['code'],'is_optional'=>$s['type']==='optional'?1:0];
}
$canCreateContent=!$contentActor['read_only']&&in_array($learningRole,['super_admin','school_admin','teacher'],true)&&$permission_status==='approved';
$pageTitle = 'Question Bank';
$activePage = 'questions';

ob_start();
?>

<!-- MathJax Configuration & Script for LaTeX Equation Rendering -->
<?php $pageAssets[]="mathjax"; ?>
<?php $pageAssets[]="mathjax"; ?>

<style>
/* Kokila Nepali / Devanagari Unicode Text & MathJax Support */
mjx-utext, mjx-mtext, .MathJax mtext, .MathJax [class*="mtext"], mjx-c {
    font-family: 'Kokila', 'Noto Sans Devanagari', 'Segoe UI', 'Kalimati', sans-serif !important;
    font-style: normal !important;
}

/* English Inputs Default Font: Arial */
.font-english,
#modalQuestionText, #modalAnswer, #modalOptA, #modalOptB, #modalOptC, #modalOptD {
    font-family: Arial, Helvetica, sans-serif !important;
    font-size: 18px;
}

/* Nepali Inputs Default Font: Kokila */
.font-nepali, .nepali-text,
#modalQuestionTextNp, #modalAnswerNp, #modalOptANp, #modalOptBNp, #modalOptCNp, #modalOptDNp {
    font-family: 'Kokila', 'Noto Sans Devanagari', 'Kalimati', sans-serif !important;
    font-size: var(--erp-kokila-size, 20pt) !important;
}

#texPreview {
    font-size: 20pt;
    overflow-x: auto;
    padding: 12px 0;
    color: #2c3e50;
    min-height: 60px;
    font-family: 'Kokila', 'Noto Sans Devanagari', 'Segoe UI', 'Kalimati', sans-serif;
}
#texPreview mjx-utext, #texPreview mjx-mtext, #texPreview mjx-c {
    font-family: 'Kokila', 'Noto Sans Devanagari', 'Segoe UI', 'Kalimati', sans-serif !important;
    font-style: normal !important;
}
.option-container {
    transition: all 0.2s ease-in-out;
}
.correct-tick-badge {
    transition: all 0.2s ease-in-out;
    user-select: none;
}
.correct-tick-badge:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1) !important;
    transform: translateY(-1px);
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-1"><i class="fas fa-database text-primary me-2"></i>Question Bank</h2>
        <span class="text-muted small">Manage Multiple Choice and Subjective questions aligned with CDC Nepal curriculum</span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= schoolRouteUrl('modules/learning/index.php') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Portal
        </a>
        <?php if ($canCreateContent): ?>
        <button class="btn btn-primary fw-bold shadow-sm" data-erp-click="learning-questions-action-0" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
            <i class="fas fa-list-ol me-1"></i> Add Multiple Choice Questions
        </button>
        <button class="btn btn-success fw-bold shadow-sm" data-erp-click="learning-questions-action-1" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
            <i class="fas fa-pen-nib me-1"></i> Add Questions
        </button>
        <button class="btn btn-info text-white fw-bold shadow-sm" data-ui-toggle="modal" data-ui-target="#importExcelModal">
            <i class="fas fa-file-excel me-1"></i> Import Excel / CSV
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if (!$is_admin): ?>
    <?php if ($view_permission_status === 'none'): ?>
        <div class="alert alert-warning border-start border-warning border-4 shadow-sm mb-4 d-flex align-items-center justify-content-between">
            <div>
                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                Your school does not have permission to view questions in the universal question bank. You can request permission from the system administrator.
            </div>
            <a href="?action=request_view_permission" class="btn btn-warning btn-sm fw-bold"><i class="fas fa-paper-plane"></i> Request View Permission</a>
        </div>
    <?php elseif ($view_permission_status === 'requested'): ?>
        <div class="alert alert-info border-start border-info border-4 shadow-sm mb-4 d-flex align-items-center">
            <i class="fas fa-info-circle text-info me-2"></i>
            Your request for question bank view permission is pending approval from the System Administrator.
        </div>
    <?php endif; ?>

    <?php if ($view_permission_status === 'approved'): ?>
        <?php if ($permission_status === 'none'): ?>
            <div class="alert alert-warning border-start border-warning border-4 shadow-sm mb-4 d-flex align-items-center justify-content-between">
                <div>
                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                    Your school does not have permission to add or edit questions in the universal question bank. You can request creation/editing permission from the system administrator.
                </div>
                <a href="?action=request_permission" class="btn btn-warning btn-sm fw-bold"><i class="fas fa-paper-plane"></i> Request Add/Edit Permission</a>
            </div>
        <?php elseif ($permission_status === 'requested'): ?>
            <div class="alert alert-info border-start border-info border-4 shadow-sm mb-4 d-flex align-items-center">
                <i class="fas fa-info-circle text-info me-2"></i>
                Your request for question creation/editing permission is pending approval from the System Administrator.
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<?php if ($is_admin || $view_permission_status === 'approved'): ?>
<div id="questionsListSection">
<!-- Category Tabs (All / MCQ / Other) -->
<ul class="nav nav-pills mb-3 bg-white p-2 rounded shadow-sm border">
    <li class="nav-item">
        <a class="nav-link fw-bold <?= $filter_category === 'all' ? 'active' : '' ?>" href="<?= schoolRouteUrl('modules/learning/questions.php') ?>?category=all<?= $filter_class ? '&class='.$filter_class : '' ?><?= $filter_subject ? '&subject='.$filter_subject : '' ?><?= $filter_cognitive ? '&cognitive_domain='.$filter_cognitive : '' ?>">
            <i class="fas fa-layer-group me-1"></i> All Questions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-bold <?= $filter_category === 'mcq' ? 'active' : '' ?>" href="<?= schoolRouteUrl('modules/learning/questions.php') ?>?category=mcq<?= $filter_class ? '&class='.$filter_class : '' ?><?= $filter_subject ? '&subject='.$filter_subject : '' ?><?= $filter_cognitive ? '&cognitive_domain='.$filter_cognitive : '' ?>">
            <i class="fas fa-list-ol me-1"></i> Multiple Choice Questions (MCQ)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-bold <?= $filter_category === 'other' ? 'active' : '' ?>" href="<?= schoolRouteUrl('modules/learning/questions.php') ?>?category=other<?= $filter_class ? '&class='.$filter_class : '' ?><?= $filter_subject ? '&subject='.$filter_subject : '' ?><?= $filter_cognitive ? '&cognitive_domain='.$filter_cognitive : '' ?>">
            <i class="fas fa-pen-alt me-1"></i> Other Questions (Subjective)
        </a>
    </li>
</ul>

<!-- Filters -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="category" value="<?= htmlspecialchars($filter_category) ?>">
            <div class="col-md-4">
                <label class="form-label fw-bold">Filter Class</label>
                <select name="class" class="form-select" data-erp-change="learning-questions-action-2" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_class == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Filter Subject</label>
                <select name="subject" class="form-select" <?= !$filter_class?'disabled':'' ?> data-erp-change="learning-questions-action-3" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                    <option value=""><?= $filter_class?'All class subjects':'Choose a class first' ?></option>
                    <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filter_subject == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Cognitive Level</label>
                <select name="cognitive_domain" class="form-select" data-erp-change="learning-questions-action-4" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                    <option value="">All Levels</option>
                    <option value="Knowledge" <?= $filter_cognitive === 'Knowledge' ? 'selected' : '' ?>>Knowledge (K)</option>
                    <option value="Understanding" <?= $filter_cognitive === 'Understanding' ? 'selected' : '' ?>>Understanding (U)</option>
                    <option value="Application" <?= $filter_cognitive === 'Application' ? 'selected' : '' ?>>Application (A)</option>
                    <option value="Higher Ability" <?= $filter_cognitive === 'Higher Ability' ? 'selected' : '' ?>>Higher Ability (HA)</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="<?= schoolRouteUrl('modules/learning/questions.php') ?>" class="btn btn-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

<form method="GET" class="input-group mb-3">
<input type="hidden" name="class" value="<?= sanitize($filter_class) ?>"><input type="hidden" name="subject" value="<?= sanitize($filter_subject) ?>"><input type="hidden" name="category" value="<?= sanitize($filter_category) ?>"><input type="hidden" name="cognitive_domain" value="<?= sanitize($filter_cognitive) ?>">
<select name="source" class="form-select" aria-label="Content source"><?php foreach(['all'=>'All content','shared'=>'Shared catalog','school'=>'This school','mine'=>'My content'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filter_source===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><select name="archived" class="form-select" aria-label="Archive filter"><option value="">Active content</option><option value="1" <?= $filter_archived?'selected':'' ?>>My archived content</option></select><input class="form-control" type="search" name="search" maxlength="100" value="<?= sanitize($questionSearch) ?>" aria-label="Search question text or chapter" placeholder="Search question text or chapter"><button class="btn btn-primary">Search questions</button></form>
<!-- Questions List Table -->
<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Registered Questions List (<?= count($questions) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Class & Subject</th>
                        <th>Chapter</th>
                        <th>Type</th>
                        <th>Cognitive Level</th>
                        <th>Question Snippet</th>
                        <th>Marks</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($questions) > 0): ?>
                        <?php foreach ($questions as $q): ?>
                        <tr>
                            <td>
                                <small class="text-muted">Record #<?= (int)$q['id'] ?></small><br>
                                <strong><?= sanitize($q['class_name'] ?: 'Universal') ?></strong><br>
                                <small class="text-muted"><?= sanitize($q['subject_name'] ?: 'Universal') ?></small>
                            </td>
                            <td><?= sanitize($q['chapter']) ?><br><span class="badge bg-light text-dark"><?= $q['visibility']==='private'?'Private':'Public' ?><?= $q['source_scope']==='school'?' — this school':'' ?></span></td>
                            <td>
                                <?php
                                $badge = [
                                    'MCQ' => 'bg-primary',
                                    'Very Short' => 'bg-info',
                                    'Short' => 'bg-success',
                                    'Long' => 'bg-danger',
                                    'Reasoning' => 'bg-warning text-dark',
                                    'Difference Between' => 'bg-secondary'
                                ][$q['type']] ?? 'bg-secondary';
                                ?>
                                <span class="badge <?= $badge ?>"><?= $q['type'] ?></span>
                                <?php if (($q['school_id'] ?? 0) == 0): ?>
                                <span class="badge bg-dark ms-1" title="Read-only shared catalog"><i class="fas fa-globe"></i> Shared catalog</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $cd = $q['cognitive_domain'] ?? 'Knowledge';
                                $cd_badge = [
                                    'Knowledge' => 'bg-info text-dark',
                                    'Understanding' => 'bg-primary text-white',
                                    'Application' => 'bg-warning text-dark',
                                    'Higher Ability' => 'bg-danger text-white'
                                ][$cd] ?? 'bg-secondary text-white';
                                $cd_short = [
                                    'Knowledge' => 'K',
                                    'Understanding' => 'U',
                                    'Application' => 'A',
                                    'Higher Ability' => 'HA', 'Knowledge and Understanding'=>'KU'
                                ][$cd] ?? substr($cd, 0, 2);
                                ?>
                                <span class="badge <?= $cd_badge ?> px-2 py-1" title="<?= htmlspecialchars($cd) ?>">
                                    <strong><?= $cd_short ?></strong> - <?= htmlspecialchars($cd) ?>
                                </span>
                            </td>
                            <td>
                                <div class="text-truncate mathjax-render" style="max-width: 300px; font-size: 1.125rem;" title="<?= sanitize($q['question_text']) ?>">
                                    <strong>EN:</strong> <?= fixNepaliInLatex($q['question_text']) ?>
                                </div>
                                <?php if (!empty($q['question_text_np'])): ?>
                                <div class="text-truncate text-muted mathjax-render font-nepali" style="max-width: 300px;" title="<?= sanitize($q['question_text_np']) ?>">
                                    <strong>NP:</strong> <?= fixNepaliInLatex($q['question_text_np']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace fw-bold"><?= $q['marks'] ?></td>
                            <td class="text-end">
                                <?php 
                                $can_modify = $q['can_edit'];
                                if ($can_modify): 
                                ?>
                                <button data-erp-click="learning-questions-action-5" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[(string)(htmlspecialchars(json_encode($q)))]) ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="post" class="d-inline" data-erp-submit="learning-questions-action-6" data-erp-submit-args="<?= schoolPageActionArgs("learning-questions",[(string)($filter_archived?'Restore this question?':'Archive this question? It can be restored.')]) ?>">
                                    <input type="hidden" name="action" value="<?= $filter_archived?'restore':'archive' ?>"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><button class="btn btn-sm btn-outline-danger"><?= $filter_archived?'Restore':'Archive' ?></button>
                                </form>
                                <?php if(!$filter_archived): ?><form method="post" class="d-inline" data-erp-submit="learning-questions-action-7" data-erp-submit-args="<?= schoolPageActionArgs("learning-questions",[(string)($q['visibility']==='private'?'Publish within this school? Shared catalog publishing is available to eligible schools.':'Make this question private?')]) ?>"><input type="hidden" name="action" value="set_visibility"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><input type="hidden" name="visibility" value="<?= $q['visibility']==='private'?'public':'private' ?>"><button class="btn btn-sm btn-outline-primary"><?= $q['visibility']==='private'?'Publish':'Make private' ?></button></form><?php endif; ?>
                                <?php else: ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled title="No edit permission"><i class="fas fa-lock"></i></button>
                                <?php endif; ?>
                                
           </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No questions found in database matching selection.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div> <!-- End card -->
</div> <!-- End #questionsListSection -->

<!-- Add/Edit Question Full-Page Form -->
<div id="questionsFormSection" class="card border-0 shadow-sm p-4 mt-2" style="display:none; min-height: 80vh; background: #ffffff; border-radius: 16px;">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <h3 class="text-primary mb-0 fw-bold" id="formTitle">Add New Question</h3>
        <button type="button" class="btn btn-outline-secondary" data-erp-click="learning-questions-action-8" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>"><i class="fas fa-times"></i> Cancel & Return</button>
    </div>
    
    <form method="POST" action="" class="needs-validation" data-erp-submit="learning-questions-action-9" data-erp-submit-args="<?= schoolPageActionArgs("learning-questions",[]) ?>" novalidate>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="question_id" id="modalQuestionId">
        <input type="hidden" name="catalog_topic_id" id="modalCatalogTopicId">
        <input type="hidden" name="topic_id" id="modalLocalTopicId">
        <div class="mb-3"><label for="modalVisibility" class="form-label">Visibility</label><select name="visibility" id="modalVisibility" class="form-select"><option value="private" <?= !$is_admin?'selected':'' ?>>Private — creator, subject teachers and school administrators</option><option value="public" <?= $is_admin?'selected':'' ?>><?= $is_admin?'Shared catalog — eligible schools':'Public — within this school only' ?></option></select><div class="form-text">Only you can edit or publish your school content.</div></div>
        <div class="row g-3">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0 fw-bold">Class *</label>
                            </div>
                            <select name="class_id" id="modalClass" class="form-select" required data-erp-change="learning-questions-action-10" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                <option value="">-- Choose Class --</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?> (<?= sanitize($c['level']) ?> Level)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0 fw-bold">Subject *</label>
                            </div>
                            <select name="subject_id" id="modalSubject" class="form-select" required data-erp-change="learning-questions-action-11" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                <option value="">-- First Select a Class --</option>
                                <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Filtered to show compulsory and active optional subjects for selected class.</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Chapter / Unit *</label>
                            <select id="modalChapterSelect" class="form-select" data-erp-change="learning-questions-action-12" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                <!-- Populated dynamically -->
                            </select>
                            <input type="hidden" name="chapter" id="modalChapter">
                            <input type="text" id="modalChapterCustom" class="form-control mt-2" placeholder="Type custom chapter..." style="display:none;" data-erp-input="learning-questions-action-13" data-erp-input-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Topic</label>
                            <select id="modalTopicSelect" class="form-select" data-erp-change="learning-questions-action-14" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                <option value="">Choose Topic</option>
                            </select>
                        </div>
                        <div class="col-md-2" id="typeContainer">
                            <label class="form-label fw-bold">Question Type *</label>
                            <div id="typeBadgeContainer" style="display:none;" class="mb-1">
                                <span class="badge bg-primary fs-6 px-3 py-2"><i class="fas fa-check-square me-1"></i> Multiple Choice (MCQ)</span>
                            </div>
                            <select name="type" id="modalType" class="form-select" data-erp-change="learning-questions-action-15" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>" required>
                                <option value="MCQ">Multiple Choice (MCQ)</option>
                                <option value="Very Short">Very Short Answer</option>
                                <option value="Short">Short Answer</option>
                                <option value="Long">Long Answer</option>
                                <option value="Reasoning">Reasoning Question</option>
                                <option value="Difference Between">Difference Between</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Cognitive Level *</label>
                            <select name="cognitive_domain" id="modalCognitiveDomain" class="form-select" required>
                                <option value="Knowledge">Knowledge (K)</option>
                                <option value="Understanding">Understanding (U)</option>
                                <option value="Application">Application (A)</option>
                                <option value="Higher Ability">Higher Ability (HA)</option><option value="Knowledge and Understanding">KU (independent category)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold">Marks Weightage *</label>
                            <input type="number" name="marks" id="modalMarks" class="form-control" value="1" required min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Question Text (English) * <span class="badge bg-light text-secondary border ms-1" style="font-size:0.75rem;">Arial</span></label>
                            <textarea name="question_text" id="modalQuestionText" class="form-control font-english" rows="3" required placeholder="Type English question here..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label font-nepali fw-semibold">Question Text (Nepali) / प्रश्न (नेपाली) <span class="badge bg-light text-secondary border ms-1 font-monospace" style="font-size:0.75rem;">Kokila</span></label>
                            <textarea name="question_text_np" id="modalQuestionTextNp" class="form-control font-nepali" rows="3" placeholder="नेपालीमा प्रश्न यहाँ लेख्नुहोस्..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Short/Long Answer model answer box -->
                    <div id="answerDetails" class="mt-4 border-top pt-3" style="display:none;">
                        <h6 class="text-primary mb-3"><i class="fas fa-key"></i> Model Answer (Optional)</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Expected Answer (English)</label>
                                <textarea name="answer" id="modalAnswer" class="form-control" rows="3" placeholder="Type expected English answer here (optional)..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label font-nepali fw-semibold">Expected Answer (Nepali) / अपेक्षित उत्तर (नेपाली)</label>
                                <textarea name="answer_np" id="modalAnswerNp" class="form-control font-nepali" rows="3" placeholder="नेपालीमा अपेक्षित उत्तर यहाँ लेख्नुहोस् (ऐच्छिक)..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- MCQ Details -->
                    <div id="mcqDetails" class="mt-4 border-top pt-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-primary mb-0 fw-bold"><i class="fas fa-list-ol"></i> Multiple Choice Options & Correct Key</h6>
                            <span class="text-muted small"><i class="fas fa-info-circle"></i> Tick the box between English and Nepali to mark that option as correct.</span>
                        </div>
                        <input type="hidden" id="modalCorrect" value="A">
                        
                        <!-- Option A Container -->
                        <div class="option-container border rounded-3 p-3 mb-3 bg-light" id="optContainer_A">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary fs-6 px-2 py-1">A</span>
                                    <span class="fw-bold text-dark">Option A (English) *</span>
                                </div>

                                <!-- Center Tick Mark Box for Correct Answer -->
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" data-erp-click="learning-questions-action-16" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <input type="radio" name="correct_option" id="correctOpt_A" value="A" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" data-erp-change="learning-questions-action-17" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>" checked>
                                    <label for="correctOpt_A" class="form-check-label fw-bold text-success m-0" style="cursor: pointer; font-size: 0.88rem;">
                                        <i class="fas fa-check-circle"></i> Correct Answer
                                    </label>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-secondary">विकल्प क (नेपाली)</span>
                                    <span class="badge bg-secondary fs-6 px-2 py-1">क</span>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <textarea name="option_a" id="modalOptA" class="form-control mcq-field" rows="2" placeholder="Answer option A (English)"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <textarea name="option_a_np" id="modalOptANp" class="form-control font-nepali" rows="2" placeholder="उत्तर विकल्प क (नेपाली)"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Option B Container -->
                        <div class="option-container border rounded-3 p-3 mb-3 bg-light" id="optContainer_B">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary fs-6 px-2 py-1">B</span>
                                    <span class="fw-bold text-dark">Option B (English) *</span>
                                </div>

                                <!-- Center Tick Mark Box for Correct Answer -->
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" data-erp-click="learning-questions-action-18" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <input type="radio" name="correct_option" id="correctOpt_B" value="B" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" data-erp-change="learning-questions-action-19" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <label for="correctOpt_B" class="form-check-label fw-bold text-success m-0" style="cursor: pointer; font-size: 0.88rem;">
                                        <i class="fas fa-check-circle"></i> Correct Answer
                                    </label>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-secondary">विकल्प ख (नेपाली)</span>
                                    <span class="badge bg-secondary fs-6 px-2 py-1">ख</span>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <textarea name="option_b" id="modalOptB" class="form-control mcq-field" rows="2" placeholder="Answer option B (English)"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <textarea name="option_b_np" id="modalOptBNp" class="form-control font-nepali" rows="2" placeholder="उत्तर विकल्प ख (नेपाली)"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Option C Container -->
                        <div class="option-container border rounded-3 p-3 mb-3 bg-light" id="optContainer_C">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary fs-6 px-2 py-1">C</span>
                                    <span class="fw-bold text-dark">Option C (English) *</span>
                                </div>

                                <!-- Center Tick Mark Box for Correct Answer -->
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" data-erp-click="learning-questions-action-20" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <input type="radio" name="correct_option" id="correctOpt_C" value="C" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" data-erp-change="learning-questions-action-21" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <label for="correctOpt_C" class="form-check-label fw-bold text-success m-0" style="cursor: pointer; font-size: 0.88rem;">
                                        <i class="fas fa-check-circle"></i> Correct Answer
                                    </label>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-secondary">विकल्प ग (नेपाली)</span>
                                    <span class="badge bg-secondary fs-6 px-2 py-1">ग</span>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <textarea name="option_c" id="modalOptC" class="form-control mcq-field" rows="2" placeholder="Answer option C (English)"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <textarea name="option_c_np" id="modalOptCNp" class="form-control font-nepali" rows="2" placeholder="उत्तर विकल्प ग (नेपाली)"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Option D Container -->
                        <div class="option-container border rounded-3 p-3 mb-3 bg-light" id="optContainer_D">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary fs-6 px-2 py-1">D</span>
                                    <span class="fw-bold text-dark">Option D (English) *</span>
                                </div>

                                <!-- Center Tick Mark Box for Correct Answer -->
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" data-erp-click="learning-questions-action-22" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <input type="radio" name="correct_option" id="correctOpt_D" value="D" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" data-erp-change="learning-questions-action-23" data-erp-change-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">
                                    <label for="correctOpt_D" class="form-check-label fw-bold text-success m-0" style="cursor: pointer; font-size: 0.88rem;">
                                        <i class="fas fa-check-circle"></i> Correct Answer
                                    </label>
                                </div>

                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-bold text-secondary">विकल्प घ (नेपाली)</span>
                                    <span class="badge bg-secondary fs-6 px-2 py-1">घ</span>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <textarea name="option_d" id="modalOptD" class="form-control mcq-field" rows="2" placeholder="Answer option D (English)"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <textarea name="option_d_np" id="modalOptDNp" class="form-control font-nepali" rows="2" placeholder="उत्तर विकल्प घ (नेपाली)"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
            
            <div class="mt-4 pt-3 border-top d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary px-4 btn-lg" data-erp-click="learning-questions-action-24" data-erp-click-args="<?= schoolPageActionArgs("learning-questions",[]) ?>">Cancel & Close</button>
                <button type="submit" class="btn btn-primary px-5 btn-lg shadow-sm"><i class="fas fa-save"></i> Save Question</button>
            </div>
        </form>
</div>

<?php include __DIR__.'/../../includes/question_editor.php'; ?>

<!-- Import Questions from Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" aria-labelledby="importExcelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="importExcelModalLabel">
                    <i class="fas fa-file-excel me-2"></i> Import Questions from Excel / CSV
                </h5>
                <button type="button" class="btn-close btn-close-white" data-ui-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action_import_excel" value="1"><label class="form-label">Imported content visibility</label><select name="import_visibility" class="form-select mb-3"><option value="private" <?= !$is_admin?'selected':'' ?>>Private</option><option value="public" <?= $is_admin?'selected':'' ?>><?= $is_admin?'Shared catalog':'Public within this school' ?></option></select>
                <div class="modal-body p-4">
                    <?php $importContextKey='bank';$importContextActor=$contentActor;$importContextClasses=$allowedClasses;$importContextSubjects=$importWriterSubjects;$importContextValues=isset($_POST['action_import_excel'])?$_POST:[];include __DIR__.'/../../includes/question_import_context_form.php'; ?>
                    <fieldset class="mb-4"><legend class="fs-6 fw-bold">Import method</legend>
                        <div class="form-check"><input class="form-check-input" type="radio" name="import_content_mode" id="importNormal" value="normal" <?= ($importContentMode??'normal')==='html'?'':'checked' ?>><label class="form-check-label" for="importNormal">Normal Text</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="import_content_mode" id="importHtml" value="html" <?= ($importContentMode??'normal')==='html'?'checked':'' ?>><label class="form-check-label" for="importHtml">HTML Source (formatted questions and answers)</label></div>
                        <p id="importModeHelp" class="small text-muted mt-2 mb-0" role="status"></p>
                        <small class="text-muted d-block">Class, Subject, Chapter and Topic remain normal text in both methods. Existing class/subject permissions still apply. Use Correct Option A, B, C or D for MCQs.</small>
                    </fieldset>
                    <!-- Download Templates Section -->
                    <div class="card bg-light border-0 shadow-sm mb-4">
                        <div class="card-body">
                            <h6 class="fw-bold text-dark mb-2"><i class="fas fa-download text-primary me-2"></i>Download Sample Excel Templates</h6>
                            <p class="small text-muted mb-3">
                                Download sample template files below to see the required column format. The first row in your Excel spreadsheet is automatically scanned as the column header.
                            </p>
                            <div class="d-flex gap-2 flex-wrap">
                                <a data-import-template href="?action=download_template&type=mcq&format=xlsx" class="btn btn-sm btn-outline-primary fw-bold">
                                    <i class="fas fa-file-excel text-success me-1"></i> Sample MCQs Template (.xlsx)
                                </a>
                                <a data-import-template href="?action=download_template&type=other&format=xlsx" class="btn btn-sm btn-outline-success fw-bold">
                                    <i class="fas fa-file-excel text-success me-1"></i> Sample Other Questions Template (.xlsx)
                                </a>
                                <a data-import-template href="?action=download_template&type=mcq&format=csv" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-file-csv me-1"></i> Sample MCQs (.csv)
                                </a>
                                <a data-import-template href="?action=download_template&type=other&format=csv" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-file-csv me-1"></i> Sample Other (.csv)
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- File Upload Input -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Excel (.xlsx) or CSV (.csv) File *</label>
                        <input type="file" name="excel_file" id="excelFileInput" class="form-control" accept=".xlsx,.csv" required>
                        <div class="form-text">Upload your .xlsx or .csv spreadsheet. First row is scanned as headers. Supports multiple questions import at once.</div>
                    </div>

                    <!-- Guidelines Box -->
                    <div class="alert alert-info py-2 px-3 mb-0 small">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Supported Columns:</strong> <em>Class</em>, <em>Subject</em>, <em>Chapter</em>, <em>Topic</em>, <em>Cognitive Level</em> (Knowledge / Understanding / Application / Higher Ability or K / U / A / HA), <em>Question (English)</em>, <em>Question (Nepali)</em>, <em>Option A/B/C/D</em>, <em>Correct Option</em>, <em>Marks</em>, <em>Model Answer</em>.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-ui-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm">
                        <i class="fas fa-upload me-1"></i> Upload & Import Questions
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php schoolPageModule("external-question-import-context"); ?>
<?php schoolPageModule("learning-questions");schoolPageValue("learning-questions-value-0",(string)(json_encode($canManageCatalog)));
schoolPageValue("learning-questions-value-1",(string)(json_encode(array_column($classes, 'name', 'id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)));
schoolPageValue("learning-questions-value-2",(string)(json_encode(array_column($subjects, 'name_en', 'id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)));
schoolPageValue("learning-questions-value-3",(string)(json_encode($class_subjects_map,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)));
schoolPageValue("learning-questions-value-4",(string)(json_encode(require __DIR__ . '/../../config/cdc_curriculum.php'))); ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
