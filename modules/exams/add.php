<?php
/**
 * Exam Management - Add Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/report_issue.php';

Auth::requireRole(['super_admin', 'school_admin', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';

// Fetch active academic year
$stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$school_id]);
$academicYear = $stmt->fetch();

if (!$academicYear) {
    redirect(APP_URL . '/modules/exams/index.php', 'No active academic year set in settings!', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $type = sanitize($_POST['type'] ?? 'terminal');
    $start_date = ($_POST['start_date'] ?? '') ?: null;
    $end_date = ($_POST['end_date'] ?? '') ?: null;
    $has_internal = isset($_POST['has_internal']) ? (int)$_POST['has_internal'] : 0;
    $result_system = sanitize($_POST['result_system'] ?? 'gpa');
    
    if (empty($name)) {
        $error = 'Exam name is required';
    } else {
        try {
            $issue=reportIssueDate([],$_POST);
            $stmt = $db->prepare("INSERT INTO exams (name, type, academic_year_id, start_date, end_date, is_published, is_locked, school_id, has_internal, result_system,issue_date_mode,issue_date) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $academicYear['id'], $start_date, $end_date, $school_id, $type==='unit'?0:$has_internal, $result_system,$issue['mode'],$issue['manual']?:null]);
            $new_id = $db->lastInsertId();
            
            Auth::logActivity(Auth::getUserId(), 'Create Exam', 'exams', $new_id);
            redirect(APP_URL . '/modules/exams/view.php?id=' . $new_id, 'Exam created successfully. Now configure subjects and weightage.');
        } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
        catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
try{$issue=reportIssueDate([],$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[]);}catch(InvalidArgumentException $e){$issue=reportIssueDate([]);}

$pageTitle = 'Create New Exam';
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-plus-circle"></i> Create New Exam</h2>
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
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Exam Definition Details</h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Active Academic Year</label>
                <input type="text" class="form-control bg-light text-muted font-monospace" value="<?= getAcademicYearLabel($academicYear['year_bs'], $academicYear['year_ad']) ?>" disabled>
            </div>
            
            <div class="mb-3">
                <label for="name" class="form-label">Exam Name *</label>
                <input type="text" class="form-control" id="name" name="name" placeholder="e.g. First Terminal Examination" required>
            </div>
            
            <div class="mb-3">
                <label for="type" class="form-label">Exam Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="terminal" selected>Terminal Exam (Term Exam)</option>
                    <option value="final">Final Exam</option>
                    <option value="unit">Unit Test / Class Test</option>
                    <option value="practical">Practical / Assessment Only</option>
                </select>
            </div>

            <div class="mb-3">
                <label for="result_system" class="form-label">Result System (for Unit Tests or General)</label>
                <select class="form-select" id="result_system" name="result_system">
                    <option value="gpa" selected>Grading System (GPA / Grades)</option>
                    <option value="percentage">Marksheet System (Marks / Percentage)</option>
                </select>
            </div>
            
            <div class="mb-3">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="has_internal" name="has_internal" value="1" checked>
                    <label class="form-check-label fw-bold text-primary" for="has_internal">
                        Include Internal Exam / Practical Assessment Component
                    </label>
                </div>
                <div class="form-text text-muted">If disabled, the exam will only have theory (external) marks. The practical marks input and calculations will be skipped.</div>
            </div>
            
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date">
                </div>
                <div class="col-md-6">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date">
                </div>
            </div>
            
            <div class="row g-3 mb-4"><?php include __DIR__.'/../../includes/report_issue_controls.php'; ?></div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-save"></i> Save and Configure Subjects
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('type').addEventListener('change',function(){
    const internal=document.getElementById('has_internal');internal.disabled=this.value==='unit';
    if(internal.disabled)internal.checked=false;
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
