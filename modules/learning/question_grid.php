<?php
/**
 * Learning Portal - Question Paper Generator
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__.'/../../includes/learning_content.php';
require_once __DIR__.'/../../includes/exam_question_language.php';

Auth::requireRole(['super_admin', 'school_admin', 'teacher', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();
try{$questionLanguage=examQuestionLanguage($_GET['language']??'both');}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}

// Filters/Inputs
$class_id = $_GET['class'] ?? '';
$subject_id = $_GET['subject'] ?? '';
$num_mcq = isset($_GET['mcq_count']) ? (int)$_GET['mcq_count'] : 5;
$num_short = isset($_GET['short_count']) ? (int)$_GET['short_count'] : 5;
$num_long = isset($_GET['long_count']) ? (int)$_GET['long_count'] : 3;
foreach(['mcq_count','short_count','long_count'] as $key)if(isset($_GET[$key])&&(!is_scalar($_GET[$key])||!preg_match('/^\\d{1,3}$/',(string)$_GET[$key])||(int)$_GET[$key]>200))denySchoolRequest('Question counts must be between 0 and 200.',422);
$exam_title = sanitize($_GET['exam_title'] ?? 'Terminal Examination - 2083');

$contentActor=learningActor($db,(int)Auth::getUserId(),(int)$school_id,Auth::isReadOnly());$classes=learningReadClasses($db,$contentActor);

$subjects=learningReadSubjects($db,$contentActor,(int)$class_id);

$mcqs = [];
$shorts = [];
$longs = [];
$message = '';
$generated = false;

if ($class_id && $subject_id) {
    $generated = true;
    
    $pool=learningContentList($db,$contentActor,'questions',['class_id'=>$class_id,'subject_id'=>$subject_id],'paper');
    $pick=static function(string $type,int $count)use($pool):array{$rows=array_values(array_filter($pool,static fn($q)=>$q['type']===$type));shuffle($rows);return array_slice($rows,0,$count);};
    $mcqs=$pick('MCQ',$num_mcq);$shorts=$pick('Short',$num_short);$longs=$pick('Long',$num_long);
    // Check if counts met
    $missing = [];
    if (count($mcqs) < $num_mcq) {
        $missing[] = ($num_mcq - count($mcqs)) . " MCQs";
    }
    if (count($shorts) < $num_short) {
        $missing[] = ($num_short - count($shorts)) . " Short questions";
    }
    if (count($longs) < $num_long) {
        $missing[] = ($num_long - count($longs)) . " Long questions";
    }
    
    if (!empty($missing)) {
        $message = "Note: Insufficient questions in the bank. Loaded all available. Missing: " . implode(', ', $missing) . ".";
    }
}

// Fetch class name, subject name, and school name for printing
$selected_class_name = '';
$selected_subject_name = '';
$school_name = 'Model Secondary School';

if ($class_id) {
    $stmt = $db->prepare("SELECT name FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    $selected_class_name = $stmt->fetchColumn();
}
if ($subject_id) {
    $stmt = $db->prepare("SELECT name_en FROM subjects WHERE id = ? AND school_id = ?");
    $stmt->execute([$subject_id, $school_id]);
    $selected_subject_name = $stmt->fetchColumn();
}
if ($school_id) {
    $stmt = $db->prepare("SELECT name_en FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school_name = $stmt->fetchColumn() ?: 'Model Secondary School';
}

$pageTitle = 'Question Paper Generator';
$activePage = 'learning';
$pageStyles=['/assets/css/exam-question-language.css?v='.(int)filemtime(__DIR__.'/../../assets/css/exam-question-language.css')];

ob_start();
?>
<?php $pageAssets[]="mathjax"; ?>
<?php $pageAssets[]="mathjax"; ?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2><i class="fas fa-file-alt text-primary"></i> Question Paper Generator</h2>
    <div>
        <a href="<?= schoolRouteUrl('modules/learning/index.php') ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <?php if ($generated): ?>
        <button type="button" data-exam-print="questions" class="btn btn-primary">
            <i class="fas fa-print"></i> Print Exam Paper
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Configuration Form -->
<div class="card mb-4 no-print">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-success"><i class="fas fa-sliders-h"></i> Custom Paper Settings</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Exam Title</label>
                <input type="text" name="exam_title" class="form-control" value="<?= sanitize($exam_title) ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Select Class *</label>
                <select name="class" id="classSelect" class="form-select" required data-erp-change="learning-question-grid-action-0" data-erp-change-args="<?= schoolPageActionArgs("learning-question-grid",[]) ?>">
                    <option value="">Choose Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Select Subject *</label>
                <select name="subject" class="form-select" required data-erp-change="learning-question-grid-action-1" data-erp-change-args="<?= schoolPageActionArgs("learning-question-grid",[]) ?>">
                    <option value="">Choose Subject</option>
                    <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $subject_id == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['display_name']??$s['name_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-1">
                <label class="form-label">MCQs</label>
                <input type="number" name="mcq_count" class="form-control" value="<?= $num_mcq ?>" min="0">
            </div>
            
            <div class="col-md-1">
                <label class="form-label">Short</label>
                <input type="number" name="short_count" class="form-control" value="<?= $num_short ?>" min="0">
            </div>
            
            <div class="col-md-1">
                <label class="form-label">Long</label>
                <input type="number" name="long_count" class="form-control" value="<?= $num_long ?>" min="0">
            </div>

            <div class="col-md-4"><label for="gridLanguage" class="form-label">Question / print language</label><select id="gridLanguage" name="language" class="form-select" data-exam-language-control><?php foreach(examQuestionLanguages() as $key=>$label): ?><option value="<?= $key ?>" <?= $questionLanguage===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="col-md-12 my-2">
                <label class="form-label d-block fw-bold text-muted small"><i class="fas fa-magic"></i> CDC Exam Grid Presets</label>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-success btn-sm" data-erp-click="learning-question-grid-action-2" data-erp-click-args="<?= schoolPageActionArgs("learning-question-grid",[]) ?>">
                        <i class="fas fa-calculator"></i> Mathematics (11 MCQ, 8 Short, 4 Long)
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" data-erp-click="learning-question-grid-action-3" data-erp-click-args="<?= schoolPageActionArgs("learning-question-grid",[]) ?>">
                        <i class="fas fa-flask"></i> Science & Tech (15 MCQ, 10 Short, 5 Long)
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" data-erp-click="learning-question-grid-action-4" data-erp-click-args="<?= schoolPageActionArgs("learning-question-grid",[]) ?>">
                        <i class="fas fa-globe-asia"></i> Social/Languages (15 MCQ, 10 Short, 5 Long)
                    </button>
                    <button type="button" class="btn btn-outline-success btn-sm" data-erp-click="learning-question-grid-action-5" data-erp-click-args="<?= schoolPageActionArgs("learning-question-grid",[]) ?>">
                        <i class="fas fa-file-invoice"></i> Standard Grid (10 MCQ, 5 Short, 2 Long)
                    </button>
                </div>
            </div>
            
            <div class="col-12 text-end mt-4">
                <button type="submit" class="btn btn-primary px-5">
                    <i class="fas fa-sync"></i> Generate Paper Grid
                </button>
            </div>
        </form>
    </div>
</div>

<?php schoolPageModule("learning-question-grid"); ?>

<?php if ($message): ?>
<div class="alert alert-warning no-print mb-4"><i class="fas fa-info-circle"></i> <?= sanitize($message) ?></div>
<?php endif; ?>

<?php if ($generated): ?>
<!-- A4 Paper Output -->
<div class="paper-preview p-5 bg-white border mx-auto position-relative" style="--exam-reading-size:16px;--erp-kokila-size:22px;max-width: 800px; min-height: 1120px; font-family: 'Times New Roman', Times, serif; color: #000; box-shadow: 0 0 10px rgba(0,0,0,0.05);">
    
    <!-- Paper Header -->
    <div class="text-center mb-4">
        <h4 class="text-uppercase fw-bold mb-1"><?= sanitize($school_name) ?></h4>
        <h5 class="text-uppercase mb-2"><?= sanitize($exam_title) ?></h5>
        <div class="d-flex justify-content-between border-bottom pb-2 fw-bold" style="font-size: 1.05rem;">
            <div>Subject: <?= sanitize($selected_subject_name) ?></div>
            <div>Class: <?= sanitize($selected_class_name) ?></div>
        </div>
        <div class="d-flex justify-content-between pt-2 fw-bold" style="font-size: 1.05rem;">
            <div>Time: 3 Hours</div>
            <div>
                Full Marks: 
                <?php
                $tot_marks = 0;
                foreach ($mcqs as $q) $tot_marks += $q['marks'];
                foreach ($shorts as $q) $tot_marks += $q['marks'];
                foreach ($longs as $q) $tot_marks += $q['marks'];
                echo $tot_marks;
                ?>
            </div>
        </div>
    </div>
    
    <div class="instructions mb-4" style="font-size: 0.95rem; font-style: italic; border-bottom: 1px solid #000; padding-bottom: 10px;">
        Candidates are required to give their answers in their own words as far as practicable. The figures in the margin indicate full marks.
    </div>

    <!-- Group A: MCQs -->
    <?php if (count($mcqs) > 0): ?>
    <div class="group-section mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-decoration-underline mb-0">Group 'A' (Multiple Choice Questions)</h6>
            <span class="fw-bold font-monospace">[<?= count($mcqs) ?> questions = <?= array_sum(array_column($mcqs,'marks')) ?> Marks]</span>
        </div>
        <ol class="ps-3" style="font-size: 1.05rem;">
            <?php foreach ($mcqs as $q): ?>
            <li class="mb-3">
                <div class="mb-2 mathjax-render"><?= examQuestionRichVariants($q['question_text'],$q['question_text_np']??'',$questionLanguage,true) ?></div>
                <?php 
                $opts = json_decode($q['options'] ?? '{}', true);
                if (!empty($opts)):
                ?>
                <div class="row g-2 ps-3" style="font-size: 0.95rem;">
                    <?php $optsNp=json_decode($q['options_np']??'{}',true);foreach(['A','B','C','D'] as $key): ?><div class="col-6 mathjax-render"><?= strtolower($key) ?>) <?= examQuestionRichVariants($opts[$key]??'',$optsNp[$key]??'',$questionLanguage) ?></div><?php endforeach; ?>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>

    <!-- Group B: Shorts -->
    <?php if (count($shorts) > 0): ?>
    <div class="group-section mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-decoration-underline mb-0">Group 'B' (Short Answer Questions)</h6>
            <span class="fw-bold font-monospace">
                [<?= count($shorts) ?> questions = <?= array_sum(array_column($shorts,'marks')) ?> Marks]
            </span>
        </div>
        <ol class="ps-3" style="font-size: 1.05rem;">
            <?php foreach ($shorts as $q): ?>
            <li class="mb-3">
                <div class="d-flex justify-content-between">
                    <div class="mathjax-render"><?= examQuestionRichVariants($q['question_text'],$q['question_text_np']??'',$questionLanguage,true) ?></div>
                    <span class="font-monospace text-muted small">[<?= $q['marks'] ?>]</span>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>

    <!-- Group C: Longs -->
    <?php if (count($longs) > 0): ?>
    <div class="group-section mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-decoration-underline mb-0">Group 'C' (Long Answer Questions)</h6>
            <span class="fw-bold font-monospace">
                [<?= count($longs) ?> questions = <?= array_sum(array_column($longs,'marks')) ?> Marks]
            </span>
        </div>
        <ol class="ps-3" style="font-size: 1.05rem;">
            <?php foreach ($longs as $q): ?>
            <li class="mb-3">
                <div class="d-flex justify-content-between">
                    <div class="mathjax-render"><?= examQuestionRichVariants($q['question_text'],$q['question_text_np']??'',$questionLanguage,true) ?></div>
                    <span class="font-monospace text-muted small">[<?= $q['marks'] ?>]</span>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
    <?php endif; ?>

    <div class="text-center fw-bold mt-5 pt-5" style="border-top: 1px dotted #ccc; font-size: 0.95rem;">
        === END OF QUESTION PAPER ===
    </div>
</div>

<style>
@media print {
    .sidebar, .top-navbar, .no-print, nav, header {
        display: none !important;
    }
    .content-wrapper {
        margin-left: 0 !important;
        padding-left: 0 !important;
        padding-top: 0 !important;
        background-color: #fff !important;
    }
    body {
        background-color: #fff !important;
    }
    .paper-preview {
        border: none !important;
        padding: 0 !important;
        box-shadow: none !important;
        max-width: 100% !important;
    }
}
</style>
<?php endif; ?>


<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
