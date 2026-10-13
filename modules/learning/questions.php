<?php
/**
 * Learning Portal - Question Bank CRUD
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/xlsx_helper.php';
require_once __DIR__.'/../../includes/curriculum_catalog.php';
require_once __DIR__.'/../../includes/question_import.php';
require_once __DIR__.'/../../includes/learning_content.php';

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
        redirect(APP_URL . '/modules/learning/questions.php', 'Question creation permission requested successfully.', 'success');
    }
}

// Handle Request Question View Permission (School Users only)
if (isset($_GET['action']) && $_GET['action'] === 'request_view_permission' && $school_id && !$is_admin) {
    if ($view_permission_status === 'none') {
        $stmt = $db->prepare("UPDATE schools SET question_view_permission = 'requested' WHERE id = ?");
        $stmt->execute([$school_id]);
        Auth::logActivity(Auth::getUserId(), 'Request Question View Permission', 'schools', $school_id);
        redirect(APP_URL . '/modules/learning/questions.php', 'Question view permission requested successfully.', 'success');
    }
}

// School content is never promoted by changing its school ID alone.
if(isset($_GET['make_universal']))denySchoolRequest('School content cannot be transferred to the shared catalog.',403);

// Handle Download Sample Excel Template
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
                                $priority=$sourceHeader?($importContentMode==='html'?2:0):1;
                                if(!isset($colPriority[$targetKey])||$priority>$colPriority[$targetKey]){$colMap[$targetKey]=$idx;$colPriority[$targetKey]=$priority;}
                                break;
                            }
                        }
                    }

                    // Fallback Class and Subject
                    $defaultClassId = (int)($_POST['default_class_id'] ?? 0);
                    $defaultSubjectId = (int)($_POST['default_subject_id'] ?? 0);

                    // Fetch existing classes and subjects for fast resolution
                    $allClasses = $allowedClasses;
                    $subjectCache = []; // Resolve once per permitted class, not once per question.

                    $importedCount = 0;
                    $skippedCount = 0;
                    $skippedReasons = [];
                    $q_school_id = $is_admin ? 0 : $school_id;
                    $db->beginTransaction();
                    $importTransactionOwned=true;

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

                        // Resolve Class
                        $classVal = $getVal('class');
                        $resolvedClassId = 0;
                        if (!empty($classVal)) {
                            $classValClean = strtolower(trim($classVal));
                            $classNum = preg_replace('/[^0-9]/', '', $classValClean);
                            foreach ($allClasses as $c) {
                                if (strtolower($c['name']) === $classValClean || strtolower($c['name_np']) === $classValClean) {
                                    $resolvedClassId = $c['id'];
                                    break;
                                }
                                if ($classNum !== '' && preg_replace('/[^0-9]/', '', $c['name']) === $classNum) {
                                    $resolvedClassId = $c['id'];
                                    break;
                                }
                            }
                        }
                        if (!$resolvedClassId && $classVal === '') {
                            $resolvedClassId = $defaultClassId;
                        }
                        if (!$resolvedClassId) {
                            $skippedCount++;
                            $skippedReasons[] = "Row {$rowNum}: class could not be matched. Supply a valid class or choose a default for blank rows.";
                            continue;
                        }

                        if(!in_array((int)$resolvedClassId,array_map('intval',array_column($allClasses,'id')),true)){
                            $skippedCount++;$skippedReasons[]="Row {$rowNum}: choose an available class in your school.";continue;
                        }
                        $allSubjects=$subjectCache[$resolvedClassId]??=array_values(array_filter(learningReadSubjects($db,$contentActor,(int)$resolvedClassId),static fn($s)=>learningCanCreate($db,$contentActor,(int)$resolvedClassId,(int)$s['id'])));
                        // Resolve Subject
                        $subVal = $getVal('subject');
                        $resolvedSubjectId = 0;
                        if ($subVal !== '') {
                            try{$resolvedSubjectId=questionImportSubjectId($allSubjects,$subVal);}
                            catch(InvalidArgumentException $e){$skippedCount++;$skippedReasons[]="Row {$rowNum}: ".$e->getMessage();continue;}
                        }
                        if (!$resolvedSubjectId && $subVal === '') {
                            $resolvedSubjectId = $defaultSubjectId;
                        }

                        if(!$resolvedClassId||!$resolvedSubjectId){$skippedCount++;$skippedReasons[]="Row {$rowNum}: choose an assigned class subject.";continue;}
                        try{validateTeacherLearningRequest($db,(int)$school_id,'learning','questions.php',['class_id'=>$resolvedClassId,'subject_id'=>$resolvedSubjectId],[]);}catch(InvalidArgumentException $e){$skippedCount++;$skippedReasons[]="Row {$rowNum}: ".$e->getMessage();continue;}
                        // Resolve Chapter
                        $chapterVal = $getVal('chapter');
                        if (empty($chapterVal)) {
                            $chapterVal = 'General';
                        }

                        $topicVal=$getVal('topic');
                        try{$catalog=learningCatalogSelection($db,$contentActor,$resolvedClassId,$resolvedSubjectId,['chapter'=>$chapterVal,'topic'=>$topicVal],$is_admin);}
                        catch(InvalidArgumentException $e){$skippedCount++;$skippedReasons[]="Row {$rowNum}: ".$e->getMessage();continue;}

                        // Resolve Cognitive Level (K, U, A, HA)
                        $cdRaw = $getVal('cognitive_domain');
                        $cdMap = [
                            'k' => 'Knowledge', 'knowledge' => 'Knowledge', 'knowledgek' => 'Knowledge',
                            'u' => 'Understanding', 'understanding' => 'Understanding', 'understandingu' => 'Understanding',
                            'a' => 'Application', 'application' => 'Application', 'applicationa' => 'Application',
                            'ha' => 'Higher Ability', 'higherability' => 'Higher Ability', 'higherabilityha' => 'Higher Ability'
                        ];
                        $cdClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $cdRaw));
                        $cognitiveDomain = $cdMap[$cdClean] ?? 'Knowledge';

                        require_once __DIR__.'/../../includes/learning_content_service.php';
                        learningSaveQuestion($db,$contentActor,$content+[
                            'class_id'=>$resolvedClassId,'subject_id'=>$resolvedSubjectId,'chapter'=>$catalog['chapter'],
                            'topic_id'=>$catalog['topic_id'],'catalog_chapter_id'=>$catalog['catalog_chapter_id'],'catalog_topic_id'=>$catalog['catalog_topic_id'],
                            'cognitive_domain'=>$cognitiveDomain,'visibility'=>learningVisibility($_POST['import_visibility']??($is_admin?'public':'private'))
                        ]);
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
                        redirect(APP_URL.'/modules/learning/questions.php?category=all','Successfully imported '.$importedCount.' questions into the Question Bank.'.$skipDetails,$skippedCount>0?'warning':'success');
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

<!-- TinyMCE CDN for MS Word formatting and Full-screen editor -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>

<!-- MathJax Configuration & Script for LaTeX Equation Rendering -->
<script>
window.MathJax = {
    tex: {
        inlineMath: [['\\(', '\\)'],['$','$']],
        displayMath: [['\\[', '\\]'],['$$','$$']],
        processEscapes: true
    },
    startup: {
        ready: () => {
            MathJax.startup.defaultReady();
            MathJax.startup.promise.then(() => {
                if (typeof fixDevanagariInMathJax === 'function') {
                    fixDevanagariInMathJax(document);
                }
            });
        }
    }
};
</script>
<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

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
        <a href="<?= APP_URL ?>/modules/learning/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Portal
        </a>
        <?php if ($canCreateContent): ?>
        <button class="btn btn-primary fw-bold shadow-sm" onclick="showAddForm('MCQ')">
            <i class="fas fa-list-ol me-1"></i> Add Multiple Choice Questions
        </button>
        <button class="btn btn-success fw-bold shadow-sm" onclick="showAddForm('other')">
            <i class="fas fa-pen-nib me-1"></i> Add Questions
        </button>
        <button class="btn btn-info text-white fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#importExcelModal">
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
        <a class="nav-link fw-bold <?= $filter_category === 'all' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php?category=all<?= $filter_class ? '&class='.$filter_class : '' ?><?= $filter_subject ? '&subject='.$filter_subject : '' ?><?= $filter_cognitive ? '&cognitive_domain='.$filter_cognitive : '' ?>">
            <i class="fas fa-layer-group me-1"></i> All Questions
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-bold <?= $filter_category === 'mcq' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php?category=mcq<?= $filter_class ? '&class='.$filter_class : '' ?><?= $filter_subject ? '&subject='.$filter_subject : '' ?><?= $filter_cognitive ? '&cognitive_domain='.$filter_cognitive : '' ?>">
            <i class="fas fa-list-ol me-1"></i> Multiple Choice Questions (MCQ)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link fw-bold <?= $filter_category === 'other' ? 'active' : '' ?>" href="<?= APP_URL ?>/modules/learning/questions.php?category=other<?= $filter_class ? '&class='.$filter_class : '' ?><?= $filter_subject ? '&subject='.$filter_subject : '' ?><?= $filter_cognitive ? '&cognitive_domain='.$filter_cognitive : '' ?>">
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
                <select name="class" class="form-select" onchange="this.form.elements.subject.value='';this.form.submit()">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_class == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Filter Subject</label>
                <select name="subject" class="form-select" <?= !$filter_class?'disabled':'' ?> onchange="this.form.submit()">
                    <option value=""><?= $filter_class?'All class subjects':'Choose a class first' ?></option>
                    <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filter_subject == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Cognitive Level</label>
                <select name="cognitive_domain" class="form-select" onchange="this.form.submit()">
                    <option value="">All Levels</option>
                    <option value="Knowledge" <?= $filter_cognitive === 'Knowledge' ? 'selected' : '' ?>>Knowledge (K)</option>
                    <option value="Understanding" <?= $filter_cognitive === 'Understanding' ? 'selected' : '' ?>>Understanding (U)</option>
                    <option value="Application" <?= $filter_cognitive === 'Application' ? 'selected' : '' ?>>Application (A)</option>
                    <option value="Higher Ability" <?= $filter_cognitive === 'Higher Ability' ? 'selected' : '' ?>>Higher Ability (HA)</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="<?= APP_URL ?>/modules/learning/questions.php" class="btn btn-secondary w-100">Reset</a>
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
                                    'Higher Ability' => 'HA'
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
                                <button onclick="editQuestion(<?= htmlspecialchars(json_encode($q)) ?>)" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('<?= $filter_archived?'Restore this question?':'Archive this question? It can be restored.' ?>')">
                                    <input type="hidden" name="action" value="<?= $filter_archived?'restore':'archive' ?>"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><button class="btn btn-sm btn-outline-danger"><?= $filter_archived?'Restore':'Archive' ?></button>
                                </form>
                                <?php if(!$filter_archived): ?><form method="post" class="d-inline" onsubmit="return confirm('<?= $q['visibility']==='private'?'Publish within this school? Shared catalog publishing is available to eligible schools.':'Make this question private?' ?>')"><input type="hidden" name="action" value="set_visibility"><input type="hidden" name="question_id" value="<?= $q['id'] ?>"><input type="hidden" name="visibility" value="<?= $q['visibility']==='private'?'public':'private' ?>"><button class="btn btn-sm btn-outline-primary"><?= $q['visibility']==='private'?'Publish':'Make private' ?></button></form><?php endif; ?>
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
        <button type="button" class="btn btn-outline-secondary" onclick="hideForm()"><i class="fas fa-times"></i> Cancel & Return</button>
    </div>
    
    <form method="POST" action="" class="needs-validation" onsubmit="tinymce.triggerSave()" novalidate>
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
                            <select name="class_id" id="modalClass" class="form-select" required onchange="onModalClassChange()">
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
                            <select name="subject_id" id="modalSubject" class="form-select" required onchange="updateChaptersDropdown('')">
                                <option value="">-- First Select a Class --</option>
                                <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Filtered to show compulsory and active optional subjects for selected class.</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Chapter / Unit *</label>
                            <select id="modalChapterSelect" class="form-select" onchange="onChapterSelectChange()">
                                <!-- Populated dynamically -->
                            </select>
                            <input type="hidden" name="chapter" id="modalChapter">
                            <input type="text" id="modalChapterCustom" class="form-control mt-2" placeholder="Type custom chapter..." style="display:none;" oninput="updateChapterHidden()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Topic</label>
                            <select id="modalTopicSelect" class="form-select" onchange="syncCatalogTopic()">
                                <option value="">Choose Topic</option>
                            </select>
                        </div>
                        <div class="col-md-2" id="typeContainer">
                            <label class="form-label fw-bold">Question Type *</label>
                            <div id="typeBadgeContainer" style="display:none;" class="mb-1">
                                <span class="badge bg-primary fs-6 px-3 py-2"><i class="fas fa-check-square me-1"></i> Multiple Choice (MCQ)</span>
                            </div>
                            <select name="type" id="modalType" class="form-select" onchange="toggleMCQFields()" required>
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
                                <option value="Higher Ability">Higher Ability (HA)</option>
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
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" onclick="selectCorrectOption('A')">
                                    <input type="radio" name="correct_option" id="correctOpt_A" value="A" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" onchange="onCorrectOptionChange('A')" checked>
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
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" onclick="selectCorrectOption('B')">
                                    <input type="radio" name="correct_option" id="correctOpt_B" value="B" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" onchange="onCorrectOptionChange('B')">
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
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" onclick="selectCorrectOption('C')">
                                    <input type="radio" name="correct_option" id="correctOpt_C" value="C" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" onchange="onCorrectOptionChange('C')">
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
                                <div class="correct-tick-badge px-3 py-1 bg-white border rounded-pill shadow-sm d-flex align-items-center gap-2" onclick="selectCorrectOption('D')">
                                    <input type="radio" name="correct_option" id="correctOpt_D" value="D" class="form-check-input m-0 correct-radio" style="cursor: pointer; width: 1.15em; height: 1.15em;" onchange="onCorrectOptionChange('D')">
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
                <button type="button" class="btn btn-secondary px-4 btn-lg" onclick="hideForm()">Cancel & Close</button>
                <button type="submit" class="btn btn-primary px-5 btn-lg shadow-sm"><i class="fas fa-save"></i> Save Question</button>
            </div>
        </form>
</div>

<!-- Mathematics in TeX Dialog Modal -->
<div class="modal fade" id="texModal" tabindex="-1" aria-hidden="true" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-square-root-alt text-primary"></i> Mathematics in TeX</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold text-secondary">Write your TeX here</label>
                    <textarea id="texInput" class="form-control font-monospace" rows="4" placeholder="e.g. x = {-b \pm \sqrt{b^2-4ac} \over 2a}"></textarea>
                </div>
                <div class="mb-3 text-end">
                    <a href="https://en.wikibooks.org/wiki/LaTeX/Mathematics" target="_blank" class="text-decoration-none small text-primary fw-bold">
                        TeX documentation <i class="fas fa-external-link-alt small"></i>
                    </a>
                </div>
                <div class="mb-3 p-3 bg-light rounded border text-center" style="min-height: 100px;">
                    <div class="text-muted small mb-2 border-bottom pb-1 fw-bold">Live MathJax Preview</div>
                    <div id="texPreview" style="font-size: 1.3rem; overflow-x: auto; padding: 10px 0; color: #2c3e50;"></div>
                </div>
                <div>
                    <label class="form-label fw-bold text-secondary">Display Mode</label>
                    <select id="texDisplayMode" class="form-select">
                        <option value="inline">Inline Math: \( ... \)</option>
                        <option value="block">Block Math (Centered): \[ ... \]</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success px-4 fw-bold" id="btnInsertTex">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Import Questions from Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" aria-labelledby="importExcelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="importExcelModalLabel">
                    <i class="fas fa-file-excel me-2"></i> Import Questions from Excel / CSV
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action_import_excel" value="1"><label class="form-label">Imported content visibility</label><select name="import_visibility" class="form-select mb-3"><option value="private" <?= !$is_admin?'selected':'' ?>>Private</option><option value="public" <?= $is_admin?'selected':'' ?>><?= $is_admin?'Shared catalog':'Public within this school' ?></option></select>
                <div class="modal-body p-4">
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

                    <!-- Fallback Defaults -->
                    <div class="row g-3 p-3 bg-light rounded border mb-3">
                        <div class="col-12">
                            <h6 class="fw-bold text-secondary mb-1"><i class="fas fa-sliders-h me-1"></i> Optional Fallback Defaults</h6>
                            <small class="text-muted">Used automatically if any row in your Excel file leaves Class or Subject empty.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Default Class</label>
                            <select name="default_class_id" id="importClass" class="form-select form-select-sm" onchange="updateImportSubjects()">
                                <option value="">-- Auto-detect from spreadsheet --</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Default Subject</label>
                            <select name="default_subject_id" id="importSubject" class="form-select form-select-sm">
                                <option value="">-- Auto-detect from spreadsheet --</option>
                                <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Guidelines Box -->
                    <div class="alert alert-info py-2 px-3 mb-0 small">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Supported Columns:</strong> <em>Class</em>, <em>Subject</em>, <em>Chapter</em>, <em>Topic</em>, <em>Cognitive Level</em> (Knowledge / Understanding / Application / Higher Ability or K / U / A / HA), <em>Question (English)</em>, <em>Question (Nepali)</em>, <em>Option A/B/C/D</em>, <em>Correct Option</em>, <em>Marks</em>, <em>Model Answer</em>.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm">
                        <i class="fas fa-upload me-1"></i> Upload & Import Questions
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const canManageCatalog=<?= json_encode($canManageCatalog) ?>;
let activeEditorId = '';
let texModalObj;
let activeTexSpan = null;
const classesMap = <?= json_encode(array_column($classes, 'name', 'id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const subjectsMap = <?= json_encode(array_column($subjects, 'name_en', 'id'),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const classSubjectsMap = <?= json_encode($class_subjects_map,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const cdcCurriculum = <?= json_encode(require __DIR__ . '/../../config/cdc_curriculum.php') ?>;

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function fixNepaliInLatex(tex) {
    if (!tex || typeof tex !== 'string') return tex;
    if (!/[\u0900-\u097F]/.test(tex)) return tex;

    return tex.replace(/(\\text\{[^{}]*\}|\\mbox\{[^{}]*\}|\\mathrm\{[^{}]*\}|\\mathbf\{[^{}]*\}|\\mathit\{[^{}]*\})|(\\[a-zA-Z]+)|([\u0900-\u097F]+(?:[ \t\u00A0]+[\u0900-\u097F]+)*)/g, function(match, textCmd, otherCmd, nepaliText) {
        if (textCmd) return textCmd;
        if (otherCmd) return otherCmd;
        if (nepaliText) return '\\text{' + nepaliText + '}';
        return match;
    });
}

function convertLatexToSpan(content) {
    if (!content) return content;
    const root=document.createElement('div');root.innerHTML=content;
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),nodes=[];
    while(walker.nextNode())if(!walker.currentNode.parentElement.closest('.math-tex,script,style'))nodes.push(walker.currentNode);
    // Only text nodes are converted: never HTML attributes or existing widgets.
    // Every supported delimiter becomes a portable widget that saves back to TeX.
    for(const node of nodes){
        const pattern=/\\\(([\s\S]*?)\\\)|\\\[([\s\S]*?)\\\]|\$\$([\s\S]*?)\$\$|(?<!\\)\$(?!\$)([^$\r\n]+)(?<!\\)\$/g;
        const fragment=document.createDocumentFragment();let end=0,matched=false;
        for(const match of node.textContent.matchAll(pattern)){
            matched=true;fragment.append(document.createTextNode(node.textContent.slice(end,match.index)));
            const block=match[2]!==undefined||match[3]!==undefined,tex=fixNepaliInLatex(match[1]??match[2]??match[3]??match[4]);
            const span=document.createElement('span');span.className='math-tex';span.setAttribute('data-tex',tex);span.setAttribute('data-mode',block?'block':'inline');
            span.textContent=(block?'\\[':'\\(')+tex+(block?'\\]':'\\)');fragment.append(span);end=match.index+match[0].length;
        }
        if(matched){fragment.append(document.createTextNode(node.textContent.slice(end)));node.replaceWith(fragment);}
    }
    return root.innerHTML;
}

$(document).ready(function() {
    // Initialize TeX Modal
    texModalObj = new bootstrap.Modal(document.getElementById('texModal'));
    
    // Clear activeTexSpan when modal hidden
    document.getElementById('texModal').addEventListener('hidden.bs.modal', function () {
        activeTexSpan = null;
    });
    
    // Event listeners for live MathJax TeX preview
    document.getElementById('texInput').addEventListener('input', updateTexPreview);
    document.getElementById('texDisplayMode').addEventListener('change', updateTexPreview);
    document.getElementById('btnInsertTex').addEventListener('click', insertTexIntoEditor);
    
    // Initialize TinyMCE editors
    if (window.tinymce) {
        tinymce.init({
            selector: '#modalQuestionText, #modalQuestionTextNp, #modalAnswer, #modalAnswerNp, #modalOptA, #modalOptB, #modalOptC, #modalOptD, #modalOptANp, #modalOptBNp, #modalOptCNp, #modalOptDNp',
            height: 250,
            font_family_formats: 'Arial=arial,helvetica,sans-serif; Kokila=Kokila,Noto Sans Devanagari,Kalimati,sans-serif; Courier New=courier new,courier; Georgia=georgia,palatino; Tahoma=tahoma,arial,helvetica,sans-serif; Times New Roman=times new roman,times; Trebuchet MS=trebuchet ms,geneva; Verdana=verdana,geneva',
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | math_tex | fullscreen code',
            branding: false,
            promotion: false,
            // Styling inside editor iframe to support Arial for English, Kokila for Nepali, and locked TeX equations
            content_style: `
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila'), url('${window.APP_URL}/assets/fonts/kokila.ttf') format('truetype');
                    font-weight: normal;
                    font-style: normal;
                }
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila Bold'), url('${window.APP_URL}/assets/fonts/kokilab.ttf') format('truetype');
                    font-weight: bold;
                    font-style: normal;
                }
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila Italic'), url('${window.APP_URL}/assets/fonts/kokilai.ttf') format('truetype');
                    font-weight: normal;
                    font-style: italic;
                }
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila Bold Italic'), url('${window.APP_URL}/assets/fonts/kokilabi.ttf') format('truetype');
                    font-weight: bold;
                    font-style: italic;
                }
                body {
                    font-family: Arial, Helvetica, sans-serif;
                    font-size: 18px;
                }
                body.nepali-editor {
                    font-family: 'Kokila', 'Noto Sans Devanagari', 'Kalimati', sans-serif !important;
                    font-size: 20pt !important;
                    line-height: 1.55;
                }
                body.english-editor {
                    font-family: Arial, Helvetica, sans-serif !important;
                    font-size: 18px !important;
                }
                span.math-tex {
                    background: transparent !important;
                    border: none !important;
                    cursor: pointer !important;
                    display: inline-block !important;
                    user-select: none !important;
                    margin: 0 2px !important;
                }
                span.math-tex:hover, span.math-tex:focus, span.math-tex[data-mce-selected] {
                    outline: 1px dashed #0d6efd !important;
                    background: rgba(13, 110, 253, 0.05) !important;
                    border-radius: 2px !important;
                }
                mjx-utext, mjx-mtext, .MathJax mtext, mjx-c {
                    font-family: 'Kokila', 'Noto Sans Devanagari', 'Segoe UI', 'Kalimati', sans-serif !important;
                    font-style: normal !important;
                }
            `,
            setup: function (editor) {
                // When content is loaded, wrap LaTeX with span so it can render inside iframe
                editor.on('BeforeSetContent', function (e) {
                    e.content = convertLatexToSpan(e.content);
                });
                
                // When retrieving content, strip MathJax HTML markup and restore clean LaTeX tags
                editor.on('PostProcess', function (e) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = e.content;
                    const spans = tempDiv.querySelectorAll('span.math-tex');
                    spans.forEach(span => {
                        const tex = span.getAttribute('data-tex');
                        const mode = span.getAttribute('data-mode') || 'inline';
                        const replacement = (mode === 'inline') ? '\\(' + tex + '\\)' : '\\[' + tex + '\\]';
                        span.replaceWith(document.createTextNode(replacement));
                    });
                    e.content = tempDiv.innerHTML;
                });
                
                // Load MathJax inside the editor iframe dynamically and configure English/Nepali base font
                editor.on('init', function () {
                    const doc = editor.getDoc();
                    const win = editor.getWin();
                    
                    // Set default font: English -> Arial, Nepali -> Kokila
                    if (editor.id.endsWith('Np')) {
                        doc.body.classList.add('nepali-editor');
                        doc.body.style.fontFamily = "'Kokila', 'Noto Sans Devanagari', 'Kalimati', sans-serif";
                        doc.body.style.fontSize = "20pt";
                    } else {
                        doc.body.classList.add('english-editor');
                        doc.body.style.fontFamily = "Arial, Helvetica, sans-serif";
                        doc.body.style.fontSize = "18px";
                    }

                    // Repair the iframe after typesetting (below and on SetContent).
                    // A parent-window MutationObserver can reject TinyMCE's body;
                    // editor events already cover all content changes here.
                    
                    const configScript = doc.createElement('script');
                    configScript.text = `
                        window.MathJax = {
                            tex: {
                                inlineMath: [['\\\\(', '\\\\)'], ['$', '$']],
                                displayMath: [['\\\\[', '\\\\]'], ['$$', '$$']],
                                processEscapes: true
                            },
                            startup: {
                                typeset: false
                            }
                        };
                    `;
                    doc.head.appendChild(configScript);
                    
                    const script = doc.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js';
                    script.async = true;
                    script.onload = function() {
                        if (win.MathJax && win.MathJax.typesetPromise) {
                            win.MathJax.typesetPromise().then(() => {
                                if (typeof fixDevanagariInMathJax === 'function') {
                                    fixDevanagariInMathJax(doc);
                                }
                            });
                        }
                    };
                    doc.head.appendChild(script);
                });
                
                // Imported content must also render when reopened, not just after typing.
                function typesetEditorMath() {
                    const win = editor.getWin();
                    if (win.MathJax && win.MathJax.typesetPromise) {
                        (win.MathJax.startup?.promise || Promise.resolve()).then(() => win.MathJax.typesetPromise()).then(() => {
                            if (typeof fixDevanagariInMathJax === 'function') {
                                fixDevanagariInMathJax(editor.getDoc());
                            }
                        }).catch(() => {});
                    }
                }
                editor.on('SetContent', typesetEditorMath);
                editor.on('change keyup', function () {
                    editor.save(); // Sync back to textarea
                    typesetEditorMath();
                });

                // Double click formula widget to trigger TeX editing modal
                editor.on('dblclick', function (e) {
                    const target = e.target.closest('span.math-tex');
                    if (target) {
                        e.preventDefault();
                        e.stopPropagation();
                        openTexModal(editor.id, target);
                    }
                });

                // Add TeX modal trigger button
                editor.ui.registry.addButton('math_tex', {
                    text: 'TeX',
                    tooltip: 'Insert Mathematics TeX',
                    onAction: function () {
                        openTexModal(editor.id);
                    }
                });
            }
        });
    }
});

function updateImportSubjects(){
 if(!document.getElementById('importClass')||!document.getElementById('importSubject'))return;
 const classId=document.getElementById('importClass').value,select=document.getElementById('importSubject');
 select.replaceChildren(new Option(classId?'Choose mapped subject':'Choose class first',''));
 classSubjectsMap.filter(s=>String(s.class_id)===classId).forEach(s=>select.add(new Option(s.name_en,s.subject_id)));
}
document.addEventListener('DOMContentLoaded',updateImportSubjects);
function updateImportContentMode(){
 const mode=document.querySelector('input[name="import_content_mode"]:checked')?.value||'normal';
 document.querySelectorAll('[data-import-template]').forEach(link=>{
  const url=new URL(link.href,window.location.href);url.searchParams.set('content_mode',mode);link.href=url.href;
 });
 const help=document.getElementById('importModeHelp');
 if(help)help.textContent=mode==='html'
  ?'HTML Source: supported HTML formatting and LaTeX in questions, options and model answers are saved. Scripts and unsafe attributes are removed. Use \\( ... \\), \\[ ... \\], $ ... $ or $$ ... $$ for maths. The templates below contain HTML examples.'
  :'Normal Text: questions, options and model answers are imported as plain text with line breaks. LaTeX is supported; HTML tags are displayed as text. The templates below contain normal-text examples.';
}
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('input[name="import_content_mode"]').forEach(radio=>radio.addEventListener('change',updateImportContentMode));
 updateImportContentMode();
});
function onModalClassChange(selectedSubjectId = '', selectedChapterValue = '', selectedTopicId = '') {
    const classId = document.getElementById('modalClass').value;
    const subjectSelect = document.getElementById('modalSubject');
    subjectSelect.innerHTML = '';
    
    if (!classId) {
        subjectSelect.innerHTML = '<option value="">-- First Select a Class --</option>';
        updateChaptersDropdown('');
        return;
    }
    
    // Filter active class-subject mappings
    const available = classSubjectsMap.filter(cs => cs.class_id == classId);
    if (available.length > 0) {
        subjectSelect.innerHTML = '<option value="">-- Choose Subject --</option>';
        available.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.subject_id;
            opt.textContent = `[${s.code || 'SUB'}] ${s.name_en} (${s.name_np || ''})${s.is_optional == 1 ? ' [Optional]' : ''}`;
            subjectSelect.appendChild(opt);
        });
    } else {
        subjectSelect.innerHTML = '<option value="">No assigned subjects in this class</option>';
    }
    
    if (selectedSubjectId) {
        subjectSelect.value = selectedSubjectId;
    }
    
    updateChaptersDropdown(selectedChapterValue, selectedTopicId);
}

function showAddForm(category = 'MCQ') {
    document.getElementById('modalVisibility').value=canManageCatalog?'public':'private';
    document.getElementById('modalCatalogTopicId').value='';document.getElementById('modalLocalTopicId').value='';
    document.getElementById('questionsListSection').style.display = 'none';
    document.getElementById('questionsFormSection').style.display = 'block';
    
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalQuestionId').value = '';
    
    const typeSelect = document.getElementById('modalType');
    const typeBadge = document.getElementById('typeBadgeContainer');
    
    if (category === 'MCQ') {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-list-ol text-primary me-2"></i> Add Multiple Choice Question (MCQ)';
        typeSelect.value = 'MCQ';
        typeSelect.style.display = 'none';
        if (typeBadge) typeBadge.style.display = 'block';
    } else {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-pen-nib text-success me-2"></i> Add Question (Subjective / Theory)';
        typeSelect.style.display = 'block';
        if (typeBadge) typeBadge.style.display = 'none';
        
        // Default to Short answer and hide MCQ option
        if (typeSelect.value === 'MCQ') {
            typeSelect.value = 'Short';
        }
        for (let i = 0; i < typeSelect.options.length; i++) {
            if (typeSelect.options[i].value === 'MCQ') {
                typeSelect.options[i].style.display = 'none';
            } else {
                typeSelect.options[i].style.display = '';
            }
        }
    }
    
    document.getElementById('modalClass').value = '';
    onModalClassChange();
    document.getElementById('modalChapter').value = '';
    document.getElementById('modalTopicSelect').innerHTML = '<option value="">Choose Topic</option>';
    document.getElementById('modalMarks').value = (category === 'MCQ') ? '1' : '5';
    if (document.getElementById('modalCognitiveDomain')) {
        document.getElementById('modalCognitiveDomain').value = 'Knowledge';
    }
    
    // Clear editors safely
    if (window.tinymce && tinymce.get('modalQuestionText')) {
        tinymce.get('modalQuestionText').setContent('');
    } else {
        document.getElementById('modalQuestionText').value = '';
    }
    if (window.tinymce && tinymce.get('modalQuestionTextNp')) {
        tinymce.get('modalQuestionTextNp').setContent('');
    } else {
        document.getElementById('modalQuestionTextNp').value = '';
    }
    if (window.tinymce && tinymce.get('modalAnswer')) {
        tinymce.get('modalAnswer').setContent('');
    } else {
        document.getElementById('modalAnswer').value = '';
    }
    if (window.tinymce && tinymce.get('modalAnswerNp')) {
        tinymce.get('modalAnswerNp').setContent('');
    } else {
        document.getElementById('modalAnswerNp').value = '';
    }
    // Clear options safely using helper
    setOptionVal('modalOptA', '');
    setOptionVal('modalOptANp', '');
    setOptionVal('modalOptB', '');
    setOptionVal('modalOptBNp', '');
    setOptionVal('modalOptC', '');
    setOptionVal('modalOptCNp', '');
    setOptionVal('modalOptD', '');
    setOptionVal('modalOptDNp', '');
    selectCorrectOption('A');
    
    toggleMCQFields();
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function selectCorrectOption(opt) {
    opt = (opt || 'A').toUpperCase();
    const radio = document.getElementById('correctOpt_' + opt);
    if (radio) {
        radio.checked = true;
    }
    onCorrectOptionChange(opt);
}

function onCorrectOptionChange(opt) {
    opt = (opt || 'A').toUpperCase();
    ['A', 'B', 'C', 'D'].forEach(o => {
        const container = document.getElementById('optContainer_' + o);
        const radio = document.getElementById('correctOpt_' + o);
        const badge = container ? container.querySelector('.correct-tick-badge') : null;
        
        if (container && radio) {
            if (o === opt) {
                radio.checked = true;
                container.style.borderColor = '#198754';
                container.style.backgroundColor = 'rgba(25, 135, 84, 0.05)';
                container.classList.add('shadow');
                if (badge) {
                    badge.classList.remove('bg-white');
                    badge.classList.add('bg-success', 'border-success');
                    const label = badge.querySelector('label');
                    if (label) label.className = 'form-check-label fw-bold text-white m-0';
                }
            } else {
                radio.checked = false;
                container.style.borderColor = '#dee2e6';
                container.style.backgroundColor = '#f8f9fa';
                container.classList.remove('shadow');
                if (badge) {
                    badge.classList.remove('bg-success', 'border-success');
                    badge.classList.add('bg-white');
                    const label = badge.querySelector('label');
                    if (label) label.className = 'form-check-label fw-bold text-success m-0';
                }
            }
        }
    });
    
    const hidden = document.getElementById('modalCorrect');
    if (hidden) hidden.value = opt;
}

function setOptionVal(id, val) {
    if (window.tinymce && tinymce.get(id)) {
        tinymce.get(id).setContent(val || '');
    } else {
        const el = document.getElementById(id);
        if (el) el.value = val || '';
    }
}

function editQuestion(q) {
    document.getElementById('modalVisibility').value=q.visibility;
    document.getElementById('modalCatalogTopicId').value=q.catalog_topic_id||'';
    document.getElementById('questionsListSection').style.display = 'none';
    document.getElementById('questionsFormSection').style.display = 'block';
    
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalQuestionId').value = q.id;
    
    const typeSelect = document.getElementById('modalType');
    const typeBadge = document.getElementById('typeBadgeContainer');
    
    // Ensure all options are visible during edit
    for (let i = 0; i < typeSelect.options.length; i++) {
        typeSelect.options[i].style.display = '';
    }
    
    if (q.type === 'MCQ') {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-list-ol text-primary me-2"></i> Edit Multiple Choice Question (MCQ)';
        typeSelect.style.display = 'none';
        if (typeBadge) typeBadge.style.display = 'block';
    } else {
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit text-warning me-2"></i> Edit Question Details';
        typeSelect.style.display = 'block';
        if (typeBadge) typeBadge.style.display = 'none';
    }
    
    document.getElementById('modalClass').value = q.class_id;
    onModalClassChange(q.subject_id, q.chapter, q.topic_id||q.catalog_topic_id);
    document.getElementById('modalType').value = q.type;
    document.getElementById('modalMarks').value = q.marks;
    if (document.getElementById('modalCognitiveDomain')) {
        document.getElementById('modalCognitiveDomain').value = q.cognitive_domain || 'Knowledge';
    }
    
    // Populate editors safely
    setOptionVal('modalQuestionText', q.question_text);
    setOptionVal('modalQuestionTextNp', q.question_text_np);
    setOptionVal('modalAnswer', q.answer);
    setOptionVal('modalAnswerNp', q.answer_np);
    
    if (q.type === 'MCQ') {
        const opts = JSON.parse(q.options || '{}');
        setOptionVal('modalOptA', opts.A || '');
        setOptionVal('modalOptB', opts.B || '');
        setOptionVal('modalOptC', opts.C || '');
        setOptionVal('modalOptD', opts.D || '');
        
        const optsNp = JSON.parse(q.options_np || '{}');
        setOptionVal('modalOptANp', optsNp.A || '');
        setOptionVal('modalOptBNp', optsNp.B || '');
        setOptionVal('modalOptCNp', optsNp.C || '');
        setOptionVal('modalOptDNp', optsNp.D || '');
        
        selectCorrectOption(q.correct_option || 'A');
    } else {
        setOptionVal('modalOptA', '');
        setOptionVal('modalOptANp', '');
        setOptionVal('modalOptB', '');
        setOptionVal('modalOptBNp', '');
        setOptionVal('modalOptC', '');
        setOptionVal('modalOptCNp', '');
        setOptionVal('modalOptD', '');
        setOptionVal('modalOptDNp', '');
    }
    
    toggleMCQFields();
    // Type-change defaults apply to new questions, not an existing saved weight.
    document.getElementById('modalMarks').value = q.marks;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function hideForm() {
    document.getElementById('questionsListSection').style.display = 'block';
    document.getElementById('questionsFormSection').style.display = 'none';
}

let chapterDropdownRequestVersion = 0;
let topicDropdownRequestVersion = 0;

function updateChaptersDropdown(selectedChapterValue = '', selectedTopicId = '') {
    const requestVersion=++chapterDropdownRequestVersion;
    ++topicDropdownRequestVersion;
    document.getElementById('modalTopicSelect').innerHTML='<option value="">Choose Topic</option>';
    const classId=document.getElementById('modalClass').value;
    const subjectId=document.getElementById('modalSubject').value;
    const select=document.getElementById('modalChapterSelect');
    const customInput=document.getElementById('modalChapterCustom');
    const hiddenInput=document.getElementById('modalChapter');
    select.innerHTML='';customInput.style.display='none';hiddenInput.value='';
    const addCustom=()=>{if(!canManageCatalog)return;const opt=document.createElement('option');opt.value='__custom__';opt.textContent='Other / Custom Chapter';select.appendChild(opt);};
    if(!classId||!subjectId){updateTopicsDropdown(0,'');return;}
    $.ajax({url:window.location.pathname,data:{action_get_chapters:1,class_id:classId,subject_id:subjectId},dataType:'json',success:function(chapters){
        if(requestVersion!==chapterDropdownRequestVersion)return;
        select.innerHTML='';
        for(const ch of chapters||[]){const opt=document.createElement('option');opt.value=ch.name;opt.textContent=ch.name;opt.setAttribute('data-id',ch.id);select.appendChild(opt);}
        addCustom();
        const matched=(chapters||[]).find(c=>c.name===selectedChapterValue);
        if(selectedChapterValue&&matched){select.value=matched.name;}
        else if(selectedChapterValue&&canManageCatalog){select.value='__custom__';customInput.style.display='block';customInput.value=selectedChapterValue;}
        else if(chapters?.length){select.value=chapters[0].name;}
        else if(canManageCatalog){select.value='__custom__';customInput.style.display='block';customInput.value=selectedChapterValue;}
        hiddenInput.value=select.value==='__custom__'?customInput.value:select.value;
        const chosen=(chapters||[]).find(c=>c.name===select.value);
        updateTopicsDropdown(chosen?.id||0,hiddenInput.value,selectedTopicId);
    },error:function(){if(requestVersion!==chapterDropdownRequestVersion)return;select.innerHTML='';addCustom();hiddenInput.value='';}});
}

function updateTopicsDropdown(chapterId, chapterName, selectedTopicId = '') {
    const requestVersion = ++topicDropdownRequestVersion;
    const classId = document.getElementById('modalClass').value;
    const subjectId = document.getElementById('modalSubject').value;
    const select = document.getElementById('modalTopicSelect');
    
    select.innerHTML = '<option value="">Choose Topic</option>';
    if (!classId || !subjectId || (!chapterId && !chapterName)) return;
    
    $.ajax({
        url: window.location.pathname,
        data: {
            action_get_topics: 1,
            chapter_id: chapterId,
            chapter_name: chapterName,
            class_id: classId,
            subject_id: subjectId
        },
        dataType: 'json',
        success: function(topics) {
            if (requestVersion !== topicDropdownRequestVersion) return;
            select.innerHTML = '<option value="">Choose Topic</option>';
            if (Array.isArray(topics) && topics.length > 0) {
                const choices = new Map();
                topics.forEach(t => {
                    const key = String(t.name).trim().replace(/\s+/g, ' ').toLowerCase();
                    if (!key) return;
                    const aliases = [t.id, ...(Array.isArray(t.alias_ids) ? t.alias_ids : [])].map(String);
                    const isSelected = selectedTopicId !== '' && aliases.includes(String(selectedTopicId));
                    if (!choices.has(key)) choices.set(key, {topic: t, value: t.id});
                    if (isSelected) choices.get(key).value = selectedTopicId;
                });
                choices.forEach(({topic, value}) => {
                    const opt = document.createElement('option');
                    opt.value = value;
                    opt.textContent = topic.name;
                    const alias=(topic.aliases||[]).find(a=>String(a.id)===String(value));
                    opt.setAttribute('data-scope',alias?.scope||topic.scope||'local');
                    select.appendChild(opt);
                });
                if (selectedTopicId) {
                    select.value = selectedTopicId;
                }
            }
            syncCatalogTopic();
        }
    });
}

function syncCatalogTopic(){
    const select=document.getElementById('modalTopicSelect');
    const global=document.getElementById('modalCatalogTopicId');
    const local=document.getElementById('modalLocalTopicId');
    if(!global||!local)return;
    const scope=select.options[select.selectedIndex]?.getAttribute('data-scope');
    global.value=scope==='shared'?select.value:'';local.value=scope==='shared'?'':select.value;
}

function onChapterSelectChange() {
    const select = document.getElementById('modalChapterSelect');
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');
    
    if (select.value === '__custom__') {
        customInput.style.display = 'block';
        hiddenInput.value = customInput.value;
        updateTopicsDropdown(0, '', '');
    } else {
        customInput.style.display = 'none';
        hiddenInput.value = select.value;
        const selectedOpt = select.options[select.selectedIndex];
        const dbChapterId = selectedOpt ? parseInt(selectedOpt.getAttribute('data-id')) || 0 : 0;
        updateTopicsDropdown(dbChapterId, select.value, '');
    }
}

function updateChapterHidden() {
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');
    hiddenInput.value = customInput.value;
}

function toggleMCQFields() {
    const type = document.getElementById('modalType').value;
    const mcqDiv = document.getElementById('mcqDetails');
    const ansDiv = document.getElementById('answerDetails');
    const marksField = document.getElementById('modalMarks');
    const mcqInputs = mcqDiv.querySelectorAll('.mcq-field');
    
    if (type === 'MCQ') {
        mcqDiv.style.display = 'block';
        ansDiv.style.display = 'block';
        mcqInputs.forEach(i => i.setAttribute('required', 'required'));
        marksField.value = '1';
    } else {
        mcqDiv.style.display = 'none';
        ansDiv.style.display = 'block';
        mcqInputs.forEach(i => i.removeAttribute('required'));
        
        if (type === 'Very Short') {
            marksField.value = '1';
        } else if (type === 'Short') {
            marksField.value = '5';
        } else if (type === 'Long') {
            marksField.value = '8';
        } else if (type === 'Reasoning') {
            marksField.value = '4';
        } else if (type === 'Difference Between') {
            marksField.value = '4';
        }
    }
}

function openTexModal(editorId, targetSpan = null) {
    activeEditorId = editorId;
    activeTexSpan = targetSpan;
    
    let initialText = '';
    let mode = 'inline';
    
    if (activeTexSpan) {
        initialText = activeTexSpan.getAttribute('data-tex') || '';
        mode = activeTexSpan.getAttribute('data-mode') || 'inline';
    } else {
        // Get text selection from active editor
        if (window.tinymce && tinymce.get(editorId)) {
            initialText = tinymce.get(editorId).selection.getContent({format: 'text'}).trim();
        }
        
        // Automatically strip LaTeX wrappers if selection already contains them
        if (initialText.startsWith('\\(') && initialText.endsWith('\\)')) {
            initialText = initialText.substring(2, initialText.length - 2).trim();
            mode = 'inline';
        } else if (initialText.startsWith('\\[') && initialText.endsWith('\\]')) {
            initialText = initialText.substring(2, initialText.length - 2).trim();
            mode = 'block';
        } else if (initialText.startsWith('$$') && initialText.endsWith('$$')) {
            initialText = initialText.substring(2, initialText.length - 2).trim();
            mode = 'block';
        } else if (initialText.startsWith('$') && initialText.endsWith('$')) {
            initialText = initialText.substring(1, initialText.length - 1).trim();
            mode = 'inline';
        }
    }
    
    document.getElementById('texInput').value = initialText;
    document.getElementById('texDisplayMode').value = mode;
    updateTexPreview();
    
    if (texModalObj) {
        texModalObj.show();
    }
}

function updateTexPreview() {
    let input = document.getElementById('texInput').value.trim();
    const preview = document.getElementById('texPreview');
    const mode = document.getElementById('texDisplayMode').value;
    
    if (input === '') {
        preview.innerHTML = '<span class="text-muted small">No TeX code entered yet</span>';
        return;
    }
    
    // Automatically wrap any Nepali / Devanagari text in \text{...} for seamless MathJax rendering
    const processedInput = fixNepaliInLatex(input);
    
    // Wrap according to selected mode
    if (mode === 'inline') {
        preview.textContent = '\\(' + processedInput + '\\)';
    } else {
        preview.textContent = '\\[' + processedInput + '\\]';
    }
    
    // Trigger MathJax render
    if (window.MathJax && window.MathJax.typesetPromise) {
        MathJax.typesetPromise([preview]).then(() => {
            if (typeof fixDevanagariInMathJax === 'function') {
                fixDevanagariInMathJax(preview);
            }
        });
    }
}

function insertTexIntoEditor() {
    let input = document.getElementById('texInput').value.trim();
    const mode = document.getElementById('texDisplayMode').value;
    
    if (input !== '' && activeEditorId !== '') {
        // Automatically wrap any Nepali / Devanagari text in \text{...} so valid LaTeX is saved
        input = fixNepaliInLatex(input);
        const escapedInput = escapeHtml(input);
        
        if (window.tinymce && tinymce.get(activeEditorId)) {
            const editor = tinymce.get(activeEditorId);
            
            if (activeTexSpan) {
                // Update existing locked math span
                activeTexSpan.setAttribute('data-tex', input);
                activeTexSpan.setAttribute('data-mode', mode);
                if (mode === 'inline') {
                    activeTexSpan.textContent = '\\(' + input + '\\)';
                } else {
                    activeTexSpan.textContent = '\\[' + input + '\\]';
                }
                editor.save();
                
                // Trigger MathJax typeset inside editor document
                const win = editor.getWin();
                if (win.MathJax && win.MathJax.typesetPromise) {
                    win.MathJax.typesetPromise().then(() => {
                        if (typeof fixDevanagariInMathJax === 'function') {
                            fixDevanagariInMathJax(editor.getDoc());
                        }
                    });
                }
            } else {
                // Insert a new locked math span
                let html = '';
                if (mode === 'inline') {
                    html = '<span class="math-tex" contenteditable="false" data-tex="' + escapedInput + '" data-mode="inline">\\(' + input + '\\)</span>';
                } else {
                    html = '<span class="math-tex" contenteditable="false" data-tex="' + escapedInput + '" data-mode="block">\\[' + input + '\\]</span>';
                }
                editor.insertContent(html);
                editor.save();
                
                // Trigger MathJax typeset inside editor document
                const win = editor.getWin();
                if (win.MathJax && win.MathJax.typesetPromise) {
                    win.MathJax.typesetPromise().then(() => {
                        if (typeof fixDevanagariInMathJax === 'function') {
                            fixDevanagariInMathJax(editor.getDoc());
                        }
                    });
                }
            }
        }
    }
    
    // Reset reference
    activeTexSpan = null;
    
    if (texModalObj) {
        texModalObj.hide();
    }
}
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
