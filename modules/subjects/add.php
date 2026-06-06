<?php
/**
 * Subject Management - Add Page with Auto-Class-Mapping
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';

// Fetch classes for dropdown
$stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch active teachers for checklist
$stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND school_id = ? ORDER BY full_name");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $code = sanitize($_POST['code'] ?? '');
    $name_en = sanitize($_POST['name_en'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $type = sanitize($_POST['type'] ?? 'compulsory');
    $full_marks = (int)($_POST['full_marks'] ?? 100);
    $pass_marks = (int)($_POST['pass_marks'] ?? 40);
    $credit_hours = (int)($_POST['credit_hours'] ?? 4);
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    
    if (empty($class_id)) {
        $error = 'Class selection is required';
    } elseif (empty($name_en)) {
        $error = 'Subject name in English is required';
    } else {
        try {
            $db->beginTransaction();
            
            // 1. Insert Subject
            $stmt = $db->prepare("INSERT INTO subjects (code, name_en, name_np, type, full_marks, pass_marks, credit_hours, school_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours, $school_id]);
            $new_subject_id = $db->lastInsertId();
            
            // 2. Insert mapping in class_subjects
            $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
            $stmt_map = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, credit_hours, teacher_id, school_id) VALUES (?, ?, ?, ?, ?)");
            $stmt_map->execute([$class_id, $new_subject_id, $credit_hours, $teacher_id_str, $school_id]);
            
            $db->commit();
            
            Auth::logActivity(Auth::getUserId(), 'Add Subject and Class Mapping', 'subjects', $new_subject_id);
            redirect(APP_URL . '/modules/subjects/index.php', 'Subject added and mapped successfully');
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Add Subject';
$activePage = 'subjects';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-plus-circle"></i> Add New Subject</h2>
    <a href="<?= APP_URL ?>/modules/subjects/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
</div>
<?php endif; ?>

<div class="card max-width-700 mx-auto">
    <div class="card-header bg-primary text-white py-3">
        <h5 class="mb-0"><i class="fas fa-book"></i> Subject Configuration</h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="" class="needs-validation" novalidate>
            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label for="class_id" class="form-label">Select Class *</label>
                    <select class="form-select" id="class_id" name="class_id" required>
                        <option value="">Choose Class</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select a class level.</div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="code" class="form-label">Subject Code / Short Name</label>
                    <input type="text" class="form-control" id="code" name="code" placeholder="e.g. M-101, NEP-10">
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label for="name_en" class="form-label">Subject Name (English) *</label>
                    <input type="text" class="form-control" id="name_en" name="name_en" placeholder="e.g. Mathematics" required>
                    <div class="invalid-feedback">Subject name in English is required.</div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="name_np" class="form-label">Subject Name (Nepali)</label>
                    <input type="text" class="form-control" id="name_np" name="name_np" placeholder="e.g. गणित">
                </div>
            </div>
            
            <div class="row g-3">
                <div class="col-md-3 mb-3">
                    <label for="type" class="form-label">Subject Type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="compulsory" selected>Compulsory</option>
                        <option value="optional">Optional (Elective)</option>
                        <option value="local">Local Subject</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="credit_hours" class="form-label">Credit Hours (CH) *</label>
                    <input type="number" class="form-control" id="credit_hours" name="credit_hours" value="4" min="1" max="10" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="full_marks" class="form-label">Default Full Marks *</label>
                    <input type="number" class="form-control" id="full_marks" name="full_marks" value="100" min="1" max="200" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="pass_marks" class="form-label">Default Pass Marks *</label>
                    <input type="number" class="form-control" id="pass_marks" name="pass_marks" value="40" min="1" max="100" required>
                </div>
            </div>

            <!-- Teachers multi-selection -->
            <div class="mb-4">
                <label class="form-label d-block">Assign Subject Teachers</label>
                <div class="border rounded p-3 bg-light" style="max-height: 180px; overflow-y: auto;">
                    <?php if (count($teachers) > 0): ?>
                        <div class="row g-2">
                            <?php foreach ($teachers as $t): ?>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" id="teacher_<?= $t['id'] ?>">
                                    <label class="form-check-label text-dark" for="teacher_<?= $t['id'] ?>">
                                        <?= sanitize($t['full_name']) ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-0">No active teachers found in school. You can assign them later.</p>
                    <?php endif; ?>
                </div>
                <small class="text-muted">Select multiple teachers if this subject is taught by more than one teacher in the selected class.</small>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-save"></i> Save Subject
            </button>
        </form>
    </div>
</div>

<script>
// Bootstrap form validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
