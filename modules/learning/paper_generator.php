<?php
if(!isset($_POST['action_save_grid'])&&!isset($_POST['action_generate'])&&($_POST['generation_mode']??$_GET['generation_mode']??'rows')==='rows'){
    include __DIR__.'/../../includes/learning_test_paper_page.php';return;
}
/**
 * Normal Paper Generator and school-defined allocation grids.
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__.'/../../includes/learning_content.php';
require_once __DIR__.'/../../includes/exam_question_language.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('paper_generator');

$db = getDB();
$school_id = Auth::getSchoolId();
$is_admin = (Auth::getRole() === 'super_admin');
try{$questionLanguage=examQuestionLanguage($_POST['language']??$_GET['language']??'both');}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}

// Handle Grid Layout Save via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_grid'])) {
    header('Content-Type: application/json');
    $grid_name = trim($_POST['grid_name'] ?? '');
    $c_id = (int)($_POST['class_id'] ?? 0);
    $s_id = (int)($_POST['subject_id'] ?? 0);
    $grid_data = $_POST['grid_data'] ?? '';
    if(!is_string($grid_data)||strlen($grid_data)>50000)denySchoolRequest('Choose a valid grid configuration of at most 50 KB.',422);
    try{$decoded=json_decode($grid_data,true,32,JSON_THROW_ON_ERROR);if(!is_array($decoded))throw new InvalidArgumentException();}
    catch(Throwable $e){denySchoolRequest('Choose a valid JSON grid configuration.',422);}
    
    if (empty($grid_name) || !$c_id || !$s_id || empty($grid_data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit;
    }
    
    try {
        $grid_school_id = $school_id ?: 0;
        $stmt = $db->prepare("
            INSERT INTO specification_grids (school_id, class_id, subject_id, name, config_json)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$grid_school_id, $c_id, $s_id, $grid_name, $grid_data]);
        $grid_id = $db->lastInsertId();
        
        echo json_encode(['success' => true, 'grid_id' => $grid_id, 'name' => $grid_name]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => schoolPublicError($e)]);
    }
    exit;
}

$contentActor=learningActor($db,(int)Auth::getUserId(),(int)$school_id,Auth::isReadOnly());$classes=learningReadClasses($db,$contentActor);

// Fetch exams for current school
if ($is_admin && !$school_id) {
    $exams_stmt = $db->prepare("SELECT * FROM exams ORDER BY start_date DESC");
    $exams_stmt->execute();
    $exams = $exams_stmt->fetchAll();
} else {
    $exams_stmt = $db->prepare("SELECT * FROM exams WHERE school_id = ? ORDER BY start_date DESC");
    $exams_stmt->execute([$school_id]);
    $exams = $exams_stmt->fetchAll();
}

// Get filter inputs
$class_id = $_POST['class_id'] ?? $_GET['class_id'] ?? '';
$subject_id = $_POST['subject_id'] ?? $_GET['subject_id'] ?? '';
$exam_id = $_POST['exam_id'] ?? $_GET['exam_id'] ?? '';
$generation_mode = $_GET['generation_mode'] ?? 'auto';

// Fetch school details for logo and print header
$school = null;
if ($school_id) {
    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school = $stmt->fetch();
}

$subjects=learningReadSubjects($db,$contentActor,(int)$class_id);

// Fetch saved grid layouts for this class/subject
$saved_grids = [];
if ($class_id && $subject_id) {
    if ($is_admin && !$school_id) {
        $stmt = $db->prepare("
            SELECT * FROM specification_grids 
            WHERE class_id = ? AND subject_id = ? 
            ORDER BY name ASC
        ");
        $stmt->execute([$class_id, $subject_id]);
        $saved_grids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $db->prepare("
            SELECT * FROM specification_grids 
            WHERE school_id = ? AND class_id = ? AND subject_id = ? 
            ORDER BY name ASC
        ");
        $stmt->execute([$school_id, $class_id, $subject_id]);
        $saved_grids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Questions variables
$selected_questions = [];
$error_msg = '';
$success_msg = '';
$generated = false;

// Default marks settings
$types_info = [
    'MCQ' => ['label' => 'Multiple Choice (MCQ)', 'marks' => 1, 'count' => 5],
    'Very Short' => ['label' => 'Very Short Answer', 'marks' => 1, 'count' => 5],
    'Short' => ['label' => 'Short Answer', 'marks' => 5, 'count' => 4],
    'Long' => ['label' => 'Long Answer', 'marks' => 8, 'count' => 3],
    'Reasoning' => ['label' => 'Reasoning Question', 'marks' => 4, 'count' => 2],
    'Difference Between' => ['label' => 'Difference Between', 'marks' => 4, 'count' => 2]
];

// Handle live parameters if supplied
foreach ($types_info as $type => $info) {
    if (isset($_GET['count_' . str_replace(' ', '_', $type)])) {
        $types_info[$type]['count'] = (int)$_GET['count_' . str_replace(' ', '_', $type)];
    }
    if (isset($_GET['marks_' . str_replace(' ', '_', $type)])) {
        $types_info[$type]['marks'] = (int)$_GET['marks_' . str_replace(' ', '_', $type)];
    }
}

// ----------------------------------------------------
// FETCH QUESTIONS CORE LOGIC (School-owned + Universal Mapping)
// ----------------------------------------------------
$chapter_filter = $_GET['chapter'] ?? '';

$all_available_questions = [];
if ($class_id && $subject_id) {
    $filters=['class_id'=>$class_id,'subject_id'=>$subject_id];
    if($chapter_filter&&$chapter_filter!=='all')$filters['chapter']=$chapter_filter;
    $all_available_questions=learningContentList($db,$contentActor,'questions',$filters,'paper');
}

// Process generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_generate'])) {
    $class_id = $_POST['class_id'] ?? '';
    $subject_id = $_POST['subject_id'] ?? '';
    $exam_id = $_POST['exam_id'] ?? '';
    $generation_mode = $_POST['generation_mode'] ?? 'auto';
    
    if (empty($class_id) || empty($subject_id)) {
        $error_msg = "Please select both Class and Subject.";
    } else {
        $generated = true;
        
        if ($generation_mode === 'auto') {
            // Automatic generation using the selected normal-paper allocation.
            $selected_questions = [];
            
            // Group available questions by type
            $questions_by_type = [];
            foreach ($all_available_questions as $q) {
                $questions_by_type[$q['type']][] = $q;
            }
            
            $insufficient = [];
            foreach ($types_info as $type => $info) {
                $req_count = (int)($_POST['count_' . str_replace(' ', '_', $type)] ?? 0);
                $type_marks = (int)($_POST['marks_' . str_replace(' ', '_', $type)] ?? $info['marks']);
                
                // Keep updated values in grid inputs
                $types_info[$type]['count'] = $req_count;
                $types_info[$type]['marks'] = $type_marks;
                
                if ($req_count > 0) {
                    $available = $questions_by_type[$type] ?? [];
                    if (count($available) < $req_count) {
                        $insufficient[] = "$type (Needed: $req_count, Available: " . count($available) . ")";
                    }
                    
                    // Shuffle and pick random questions
                    shuffle($available);
                    $picked = array_slice($available, 0, $req_count);
                    
                    foreach ($picked as $p) {
                        // Override marks weightage on paper generation as specified by grid
                        $p['marks'] = $type_marks;
                        $selected_questions[] = $p;
                    }
                }
            }
            
            if (!empty($insufficient)) {
                $success_msg = "Exam paper generated, but some question types had insufficient items in the bank: " . implode(', ', $insufficient) . ". Filled with available ones.";
            } else {
                $success_msg = "Normal paper generated successfully using your question allocation parameters.";
            }
            
        } else {
            // Manual selection logic
            $selected_ids = $_POST['manual_questions'] ?? [];
            if(!is_array($selected_ids)||!array_is_list($selected_ids)||count($selected_ids)>200)denySchoolRequest('Choose valid paper questions.',422);
            try{learningValidateQuestionIds($db,$contentActor,$selected_ids,'paper');}catch(InvalidArgumentException $e){denySchoolRequest('Some paper questions are unavailable. Reload the selection.',422);}
            $selected_questions = [];
            
            if (empty($selected_ids)) {
                $error_msg = "Please select at least one question from the list.";
                $generated = false;
            } else {
                foreach ($all_available_questions as $q) {
                    if (in_array($q['id'], $selected_ids)) {
                        // User can specify custom marks for each question in post parameters if they wish
                        $custom_marks_key = 'manual_marks_' . $q['id'];
                        if (isset($_POST[$custom_marks_key])) {
                            $q['marks'] = (int)$_POST[$custom_marks_key];
                        }
                        $selected_questions[] = $q;
                    }
                }
                $success_msg = "Exam paper generated with " . count($selected_questions) . " manually selected questions.";
            }
        }
    }
}

// Fetch selected exam metadata
$selected_exam = null;
if ($exam_id) {
    $stmt = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
    $stmt->execute([$exam_id, $school_id]);
    $selected_exam = $stmt->fetch();
}

// Fetch selected class metadata
$selected_class = null;
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $selected_class = $stmt->fetch();
}

// Fetch selected subject metadata
$selected_subject = null;
if ($subject_id) {
    $stmt = $db->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
    $stmt->execute([$subject_id, $school_id]);
    $selected_subject = $stmt->fetch();
}

$error=$error_msg;$pageMutationOutcome=$generated?'preview':null;
$pageTitle = 'Normal Paper Generator';
$activePage = 'paper_generator';
$pageStyles=['/assets/css/exam-question-language.css?v='.(int)filemtime(__DIR__.'/../../assets/css/exam-question-language.css')];

ob_start();
?>
<style>
/* Glassmorphism dashboard style */
.generator-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.08);
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255, 255, 255, 0.18);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.generator-card:hover {
    box-shadow: 0 12px 40px 0 rgba(31, 38, 135, 0.12);
}
.input-grid-box {
    border-left: 4px solid #3498db;
    background-color: #f8f9fa;
}
/* A4 Preview CSS */
.paper-preview-container {
    background: #525659;
    padding: 30px 15px;
    border-radius: 8px;
    overflow-x: auto;
}
.a4-sheet {
    --exam-reading-size:12pt;
    --erp-kokila-size:16.5pt;
    background: #ffffff;
    width: 210mm;
    min-height: 297mm;
    padding: 20mm;
    margin: 0 auto;
    box-shadow: 0 0 10px rgba(0,0,0,0.5);
    color: #000000;
    font-family: 'Kokila', 'Times New Roman', Times, 'Noto Sans Devanagari', serif;
    font-size: var(--erp-kokila-size, 20pt);
    line-height: 1.5;
    position: relative;
    box-sizing: border-box;
}
.a4-header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.a4-header-table td {
    vertical-align: middle;
    border: none;
}
.school-logo-print {
    max-height: 80px;
    max-width: 80px;
    object-fit: contain;
}
.paper-title {
    text-align: center;
    font-size: 18px;
    font-weight: bold;
    text-transform: uppercase;
}
.school-name-print {
    font-size: 22px;
    font-weight: bold;
    text-transform: uppercase;
    color: #1a252f;
}
.metadata-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 15px;
    border-bottom: 2px double #000;
}
.metadata-table td {
    padding: 4px 0;
    font-weight: bold;
    border: none;
}
.question-group-title {
    font-weight: bold;
    text-transform: uppercase;
    margin-top: 15px;
    margin-bottom: 5px;
    border-bottom: 1px solid #000;
    padding-bottom: 2px;
}
.question-item {
    margin-bottom: 12px;
    page-break-inside: avoid;
}
.question-marks {
    float: right;
    font-weight: bold;
}
.mcq-options-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 5px 15px;
    margin-left: 20px;
    margin-top: 5px;
}
.ocr-collapse-box {
    background-color: #fcfcfc;
    border: 1px dashed #27ae60;
    border-radius: 8px;
}

