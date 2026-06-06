<?php
/**
 * Exam Management - View / Configure Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$error = '';
$success = '';
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch exam details
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
$stmt->execute([$exam_id, $school_id]);
$exam = $stmt->fetch();

if (!$exam) {
    redirect(APP_URL . '/modules/exams/index.php', 'Exam not found', 'error');
}

// Fetch school details for signatures
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

// Handle Signature Uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_signatures']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($exam['is_locked']) {
        $error = "This exam is locked. You cannot modify configuration.";
    } else {
        $sig_dir = UPLOAD_DIR;
        if (!file_exists($sig_dir)) {
            mkdir($sig_dir, 0777, true);
        }
        
        $updated_cols = [];
        $updated_params = [];
        
        // Handle Class Teacher Signature
        if (isset($_FILES['class_teacher_sig']) && $_FILES['class_teacher_sig']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['class_teacher_sig']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $filename = 'class_teacher_' . $school_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['class_teacher_sig']['tmp_name'], $sig_dir . $filename)) {
                    $updated_cols[] = "class_teacher_signature = ?";
                    $updated_params[] = $filename;
                }
            } else {
                $error = "Only PNG, JPG, and JPEG files are allowed for signatures.";
            }
        }
        
        // Handle Exam Coordinator Signature
        if (isset($_FILES['exam_coordinator_sig']) && $_FILES['exam_coordinator_sig']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['exam_coordinator_sig']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $filename = 'exam_coordinator_' . $school_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['exam_coordinator_sig']['tmp_name'], $sig_dir . $filename)) {
                    $updated_cols[] = "exam_coordinator_signature = ?";
                    $updated_params[] = $filename;
                }
            } else {
                $error = "Only PNG, JPG, and JPEG files are allowed for signatures.";
            }
        }
        
        if (empty($error) && !empty($updated_cols)) {
            $updated_params[] = $school_id;
            $sql = "UPDATE schools SET " . implode(', ', $updated_cols) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($updated_params);
            $success = "Signatures uploaded successfully!";
            
            // Reload school details
            $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $school = $stmt->fetch();
        }
    }
}

// Fetch edit mapping if requested
$edit_mapping = null;
$edit_mapping_id = isset($_GET['edit_mapping_id']) ? (int)$_GET['edit_mapping_id'] : 0;
if ($edit_mapping_id > 0) {
    $stmt = $db->prepare("SELECT * FROM exam_subjects WHERE id = ? AND exam_id = ?");
    $stmt->execute([$edit_mapping_id, $exam_id]);
    $edit_mapping = $stmt->fetch();
}

// Handle Subject Mapping Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['map_subject']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($exam['is_locked']) {
        $error = "This exam is locked. You cannot modify configuration.";
    } else {
        $class_id = (int)$_POST['class_id'];
        $subject_id = (int)$_POST['subject_id'];
        $theory_full = (int)$_POST['theory_full'];
        $theory_pass = (int)$_POST['theory_pass'];
        $practical_full = $exam['has_internal'] ? (int)($_POST['practical_full'] ?? 0) : 0;
        $practical_pass = $exam['has_internal'] ? (int)($_POST['practical_pass'] ?? 0) : 0;
        $credit_hours = $_POST['credit_hours'] !== '' ? (float)$_POST['credit_hours'] : null;
        $internal_credit_hours = $_POST['internal_credit_hours'] !== '' ? (float)$_POST['internal_credit_hours'] : null;
        $external_credit_hours = $_POST['external_credit_hours'] !== '' ? (float)$_POST['external_credit_hours'] : null;
        $form_edit_id = (int)($_POST['edit_mapping_id'] ?? 0);
        
        if (empty($class_id) || empty($subject_id)) {
            $error = 'Both Class and Subject must be selected';
        } else {
            try {
                if ($form_edit_id > 0) {
                    $stmt = $db->prepare("UPDATE exam_subjects SET class_id = ?, subject_id = ?, theory_full = ?, theory_pass = ?, practical_full = ?, practical_pass = ?, credit_hours = ?, internal_credit_hours = ?, external_credit_hours = ? WHERE id = ? AND exam_id = ?");
                    $stmt->execute([$class_id, $subject_id, $theory_full, $theory_pass, $practical_full, $practical_pass, $credit_hours, $internal_credit_hours, $external_credit_hours, $form_edit_id, $exam_id]);
                    Auth::logActivity(Auth::getUserId(), "Updated Exam Subject Configuration", 'exam_subjects', $form_edit_id);
                    $success = 'Subject configuration updated successfully!';
                    redirect(APP_URL . "/modules/exams/view.php?id=$exam_id", $success);
                } else {
                    // Check if already mapped
                    $stmt = $db->prepare("SELECT id FROM exam_subjects WHERE exam_id = ? AND subject_id = ? AND class_id = ?");
                    $stmt->execute([$exam_id, $subject_id, $class_id]);
                    if ($stmt->fetch()) {
                        $error = 'This subject is already configured for the selected class in this exam.';
                    } else {
                        $stmt = $db->prepare("INSERT INTO exam_subjects (exam_id, subject_id, class_id, theory_full, theory_pass, practical_full, practical_pass, credit_hours, internal_credit_hours, external_credit_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$exam_id, $subject_id, $class_id, $theory_full, $theory_pass, $practical_full, $practical_pass, $credit_hours, $internal_credit_hours, $external_credit_hours]);
                        Auth::logActivity(Auth::getUserId(), "Mapped Subject ID $subject_id to Class ID $class_id in Exam ID $exam_id", 'exam_subjects');
                        $success = 'Subject mapped successfully!';
                    }
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle Bulk Initialization from Class Mappings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize_defaults']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($exam['is_locked']) {
        $error = "This exam is locked. You cannot modify configuration.";
    } else {
        $class_id = (int)$_POST['init_class_id'];
        if (empty($class_id)) {
            $error = 'Class must be selected to initialize.';
        } else {
            try {
                // Fetch subjects mapped to this class in class_subjects
                $stmt = $db->prepare("
                    SELECT cs.*, s.name_en as subject_name, s.full_marks as s_full, s.pass_marks as s_pass, s.credit_hours as s_credit 
                    FROM class_subjects cs
                    JOIN subjects s ON cs.subject_id = s.id
                    WHERE cs.class_id = ?
                ");
                $stmt->execute([$class_id]);
                $class_subs = $stmt->fetchAll();
                
                // Get class name
                $stmt_c = $db->prepare("SELECT name FROM classes WHERE id = ?");
                $stmt_c->execute([$class_id]);
                $class_name = $stmt_c->fetchColumn();

                $inserted = 0;
                foreach ($class_subs as $cs) {
                    $chk = $db->prepare("SELECT id FROM exam_subjects WHERE exam_id = ? AND subject_id = ? AND class_id = ?");
                    $chk->execute([$exam_id, $cs['subject_id'], $class_id]);
                    if (!$chk->fetch()) {
                        $total_full = $cs['s_full'] ?: 100;
                        $total_pass = $cs['s_pass'] ?: 40;
                        $credit_hours = $cs['credit_hours'] ?: $cs['s_credit'];
                        
                        // Detect internal / external credit hours splits
                        $int_ch = null;
                        $ext_ch = null;
                        
                        $is_class_4_8 = false;
                        $is_class_9_12 = false;
                        $is_class_1_3 = false;
                        if (preg_match('/Class\s+(\d+)/i', $class_name, $m_c)) {
                            $num = (int)$m_c[1];
                            if ($num >= 1 && $num <= 3) $is_class_1_3 = true;
                            elseif ($num >= 4 && $num <= 8) $is_class_4_8 = true;
                            elseif ($num >= 9) $is_class_9_12 = true;
                        } elseif (in_array(strtolower($class_name), ['nursery', 'lkg', 'ukg'])) {
                            $is_class_1_3 = true;
                        } else {
                            $stmt_level = $db->prepare("SELECT level FROM classes WHERE id = ?");
                            $stmt_level->execute([$class_id]);
                            $c_lvl = $stmt_level->fetchColumn();
                            if ($c_lvl === 'ECD') $is_class_1_3 = true;
                            elseif ($c_lvl === 'Secondary') $is_class_9_12 = true;
                            else $is_class_4_8 = true;
                        }
                        
                        $theory_full = $total_full;
                        $theory_pass = $total_pass;
                        $practical_full = 0;
                        $practical_pass = 0;
                        
                        if ($exam['has_internal']) {
                            if ($is_class_4_8) {
                                $theory_full = 50;
                                $theory_pass = 17.5;
                                $practical_full = 50;
                                $practical_pass = 20.0;
                                
                                if (stripos($cs['subject_name'], 'Nepali') !== false || stripos($cs['subject_name'], 'English') !== false || stripos($cs['subject_name'], 'Math') !== false || stripos($cs['subject_name'], 'Science') !== false || stripos($cs['subject_name'], 'Social') !== false) {
                                    $int_ch = 2.5; $ext_ch = 2.5;
                                } elseif (stripos($cs['subject_name'], 'Health') !== false || stripos($cs['subject_name'], 'Creative') !== false || stripos($cs['subject_name'], 'Arts') !== false) {
                                    $int_ch = 1.5; $ext_ch = 1.5;
                                } else {
                                    $int_ch = 2.0; $ext_ch = 2.0;
                                }
                            } elseif ($is_class_9_12) {
                                $theory_full = 75;
                                $theory_pass = 26.25;
                                $practical_full = 25;
                                $practical_pass = 10.0;
                                
                                if (stripos($cs['subject_name'], 'Nepali') !== false || stripos($cs['subject_name'], 'English') !== false || stripos($cs['subject_name'], 'Math') !== false || stripos($cs['subject_name'], 'Science') !== false) {
                                    $int_ch = 1.25; $ext_ch = 3.75;
                                } else {
                                    $int_ch = 1.0; $ext_ch = 3.0;
                                }
                            }
                        }
                        
                        $ins = $db->prepare("INSERT INTO exam_subjects (exam_id, subject_id, class_id, theory_full, theory_pass, practical_full, practical_pass, credit_hours, internal_credit_hours, external_credit_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$exam_id, $cs['subject_id'], $class_id, $theory_full, $theory_pass, $practical_full, $practical_pass, $credit_hours, $int_ch, $ext_ch]);
                        $inserted++;
                    }
                }
                
                Auth::logActivity(Auth::getUserId(), "Initialized $inserted Subjects for Class ID $class_id in Exam ID $exam_id", 'exam_subjects');
                $success = "Initialized $inserted subject configurations successfully!";
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Handle deleting mapping
if (isset($_GET['delete_mapping']) && Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])) {
    if ($exam['is_locked']) {
        redirect(APP_URL . "/modules/exams/view.php?id=$exam_id", "Cannot modify locked exam configuration", 'error');
    } else {
        $mapping_id = (int)$_GET['delete_mapping'];
        try {
            $db->prepare("DELETE FROM exam_subjects WHERE id = ? AND exam_id = ?")->execute([$mapping_id, $exam_id]);
            Auth::logActivity(Auth::getUserId(), 'Removed Exam Subject Configuration', 'exam_subjects', $mapping_id);
            redirect(APP_URL . "/modules/exams/view.php?id=$exam_id", "Subject configuration removed");
        } catch (PDOException $e) {
            redirect(APP_URL . "/modules/exams/view.php?id=$exam_id", "Error: " . $e->getMessage(), 'error');
        }
    }
}

// Fetch classes
$stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY level, name");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch subjects
$stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY code, name_en");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Fetch class-subject mappings for dynamic dropdown filtering
$stmt_cs_map = $db->prepare("
    SELECT cs.class_id, s.id as subject_id, s.name_en, s.code
    FROM class_subjects cs
    JOIN subjects s ON cs.subject_id = s.id
    JOIN classes c ON cs.class_id = c.id
    WHERE cs.school_id = ? AND c.is_active = 1
");
$stmt_cs_map->execute([$school_id]);
$class_subject_mappings = $stmt_cs_map->fetchAll(PDO::FETCH_ASSOC);

$class_subjects_json = [];
foreach ($class_subject_mappings as $mapping) {
    $class_subjects_json[$mapping['class_id']][] = [
        'id' => $mapping['subject_id'],
        'name' => ($mapping['code'] ? '[' . $mapping['code'] . '] ' : '') . $mapping['name_en']
    ];
}

// Fetch mapped subjects grouped by Class
$mapped_subjects = $db->prepare("
    SELECT es.*, c.name as class_name, sub.name_en as subject_name, sub.code as subject_code, sub.credit_hours as default_credit
    FROM exam_subjects es
    JOIN classes c ON es.class_id = c.id
    JOIN subjects sub ON es.subject_id = sub.id
    WHERE es.exam_id = ? AND c.is_active = 1
    ORDER BY c.name, sub.name_en
");
$mapped_subjects->execute([$exam_id]);
$mappings = $mapped_subjects->fetchAll();

// Group mappings by class
$grouped_mappings = [];
foreach ($mappings as $m) {
    $grouped_mappings[$m['class_name']][] = $m;
}


$pageTitle = 'Configure Exam: ' . $exam['name'];
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2><i class="fas fa-cog text-primary"></i> Configure Exam Subjects</h2>
        <h5 class="text-muted"><?= sanitize($exam['name']) ?></h5>
    </div>
    <a href="<?= APP_URL ?>/modules/exams/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success mb-4"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>

<div class="row">
    <!-- Configuration & Utility Panel -->
    <div class="col-md-4 mb-4">
        <!-- Add/Edit Subject Configuration Card -->
        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$exam['is_locked']): ?>
        <div class="card mb-4 shadow-sm border-start border-primary border-3">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><i class="fas <?= $edit_mapping ? 'fa-edit' : 'fa-plus' ?>"></i> <?= $edit_mapping ? 'Edit Subject Details' : 'Add Subject Configuration' ?></h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="map_subject" value="1">
                    <?php if ($edit_mapping): ?>
                    <input type="hidden" name="edit_mapping_id" value="<?= $edit_mapping['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="class_id" class="form-label">Select Class *</label>
                        <select class="form-select" id="class_id" name="class_id" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($edit_mapping && $edit_mapping['class_id'] == $c['id']) ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="subject_id" class="form-label">Select Subject *</label>
                        <select class="form-select" id="subject_id" name="subject_id" required>
                            <option value="">Choose Class First</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="credit_hours" class="form-label text-info font-weight-bold">Total Credit Hours</label>
                        <input type="number" step="any" name="credit_hours" id="credit_hours" class="form-control" placeholder="Default subject credit hours" value="<?= $edit_mapping ? sanitize($edit_mapping['credit_hours'] ?? '') : '' ?>">
                        <div class="form-text text-muted">Leave blank to use the subject's default credit hours from the subjects tab.</div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label for="internal_credit_hours" class="form-label text-info font-weight-bold">Internal Credit Hours</label>
                            <input type="number" step="any" name="internal_credit_hours" id="internal_credit_hours" class="form-control" value="<?= $edit_mapping ? sanitize($edit_mapping['internal_credit_hours'] ?? '') : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-info font-weight-bold">External Credit Hours</label>
                            <input type="number" step="any" name="external_credit_hours" id="external_credit_hours" class="form-control" value="<?= $edit_mapping ? sanitize($edit_mapping['external_credit_hours'] ?? '') : '' ?>">
                        </div>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-success">Theory Full *</label>
                            <input type="number" name="theory_full" class="form-control" value="<?= $edit_mapping ? $edit_mapping['theory_full'] : 75 ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-success">Theory Pass *</label>
                            <input type="number" name="theory_pass" class="form-control" value="<?= $edit_mapping ? $edit_mapping['theory_pass'] : 30 ?>" required>
                        </div>
                    </div>
                    
                    <?php if ($exam['has_internal']): ?>
                    <div class="row g-2 mb-4">
                        <div class="col-md-6">
                            <label class="form-label text-warning">Practical Full *</label>
                            <input type="number" name="practical_full" class="form-control" value="<?= $edit_mapping ? $edit_mapping['practical_full'] : 25 ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-warning">Practical Pass *</label>
                            <input type="number" name="practical_pass" class="form-control" value="<?= $edit_mapping ? $edit_mapping['practical_pass'] : 10 ?>" required>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary py-2 small mb-3">
                        <i class="fas fa-info-circle"></i> Internal exam / practical component is disabled for this exam.
                    </div>
                    <input type="hidden" name="practical_full" value="0">
                    <input type="hidden" name="practical_pass" value="0">
                    <?php endif; ?>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> <?= $edit_mapping ? 'Update Configuration' : 'Map Subject' ?>
                    </button>
                    
                    <?php if ($edit_mapping): ?>
                    <a href="<?= APP_URL ?>/modules/exams/view.php?id=<?= $exam_id ?>" class="btn btn-secondary w-100 mt-2">
                        <i class="fas fa-times"></i> Cancel Edit
                    </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-success text-white py-3">
                <h5 class="mb-0"><i class="fas fa-magic"></i> Auto-initialize Subjects</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <p class="text-muted small">Select a class to automatically load all subjects mapped to it in class configurations with standard 75/25 weightage defaults.</p>
                    <div class="mb-3">
                        <label for="init_class_id" class="form-label">Select Class *</label>
                        <select class="form-select" id="init_class_id" name="init_class_id" required>
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="initialize_defaults" class="btn btn-success w-100">
                        <i class="fas fa-sync"></i> Initialize Class Subjects
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Print Admit Cards Card -->
        <div class="card mb-4 shadow-sm border-start border-info border-3">
            <div class="card-header bg-info bg-opacity-10 text-info-emphasis py-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-print"></i> Print Admit Cards</h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">Select a class to print admit cards for all students in that class. Renders 4 students per page.</p>
                <div class="mb-3">
                    <label for="print_class_id" class="form-label font-weight-bold">Select Class *</label>
                    <select class="form-select" id="print_class_id" required>
                        <option value="">Choose Class</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" onclick="printAdmitCards()" class="btn btn-info text-white w-100 fw-bold">
                    <i class="fas fa-id-card"></i> Generate & Print Cards
                </button>
            </div>
        </div>

        <!-- Signature Uploads Card -->
        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])): ?>
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0"><i class="fas fa-signature"></i> Signature Uploads</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="upload_signatures" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Class Teacher Signature</label>
                        <?php if (!empty($school['class_teacher_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['class_teacher_signature'] ?>" alt="Class Teacher Signature" class="img-fluid" style="max-height: 40px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="class_teacher_sig" class="form-control" accept="image/png, image/jpeg, image/jpg">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Exam Coordinator Signature</label>
                        <?php if (!empty($school['exam_coordinator_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['exam_coordinator_signature'] ?>" alt="Exam Coordinator Signature" class="img-fluid" style="max-height: 40px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="exam_coordinator_sig" class="form-control" accept="image/png, image/jpeg, image/jpg">
                    </div>

                    <button type="submit" class="btn btn-dark w-100">
                        <i class="fas fa-upload"></i> Upload Signatures
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Table of Configured Subjects -->
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-success"><i class="fas fa-clipboard-list"></i> Configured Subjects Grid</h5>
            </div>
            <div class="card-body">
                <?php if (count($grouped_mappings) > 0): ?>
                    <?php foreach ($grouped_mappings as $class_name => $class_mappings): ?>
                    <div class="mb-4">
                        <h6 class="text-primary bg-primary bg-opacity-10 p-2 rounded mb-3">
                            <i class="fas fa-school"></i> <?= sanitize($class_name) ?>
                        </h6>
                        <div class="table-responsive mb-2">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Subject</th>
                                        <th class="text-center">Credit Hours</th>
                                        <th class="text-center">Theory (Full/Pass)</th>
                                        <th class="text-center">Practical (Full/Pass)</th>
                                        <th class="text-center">Total Full Marks</th>
                                        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$exam['is_locked']): ?>
                                        <th class="text-end">Action</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($class_mappings as $m): ?>
                                    <tr>
                                        <td>
                                            <strong><?= sanitize($m['subject_name']) ?></strong> 
                                            <span class="text-muted font-monospace small">(<?= sanitize($m['subject_code']) ?>)</span>
                                        </td>
                                        <td class="text-center font-monospace">
                                            <?= sanitize($m['credit_hours'] ?? $m['default_credit'] ?? 'N/A') ?><br>
                                            <small class="text-muted">
                                                (IN: <?= sanitize($m['internal_credit_hours'] ?? '-') ?>, EX: <?= sanitize($m['external_credit_hours'] ?? '-') ?>)
                                            </small>
                                        </td>
                                        <td class="text-center text-success">
                                            <?= $m['theory_full'] ?> / <?= $m['theory_pass'] ?>
                                        </td>
                                        <td class="text-center text-warning">
                                            <?= $m['practical_full'] ?> / <?= $m['practical_pass'] ?>
                                        </td>
                                        <td class="text-center font-monospace">
                                            <strong><?= $m['theory_full'] + $m['practical_full'] ?></strong>
                                        </td>
                                        <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$exam['is_locked']): ?>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="<?= APP_URL ?>/modules/exams/view.php?id=<?= $exam_id ?>&edit_mapping_id=<?= $m['id'] ?>" 
                                                   class="btn btn-sm btn-outline-warning" title="Edit Configuration">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="<?= APP_URL ?>/modules/exams/view.php?id=<?= $exam_id ?>&delete_mapping=<?= $m['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger" title="Remove Subject">
                                                    <i class="fas fa-times-circle"></i> Remove
                                                </a>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-exclamation-triangle fa-3x text-muted mb-3"></i>
                        <p class="text-muted mb-0">No subjects mapped to this exam yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function printAdmitCards() {
    const classId = document.getElementById('print_class_id').value;
    if (!classId) {
        Swal.fire('Error', 'Please select a class first.', 'error');
        return;
    }
    window.open('<?= APP_URL ?>/modules/exams/admit_cards.php?exam_id=<?= $exam_id ?>&class_id=' + classId, '_blank');
}

// Dynamic class-subject dropdown filtering
const classSubjectsMap = <?= json_encode($class_subjects_json) ?>;
const editMapping = <?= $edit_mapping ? json_encode($edit_mapping) : 'null' ?>;

document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    
    function updateSubjects() {
        const classId = classSelect.value;
        const selectedSubjectId = editMapping ? editMapping.subject_id : '';
        
        if (classId) {
            subjectSelect.innerHTML = '<option value="">Choose Subject</option>';
            if (classSubjectsMap[classId]) {
                classSubjectsMap[classId].forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name;
                    if (sub.id == selectedSubjectId) {
                        opt.selected = true;
                    }
                    subjectSelect.appendChild(opt);
                });
            } else {
                subjectSelect.innerHTML = '<option value="">No Subjects Mapped to Class</option>';
            }
        } else {
            subjectSelect.innerHTML = '<option value="">Choose Class First</option>';
        }
    }
    
    if (classSelect) {
        classSelect.addEventListener('change', updateSubjects);
        // Pre-fill / trigger on page load (essential for edit states)
        updateSubjects();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
