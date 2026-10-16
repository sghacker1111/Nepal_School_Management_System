<?php
/**
 * Exam Management - Edit Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/exam_service.php';

Auth::requireRole(['super_admin', 'school_admin', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';
$success = '';
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch exam details
$stmt = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
$stmt->execute([$exam_id, $school_id]);
$exam = $stmt->fetch();

if (!$exam) {
    redirect(schoolRouteUrl('modules/exams/index.php'), 'Exam not found', 'error');
}
if(($exam['creation_mode']??'manual')==='automatic')redirect(schoolRouteUrl('modules/exams/view.php') . '?id='.$exam_id,'Automatic exam settings are read-only. Create a manual exam to customize defaults.','error');

if ($exam['is_locked'] || $exam['is_published']) {
    redirect(schoolRouteUrl('modules/exams/index.php'), 'Locked exams cannot be edited.', 'error');
}

$hasMarks = examHasMarks($db,$exam_id);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $addedInternal=updateExamDetails($db,$school_id,$exam_id,$_POST);
        Auth::logActivity(Auth::getUserId(),$addedInternal?'Enabled Internal Assessment; Theory Marks Preserved':'Edit Exam Details','exams',$exam_id);
        redirect(schoolRouteUrl('modules/exams/index.php'),$addedInternal?'Internal assessment enabled. Existing theory marks are preserved. Enter the missing internal marks before publishing.':'Exam details updated successfully.');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (PDOException $e) { error_log($e->getMessage());$error='The exam could not be updated. Please try again.'; }
}
$formExam=array_replace($exam,array_intersect_key($_POST,$exam));
try{$issue=reportIssueDate($exam,$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[]);}catch(InvalidArgumentException $e){$issue=reportIssueDate($exam);}

$pageTitle = 'Edit Exam: ' . $exam['name'];
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit"></i> Edit Exam Details</h2>
    <a href="<?= schoolRouteUrl('modules/exams/index.php') ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
</div>
<?php endif; ?>

<div class="card max-width-600 mx-auto">
    <div class="card-header bg-primary text-white py-3">
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Edit Exam Configuration</h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="">
            <?php if($hasMarks): ?>
            <div class="alert alert-info small">Theory marks are already saved. You can enable internal assessment without losing them. Exam type and result system stay fixed; internal assessment cannot be removed after marks are recorded.</div>
            <input type="hidden" name="type" value="<?= sanitize($exam['type']) ?>">
            <input type="hidden" name="result_system" value="<?= sanitize($exam['result_system']) ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label for="name" class="form-label font-weight-bold">Exam Name *</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= sanitize($formExam['name']) ?>" required>
            </div>
            
            <div class="mb-3">
                <label for="type" class="form-label font-weight-bold">Exam Type</label>
                <select class="form-select" id="type" name="type" <?= $hasMarks?'disabled':'' ?>>
                    <option value="terminal" <?= $exam['type'] === 'terminal' ? 'selected' : '' ?>>Terminal Exam (Term Exam)</option>
                    <option value="final" <?= $exam['type'] === 'final' ? 'selected' : '' ?>>Final Exam</option>
                    <option value="unit" <?= $exam['type'] === 'unit' ? 'selected' : '' ?>>Unit Test / Class Test</option>
                    <option value="practical" <?= $exam['type'] === 'practical' ? 'selected' : '' ?>>Practical / Assessment Only</option>
                </select>
            </div>

            <div class="mb-3">
                <label for="result_system" class="form-label font-weight-bold">Result System (for Unit Tests or General)</label>
                <select class="form-select" id="result_system" name="result_system" <?= $hasMarks?'disabled':'' ?>>
                    <option value="gpa" <?= ($exam['result_system'] ?? 'gpa') === 'gpa' ? 'selected' : '' ?>>Grading System (GPA / Grades)</option>
                    <option value="percentage" <?= ($exam['result_system'] ?? 'gpa') === 'percentage' ? 'selected' : '' ?>>Marksheet System (Marks / Percentage)</option>
                </select>
            </div>
            
            <div class="mb-3">
                <div class="form-check form-switch">
                    <?php $internalFrozen=$hasMarks&&(int)$exam['has_internal']===1; ?>
                    <?php if($internalFrozen): ?><input type="hidden" name="has_internal" value="1"><?php endif; ?>
                    <input class="form-check-input" type="checkbox" id="has_internal" name="has_internal" value="1" <?= $exam['has_internal'] ? 'checked' : '' ?> <?= ($internalFrozen||$exam['type']==='unit')?'disabled':'' ?>>
                    <label class="form-check-label fw-bold text-primary" for="has_internal">
                        Include Internal Exam / Practical Assessment Component
                    </label>
                </div>
                <div class="form-text text-muted">Enabling adds internal full/pass marks from the class subjects. Existing theory marks, theory limits and subject selections stay unchanged. Unit tests remain theory-only; Grades 1–3 keep achievement assessment.</div>
            </div>
            
            <?php include __DIR__.'/../../includes/exam_date_controls.php'; ?>
            <div class="row g-3 mb-4"><?php include __DIR__.'/../../includes/report_issue_controls.php'; ?></div>
            
            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
