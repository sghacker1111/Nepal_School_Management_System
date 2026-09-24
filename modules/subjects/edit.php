<?php
/**
 * Subject Management - Edit Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$success = '';

// Fetch subject details
$stmt = $db->prepare("SELECT * FROM subjects WHERE id = ? AND (school_id = ? OR school_id = 0)");
$stmt->execute([$id, $school_id]);
$subject = $stmt->fetch();

if (!$subject) {
    redirect(APP_URL . '/modules/subjects/index.php', 'Subject not found', 'error');
}

// Fetch current class mapping
$stmt_map = $db->prepare("SELECT * FROM class_subjects WHERE subject_id = ? AND (school_id = ? OR school_id = 0) LIMIT 1");
$stmt_map->execute([$id, $school_id]);
$mapping = $stmt_map->fetch();

// Fetch classes for dropdown
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ? OR school_id = 0) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch active teachers for checklist
$stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND (school_id = ? OR school_id = 0) ORDER BY full_name");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

// Pre-fill existing teachers
$current_teachers = [];
if ($mapping && $mapping['teacher_id']) {
    $current_teachers = explode(',', $mapping['teacher_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $code = sanitize($_POST['code'] ?? '');
    $name_en = sanitize($_POST['name_en'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $type = sanitize($_POST['type'] ?? 'compulsory');
    $full_marks = round((float)($_POST['full_marks'] ?? 100.0), 2);
    $pass_marks = round((float)($_POST['pass_marks'] ?? 40.0), 2);
    $credit_hours = round((float)($_POST['credit_hours'] ?? 4.0), 2);
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    
    if (empty($class_id)) {
        $error = 'Class selection is required';
    } elseif (empty($name_en)) {
        $error = 'Subject name in English is required';
    } else {
        // Check if another subject in this class already has the same name or code
        $stmt_check = $db->prepare("
            SELECT s.name_en, s.code FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.id
            WHERE cs.class_id = ? AND cs.subject_id != ? AND (
                LOWER(TRIM(s.name_en)) = LOWER(TRIM(?))
                OR (? != '' AND s.name_np != '' AND s.name_np IS NOT NULL AND TRIM(s.name_np) = TRIM(?))
                OR (? != '' AND LOWER(TRIM(s.code)) = LOWER(TRIM(?)))
            )
        ");
        $stmt_check->execute([$class_id, $id, $name_en, $name_np, $name_np, $code, $code]);
        $existing = $stmt_check->fetch();

        if ($existing) {
            $error = "Duplicate Subject Blocked: Another subject with name '" . htmlspecialchars($existing['name_en']) . "' (Code: " . htmlspecialchars($existing['code']) . ") is already assigned to the selected class. Duplicate subjects for each class are not allowed.";
        } else {
            try {
                $db->beginTransaction();
                
                // 1. Update Subject
                $stmt = $db->prepare("UPDATE subjects SET code = ?, name_en = ?, name_np = ?, type = ?, full_marks = ?, pass_marks = ?, credit_hours = ? WHERE id = ? AND (school_id = ? OR school_id = 0)");
                $stmt->execute([$code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours, $id, $school_id]);
                
                // 2. Update mapping in class_subjects
                $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
                if ($mapping) {
                    $stmt_up = $db->prepare("UPDATE class_subjects SET class_id = ?, credit_hours = ?, teacher_id = ? WHERE id = ?");
                    $stmt_up->execute([$class_id, $credit_hours, $teacher_id_str, $mapping['id']]);
                } else {
                    $stmt_ins = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, credit_hours, teacher_id, school_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$class_id, $id, $credit_hours, $teacher_id_str, $school_id]);
                }
                
                $db->commit();
                
                Auth::logActivity(Auth::getUserId(), 'Edit Subject and Class Mapping', 'subjects', $id);
                redirect(APP_URL . '/modules/subjects/index.php', 'Subject updated successfully');
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $error = 'Duplicate Subject Blocked: This subject is already assigned to this class.';
                } else {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Edit Subject';
$activePage = 'subjects';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit"></i> Edit Subject</h2>
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
        <h5 class="mb-0"><i class="fas fa-book"></i> Edit Subject Configuration</h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="" class="needs-validation" novalidate>
            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label for="class_id" class="form-label">Select Class *</label>
                    <select class="form-select" id="class_id" name="class_id" required>
                        <option value="">Choose Class</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($mapping && $mapping['class_id'] == $c['id']) ? 'selected' : '' ?>>
                            <?= sanitize($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback">Please select a class level.</div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="code" class="form-label">Subject Code / Short Name</label>
                    <input type="text" class="form-control" id="code" name="code" value="<?= sanitize($subject['code'] ?? '') ?>" placeholder="e.g. M-101, NEP-10">
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6 mb-3">
                    <label for="name_en" class="form-label">Subject Name (English) *</label>
                    <input type="text" class="form-control" id="name_en" name="name_en" value="<?= sanitize($subject['name_en'] ?? '') ?>" placeholder="e.g. Mathematics" required>
                    <div class="invalid-feedback">Subject name in English is required.</div>
                </div>
                
                <div class="col-md-6 mb-3">
                    <label for="name_np" class="form-label">Subject Name (Nepali)</label>
                    <input type="text" class="form-control" id="name_np" name="name_np" value="<?= sanitize($subject['name_np'] ?? '') ?>" placeholder="e.g. गणित">
                </div>
            </div>
            
            <div class="row g-3">
                <div class="col-md-3 mb-3">
                    <label for="type" class="form-label">Subject Type</label>
                    <select class="form-select" id="type" name="type">
                        <option value="compulsory" <?= $subject['type'] === 'compulsory' ? 'selected' : '' ?>>Compulsory</option>
                        <option value="optional" <?= $subject['type'] === 'optional' ? 'selected' : '' ?>>Optional (Elective)</option>
                        <option value="local" <?= $subject['type'] === 'local' ? 'selected' : '' ?>>Local Subject</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="credit_hours" class="form-label">Credit Hours (CH) *</label>
                    <input type="number" step="0.01" class="form-control" id="credit_hours" name="credit_hours" value="<?= sanitize($subject['credit_hours'] ?? '4') ?>" min="0" max="10" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="full_marks" class="form-label">Default Full Marks *</label>
                    <input type="number" step="0.01" class="form-control" id="full_marks" name="full_marks" value="<?= sanitize($subject['full_marks'] ?? '100') ?>" min="0" max="200" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="pass_marks" class="form-label">Default Pass Marks *</label>
                    <input type="number" step="0.01" class="form-control" id="pass_marks" name="pass_marks" value="<?= sanitize($subject['pass_marks'] ?? '40') ?>" min="0" max="100" required>
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
                                    <input class="form-check-input" type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" id="teacher_<?= $t['id'] ?>" <?= in_array($t['id'], $current_teachers) ? 'checked' : '' ?>>
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
                <i class="fas fa-save"></i> Update Subject
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