/* Print Specific Overrides */
@media print {
    body * {
        visibility: hidden;
    }
    #printArea, #printArea * {
        visibility: visible;
    }
    #printArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 0;
        box-shadow: none;
        background: #fff;
    }
    .no-print {
        display: none !important;
    }
    @page {
        size: A4;
        margin: 15mm;
    }
}
</style>

<!-- MathJax support for LaTeX -->
<?php $pageAssets[]="mathjax"; ?>
<?php $pageAssets[]="mathjax"; ?>

<!-- Tesseract OCR CDN -->
<?php $pageAssets[]="tesseract"; ?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2><i class="fas fa-file-invoice text-primary"></i> Normal Paper Generator</h2>
    <?php if(in_array(Auth::getRole(),['super_admin','school_admin','teacher'],true)&&Auth::hasAccess('exam_paper_generator')): ?><a class="btn btn-outline-primary" href="<?= schoolRouteUrl('modules/learning/exam_paper_generator.php') ?>">Open separate Exam Paper Generator</a><?php endif; ?>
    <div>
        <a href="<?= schoolRouteUrl('index.php') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>
</div>

<?php if ($error_msg): ?>
<div class="alert alert-danger no-print"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error_msg) ?></div>
<?php endif; ?>

<?php if ($success_msg): ?>
<div class="alert alert-success no-print"><i class="fas fa-check-circle"></i> <?= sanitize($success_msg) ?></div>
<?php endif; ?>

