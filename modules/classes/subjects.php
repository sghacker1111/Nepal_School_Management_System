<?php
/**
 * Classes Management - Subject Mapping & Teacher Assignment (Compulsory & Optional Subjects)
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
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}
$stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND (school_id = ? OR school_id = 0)");
$stmt->execute([$class_id, $school_id]);
$class = $stmt->fetch();

if (!$class) {
    redirect(APP_URL . '/modules/classes/index.php', 'Class not found', 'error');
}

// Handle Toggle Optional Subject Active State (AJAX or direct POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_optional') {
    $mapping_id = (int)($_POST['mapping_id'] ?? 0);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $db->prepare("UPDATE class_subjects SET is_active = ? WHERE id = ? AND class_id = ?");
        $stmt->execute([$is_active, $mapping_id, $class_id]);
        
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                   || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'is_active' => $is_active, 'message' => $is_active ? 'Subject is now active and in use.' : 'Subject is now hidden from class.']);
            exit;
        }
        redirect(APP_URL . '/modules/classes/subjects.php?class_id=' . $class_id, 'Subject status updated successfully.');
    } catch (PDOException $e) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle Add Subject Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'map_subject') {
    $subject_id = (int)$_POST['subject_id'];
    $section_id = !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $credit_hours = round((float)($_POST['credit_hours'] ?? 4), 2);
    $is_optional = isset($_POST['is_optional']) ? (int)$_POST['is_optional'] : 0;
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
                $stmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, section_id, credit_hours, teacher_id, school_id, is_optional, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$class_id, $subject_id, $section_id, $credit_hours, $teacher_id_str, $school_id, $is_optional]);
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

// Handle Edit Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_mapping') {
    $mapping_id = (int)$_POST['mapping_id'];
    $credit_hours = round((float)$_POST['credit_hours'], 2);
    $is_optional = isset($_POST['is_optional']) ? (int)$_POST['is_optional'] : 0;
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
    
    try {
        $stmt = $db->prepare("UPDATE class_subjects SET credit_hours = ?, teacher_id = ?, is_optional = ? WHERE id = ? AND class_id = ?");
        $stmt->execute([$credit_hours, $teacher_id_str, $is_optional, $mapping_id, $class_id]);
        Auth::logActivity(Auth::getUserId(), 'Edit Class Subject Assignment', 'class_subjects', $mapping_id);
        $success = "Subject mapping updated successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch subjects currently mapped to this class
$stmt = $db->prepare("
    SELECT cs.*, s.name_en as subject_name_en, s.name_np as subject_name_np, s.code as subject_code, s.type as subject_catalog_type, sec.name as section_name
    FROM class_subjects cs 
    JOIN subjects s ON cs.subject_id = s.id 
    LEFT JOIN sections sec ON cs.section_id = sec.id
    WHERE cs.class_id = ? 
    ORDER BY cs.is_optional ASC, s.name_en ASC, sec.name ASC
");
$stmt->execute([$class_id]);
$all_mapped = $stmt->fetchAll();

$compulsory_subjects = [];
$optional_subjects = [];
foreach ($all_mapped as $m) {
    if (!empty($m['is_optional'])) {
        $optional_subjects[] = $m;
    } else {
        $compulsory_subjects[] = $m;
    }
}

// Fetch all available subjects for mapping (excluding already mapped ones)
$stmt = $db->prepare("
    SELECT * FROM subjects 
    WHERE (school_id = ? OR school_id = 0) AND id NOT IN (SELECT subject_id FROM class_subjects WHERE class_id = ?) 
    ORDER BY type ASC, name_en ASC
");
$stmt->execute([$school_id, $class_id]);
$available_subjects = $stmt->fetchAll();

// Fetch all class sections
$stmt = $db->prepare("SELECT * FROM sections WHERE class_id = ? AND (school_id = ? OR school_id = 0) ORDER BY name");
$stmt->execute([$class_id, $school_id]);
$class_sections = $stmt->fetchAll();

// Fetch all active teachers for dropdown
$stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND (school_id = ? OR school_id = 0) ORDER BY full_name");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

$all_teachers_map = array_column($teachers, 'full_name', 'id');

$pageTitle = 'Class Subjects - ' . sanitize($class['name']);
$activePage = 'classes';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/modules/classes/index.php">Classes</a></li>
                <li class="breadcrumb-item active"><?= sanitize($class['name']) ?></li>
            </ol>
        </nav>
        <h2 class="mb-0">
            <i class="fas fa-book text-primary me-2"></i>Subject Curriculum: <strong><?= sanitize($class['name']) ?></strong> 
            <?php if (!empty($class['name_np'])): ?>
                <span class="text-muted fs-5">(<?= sanitize($class['name_np']) ?>)</span>
            <?php endif; ?>
            <span class="badge <?= $class['level'] === 'Secondary' ? 'bg-success' : 'bg-primary' ?> ms-2 fs-6">
                <?= $class['level'] ?> Level
            </span>
        </h2>
    </div>
    <a href="<?= APP_URL ?>/modules/classes/index.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back to Classes
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show shadow-sm">
    <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show shadow-sm">
    <i class="fas fa-check-circle me-2"></i> <?= sanitize($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <!-- Main Subjects Listing -->
    <div class="col-lg-8 mb-4">
        <!-- 1. Compulsory Subjects Section -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <h5 class="mb-0 text-primary">
                    <i class="fas fa-layer-group me-2"></i> Compulsory Subjects (अनिवार्य विषयहरू)
                </h5>
                <span class="badge bg-primary rounded-pill"><?= count($compulsory_subjects) ?> Compulsory</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 15%;">Code</th>
                                <th>Subject Name</th>
                                <th>Section</th>
                                <th>Credit Hrs</th>
                                <th>Assigned Teacher(s)</th>
                                <th class="text-end" style="width: 15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($compulsory_subjects) > 0): ?>
                                <?php foreach ($compulsory_subjects as $cs): ?>
                                <tr>
                                    <td><span class="badge bg-secondary font-monospace"><?= sanitize($cs['subject_code'] ?? 'N/A') ?></span></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= sanitize($cs['subject_name_en']) ?></div>
                                        <small class="text-muted"><?= sanitize($cs['subject_name_np'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($cs['section_id']): ?>
                                            <span class="badge bg-info text-dark">Section <?= sanitize($cs['section_name']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border">All Sections</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= $cs['credit_hours'] ?></strong> CH</td>
                                    <td>
                                        <?php
                                        if (!empty($cs['teacher_id'])) {
                                            $t_ids = explode(',', $cs['teacher_id']);
                                            $t_names = [];
                                            foreach ($t_ids as $tid) {
                                                if (isset($all_teachers_map[$tid])) {
                                                    $t_names[] = sanitize($all_teachers_map[$tid]);
                                                }
                                            }
                                            echo '<small><i class="fas fa-chalkboard-teacher text-primary me-1"></i>' . implode(', ', $t_names) . '</small>';
                                        } else {
                                            echo '<span class="text-muted small">Not Assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <button onclick="editMapping(<?= $cs['id'] ?>, <?= $cs['credit_hours'] ?>, '<?= $cs['teacher_id'] ?>', 0)" 
                                                class="btn btn-sm btn-outline-warning me-1" title="Edit Assignment">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="<?= APP_URL ?>/modules/classes/subjects.php?class_id=<?= $class_id ?>&delete_mapping=<?= $cs['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger" title="Remove Subject"
                                           onclick="return confirm('Are you sure you want to remove this subject from <?= sanitize($class['name']) ?>?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No compulsory subjects configured yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light py-2">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i> Compulsory subjects are required by the CDC Nepal curriculum and automatically active in all school routines, exams, and grading.
                </small>
            </div>
        </div>

        <!-- 2. Optional / Elective Subjects Section with Live Tick/Untick Toggle -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <div>
                    <h5 class="mb-0 text-success">
                        <i class="fas fa-toggle-on me-2"></i> Optional / Elective Subjects (ऐच्छिक विषयहरू)
                    </h5>
                    <small class="text-muted">Tick (enable) or untick (hide) subjects for this class</small>
                </div>
                <span class="badge bg-success rounded-pill"><?= count($optional_subjects) ?> Configured</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 15%;">Code</th>
                                <th>Subject Name</th>
                                <th>Credit Hrs</th>
                                <th>In Use / Active?</th>
                                <th>Assigned Teacher(s)</th>
                                <th class="text-end" style="width: 15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($optional_subjects) > 0): ?>
                                <?php foreach ($optional_subjects as $os): ?>
                                <tr id="row_mapping_<?= $os['id'] ?>" class="<?= empty($os['is_active']) ? 'table-secondary opacity-75' : '' ?>">
                                    <td><span class="badge bg-secondary font-monospace"><?= sanitize($os['subject_code'] ?? 'N/A') ?></span></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= sanitize($os['subject_name_en']) ?></div>
                                        <small class="text-muted"><?= sanitize($os['subject_name_np'] ?? '') ?></small>
                                    </td>
                                    <td><strong><?= $os['credit_hours'] ?></strong> CH</td>
                                    <td>
                                        <!-- Tick/Untick Toggle Switch -->
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input optional-toggle" type="checkbox" role="switch" 
                                                   id="toggle_<?= $os['id'] ?>" 
                                                   data-id="<?= $os['id'] ?>" 
                                                   <?= !empty($os['is_active']) ? 'checked' : '' ?>
                                                   title="Toggle to use or hide this optional subject for <?= sanitize($class['name']) ?>">
                                            <label class="form-check-label fw-bold small ms-1" id="label_<?= $os['id'] ?>" for="toggle_<?= $os['id'] ?>">
                                                <?php if (!empty($os['is_active'])): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active (In Use)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden (Unticked)</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($os['teacher_id'])) {
                                            $t_ids = explode(',', $os['teacher_id']);
                                            $t_names = [];
                                            foreach ($t_ids as $tid) {
                                                if (isset($all_teachers_map[$tid])) {
                                                    $t_names[] = sanitize($all_teachers_map[$tid]);
                                                }
                                            }
                                            echo '<small><i class="fas fa-chalkboard-teacher text-success me-1"></i>' . implode(', ', $t_names) . '</small>';
                                        } else {
                                            echo '<span class="text-muted small">Not Assigned</span>';
                                        }
                                        ?>
                                    </td>
                                    <td class="text-end">
                                        <button onclick="editMapping(<?= $os['id'] ?>, <?= $os['credit_hours'] ?>, '<?= $os['teacher_id'] ?>', 1)" 
                                                class="btn btn-sm btn-outline-warning me-1" title="Edit Assignment">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <a href="<?= APP_URL ?>/modules/classes/subjects.php?class_id=<?= $class_id ?>&delete_mapping=<?= $os['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger" title="Remove Subject"
                                           onclick="return confirm('Are you sure you want to remove this optional subject?');">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No optional subjects mapped to this class yet. Use the form on the right to add elective subjects.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light py-2">
                <small class="text-muted">
                    <i class="fas fa-lightbulb text-warning me-1"></i> 
                    <strong>How it works:</strong> Ticked subjects are actively offered in this class and appear in Exam Paper Generator, Question Bank, and Mark Sheets. Unticked subjects are completely hidden.
                </small>
            </div>
        </div>
    </div>

    <!-- Map New Subject Form Column -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm border-0 sticky-top" style="top: 20px;">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="mb-0 text-dark"><i class="fas fa-plus-circle text-primary me-2"></i>Map Subject to Class</h5>
            </div>
            <div class="card-body p-4">
                <?php if (count($available_subjects) > 0): ?>
                    <form method="POST" action="" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="map_subject">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Subject *</label>
                            <select name="subject_id" id="subject_id" class="form-select" required onchange="autoDetectType(this)">
                                <option value="">-- Choose Subject from CDC Catalog --</option>
                                <?php foreach ($available_subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>" data-type="<?= $sub['type'] ?? 'compulsory' ?>">
                                    [<?= sanitize($sub['code'] ?? 'SUB') ?>] <?= sanitize($sub['name_en']) ?> (<?= sanitize($sub['name_np'] ?? '') ?>)
                                    <?= ($sub['type'] === 'optional') ? ' [Optional]' : ' [Compulsory]' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select a subject.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Subject Type for this Class *</label>
                            <select name="is_optional" id="is_optional" class="form-select" required>
                                <option value="0">Compulsory Subject (अनिवार्य)</option>
                                <option value="1">Optional / Elective Subject (ऐच्छिक)</option>
                            </select>
                            <small class="text-muted">Select whether this is compulsory or elective for <?= sanitize($class['name']) ?>.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Target Section (Optional)</label>
                            <select name="section_id" class="form-select">
                                <option value="">All Sections (Global Class Subject)</option>
                                <?php foreach ($class_sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>">Section <?= sanitize($sec['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Credit Hours *</label>
                            <input type="number" step="0.01" name="credit_hours" class="form-control" value="4" min="0" max="10" required>
                            <div class="invalid-feedback">Credit hours must be between 0 and 10.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold d-block">Assign Teachers</label>
                            <div class="border rounded p-2 bg-light" style="max-height: 140px; overflow-y: auto;">
                                <?php if (count($teachers) > 0): ?>
                                    <?php foreach ($teachers as $t): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" id="teacher_new_<?= $t['id'] ?>">
                                        <label class="form-check-label text-dark" for="teacher_new_<?= $t['id'] ?>">
                                            <?= sanitize($t['full_name']) ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted small">No teachers registered yet.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">
                            <i class="fas fa-link me-1"></i> Map Subject to <?= sanitize($class['name']) ?>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-check-double fa-3x text-success mb-3"></i>
                        <p class="mb-0">All catalog subjects have already been mapped to this class.</p>
                    </div>
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
            <div class="modal-content shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Subject Mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Subject Classification</label>
                        <select name="is_optional" id="editIsOptional" class="form-select" required>
                            <option value="0">Compulsory (अनिवार्य)</option>
                            <option value="1">Optional / Elective (ऐच्छिक)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Credit Hours *</label>
                        <input type="number" step="0.01" name="credit_hours" id="editCreditHours" class="form-control" required min="0" max="10">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold d-block">Assigned Teachers</label>
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
                    <button type="submit" class="btn btn-primary fw-bold">Save Changes</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Toast Container for instant AJAX updates -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080;">
    <div id="liveToast" class="toast align-items-center text-white bg-dark border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">
                Subject status updated.
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<script>
function autoDetectType(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    const type = selectedOption.getAttribute('data-type');
    const typeSelect = document.getElementById('is_optional');
    if (type === 'optional') {
        typeSelect.value = '1';
    } else if (type === 'compulsory') {
        typeSelect.value = '0';
    }
}

function editMapping(id, creditHours, teacherIds, isOptional) {
    document.getElementById('editMappingId').value = id;
    document.getElementById('editCreditHours').value = creditHours;
    document.getElementById('editIsOptional').value = isOptional ? '1' : '0';
    
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

// Live Toggle for Optional Subjects
document.addEventListener('DOMContentLoaded', function() {
    const toastElem = document.getElementById('liveToast');
    const toast = new bootstrap.Toast(toastElem, { delay: 3000 });
    const toastMsg = document.getElementById('toastMessage');

    document.querySelectorAll('.optional-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            const mappingId = this.getAttribute('data-id');
            const isActive = this.checked ? 1 : 0;
            const label = document.getElementById('label_' + mappingId);
            const row = document.getElementById('row_mapping_' + mappingId);

            // Optimistic UI update
            if (isActive) {
                label.innerHTML = '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active (In Use)</span>';
                if (row) row.classList.remove('table-secondary', 'opacity-75');
            } else {
                label.innerHTML = '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden (Unticked)</span>';
                if (row) row.classList.add('table-secondary', 'opacity-75');
            }

            // AJAX Request
            const formData = new FormData();
            formData.append('action', 'toggle_optional');
            formData.append('mapping_id', mappingId);
            formData.append('is_active', isActive);
            formData.append('is_ajax', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    toastElem.className = 'toast align-items-center text-white bg-success border-0 shadow';
                    toastMsg.innerHTML = '<i class="fas fa-check-circle me-1"></i> ' + (data.message || 'Status updated');
                    toast.show();
                } else {
                    throw new Error(data.error || 'Update failed');
                }
            })
            .catch(err => {
                // Revert on error
                toggle.checked = !isActive;
                toastElem.className = 'toast align-items-center text-white bg-danger border-0 shadow';
                toastMsg.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Failed to update: ' + err.message;
                toast.show();
            });
        });
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
