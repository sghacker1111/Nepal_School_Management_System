<?php
/**
 * Exam Management - Edit Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

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
    redirect(APP_URL . '/modules/exams/index.php', 'Exam not found', 'error');
}

if ($exam['is_locked']) {
    redirect(APP_URL . '/modules/exams/index.php', 'Locked exams cannot be edited.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $type = sanitize($_POST['type'] ?? 'terminal');
    $start_date = $_POST['start_date'] ?: null;
    $end_date = $_POST['end_date'] ?: null;
    $has_internal = isset($_POST['has_internal']) ? (int)$_POST['has_internal'] : 0;
    $result_system = sanitize($_POST['result_system'] ?? 'gpa');
    
    if (empty($name)) {
        $error = 'Exam name is required';
    } else {
        try {
            $stmt = $db->prepare("UPDATE exams SET name = ?, type = ?, start_date = ?, end_date = ?, has_internal = ?, result_system = ? WHERE id = ? AND school_id = ?");
            $stmt->execute([$name, $type, $start_date, $end_date, $has_internal, $result_system, $exam_id, $school_id]);
            
            Auth::logActivity(Auth::getUserId(), 'Edit Exam Details', 'exams', $exam_id);
            redirect(APP_URL . '/modules/exams/index.php', 'Exam details updated successfully.');
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Exam: ' . $exam['name'];
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit"></i> Edit Exam Details</h2>
    <a href="<?= APP_URL ?>/modules/exams/index.php" class="btn btn-secondary">
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
            <div class="mb-3">
                <label for="name" class="form-label font-weight-bold">Exam Name *</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= sanitize($exam['name']) ?>" required>
            </div>
            
            <div class="mb-3">
                <label for="type" class="form-label font-weight-bold">Exam Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="terminal" <?= $exam['type'] === 'terminal' ? 'selected' : '' ?>>Terminal Exam (Term Exam)</option>
                    <option value="final" <?= $exam['type'] === 'final' ? 'selected' : '' ?>>Final Exam</option>
                    <option value="unit" <?= $exam['type'] === 'unit' ? 'selected' : '' ?>>Unit Test / Class Test</option>
                    <option value="practical" <?= $exam['type'] === 'practical' ? 'selected' : '' ?>>Practical / Assessment Only</option>
                </select>
            </div>

            <div class="mb-3">
                <label for="result_system" class="form-label font-weight-bold">Result System (for Unit Tests or General)</label>
                <select class="form-select" id="result_system" name="result_system">
                    <option value="gpa" <?= ($exam['result_system'] ?? 'gpa') === 'gpa' ? 'selected' : '' ?>>Grading System (GPA / Grades)</option>
                    <option value="percentage" <?= ($exam['result_system'] ?? 'gpa') === 'percentage' ? 'selected' : '' ?>>Marksheet System (Marks / Percentage)</option>
                </select>
            </div>
            
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="has_internal" name="has_internal" value="1" <?= $exam['has_internal'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-bold text-primary" for="has_internal">
                        Include Internal Exam / Practical Assessment Component
                    </label>
                </div>
                <div class="form-text text-muted">If disabled, the exam will only have theory (external) marks. The practical marks input and calculations will be skipped.</div>
            </div>
            
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label for="start_date" class="form-label font-weight-bold">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?= sanitize($exam['start_date'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label for="end_date" class="form-label font-weight-bold">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?= sanitize($exam['end_date'] ?? '') ?>">
                </div>
            </div>
            
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
