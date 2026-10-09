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
$stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND (school_id = ?)");
$stmt->execute([$class_id, $school_id]);
$class = $stmt->fetch();

if (!$class) {
    redirect(APP_URL . '/modules/classes/index.php', 'Class not found', 'error');
}

// Handle Toggle Subject Active/Hidden State (Compulsory or Optional)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['toggle_optional', 'toggle_subject_status'])) {
    $mapping_id = (int)($_POST['mapping_id'] ?? 0);
    $is_active = !empty($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $db->prepare("UPDATE class_subjects SET is_active = ? WHERE id = ? AND class_id = ?");
        $stmt->execute([$is_active, $mapping_id, $class_id]);
        
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                   || (isset($_POST['is_ajax']) && $_POST['is_ajax'] == '1');
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'is_active' => $is_active, 'message' => $is_active ? 'Subject is now active and in use.' : 'Subject is now hidden from class routines and exams.']);
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
    
    // Theory (TH) & Internal (IN) individual fields
    $th_credit_hours = round((float)($_POST['th_credit_hours'] ?? 0), 2);
    $in_credit_hours = round((float)($_POST['in_credit_hours'] ?? 0), 2);
    $credit_hours = round((float)($_POST['credit_hours'] ?? ($th_credit_hours + $in_credit_hours)), 2);
    if ($credit_hours <= 0 && ($th_credit_hours + $in_credit_hours) > 0) {
        $credit_hours = $th_credit_hours + $in_credit_hours;
    }
    $th_full_marks = round((float)($_POST['th_full_marks'] ?? 75), 2);
    $th_pass_marks = round((float)($_POST['th_pass_marks'] ?? 26.25), 2);
    $in_full_marks = round((float)($_POST['in_full_marks'] ?? 25), 2);
    $in_pass_marks = round((float)($_POST['in_pass_marks'] ?? 10), 2);
    
    $is_optional = isset($_POST['is_optional']) ? (int)$_POST['is_optional'] : 0;
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
    
    if (empty($subject_id)) {
        $error = "Subject is required.";
    } else {
        try {
            // 1. Check if subject_id is already mapped to this class
            $stmt_check_id = $db->prepare("SELECT COUNT(*) FROM class_subjects WHERE class_id = ? AND subject_id = ?");
            $stmt_check_id->execute([$class_id, $subject_id]);
            if ($stmt_check_id->fetchColumn() > 0) {
                $error = "Duplicate Subject Blocked: This subject is already assigned to this class.";
            } else {
                // 2. Check if a subject with the same name (English or Nepali) is already assigned to this class
                $stmt_target_subj = $db->prepare("SELECT name_en, name_np, code FROM subjects WHERE id = ?");
                $stmt_target_subj->execute([$subject_id]);
                $target_sub = $stmt_target_subj->fetch();

                $stmt_check_name = $db->prepare("
                    SELECT s.name_en FROM class_subjects cs
                    JOIN subjects s ON cs.subject_id = s.id
                    WHERE cs.class_id = ? AND (
                        LOWER(TRIM(s.name_en)) = LOWER(TRIM(?))
                        OR (? != '' AND s.name_np != '' AND s.name_np IS NOT NULL AND TRIM(s.name_np) = TRIM(?))
                    )
                ");
                $target_en = $target_sub['name_en'] ?? '';
                $target_np = $target_sub['name_np'] ?? '';
                $stmt_check_name->execute([$class_id, $target_en, $target_np, $target_np]);
                $existing_sub = $stmt_check_name->fetch();

                if ($existing_sub) {
                    $error = "Duplicate Subject Blocked: A subject with the name '" . htmlspecialchars($existing_sub['name_en']) . "' is already assigned to this class. Creating or mapping duplicate subjects for the same class is not allowed.";
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO class_subjects (
                            class_id, subject_id, section_id, credit_hours,
                            th_credit_hours, in_credit_hours, th_full_marks, th_pass_marks, in_full_marks, in_pass_marks,
                            teacher_id, school_id, is_optional, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $class_id, $subject_id, $section_id, $credit_hours,
                        $th_credit_hours, $in_credit_hours, $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks,
                        $teacher_id_str, $school_id, $is_optional
                    ]);
                    Auth::logActivity(Auth::getUserId(), 'Map Subject to Class', 'class_subjects', $db->lastInsertId());
                    $success = "Subject mapped successfully.";
                }
            }
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                $error = "Duplicate Subject Blocked: This subject is already assigned to this class.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Disallow Hard Deletion of Subject Mappings (Data Integrity Protection)
if (isset($_GET['delete_mapping'])) {
    $error = "Subject deletion is disabled to protect student marks, report cards, and historical records. You can toggle the subject to 'Hidden' instead.";
}

// Handle Edit Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_mapping') {
    $mapping_id = (int)$_POST['mapping_id'];
    $th_credit_hours = round((float)($_POST['th_credit_hours'] ?? 0), 2);
    $in_credit_hours = round((float)($_POST['in_credit_hours'] ?? 0), 2);
    $credit_hours = round((float)($_POST['credit_hours'] ?? ($th_credit_hours + $in_credit_hours)), 2);
    if ($credit_hours <= 0 && ($th_credit_hours + $in_credit_hours) > 0) {
        $credit_hours = $th_credit_hours + $in_credit_hours;
    }
    $th_full_marks = round((float)($_POST['th_full_marks'] ?? 75), 2);
    $th_pass_marks = round((float)($_POST['th_pass_marks'] ?? 26.25), 2);
    $in_full_marks = round((float)($_POST['in_full_marks'] ?? 25), 2);
    $in_pass_marks = round((float)($_POST['in_pass_marks'] ?? 10), 2);
    
    $is_optional = isset($_POST['is_optional']) ? (int)$_POST['is_optional'] : 0;
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    $teacher_id_str = !empty($teacher_ids) ? implode(',', array_map('intval', $teacher_ids)) : null;
    
    try {
        $stmt = $db->prepare("
            UPDATE class_subjects 
            SET credit_hours = ?, th_credit_hours = ?, in_credit_hours = ?,
                th_full_marks = ?, th_pass_marks = ?, in_full_marks = ?, in_pass_marks = ?,
                teacher_id = ?, is_optional = ? 
            WHERE id = ? AND class_id = ?
        ");
        $stmt->execute([
            $credit_hours, $th_credit_hours, $in_credit_hours,
            $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks,
            $teacher_id_str, $is_optional, $mapping_id, $class_id
        ]);
        Auth::logActivity(Auth::getUserId(), 'Edit Class Subject Assignment', 'class_subjects', $mapping_id);
        $success = "Subject mapping updated successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch subjects currently mapped to this class
$stmt = $db->prepare("
    SELECT cs.*, s.name_en as subject_name_en, s.name_np as subject_name_np, s.code as subject_code, s.type as subject_catalog_type,
           s.is_hidden as is_catalog_hidden,
           COALESCE(cs.th_credit_hours, s.th_credit_hours, 0) as th_credit_hours,
           COALESCE(cs.in_credit_hours, s.in_credit_hours, 0) as in_credit_hours,
           COALESCE(cs.th_full_marks, s.th_full_marks, 75) as th_full_marks,
           COALESCE(cs.th_pass_marks, s.th_pass_marks, 26.25) as th_pass_marks,
           COALESCE(cs.in_full_marks, s.in_full_marks, 25) as in_full_marks,
           COALESCE(cs.in_pass_marks, s.in_pass_marks, 10) as in_pass_marks,
           sec.name as section_name
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

// Fetch all available subjects for mapping (excluding already mapped ones, identical names, or hidden subjects)
$stmt = $db->prepare("
    SELECT * FROM subjects 
    WHERE (school_id = ?)
      AND (is_hidden = 0 OR is_hidden IS NULL)
      AND id NOT IN (SELECT subject_id FROM class_subjects WHERE class_id = ?)
      AND LOWER(TRIM(name_en)) NOT IN (
          SELECT LOWER(TRIM(s_m.name_en)) 
          FROM class_subjects cs_m 
          JOIN subjects s_m ON cs_m.subject_id = s_m.id 
          WHERE cs_m.class_id = ?
      )
    ORDER BY type ASC, name_en ASC
");
$stmt->execute([$school_id, $class_id, $class_id]);
$available_subjects = $stmt->fetchAll();

// Fetch all class sections
$stmt = $db->prepare("SELECT * FROM sections WHERE class_id = ? AND (school_id = ?) ORDER BY name");
$stmt->execute([$class_id, $school_id]);
$class_sections = $stmt->fetchAll();

// Fetch all active teachers for dropdown
$stmt = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND (school_id = ?) ORDER BY full_name");
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
                <div>
                    <h5 class="mb-0 text-primary fw-bold">
                        <i class="fas fa-layer-group me-2"></i> Compulsory Subjects (अनिवार्य विषयहरू)
                    </h5>
                    <small class="text-muted">Core curriculum subjects with Theory (TH) & Internal (IN) components</small>
                </div>
                <span class="badge bg-primary rounded-pill"><?= count($compulsory_subjects) ?> Compulsory</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 12%;">Code</th>
                                <th>Subject Name</th>
                                <th>Theory (TH)</th>
                                <th>Internal (IN)</th>
                                <th>Credit</th>
                                <th>Section</th>
                                <th>Status</th>
                                <th>Teacher(s)</th>
                                <th class="text-end" style="width: 10%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($compulsory_subjects) > 0): ?>
                                <?php foreach ($compulsory_subjects as $cs): ?>
                                <tr id="row_mapping_<?= $cs['id'] ?>" class="<?= empty($cs['is_active']) ? 'table-secondary opacity-75' : '' ?>">
                                    <td><span class="badge bg-secondary font-monospace"><?= sanitize($cs['subject_code'] ?? 'N/A') ?></span></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= sanitize($cs['subject_name_en']) ?></div>
                                        <small class="text-muted"><?= sanitize($cs['subject_name_np'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary font-monospace">
                                            <?= (float)$cs['th_credit_hours'] ?> CH
                                        </span>
                                        <div class="small text-muted font-monospace"><?= (float)$cs['th_full_marks'] ?> FM / <?= (float)$cs['th_pass_marks'] ?> PM</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning font-monospace">
                                            <?= (float)$cs['in_credit_hours'] ?> CH
                                        </span>
                                        <div class="small text-muted font-monospace"><?= (float)$cs['in_full_marks'] ?> FM / <?= (float)$cs['in_pass_marks'] ?> PM</div>
                                    </td>
                                    <td><strong class="font-monospace"><?= $cs['credit_hours'] ?></strong> CH</td>
                                    <td>
                                        <?php if ($cs['section_id']): ?>
                                            <span class="badge bg-info text-dark">Section <?= sanitize($cs['section_name']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border">All</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input subject-status-toggle" type="checkbox" role="switch" 
                                                   id="toggle_<?= $cs['id'] ?>" 
                                                   data-id="<?= $cs['id'] ?>" 
                                                   <?= !empty($cs['is_active']) ? 'checked' : '' ?>
                                                   title="Toggle to make subject active or hide from <?= sanitize($class['name']) ?>">
                                            <label class="form-check-label fw-bold small ms-1" id="label_<?= $cs['id'] ?>" for="toggle_<?= $cs['id'] ?>">
                                                <?php if (!empty($cs['is_active'])): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden</span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </td>
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
                                        <button onclick='editMapping(<?= json_encode($cs) ?>)' 
                                                class="btn btn-sm btn-outline-warning" title="Edit Assignment & Evaluation">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No compulsory subjects configured yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light py-2">
                <small class="text-muted">
                    <i class="fas fa-shield-alt text-primary me-1"></i> 
                    <strong>Protected:</strong> Deletion is disabled to protect grades. Active subjects are in use in exams and grading; hidden subjects cannot show in student mark entry.
                </small>
            </div>
        </div>

        <!-- 2. Optional / Elective Subjects Section with Live Tick/Untick Toggle -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                <div>
                    <h5 class="mb-0 text-success fw-bold">
                        <i class="fas fa-toggle-on me-2"></i> Optional / Elective Subjects (ऐच्छिक विषयहरू)
                    </h5>
                    <small class="text-muted">Tick (Active/In Use) or Untick (Hide) elective subjects for this class</small>
                </div>
                <span class="badge bg-success rounded-pill"><?= count($optional_subjects) ?> Configured</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 12%;">Code</th>
                                <th>Subject Name</th>
                                <th>Theory (TH)</th>
                                <th>Internal (IN)</th>
                                <th>Credit</th>
                                <th>In Use / Active?</th>
                                <th>Teacher(s)</th>
                                <th class="text-end" style="width: 10%;">Actions</th>
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
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary font-monospace">
                                            <?= (float)$os['th_credit_hours'] ?> CH
                                        </span>
                                        <div class="small text-muted font-monospace"><?= (float)$os['th_full_marks'] ?> FM / <?= (float)$os['th_pass_marks'] ?> PM</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning font-monospace">
                                            <?= (float)$os['in_credit_hours'] ?> CH
                                        </span>
                                        <div class="small text-muted font-monospace"><?= (float)$os['in_full_marks'] ?> FM / <?= (float)$os['in_pass_marks'] ?> PM</div>
                                    </td>
                                    <td><strong class="font-monospace"><?= $os['credit_hours'] ?></strong> CH</td>
                                    <td>
                                        <!-- Tick/Untick Toggle Switch -->
                                        <div class="form-check form-switch d-inline-block">
                                            <input class="form-check-input subject-status-toggle" type="checkbox" role="switch" 
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
                                        <button onclick='editMapping(<?= json_encode($os) ?>)' 
                                                class="btn btn-sm btn-outline-warning" title="Edit Assignment & Evaluation">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No optional subjects mapped to this class yet. Use the form on the right to add elective subjects.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light py-2">
                <small class="text-muted">
                    <i class="fas fa-lightbulb text-warning me-1"></i> 
                    <strong>How it works:</strong> Ticked subjects are actively offered in this class and appear in Exam Paper Generator and Mark Sheets. Unticked subjects are hidden.
                </small>
            </div>
        </div>
    </div>

    <!-- Map New Subject Form Column -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm border-0 sticky-top" style="top: 20px;">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="mb-0 text-dark fw-bold"><i class="fas fa-plus-circle text-primary me-2"></i>Map Subject to Class</h5>
            </div>
            <div class="card-body p-4">
                <?php if (count($available_subjects) > 0): ?>
                    <form method="POST" action="" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="map_subject">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Subject *</label>
                            <select name="subject_id" id="subject_id" class="form-select" required onchange="populateSubjectDetails(this)">
                                <option value="">-- Choose Subject from CDC Catalog --</option>
                                <?php foreach ($available_subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>" 
                                        data-type="<?= $sub['type'] ?? 'compulsory' ?>"
                                        data-optional="<?= ($sub['type'] === 'optional' || !empty($sub['is_optional'])) ? 1 : 0 ?>"
                                        data-th-ch="<?= (float)($sub['th_credit_hours'] ?? 3.0) ?>"
                                        data-in-ch="<?= (float)($sub['in_credit_hours'] ?? 1.0) ?>"
                                        data-th-fm="<?= (float)($sub['th_full_marks'] ?? 75.0) ?>"
                                        data-th-pm="<?= (float)($sub['th_pass_marks'] ?? 26.25) ?>"
                                        data-in-fm="<?= (float)($sub['in_full_marks'] ?? 25.0) ?>"
                                        data-in-pm="<?= (float)($sub['in_pass_marks'] ?? 10.0) ?>">
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

                        <!-- Theory (TH) & Internal (IN) Evaluation Boxes -->
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-2 border rounded bg-light">
                                    <div class="d-flex align-items-center mb-1">
                                        <span class="badge bg-primary me-1"><i class="fas fa-book-open"></i></span>
                                        <strong class="small text-primary">Theory (TH)</strong>
                                    </div>
                                    <div class="mb-1">
                                        <label class="small text-muted mb-0">Credit Hrs</label>
                                        <input type="number" step="0.01" name="th_credit_hours" id="map_th_ch" class="form-control form-control-sm font-monospace" value="3.00" oninput="calculateMappingTotals()">
                                    </div>
                                    <div class="mb-1">
                                        <label class="small text-muted mb-0">Full Marks</label>
                                        <input type="number" step="0.01" name="th_full_marks" id="map_th_fm" class="form-control form-control-sm font-monospace" value="75" oninput="calculateMappingTotals()">
                                    </div>
                                    <div>
                                        <label class="small text-muted mb-0">Pass Marks</label>
                                        <input type="number" step="0.01" name="th_pass_marks" id="map_th_pm" class="form-control form-control-sm font-monospace" value="26.25">
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-2 border rounded bg-light">
                                    <div class="d-flex align-items-center mb-1">
                                        <span class="badge bg-warning text-dark me-1"><i class="fas fa-tasks"></i></span>
                                        <strong class="small text-dark">Internal (IN)</strong>
                                    </div>
                                    <div class="mb-1">
                                        <label class="small text-muted mb-0">Credit Hrs</label>
                                        <input type="number" step="0.01" name="in_credit_hours" id="map_in_ch" class="form-control form-control-sm font-monospace" value="1.00" oninput="calculateMappingTotals()">
                                    </div>
                                    <div class="mb-1">
                                        <label class="small text-muted mb-0">Full Marks</label>
                                        <input type="number" step="0.01" name="in_full_marks" id="map_in_fm" class="form-control form-control-sm font-monospace" value="25" oninput="calculateMappingTotals()">
                                    </div>
                                    <div>
                                        <label class="small text-muted mb-0">Pass Marks</label>
                                        <input type="number" step="0.01" name="in_pass_marks" id="map_in_pm" class="form-control form-control-sm font-monospace" value="10">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Total Evaluation Summary -->
                        <div class="p-2 rounded border bg-white mb-3 d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted d-block">Total Credit Hrs:</small>
                                <strong id="map_total_ch_display" class="text-primary font-monospace">4.00 CH</strong>
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">Total Full Marks:</small>
                                <strong id="map_total_fm_display" class="text-dark font-monospace">100 FM</strong>
                            </div>
                            <input type="hidden" name="credit_hours" id="map_credit_hours" value="4.00">
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

<!-- Edit Mapping Modal with Individual TH and IN Fields -->
<div class="modal fade" id="editMappingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="">
            <input type="hidden" name="action" value="edit_mapping">
            <input type="hidden" name="mapping_id" id="editMappingId">
            <div class="modal-content shadow">
                <div class="modal-header bg-light">
                    <h5 class="modal-title"><i class="fas fa-edit me-2 text-warning"></i>Edit Subject Mapping & Evaluation</h5>
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

                    <!-- Individual Fields for Theory (TH) and Internal (IN) -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="p-2 border rounded bg-light">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="badge bg-primary me-1"><i class="fas fa-book-open"></i></span>
                                    <strong class="small text-primary">Theory (TH)</strong>
                                </div>
                                <div class="mb-1">
                                    <label class="small text-muted mb-0">Credit Hrs</label>
                                    <input type="number" step="0.01" name="th_credit_hours" id="editThCh" class="form-control form-control-sm font-monospace" required oninput="calculateEditTotals()">
                                </div>
                                <div class="mb-1">
                                    <label class="small text-muted mb-0">Full Marks</label>
                                    <input type="number" step="0.01" name="th_full_marks" id="editThFm" class="form-control form-control-sm font-monospace" required oninput="calculateEditTotals()">
                                </div>
                                <div>
                                    <label class="small text-muted mb-0">Pass Marks</label>
                                    <input type="number" step="0.01" name="th_pass_marks" id="editThPm" class="form-control form-control-sm font-monospace" required>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 border rounded bg-light">
                                <div class="d-flex align-items-center mb-1">
                                    <span class="badge bg-warning text-dark me-1"><i class="fas fa-tasks"></i></span>
                                    <strong class="small text-dark">Internal (IN)</strong>
                                </div>
                                <div class="mb-1">
                                    <label class="small text-muted mb-0">Credit Hrs</label>
                                    <input type="number" step="0.01" name="in_credit_hours" id="editInCh" class="form-control form-control-sm font-monospace" required oninput="calculateEditTotals()">
                                </div>
                                <div class="mb-1">
                                    <label class="small text-muted mb-0">Full Marks</label>
                                    <input type="number" step="0.01" name="in_full_marks" id="editInFm" class="form-control form-control-sm font-monospace" required oninput="calculateEditTotals()">
                                </div>
                                <div>
                                    <label class="small text-muted mb-0">Pass Marks</label>
                                    <input type="number" step="0.01" name="in_pass_marks" id="editInPm" class="form-control form-control-sm font-monospace" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-2 rounded border bg-white mb-3 d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted d-block">Combined Credit Hrs:</small>
                            <strong id="edit_total_ch_display" class="text-primary font-monospace">4.00 CH</strong>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block">Combined Full Marks:</small>
                            <strong id="edit_total_fm_display" class="text-dark font-monospace">100 FM</strong>
                        </div>
                        <input type="hidden" name="credit_hours" id="editCreditHours" value="4.00">
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
function populateSubjectDetails(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    if (!selectedOption || !selectedOption.value) return;

    const isOpt = selectedOption.getAttribute('data-optional');
    const typeSelect = document.getElementById('is_optional');
    if (typeSelect) {
        typeSelect.value = isOpt == '1' ? '1' : '0';
    }

    const thCh = parseFloat(selectedOption.getAttribute('data-th-ch')) || 0;
    const inCh = parseFloat(selectedOption.getAttribute('data-in-ch')) || 0;
    const thFm = parseFloat(selectedOption.getAttribute('data-th-fm')) || 0;
    const thPm = parseFloat(selectedOption.getAttribute('data-th-pm')) || 0;
    const inFm = parseFloat(selectedOption.getAttribute('data-in-fm')) || 0;
    const inPm = parseFloat(selectedOption.getAttribute('data-in-pm')) || 0;

    document.getElementById('map_th_ch').value = thCh.toFixed(2);
    document.getElementById('map_in_ch').value = inCh.toFixed(2);
    document.getElementById('map_th_fm').value = thFm.toFixed(2);
    document.getElementById('map_th_pm').value = thPm.toFixed(2);
    document.getElementById('map_in_fm').value = inFm.toFixed(2);
    document.getElementById('map_in_pm').value = inPm.toFixed(2);

    calculateMappingTotals();
}

function calculateMappingTotals() {
    const thCh = parseFloat(document.getElementById('map_th_ch').value) || 0;
    const inCh = parseFloat(document.getElementById('map_in_ch').value) || 0;
    const thFm = parseFloat(document.getElementById('map_th_fm').value) || 0;
    const inFm = parseFloat(document.getElementById('map_in_fm').value) || 0;

    const totalCh = (thCh + inCh).toFixed(2);
    const totalFm = (thFm + inFm).toFixed(2);

    document.getElementById('map_credit_hours').value = totalCh;
    document.getElementById('map_total_ch_display').innerText = totalCh + ' CH';
    document.getElementById('map_total_fm_display').innerText = totalFm + ' FM';
}

function calculateEditTotals() {
    const thCh = parseFloat(document.getElementById('editThCh').value) || 0;
    const inCh = parseFloat(document.getElementById('editInCh').value) || 0;
    const thFm = parseFloat(document.getElementById('editThFm').value) || 0;
    const inFm = parseFloat(document.getElementById('editInFm').value) || 0;

    const totalCh = (thCh + inCh).toFixed(2);
    const totalFm = (thFm + inFm).toFixed(2);

    document.getElementById('editCreditHours').value = totalCh;
    document.getElementById('edit_total_ch_display').innerText = totalCh + ' CH';
    document.getElementById('edit_total_fm_display').innerText = totalFm + ' FM';
}

function editMapping(item) {
    document.getElementById('editMappingId').value = item.id;
    document.getElementById('editIsOptional').value = (item.is_optional == '1') ? '1' : '0';
    
    document.getElementById('editThCh').value = parseFloat(item.th_credit_hours || 0).toFixed(2);
    document.getElementById('editInCh').value = parseFloat(item.in_credit_hours || 0).toFixed(2);
    document.getElementById('editThFm').value = parseFloat(item.th_full_marks || 75).toFixed(2);
    document.getElementById('editThPm').value = parseFloat(item.th_pass_marks || 26.25).toFixed(2);
    document.getElementById('editInFm').value = parseFloat(item.in_full_marks || 25).toFixed(2);
    document.getElementById('editInPm').value = parseFloat(item.in_pass_marks || 10).toFixed(2);
    
    calculateEditTotals();
    
    // Uncheck all edit checkboxes
    const checkboxes = document.querySelectorAll('.edit-teacher-check');
    checkboxes.forEach(cb => cb.checked = false);
    
    // Check assigned teachers
    if (item.teacher_id) {
        const ids = item.teacher_id.toString().split(',');
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

// Live Toggle for Subject Status (Compulsory or Optional)
document.addEventListener('DOMContentLoaded', function() {
    const toastElem = document.getElementById('liveToast');
    const toast = new bootstrap.Toast(toastElem, { delay: 3000 });
    const toastMsg = document.getElementById('toastMessage');

    document.querySelectorAll('.subject-status-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            const mappingId = this.getAttribute('data-id');
            const isActive = this.checked ? 1 : 0;
            const label = document.getElementById('label_' + mappingId);
            const row = document.getElementById('row_mapping_' + mappingId);

            // Optimistic UI update
            if (isActive) {
                label.innerHTML = '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active</span>';
                if (row) row.classList.remove('table-secondary', 'opacity-75');
            } else {
                label.innerHTML = '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden</span>';
                if (row) row.classList.add('table-secondary', 'opacity-75');
            }

            // AJAX Request
            const formData = new FormData();
            formData.append('action', 'toggle_subject_status');
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
