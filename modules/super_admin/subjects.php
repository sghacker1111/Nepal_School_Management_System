<?php
/**
 * Super Admin Subjects & Chapters Catalog Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole('super_admin');

$db = getDB();
$error = '';
$success = '';

// Handle AJAX Chapter Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_chapter'])) {
    header('Content-Type: application/json');
    $action = $_POST['action_chapter'];
    
    if ($action === 'add') {
        $c_id = (int)$_POST['class_id'];
        $s_id = (int)$_POST['subject_id'];
        $name = trim($_POST['name'] ?? '');
        $name_np = trim($_POST['name_np'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        if (empty($name) || !$c_id || !$s_id) {
            echo json_encode(['success' => false, 'message' => 'Chapter name is required.']);
            exit;
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO chapters (school_id, class_id, subject_id, name, name_np, sort_order) VALUES (0, ?, ?, ?, ?, ?)");
            $stmt->execute([$c_id, $s_id, $name, $name_np, $sort_order]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete') {
        $ch_id = (int)$_POST['chapter_id'];
        try {
            $stmt = $db->prepare("DELETE FROM chapters WHERE id = ? AND school_id = 0");
            $stmt->execute([$ch_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_by_name') {
        $c_id = (int)$_POST['class_id'];
        $s_id = (int)$_POST['subject_id'];
        $name = trim($_POST['name'] ?? '');
        try {
            $stmt = $db->prepare("DELETE FROM chapters WHERE school_id = 0 AND class_id = ? AND subject_id = ? AND name = ?");
            $stmt->execute([$c_id, $s_id, $name]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle Add/Edit Subject Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_subject'])) {
    $action = $_POST['action_subject'];
    $name_en = trim($_POST['name_en'] ?? '');
    $name_np = trim($_POST['name_np'] ?? '');
    $code = trim($_POST['code'] ?? '');
    
    // Theory (TH) individual fields
    $th_credit_hours = round((float)($_POST['th_credit_hours'] ?? 3.0), 2);
    $th_full_marks = round((float)($_POST['th_full_marks'] ?? 75.0), 2);
    $th_pass_marks = round((float)($_POST['th_pass_marks'] ?? 26.25), 2);
    
    // Internal (IN) individual fields
    $in_credit_hours = round((float)($_POST['in_credit_hours'] ?? 1.0), 2);
    $in_full_marks = round((float)($_POST['in_full_marks'] ?? 25.0), 2);
    $in_pass_marks = round((float)($_POST['in_pass_marks'] ?? 10.0), 2);
    
    // Total combined fields
    $credit_hours = round($th_credit_hours + $in_credit_hours, 2);
    $full_marks = round($th_full_marks + $in_full_marks, 2);
    $pass_marks = round($th_pass_marks + $in_pass_marks, 2);
    
    $is_optional = !empty($_POST['is_optional']) ? 1 : 0;
    $type = $is_optional ? 'optional' : 'compulsory';
    
    if (empty($name_en) || empty($code)) {
        $error = "Subject Name (EN) and Code are required.";
    } else {
        try {
            if ($action === 'add') {
                $stmt = $db->prepare("
                    INSERT INTO subjects (
                        code, name_en, name_np, type, full_marks, pass_marks, credit_hours,
                        th_credit_hours, in_credit_hours, th_full_marks, th_pass_marks, in_full_marks, in_pass_marks,
                        is_hidden, school_id
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
                ");
                $stmt->execute([
                    $code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours,
                    $th_credit_hours, $in_credit_hours, $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks
                ]);
                $new_subject_id = $db->lastInsertId();
                
                // Automatically map class if selected
                $map_class_id = (int)($_POST['map_class_id'] ?? 0);
                if ($map_class_id) {
                    $stmt_map_check = $db->prepare("
                        SELECT s.name_en FROM class_subjects cs
                        JOIN subjects s ON cs.subject_id = s.id
                        WHERE cs.class_id = ? AND (
                            LOWER(TRIM(s.name_en)) = LOWER(TRIM(?))
                            OR (? != '' AND s.name_np != '' AND s.name_np IS NOT NULL AND TRIM(s.name_np) = TRIM(?))
                            OR (? != '' AND LOWER(TRIM(s.code)) = LOWER(TRIM(?)))
                        )
                    ");
                    $stmt_map_check->execute([$map_class_id, $name_en, $name_np, $name_np, $code, $code]);
                    if (!$stmt_map_check->fetch()) {
                        $stmt_map = $db->prepare("
                            INSERT INTO class_subjects (
                                class_id, subject_id, credit_hours,
                                th_credit_hours, in_credit_hours, th_full_marks, th_pass_marks, in_full_marks, in_pass_marks,
                                school_id, is_optional, is_active
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1)
                        ");
                        $stmt_map->execute([
                            $map_class_id, $new_subject_id, $credit_hours,
                            $th_credit_hours, $in_credit_hours, $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks,
                            $is_optional
                        ]);
                    }
                }
                
                $success = "Universal subject added and mapped successfully with Theory (TH) and Internal (IN) components.";
            } elseif ($action === 'edit') {
                $s_id = (int)$_POST['subject_id'];
                $stmt = $db->prepare("
                    UPDATE subjects 
                    SET code = ?, name_en = ?, name_np = ?, type = ?, full_marks = ?, pass_marks = ?, credit_hours = ?,
                        th_credit_hours = ?, in_credit_hours = ?, th_full_marks = ?, th_pass_marks = ?, in_full_marks = ?, in_pass_marks = ?
                    WHERE id = ? AND school_id = 0
                ");
                $stmt->execute([
                    $code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours,
                    $th_credit_hours, $in_credit_hours, $th_full_marks, $th_pass_marks, $in_full_marks, $in_pass_marks,
                    $s_id
                ]);
                $success = "Universal subject updated successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Disallow Hard Delete Action (Data Integrity Protection)
if (isset($_GET['delete_subject'])) {
    $error = "Permanent deletion of subjects is disabled to protect student marks, report cards, and examination ledgers. You can toggle the subject to 'Hidden' instead.";
}

// Handle Toggle Hide / Show Subject Action
if (isset($_GET['toggle_hide'])) {
    $s_id = (int)$_GET['toggle_hide'];
    try {
        $stmt = $db->prepare("UPDATE subjects SET is_hidden = (CASE WHEN is_hidden = 1 THEN 0 ELSE 1 END) WHERE id = ? AND school_id = 0");
        $stmt->execute([$s_id]);
        $success = "Subject visibility status toggled successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle Add Class-Subject Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_map'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    
    if (!$class_id || !$subject_id) {
        $error = "Please select both Class and Subject to map.";
    } else {
        try {
            // Check if already mapped by ID or by matching name
            $stmt = $db->prepare("
                SELECT s.name_en FROM class_subjects cs
                JOIN subjects s ON cs.subject_id = s.id
                WHERE cs.class_id = ? AND (
                    cs.subject_id = ?
                    OR LOWER(TRIM(s.name_en)) = LOWER(TRIM((SELECT name_en FROM subjects WHERE id = ?)))
                )
            ");
            $stmt->execute([$class_id, $subject_id, $subject_id]);
            $existing_map = $stmt->fetch();
            if ($existing_map) {
                $error = "Duplicate Subject Blocked: A subject with name '" . htmlspecialchars($existing_map['name_en']) . "' is already mapped to this class. Duplicate subjects for each class are not allowed.";
            } else {
                // Fetch subject default evaluation data
                $stmt_s = $db->prepare("SELECT * FROM subjects WHERE id = ?");
                $stmt_s->execute([$subject_id]);
                $s_data = $stmt_s->fetch();
                
                $ch = (float)($s_data['credit_hours'] ?? 4.0);
                $th_ch = (float)($s_data['th_credit_hours'] ?? 3.0);
                $in_ch = (float)($s_data['in_credit_hours'] ?? 1.0);
                $th_fm = (float)($s_data['th_full_marks'] ?? 75.0);
                $th_pm = (float)($s_data['th_pass_marks'] ?? 26.25);
                $in_fm = (float)($s_data['in_full_marks'] ?? 25.0);
                $in_pm = (float)($s_data['in_pass_marks'] ?? 10.0);
                $is_opt = ($s_data['type'] === 'optional') ? 1 : 0;
                
                $stmt = $db->prepare("
                    INSERT INTO class_subjects (
                        class_id, subject_id, credit_hours,
                        th_credit_hours, in_credit_hours, th_full_marks, th_pass_marks, in_full_marks, in_pass_marks,
                        school_id, is_optional, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1)
                ");
                $stmt->execute([$class_id, $subject_id, $ch, $th_ch, $in_ch, $th_fm, $th_pm, $in_fm, $in_pm, $is_opt]);
                $success = "Subject mapped to class successfully.";
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

// Disallow Delete Mapping (Data Integrity Protection)
if (isset($_GET['delete_map'])) {
    $error = "Deleting class mappings is disabled to preserve historical exam records. You can toggle mapping status instead.";
}

// Handle Toggle Mapping Status
if (isset($_GET['toggle_map_status'])) {
    $map_id = (int)$_GET['toggle_map_status'];
    try {
        $stmt = $db->prepare("UPDATE class_subjects SET is_active = (CASE WHEN is_active = 1 THEN 0 ELSE 1 END) WHERE id = ? AND school_id = 0");
        $stmt->execute([$map_id]);
        $success = "Class mapping status updated successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch all universal classes
$classes = $db->query("SELECT * FROM classes WHERE school_id = 0 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Filter show hidden
$show_hidden = isset($_GET['show_hidden']) && $_GET['show_hidden'] == '1';
$hidden_clause = $show_hidden ? "" : "WHERE is_hidden = 0 OR is_hidden IS NULL";

// Fetch universal subjects
$subjects = $db->query("SELECT * FROM subjects WHERE school_id = 0 " . ($show_hidden ? "" : "AND (is_hidden = 0 OR is_hidden IS NULL)") . " ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);

// Fetch class-subject mappings
$mappings = $db->query("
    SELECT cs.id, c.name as class_name, s.name_en as subject_name, cs.credit_hours, cs.th_credit_hours, cs.in_credit_hours, cs.class_id, cs.subject_id, cs.is_active, cs.is_optional
    FROM class_subjects cs
    JOIN classes c ON cs.class_id = c.id
    JOIN subjects s ON cs.subject_id = s.id
    WHERE cs.school_id = 0
    ORDER BY c.id ASC, s.name_en ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Subjects & Chapters Catalog';
$activePage = 'super_admin_subjects';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
    <h2><i class="fas fa-book-open text-primary"></i> Subjects & Chapters Catalog (Universal)</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Left Column: Manage Universal Subjects & Mapping -->
    <div class="col-lg-6">
        <!-- 1. Manage Subjects Card -->
        <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px;">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-book"></i> Universal Subjects</h5>
                    <small class="text-muted">Standard CDC subjects with TH & IN breakdown</small>
                </div>
                <div class="d-flex gap-2">
                    <a href="?show_hidden=<?= $show_hidden ? '0' : '1' ?>" class="btn btn-sm <?= $show_hidden ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>">
                        <i class="fas <?= $show_hidden ? 'fa-eye-slash' : 'fa-eye' ?>"></i> <?= $show_hidden ? 'Hide Inactive' : 'Show Hidden' ?>
                    </a>
                    <button class="btn btn-sm btn-primary" onclick="showAddSubjectForm()"><i class="fas fa-plus"></i> Add Subject</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Theory (TH)</th>
                                <th>Internal (IN)</th>
                                <th>Credit</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $s): ?>
                            <tr class="<?= !empty($s['is_hidden']) ? 'table-secondary opacity-75' : '' ?>">
                                <td><code><?= sanitize($s['code']) ?></code></td>
                                <td>
                                    <strong><?= sanitize($s['name_en']) ?></strong>
                                    <div class="text-muted small"><?= sanitize($s['name_np']) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary font-monospace">
                                        <?= (float)($s['th_credit_hours'] ?? 0) ?> CH
                                    </span>
                                    <div class="small text-muted font-monospace"><?= (float)($s['th_full_marks'] ?? 75) ?> FM</div>
                                </td>
                                <td>
                                    <span class="badge bg-warning bg-opacity-10 text-dark border border-warning font-monospace">
                                        <?= (float)($s['in_credit_hours'] ?? 0) ?> CH
                                    </span>
                                    <div class="small text-muted font-monospace"><?= (float)($s['in_full_marks'] ?? 25) ?> FM</div>
                                </td>
                                <td><strong class="font-monospace"><?= $s['credit_hours'] ?></strong> CH</td>
                                <td>
                                    <?php if (!empty($s['is_hidden'])): ?>
                                        <span class="badge bg-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden</span>
                                    <?php else: ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-warning" onclick='editSubject(<?= json_encode($s) ?>)' title="Edit Subject"><i class="fas fa-edit"></i></button>
                                    <a href="?toggle_hide=<?= $s['id'] ?><?= $show_hidden ? '&show_hidden=1' : '' ?>" 
                                       class="btn btn-sm <?= !empty($s['is_hidden']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>" 
                                       title="<?= !empty($s['is_hidden']) ? 'Unhide Subject' : 'Hide Subject' ?>">
                                        <i class="fas <?= !empty($s['is_hidden']) ? 'fa-eye' : 'fa-eye-slash' ?>"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-light py-2">
                <small class="text-muted"><i class="fas fa-shield-alt text-primary me-1"></i> Deletion is permanently disabled to preserve student marks and results. Use Hide/Unhide.</small>
            </div>
        </div>

        <!-- 2. Class-Subject Mappings Card -->
        <div class="card border-0 shadow-sm" style="border-radius: 16px;">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-link"></i> Class-Subject Mapping</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="row g-2 align-items-end mb-4">
                    <input type="hidden" name="action_map" value="1">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Select Class</label>
                        <select name="class_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Select Subject</label>
                        <select name="subject_id" class="form-select form-select-sm" required>
                            <option value="">-- Choose Subject --</option>
                            <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize($s['name_en']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-link"></i> Map</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Credit (TH + IN)</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $m): ?>
                            <tr class="<?= empty($m['is_active']) ? 'table-secondary opacity-75' : '' ?>">
                                <td><span class="badge bg-secondary"><?= sanitize($m['class_name']) ?></span></td>
                                <td><strong><?= sanitize($m['subject_name']) ?></strong></td>
                                <td>
                                    <span class="font-monospace fw-bold"><?= $m['credit_hours'] ?> CH</span>
                                    <small class="text-muted">(TH: <?= (float)($m['th_credit_hours'] ?? 0) ?> | IN: <?= (float)($m['in_credit_hours'] ?? 0) ?>)</small>
                                </td>
                                <td>
                                    <?php if (!empty($m['is_active'])): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="?toggle_map_status=<?= $m['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Toggle active/hidden">
                                        <i class="fas <?= !empty($m['is_active']) ? 'fa-eye-slash' : 'fa-eye' ?>"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Manage Chapters Class-wise & Subject-wise -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm" style="border-radius: 16px; min-height: 80vh;">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-th-list"></i> Subject Chapters Manager</h5>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-4">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Filter Class</label>
                        <select id="chapterFilterClass" class="form-select form-select-sm" onchange="loadChaptersList()">
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Filter Subject</label>
                        <select id="chapterFilterSubject" class="form-select form-select-sm" onchange="loadChaptersList()">
                            <option value="">-- Choose Subject --</option>
                            <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= sanitize($s['name_en']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Add Chapter Section -->
                <div id="addChapterWrapper" style="display:none;" class="bg-light p-3 rounded-3 mb-4">
                    <h6 class="fw-bold mb-2 text-secondary">Add New Chapter</h6>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <input type="text" id="newChapterName" class="form-control form-control-sm" placeholder="Chapter Name (English)">
                        </div>
                        <div class="col-md-6">
                            <input type="text" id="newChapterNameNp" class="form-control form-control-sm" placeholder="Chapter Name (Nepali - optional)">
                        </div>
                        <div class="col-md-3">
                            <input type="number" id="newChapterSort" class="form-control form-control-sm" placeholder="Order (e.g. 1)" value="0">
                        </div>
                        <div class="col-md-9 text-end">
                            <button class="btn btn-sm btn-success px-4" onclick="addChapter()"><i class="fas fa-plus"></i> Add</button>
                        </div>
                    </div>
                </div>

                <!-- Chapters List Table -->
                <div id="chaptersListWrapper">
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-filter fa-2x mb-3 text-muted"></i>
                        <p>Select both Class and Subject filters to manage chapters.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Subject Modal with Individual Theory & Internal Evaluation Fields -->
<div class="modal fade" id="subjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content border-0 shadow-lg" style="border-radius:16px;">
            <input type="hidden" name="action_subject" id="subjectAction" value="add">
            <input type="hidden" name="subject_id" id="subjectId">
            
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="subjectModalTitle"><i class="fas fa-book"></i> Add Subject</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Code *</label>
                        <input type="text" name="code" id="subjectCode" class="form-control font-monospace" required placeholder="e.g. nep01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Classification *</label>
                        <select name="is_optional" id="subjectIsOptional" class="form-select">
                            <option value="0">Compulsory (अनिवार्य)</option>
                            <option value="1">Optional / Elective (ऐच्छिक)</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Name (EN) *</label>
                        <input type="text" name="name_en" id="subjectNameEn" class="form-control" required placeholder="e.g. Nepali">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Name (NP)</label>
                        <input type="text" name="name_np" id="subjectNameNp" class="form-control" placeholder="e.g. नेपाली">
                    </div>
                    
                    <div class="col-12" id="subjectMapClassWrapper">
                        <label class="form-label fw-semibold">Target Class (Map Class) *</label>
                        <select name="map_class_id" id="subjectMapClassId" class="form-select">
                            <option value="">-- Choose Class to Map Automatically --</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Individual Fields for Theory (TH) and Internal (IN) -->
                    <div class="col-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-primary me-2"><i class="fas fa-book-open"></i></span>
                                <strong class="text-primary">Theory (TH)</strong>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">Credit Hours</label>
                                <input type="number" step="0.01" name="th_credit_hours" id="subjectThCh" class="form-control form-control-sm font-monospace" value="3.00" oninput="calculateModalTotals()">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">Full Marks</label>
                                <input type="number" step="0.01" name="th_full_marks" id="subjectThFm" class="form-control form-control-sm font-monospace" value="75.00" oninput="calculateModalTotals()">
                            </div>
                            <div>
                                <label class="form-label small text-muted mb-0">Pass Marks</label>
                                <input type="number" step="0.01" name="th_pass_marks" id="subjectThPm" class="form-control form-control-sm font-monospace" value="26.25">
                            </div>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="p-3 border rounded bg-light">
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-warning text-dark me-2"><i class="fas fa-tasks"></i></span>
                                <strong class="text-dark">Internal (IN)</strong>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">Credit Hours</label>
                                <input type="number" step="0.01" name="in_credit_hours" id="subjectInCh" class="form-control form-control-sm font-monospace" value="1.00" oninput="calculateModalTotals()">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small text-muted mb-0">Full Marks</label>
                                <input type="number" step="0.01" name="in_full_marks" id="subjectInFm" class="form-control form-control-sm font-monospace" value="25.00" oninput="calculateModalTotals()">
                            </div>
                            <div>
                                <label class="form-label small text-muted mb-0">Pass Marks</label>
                                <input type="number" step="0.01" name="in_pass_marks" id="subjectInPm" class="form-control form-control-sm font-monospace" value="10.00">
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="p-2 border rounded bg-white d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-muted d-block">Combined Credit Hours:</small>
                                <strong id="modal_total_ch_display" class="text-primary font-monospace">4.00 CH</strong>
                            </div>
                            <div class="text-end">
                                <small class="text-muted d-block">Combined Full Marks:</small>
                                <strong id="modal_total_fm_display" class="text-dark font-monospace">100.00 FM</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fas fa-save"></i> Save Subject</button>
            </div>
        </form>
    </div>
</div>

<script>
let subjectModal = null;

document.addEventListener('DOMContentLoaded', function() {
    subjectModal = new bootstrap.Modal(document.getElementById('subjectModal'));
});

function calculateModalTotals() {
    const thCh = parseFloat(document.getElementById('subjectThCh').value) || 0;
    const inCh = parseFloat(document.getElementById('subjectInCh').value) || 0;
    const thFm = parseFloat(document.getElementById('subjectThFm').value) || 0;
    const inFm = parseFloat(document.getElementById('subjectInFm').value) || 0;

    const totalCh = (thCh + inCh).toFixed(2);
    const totalFm = (thFm + inFm).toFixed(2);

    document.getElementById('modal_total_ch_display').innerText = totalCh + ' CH';
    document.getElementById('modal_total_fm_display').innerText = totalFm + ' FM';
}

function showAddSubjectForm() {
    document.getElementById('subjectAction').value = 'add';
    document.getElementById('subjectId').value = '';
    document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-book"></i> Add Universal Subject';
    
    document.getElementById('subjectCode').value = '';
    document.getElementById('subjectNameEn').value = '';
    document.getElementById('subjectNameNp').value = '';
    document.getElementById('subjectIsOptional').value = '0';
    
    document.getElementById('subjectThCh').value = '3.00';
    document.getElementById('subjectThFm').value = '75.00';
    document.getElementById('subjectThPm').value = '26.25';
    
    document.getElementById('subjectInCh').value = '1.00';
    document.getElementById('subjectInFm').value = '25.00';
    document.getElementById('subjectInPm').value = '10.00';
    
    calculateModalTotals();
    
    // Show and require class selection for adding
    document.getElementById('subjectMapClassWrapper').style.display = 'block';
    document.getElementById('subjectMapClassId').required = true;
    document.getElementById('subjectMapClassId').value = '';
    
    subjectModal.show();
}

function editSubject(s) {
    document.getElementById('subjectAction').value = 'edit';
    document.getElementById('subjectId').value = s.id;
    document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Universal Subject';
    
    document.getElementById('subjectCode').value = s.code;
    document.getElementById('subjectNameEn').value = s.name_en;
    document.getElementById('subjectNameNp').value = s.name_np || '';
    document.getElementById('subjectIsOptional').value = (s.type === 'optional') ? '1' : '0';
    
    document.getElementById('subjectThCh').value = parseFloat(s.th_credit_hours || 0).toFixed(2);
    document.getElementById('subjectThFm').value = parseFloat(s.th_full_marks || 75).toFixed(2);
    document.getElementById('subjectThPm').value = parseFloat(s.th_pass_marks || 26.25).toFixed(2);
    
    document.getElementById('subjectInCh').value = parseFloat(s.in_credit_hours || 0).toFixed(2);
    document.getElementById('subjectInFm').value = parseFloat(s.in_full_marks || 25).toFixed(2);
    document.getElementById('subjectInPm').value = parseFloat(s.in_pass_marks || 10).toFixed(2);
    
    calculateModalTotals();
    
    // Hide and don't require class selection for editing
    document.getElementById('subjectMapClassWrapper').style.display = 'none';
    document.getElementById('subjectMapClassId').required = false;
    document.getElementById('subjectMapClassId').value = '';
    
    subjectModal.show();
}

function loadChaptersList() {
    const classId = document.getElementById('chapterFilterClass').value;
    const subjectId = document.getElementById('chapterFilterSubject').value;
    const wrapper = document.getElementById('chaptersListWrapper');
    const addWrapper = document.getElementById('addChapterWrapper');
    
    if (!classId || !subjectId) {
        wrapper.innerHTML = `
            <div class="text-center text-muted py-5">
                <i class="fas fa-filter fa-2x mb-3 text-muted"></i>
                <p>Select both Class and Subject filters to manage chapters.</p>
            </div>
        `;
        addWrapper.style.display = 'none';
        return;
    }
    
    addWrapper.style.display = 'block';
    
    // Fetch chapters via AJAX (fallback to cdc curriculum if empty)
    $.ajax({
        url: '../learning/questions.php',
        data: {
            action_get_chapters: 1,
            class_id: classId,
            subject_id: subjectId
        },
        dataType: 'json',
        success: function(chapters) {
            let html = '';
            if (chapters.length > 0) {
                html = `
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Chapter Name</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                chapters.forEach((ch, idx) => {
                    html += `
                        <tr>
                            <td><strong>${ch}</strong></td>
                            <td class="text-end">
                                <button class="btn btn-xs btn-outline-danger" onclick="deleteChapter('${ch}')"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                });
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                html = `
                    <div class="text-center text-muted py-4">
                        <p>No chapters found for this combination. Fallback catalog has no default chapters either.</p>
                    </div>
                `;
            }
            wrapper.innerHTML = html;
        },
        error: function() {
            wrapper.innerHTML = `<div class="alert alert-danger">Error loading chapters list.</div>`;
        }
    });
}

function addChapter() {
    const classId = document.getElementById('chapterFilterClass').value;
    const subjectId = document.getElementById('chapterFilterSubject').value;
    const name = document.getElementById('newChapterName').value.trim();
    const nameNp = document.getElementById('newChapterNameNp').value.trim();
    const sort = document.getElementById('newChapterSort').value;
    
    if (!name) {
        alert("Chapter name is required.");
        return;
    }
    
    $.ajax({
        url: 'subjects.php',
        type: 'POST',
        data: {
            action_chapter: 'add',
            class_id: classId,
            subject_id: subjectId,
            name: name,
            name_np: nameNp,
            sort_order: sort
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                document.getElementById('newChapterName').value = '';
                document.getElementById('newChapterNameNp').value = '';
                document.getElementById('newChapterSort').value = '0';
                loadChaptersList();
            } else {
                alert("Error: " + res.message);
            }
        },
        error: function() {
            alert("Error adding chapter.");
        }
    });
}

function deleteChapter(name) {
    if (!confirm("Are you sure you want to delete chapter: " + name + "?")) return;
    
    const classId = document.getElementById('chapterFilterClass').value;
    const subjectId = document.getElementById('chapterFilterSubject').value;
    
    // We need to fetch the ID of the chapter first or delete by name
    // Let's create an AJAX request that deletes by name
    $.ajax({
        url: 'subjects.php',
        type: 'POST',
        data: {
            action_chapter: 'delete_by_name',
            class_id: classId,
            subject_id: subjectId,
            name: name
        },
        dataType: 'json',
        success: function(res) {
            loadChaptersList();
        },
        error: function() {
            alert("Error deleting chapter.");
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