<!-- Setup Panel (Forms & Specification Grid) -->
<div class="row g-4 no-print">
    <!-- Filters and Modes -->
    <div class="col-xl-4 col-lg-5">
        <div class="card generator-card h-100">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-sliders-h"></i> Configuration Panel</h5>
            </div>
            <div class="card-body p-4">
                <form id="paramForm" method="GET" action=""><input type="hidden" name="language" value="<?= $questionLanguage ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">1. Select Target Class *</label>
                        <select name="class_id" class="form-select" data-erp-change="learning-paper-generator-action-0" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">2. Select Subject *</label>
                        <select name="subject_id" class="form-select" data-erp-change="learning-paper-generator-action-1" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>" required>
                            <option value="">Choose Subject</option>
                            <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $subject_id == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">3. Filter by Chapter</label>
                        <select name="chapter" id="chapterFilterSelect" class="form-select" data-erp-change="learning-paper-generator-action-2" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                            <option value="">All Chapters (Full Syllabus)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">4. Select Examination Context</label>
                        <select name="exam_id" class="form-select" data-erp-change="learning-paper-generator-action-3" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                            <option value="">Choose Exam (Optional)</option>
                            <?php foreach ($exams as $e): ?>
                            <option value="<?= $e['id'] ?>" <?= $exam_id == $e['id'] ? 'selected' : '' ?>><?= sanitize($e['name']) ?> (<?= sanitize($e['type'] ?? '-') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">4. Selection Workflow</label>
                        <div class="btn-group-vertical w-100 gap-2" role="group">
                            <input type="radio" class="btn-check" name="generation_mode" id="modeAuto" value="auto" <?= $generation_mode === 'auto' ? 'checked' : '' ?> data-erp-change="learning-paper-generator-action-4" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                            <label class="btn btn-outline-primary text-start px-3 py-2 fw-semibold" for="modeAuto"><i class="fas fa-magic me-2"></i> Automatic (Normal Grid)</label>

                            <input type="radio" class="btn-check" name="generation_mode" id="modeManual" value="manual" <?= $generation_mode === 'manual' ? 'checked' : '' ?> data-erp-change="learning-paper-generator-action-5" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                            <label class="btn btn-outline-primary text-start px-3 py-2 fw-semibold" for="modeManual"><i class="fas fa-hand-pointer me-2"></i> Manual Selection</label>
                            
                            <input type="radio" class="btn-check" name="generation_mode" id="modeOcr" value="ocr" <?= $generation_mode === 'ocr' ? 'checked' : '' ?> data-erp-change="learning-paper-generator-action-6" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                            <label class="btn btn-outline-primary text-start px-3 py-2 fw-semibold" for="modeOcr"><i class="fas fa-camera me-2"></i> OCR Paper Scanner</label>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Generator Options Grid -->
    <div class="col-xl-8 col-lg-7">
        <?php if ($class_id && $subject_id): ?>
        <form method="POST" action="" id="generatorForm">
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>">
            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($subject_id) ?>">
            <input type="hidden" name="exam_id" value="<?= htmlspecialchars($exam_id) ?>">
            <input type="hidden" name="generation_mode" value="<?= htmlspecialchars($generation_mode) ?>">
            <label for="legacyPaperLanguage" class="form-label">Question / print language</label><select id="legacyPaperLanguage" name="language" class="form-select mb-3" data-exam-language-control><?php foreach(examQuestionLanguages() as $key=>$label): ?><option value="<?= $key ?>" <?= $questionLanguage===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select>
            <a class="btn btn-outline-primary mb-3" href="?<?= http_build_query(['class_id'=>$class_id,'subject_id'=>$subject_id,'generation_mode'=>'rows','language'=>$questionLanguage]) ?>">Chapter / topic Test Paper Builder</a>
            <input type="hidden" name="action_generate" value="1">

            <?php if ($generation_mode === 'auto'): ?>
            <!-- CDC NEPAL SPECIFICATION GRID -->
            <div class="card generator-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-border-all"></i> Normal Paper Allocation Grid</h5>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="max-width: 230px;">
                            <label class="input-group-text bg-light text-muted small" for="loadGridSelect">Load Grid</label>
                            <select id="loadGridSelect" class="form-select" data-erp-change="learning-paper-generator-action-7" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                                <option value="">-- Saved Grids --</option>
                                <?php foreach ($saved_grids as $sg): ?>
                                    <option value="<?= htmlspecialchars($sg['config_json']) ?>"><?= sanitize($sg['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-success fw-semibold" data-erp-click="learning-paper-generator-action-8" data-erp-click-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>" title="Save Current Grid Config"><i class="fas fa-save"></i> Save Layout</button>
                        <div class="badge bg-primary fs-6 p-2 shadow-sm">
                            Live Full Marks: <span id="lblLiveMarks">0</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">Set the number of questions needed for each type. The system will pull random questions matching your counts from the Question Bank. Set marks for each group below.</p>
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="table-responsive">
                                <table class="table table-bordered align-middle text-center">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Question Classification</th>
                                            <th style="width: 25%;">Number of Questions</th>
                                            <th style="width: 25%;">Marks Per Question</th>
                                            <th style="width: 25%;">Subtotal Marks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($types_info as $type => $info): ?>
                                        <tr class="grid-row">
                                            <td class="text-start fw-semibold">
                                                <?= sanitize($info['label']) ?>
                                            </td>
                                            <td>
                                                <input type="number" 
                                                       name="count_<?= str_replace(' ', '_', $type) ?>" 
                                                       class="form-control text-center grid-count" 
                                                       value="<?= $info['count'] ?>" 
                                                       min="0" 
                                                       data-erp-input="learning-paper-generator-action-9" data-erp-input-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                                            </td>
                                            <td>
                                                <input type="number" 
                                                       name="marks_<?= str_replace(' ', '_', $type) ?>" 
                                                       class="form-control text-center grid-marks" 
                                                       value="<?= $info['marks'] ?>" 
                                                       min="1" 
                                                       data-erp-input="learning-paper-generator-action-10" data-erp-input-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                                            </td>
                                            <td class="fw-bold text-primary font-monospace">
                                                <span class="grid-subtotal">0</span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-end">
                        <button type="submit" class="btn btn-primary btn-lg px-5 shadow"><i class="fas fa-cog"></i> Generate Question Paper</button>
                    </div>
                </div>
            </div>
            
            <?php elseif ($generation_mode === 'manual'): ?>
            <!-- MANUAL SELECTION -->
            <div class="card generator-card h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-list-check"></i> Manual Question Pick</h5>
                    <div class="badge bg-success fs-6 p-2 shadow-sm">
                        Selected: <span id="lblManualCount">0</span> Questions
                    </div>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">Pick the specific questions you want to include in this paper by checking their boxes. You can also specify custom marks weightage for each question.</p>
                    
                    <?php if (count($all_available_questions) > 0): ?>
                        <div class="table-responsive" style="max-height: 50vh; overflow-y: auto;">
                            <table class="table table-hover table-striped align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 5%;">Select</th>
                                        <th style="width: 15%;">Type</th>
                                        <th>Question Text</th>
                                        <th style="width: 15%;">Weightage (Marks)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_available_questions as $q): ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" 
                                                   name="manual_questions[]" 
                                                   value="<?= $q['id'] ?>" 
                                                   class="form-check-input manual-check" 
                                                   data-erp-change="learning-paper-generator-action-11" data-erp-change-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>">
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= sanitize($q['type']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <?= examQuestionRich($q['question_text'],$q['question_text_np']??'',$questionLanguage,true) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="manual_marks_<?= $q['id'] ?>" 
                                                   value="<?= $q['marks'] ?>" 
                                                   min="1" 
                                                   class="form-control form-control-sm text-center">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-4 text-end">
                            <button type="submit" class="btn btn-success btn-lg px-5 shadow"><i class="fas fa-check-double"></i> Assemble & Generate</button>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning border-start border-warning border-4">
                            <i class="fas fa-exclamation-triangle me-2"></i> No questions found in the Question Bank matching the selected class and subject. Add questions first!
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php elseif ($generation_mode === 'ocr'): ?>
            <!-- OCR PAPER SCANNER -->
            <div class="card generator-card h-100">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="mb-0 text-success fw-bold"><i class="fas fa-camera"></i> OCR Exam Paper Scanner</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted mb-4">Upload a high-quality photo or scanned image of a printed examination paper to extract question texts and copy them directly into the system.</p>
                    
                    <div class="row g-4">
                        <div class="col-md-5 border-end">
                            <div class="mb-4">
                                <label class="form-label fw-semibold">1. Choose Scan Image File</label>
                                <input type="file" id="ocrImageFile" class="form-control" accept="image/*">
                            </div>
                            
                            <div class="mb-4">
                                <button type="button" class="btn btn-success btn-lg w-100 shadow" id="btnRunOCR"><i class="fas fa-cogs"></i> Run OCR Extraction</button>
                            </div>
                            
                            <div id="ocrProgress" class="progress mb-3" style="display:none; height:24px;">
                                <div id="ocrProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;">0%</div>
                            </div>
                            <div id="ocrStatus" class="alert alert-info fw-semibold text-center py-2" style="display:none;">Initializing OCR Engine...</div>
                        </div>
                        
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Extracted Text Output</label>
                            <textarea id="ocrOutputText" class="form-control mb-3 font-monospace" rows="12" placeholder="Extracted text will appear here..." style="font-size:0.9rem;"></textarea>
                            
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-success w-50" data-erp-click="learning-paper-generator-action-12" data-erp-click-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>"><i class="fas fa-copy"></i> Copy Text</button>
                                <a href="<?= schoolRouteUrl('modules/learning/questions.php') ?>" class="btn btn-primary w-50"><i class="fas fa-plus"></i> Go to Question Bank</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <div class="card generator-card h-100 text-center py-5">
            <div class="card-body">
                <i class="fas fa-info-circle fa-4x text-muted mb-3"></i>
                <h5 class="text-dark">Awaiting Class & Subject Selection</h5>
                <p class="text-muted">Please configure the left Configuration Panel to load the Specification Grid or Manual Selector.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Generated Exam Paper Preview (Printable Sheet) -->
<?php if ($generated && !empty($selected_questions)): ?>
<div class="card generator-card mt-5 no-print">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-success fw-bold"><i class="fas fa-print"></i> Printable Paper Preview</h5>
        <div>
            <button class="btn btn-outline-secondary me-2" data-erp-click="learning-paper-generator-action-13" data-erp-click-args="<?= schoolPageActionArgs("learning-paper-generator",[]) ?>"><i class="fas fa-file-word text-primary"></i> Export to Word (.doc)</button>
            <label for="legacyPrintLanguage" class="form-label">Print language</label><select id="legacyPrintLanguage" class="form-select d-inline-block w-auto" data-exam-language-control><?php foreach(examQuestionLanguages() as $key=>$label): ?><option value="<?= $key ?>" <?= $questionLanguage===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><button type="button" class="btn btn-success" data-exam-print="questions"><i class="fas fa-print"></i> Print / Save PDF</button>
        </div>
    </div>
    <div class="card-body paper-preview-container">
        
        <!-- Printable A4 Box -->
        <div class="a4-sheet" id="printArea">
            <!-- Header Table -->
            <table class="a4-header-table">
                <tr>
                    <td style="width: 80px;">
                        <?php if ($school && $school['logo']): ?>
                            <img src="<?= APP_URL ?>/uploads/logo/<?= $school['logo'] ?>" class="school-logo-print" alt="Logo">
                        <?php else: ?>
                            <div style="width:80px;height:80px;border:1px solid #ccc;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;">LOGO</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="school-name-print"><?= $school ? sanitize($school['name_en']) : 'Nepal Model School' ?></span><br>
                        <span style="font-size:14px;font-weight:bold;"><?= $school ? sanitize($school['address']) : 'Kathmandu, Nepal' ?></span><br>
                        <span class="paper-title"><?= $selected_exam ? sanitize($selected_exam['name']) : 'Terminal Examination' ?></span>
                    </td>
                    <td style="width: 80px;">&nbsp;</td>
                </tr>
            </table>

            <!-- Metadata Details Grid -->
            <?php
            // Calculate total full marks of generated questions
            $full_marks = 0;
            foreach ($selected_questions as $q) {
                $full_marks += $q['marks'];
            }
            $pass_marks = round($full_marks * 0.4); // Standard pass mark is 40%
            ?>
            <table class="metadata-table">
                <tr>
                    <td style="width: 33%;">Class: <?= $selected_class ? sanitize($selected_class['name']) : 'N/A' ?></td>
                    <td style="width: 34%; text-align: center;">Subject: <?= $selected_subject ? sanitize($selected_subject['name_en']) : 'N/A' ?></td>
                    <td style="width: 33%; text-align: right;">Time: 3 Hours</td>
                </tr>
                <tr>
                    <td>Full Marks: <?= $full_marks ?></td>
                    <td style="text-align: center;">&nbsp;</td>
                    <td style="text-align: right;">Pass Marks: <?= $pass_marks ?></td>
                </tr>
            </table>

            <!-- Standard Instructions -->
            <div style="font-style: italic; font-size:12px; margin-bottom: 20px;" class="text-center">
                Candidates are required to give their answers in their own words as far as practicable. The figures in the margin indicate full marks.
            </div>

            <!-- Grouped Questions Rendering -->
            <?php
            // Group questions by type for rendering Groups (e.g. Group A: MCQ, Group B: Short)
            $grouped_questions = [];
            foreach ($selected_questions as $q) {
                $grouped_questions[$q['type']][] = $q;
            }
            
            $group_num = 1;
            foreach ($grouped_questions as $type => $questions_list):
                $group_letter = chr(64 + $group_num); // A, B, C...
            ?>
                <div class="question-group-title">
                    Group <?= $group_letter ?>: <?= sanitize($types_info[$type]['label'] ?? $type) ?> (<?= count($questions_list) ?> &times; <?= $questions_list[0]['marks'] ?> = <?= count($questions_list) * $questions_list[0]['marks'] ?> Marks)
                </div>
                
                <div class="questions-list">
                    <?php 
                    $q_idx = 1;
                    foreach ($questions_list as $q): 
                    ?>
                        <div class="question-item">
                            <span class="question-marks">[<?= $q['marks'] ?>]</span>
                            <div style="margin-left: 25px; text-indent: -25px;">
                                <?= $q_idx ?>. 
                                <div class="mathjax-render"><?= examQuestionRichVariants($q['question_text'],$q['question_text_np']??'',$questionLanguage,true) ?></div>
                            </div>
                            

                            <!-- MCQ Options Rendering -->
                            <?php if ($type === 'MCQ' && !empty($q['options'])): 
                                $opts = json_decode($q['options'], true);
                                $opts_np = json_decode($q['options_np'], true);
                            ?>
                                <div class="mcq-options-grid">
                                    <?php foreach(['A','B','C','D'] as $option): ?><div><?= $option ?>. <?= examQuestionRichVariants($opts[$option]??'',$opts_np[$option]??'',$questionLanguage) ?></div><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php 
                    $q_idx++;
                    endforeach; 
                    ?>
                </div>
            <?php 
            $group_num++;
            endforeach; 
            ?>
        </div>

    </div>
</div>
<?php endif; ?>

<?php schoolPageModule("external-paper-chapters"); ?>
<?php schoolPageModule("learning-paper-generator");schoolPageValue("learning-paper-generator-value-0",(string)(htmlspecialchars($class_id)));
schoolPageValue("learning-paper-generator-value-1",(string)(htmlspecialchars($subject_id)));
schoolPageValue("learning-paper-generator-value-2",(string)(htmlspecialchars($class_id)));
schoolPageValue("learning-paper-generator-value-3",(string)(htmlspecialchars($subject_id)));
schoolPageValue("learning-paper-generator-value-4",(string)(json_encode($chapter_filter,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)));
schoolPageValue("learning-paper-generator-value-5",(string)(json_encode(schoolRouteUrl('modules/learning/questions.php')))); ?>


<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
