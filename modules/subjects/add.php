<?php
/**
 * Subject Management - Add Page with Auto-Class-Mapping
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/catalog_validation.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}
$error = '';

// Fetch classes for dropdown (all 16 standard classes)
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch active teachers for checklist
$stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND (school_id = ?) ORDER BY full_name");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $code = sanitize($_POST['code'] ?? '');
    $name_en = subjectCanonicalName(sanitize($_POST['name_en'] ?? ''));
    $name_np = sanitize($_POST['name_np'] ?? '');
    $is_optional = !empty($_POST['is_optional']) ? 1 : 0;
    $type = $is_optional ? 'optional' : 'compulsory';
    
    // Theory (TH) individual fields
    $th_credit_hours = round((float)($_POST['th_credit_hours'] ?? 3.0), 2);
    $th_full_marks = round((float)($_POST['th_full_marks'] ?? 75.0), 2);
    $th_pass_marks = round((float)($_POST['th_pass_marks'] ?? 26.25), 2);
    
    // Internal (IN) individual fields
    $in_credit_hours = round((float)($_POST['in_credit_hours'] ?? 1.0), 2);
    $in_full_marks = round((float)($_POST['in_full_marks'] ?? 25.0), 2);
    $in_pass_marks = round((float)($_POST['in_pass_marks'] ?? 10.0), 2);
    
    // Combined Totals
    $credit_hours = round($th_credit_hours + $in_credit_hours, 2);
    $full_marks = round($th_full_marks + $in_full_marks, 2);
    $pass_marks = round($th_pass_marks + $in_pass_marks, 2);
    
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    
    if (empty($class_id)) {
        $error = 'Class selection is required';
    } elseif (empty($name_en)) {
        $error = 'Subject name in English is required';
    } else {
        // Check if this class already has a subject with identical English name, Nepali name, or code
        $stmt_check = $db->prepare("
            SELECT s.name_en, s.code FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.id
            WHERE cs.class_id = ? AND (
                LOWER(TRIM(s.name_en)) = LOWER(TRIM(?))
                OR (? != '' AND s.name_np != '' AND s.name_np IS NOT NULL AND TRIM(s.name_np) = TRIM(?))
                OR (? != '' AND LOWER(TRIM(s.code)) = LOWER(TRIM(?)))
            )
        ");
        $stmt_check->execute([$class_id, $name_en, $name_np, $name_np, $code, $code]);
        $existing = $stmt_check->fetch();

        if ($existing) {
            $error = "Duplicate Subject Blocked: A subject with name '" . htmlspecialchars($existing['name_en']) . "' (Code: " . htmlspecialchars($existing['code']) . ") is already assigned to the selected class. Duplicate subjects for each class are not allowed.";
        } else {
            try {
                $db->beginTransaction();
                catalogWriteLock($db,(int)$school_id);
                validateUniqueClassSubject($db,(int)$school_id,$class_id,['name_en'=>$name_en,'name_np'=>$name_np,'code'=>$code]);
                
                // 1. Insert Subject with Theory and Internal fields
                $stmt = $db->prepare("
                    INSERT INTO subjects (
                        code, name_en, name_np, type, full_marks, pass_marks, credit_hours,
                        th_credit_hours, in_credit_hours, th_full_marks, th_pass_marks, in_full_marks, in_pass_marks,
                        is_hidden, school_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)
                ");
                $stmt->execute([
                    $code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours,
                    $th_credit_hours, $in_credit_hours, $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks,
                    $school_id
                ]);
                $new_subject_id = $db->lastInsertId();
                
                // 2. Insert mapping in class_subjects
                $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
                $stmt_map = $db->prepare("
                    INSERT INTO class_subjects (
                        class_id, subject_id, credit_hours, teacher_id, school_id, is_optional, is_active,
                        th_credit_hours, in_credit_hours, th_full_marks, th_pass_marks, in_full_marks, in_pass_marks
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_map->execute([
                    $class_id, $new_subject_id, $credit_hours, $teacher_id_str, $school_id, $is_optional,
                    $th_credit_hours, $in_credit_hours, $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks
                ]);
                
                $db->commit();
                
                Auth::logActivity(Auth::getUserId(), 'Add Subject and Class Mapping', 'subjects', $new_subject_id);
                redirect(APP_URL . '/modules/subjects/index.php', 'Subject added and mapped successfully with Theory & Internal evaluation components.');
            } catch (PDOException|InvalidArgumentException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($e instanceof InvalidArgumentException) {
                    $error=$e->getMessage();
                } elseif (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $error = 'Duplicate Subject Blocked: This subject is already assigned to this class.';
                } else {
                    $error = schoolPublicError($e);
                }
            }
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
            
            <!-- Theory (TH) and Internal (IN) Individual Field Boxes -->
            <div class="row g-3 mb-3">
                <!-- Theory (TH) Card -->
                <div class="col-md-6">
                    <div class="card border border-primary h-100">
                        <div class="card-header bg-primary text-white py-2">
                            <h6 class="mb-0 fw-bold"><i class="fas fa-file-alt me-1"></i> Theory Evaluation (TH)</h6>
                        </div>
                        <div class="card-body p-3 bg-light">
                            <div class="mb-2">
                                <label for="th_credit_hours" class="form-label small fw-bold">Theory Credit Hours (TH) *</label>
                                <input type="number" step="0.01" class="form-control" id="th_credit_hours" name="th_credit_hours" value="3.00" min="0" max="10" required oninput="calcTotals()">
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label for="th_full_marks" class="form-label small fw-bold">Theory Full Marks *</label>
                                    <input type="number" step="0.01" class="form-control" id="th_full_marks" name="th_full_marks" value="75.00" min="0" max="100" required oninput="calcTotals()">
                                </div>
                                <div class="col-6">
                                    <label for="th_pass_marks" class="form-label small fw-bold">Theory Pass Marks *</label>
                                    <input type="number" step="0.01" class="form-control" id="th_pass_marks" name="th_pass_marks" value="26.25" min="0" max="100" required oninput="calcTotals()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Internal (IN) Card (Changed from Practical) -->
                <div class="col-md-6">
                    <div class="card border border-info h-100">
                        <div class="card-header bg-info text-dark py-2">
                            <h6 class="mb-0 fw-bold"><i class="fas fa-tasks me-1"></i> Internal Assessment (IN)</h6>
                        </div>
                        <div class="card-body p-3 bg-light">
                            <div class="mb-2">
                                <label for="in_credit_hours" class="form-label small fw-bold">Internal Credit Hours (IN) *</label>
                                <input type="number" step="0.01" class="form-control" id="in_credit_hours" name="in_credit_hours" value="1.00" min="0" max="10" required oninput="calcTotals()">
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label for="in_full_marks" class="form-label small fw-bold">Internal Full Marks *</label>
                                    <input type="number" step="0.01" class="form-control" id="in_full_marks" name="in_full_marks" value="25.00" min="0" max="100" required oninput="calcTotals()">
                                </div>
                                <div class="col-6">
                                    <label for="in_pass_marks" class="form-label small fw-bold">Internal Pass Marks *</label>
                                    <input type="number" step="0.01" class="form-control" id="in_pass_marks" name="in_pass_marks" value="10.00" min="0" max="100" required oninput="calcTotals()">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Evaluation Summary (Auto Sum) -->
            <div class="p-3 mb-3 bg-white border rounded shadow-sm">
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted mb-1">Total Credit Hours (TH + IN)</label>
                        <div class="input-group">
                            <input type="text" class="form-control fw-bold bg-light" id="total_credit_hours" readonly value="4.00">
                            <span class="input-group-text bg-light">CH</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted mb-1">Total Full Marks</label>
                        <input type="text" class="form-control fw-bold text-success bg-light" id="total_full_marks" readonly value="100.00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-muted mb-1">Total Pass Marks</label>
                        <input type="text" class="form-control fw-bold text-danger bg-light" id="total_pass_marks" readonly value="36.25">
                    </div>
                </div>
            </div>

            <!-- Optional Subject Toggle -->
            <div class="mb-3 p-3 bg-light border rounded">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_optional" name="is_optional" value="1">
                    <label class="form-check-label fw-bold" for="is_optional">
                        Elective / Optional Subject (ऐच्छिक विषय)
                    </label>
                    <div class="text-muted small">Tick if this subject is elective / optional for students in this class. Untick for compulsory core subjects.</div>
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
                <i class="fas fa-save"></i> Save Subject (Theory & Internal)
            </button>
        </form>
    </div>
</div>

<script>
function calcTotals() {
    var th_ch = parseFloat(document.getElementById('th_credit_hours').value) || 0;
    var in_ch = parseFloat(document.getElementById('in_credit_hours').value) || 0;
    document.getElementById('total_credit_hours').value = (th_ch + in_ch).toFixed(2);

    var th_fm = parseFloat(document.getElementById('th_full_marks').value) || 0;
    var in_fm = parseFloat(document.getElementById('in_full_marks').value) || 0;
    document.getElementById('total_full_marks').value = (th_fm + in_fm).toFixed(2);

    var th_pm = parseFloat(document.getElementById('th_pass_marks').value) || 0;
    var in_pm = parseFloat(document.getElementById('in_pass_marks').value) || 0;
    document.getElementById('total_pass_marks').value = (th_pm + in_pm).toFixed(2);
}

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
