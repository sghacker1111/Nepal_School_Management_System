<?php
/**
 * Classes Management - Subject Mapping & Teacher Assignment
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$error = '';
$success = '';
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Fetch class details
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ?");
$stmt->execute([$class_id, $school_id]);
$class = $stmt->fetch();

if (!$class) {
    redirect(APP_URL . '/modules/classes/index.php', 'Class not found', 'error');
}

// Handle Add Subject Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'map_subject') {
    $subject_id = (int)$_POST['subject_id'];
    $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $credit_hours = (int)($_POST['credit_hours'] ?? 4);
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
    
    if (empty($subject_id)) {
        $error = "Subject is required.";
    } else {
        try {
            // Check if already mapped to this class/section combo
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM class_subjects 
                WHERE class_id = ? AND subject_id = ? AND (
                    (section_id IS NULL AND ? IS NULL)
                    OR
                    (section_id = ?)
                )
            ");
            $stmt->execute([$class_id, $subject_id, $section_id, $section_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "This subject is already mapped to this class / section.";
            } else {
                $stmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, section_id, credit_hours, teacher_id, school_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$class_id, $subject_id, $section_id, $credit_hours, $teacher_id_str, $school_id]);
                Auth::logActivity(Auth::getUserId(), 'Map Subject to Class', 'class_subjects', $db->lastInsertId());
                $success = "Subject mapped successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle Delete Subject Mapping
if (isset($_GET['delete_mapping'])) {
    $mapping_id = (int)$_GET['delete_mapping'];
    try {
        $stmt = $db->prepare("DELETE FROM class_subjects WHERE id = ? AND class_id = ?");
        $stmt->execute([$mapping_id, $class_id]);
        Auth::logActivity(Auth::getUserId(), 'Delete Subject Class Mapping', 'class_subjects', $mapping_id);
        redirect(APP_URL . '/modules/classes/subjects.php?class_id=' . $class_id, 'Subject mapping deleted successfully.');
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle Edit Mapping (AJAX or direct POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_mapping') {
    $mapping_id = (int)$_POST['mapping_id'];
    $credit_hours = round((float)$_POST['credit_hours'], 2);
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
    
    try {
        $stmt = $db->prepare("UPDATE class_subjects SET credit_hours = ?, teacher_id = ? WHERE id = ? AND class_id = ?");
        $stmt->execute([$credit_hours, $teacher_id_str, $mapping_id, $class_id]);
        Auth::logActivity(Auth::getUserId(), 'Edit Class Subject Assignment', 'class_subjects', $mapping_id);
        $success = "Subject mapping updated successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch subjects currently mapped to this class
$stmt = $db->prepare("
    SELECT cs.*, s.name_en as subject_name_en, s.name_np as subject_name_np, s.code as subject_code, sec.name as section_name
    FROM class_subjects cs 
    JOIN subjects s ON cs.subject_id = s.id 
    LEFT JOIN sections sec ON cs.section_id = sec.id
    WHERE cs.class_id = ? 
    ORDER BY s.name_en, sec.name
");
$stmt->execute([$class_id]);
$mapped_subjects = $stmt->fetchAll();

// Fetch all available subjects for mapping (excluding already mapped ones)
$stmt = $db->prepare("
    SELECT * FROM subjects 
    WHERE school_id = ? AND id NOT IN (SELECT subject_id FROM class_subjects WHERE class_id = ?) 
    ORDER BY name_en
");
$stmt->execute([$school_id, $class_id]);
$available_subjects = $stmt->fetchAll();

// Fetch all class sections
$stmt = $db->prepare("SELECT * FROM sections WHERE class_id = ? AND school_id = ? ORDER BY name");
$stmt->execute([$class_id, $school_id]);
$class_sections = $stmt->fetchAll();

// Fetch all active teachers for dropdown
$stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND school_id = ? ORDER BY full_name");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

$all_teachers_map = array_column($teachers, 'full_name', 'id');

$pageTitle = 'Class Subjects Configuration';
$activePage = 'classes';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-book"></i> Subject Configuration: <?= sanitize($class['name']) ?></h2>
    <a href="<?= APP_URL ?>/modules/classes/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Classes
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> <?= sanitize($success) ?>
</div>
<?php endif; ?>

<div class="row">
    <!-- Mapped Subjects list -->
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-primary"><i class="fas fa-list"></i> Configured Subjects for <?= sanitize($class['name']) ?></h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Target Section</th>
                                <th>Credit Hours</th>
                                <th>Assigned Teacher(s)</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($mapped_subjects) > 0): ?>
                                <?php foreach ($mapped_subjects as $ms): ?>
                                <tr>
                                    <td><code><?= sanitize($ms['subject_code'] ?? 'N/A') ?></code></td>
                                    <td>
                                        <strong><?= sanitize($ms['subject_name_en']) ?></strong><br>
                                        <small class="text-muted"><?= sanitize($ms['subject_name_np'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($ms['section_id']): ?>
                                            <span class="badge bg-info text-dark"><i class="fas fa-shapes"></i> Section <?= sanitize($ms['section_name']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-layer-group"></i> All Sections</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $ms['credit_hours'] ?> CH</td>
                                    <td>
                                        <i class="fas fa-user-tie text-secondary me-1"></i>
                                        <?php
                                        if (!empty($ms['teacher_id'])) {
                                            $t_ids = explode(',', $ms['teacher_id']);
                                            $t_names = [];
                                            foreach ($t_ids as $tid) {
                                                if (isset($all_teachers_map[$tid])) {
                                                    $t_names[] = sanitize($all_teachers_map[$tid]);
                                                }
                                            }
                                            echo implode(', ', $t_names);
                                        } else {
                                            echo '<span class="text-muted">Not Assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <button onclick="editMapping(<?= $ms['id'] ?>, <?= $ms['credit_hours'] ?>, '<?= $ms['teacher_id'] ?>')" 
                                                class="btn btn-sm btn-outline-warning" title="Edit Assignment">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="<?= APP_URL ?>/modules/classes/subjects.php?class_id=<?= $class_id ?>&delete_mapping=<?= $ms['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger" title="Delete Mapping"
                                           onclick="return confirm('Are you sure you want to remove this subject mapping?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No subjects mapped to this class yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Map New Subject Form -->
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-success"><i class="fas fa-plus-circle"></i> Map Subject to Class</h5>
            </div>
            <div class="card-body">
                <?php if (count($available_subjects) > 0): ?>
                    <form method="POST" action="" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="map_subject">
                        
                        <div class="mb-3">
                            <label class="form-label">Select Subject *</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Choose Subject</option>
                                <?php foreach ($available_subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>">
                                    <?= sanitize($sub['name_en']) ?> (<?= sanitize($sub['code'] ?? 'N/A') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a subject.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Target Section (Optional)</label>
                            <select name="section_id" class="form-select">
                                <option value="">All Sections (Global Class Subject)</option>
                                <?php foreach ($class_sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>">Section <?= sanitize($sec['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Choose a specific section if this subject is only taught in that section.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Credit Hours *</label>
                            <input type="number" step="0.01" name="credit_hours" class="form-control" value="4" min="0" max="10" required>
                            <div class="invalid-feedback">Credit hours must be between 0 and 10.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label d-block">Assign Teachers</label>
                            <div class="border rounded p-2 bg-light" style="max-height: 120px; overflow-y: auto;">
                                <?php foreach ($teachers as $t): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" id="teacher_new_<?= $t['id'] ?>">
                                    <label class="form-check-label text-dark" for="teacher_new_<?= $t['id'] ?>">
                                        <?= sanitize($t['full_name']) ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save"></i> Map Subject
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-muted text-center py-4 mb-0">All available subjects are already mapped to this class.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit Mapping Modal -->
<div class="modal fade" id="editMappingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_mapping">
            <input type="hidden" name="mapping_id" id="editMappingId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Subject Assignment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Credit Hours *</label>
                        <input type="number" step="0.01" name="credit_hours" id="editCreditHours" class="form-control" required min="0" max="10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label d-block">Assigned Teachers</label>
                        <div class="border rounded p-2 bg-light" style="max-height: 150px; overflow-y: auto;">
                            <?php foreach ($teachers as $t): ?>
                            <div class="form-check">
                                <input class="form-check-input edit-teacher-check" type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" id="edit_teacher_<?= $t['id'] ?>">
                                <label class="form-check-label text-dark" for="edit_teacher_<?= $t['id'] ?>">
                                    <?= sanitize($t['full_name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function editMapping(id, creditHours, teacherIds) {
    document.getElementById('editMappingId').value = id;
    document.getElementById('editCreditHours').value = creditHours;
    
    // Uncheck all edit checkboxes
    const checkboxes = document.querySelectorAll('.edit-teacher-check');
    checkboxes.forEach(cb => cb.checked = false);
    
    // Check assigned teachers
    if (teacherIds) {
        const ids = teacherIds.toString().split(',');
        ids.forEach(tid => {
            const cb = document.getElementById('edit_teacher_' + tid.trim());
            if (cb) {
                cb.checked = true;
            }
        });
    }
    
    var editModal = new bootstrap.Modal(document.getElementById('editMappingModal'));
    editModal.show();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
