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
    $type = $_POST['type'] ?? 'Theory';
    $full_marks = (int)($_POST['full_marks'] ?? 100);
    $pass_marks = (int)($_POST['pass_marks'] ?? 35);
    $credit_hours = (float)($_POST['credit_hours'] ?? 4.0);
    
    if (empty($name_en) || empty($code)) {
        $error = "Subject Name (EN) and Code are required.";
    } else {
        try {
            if ($action === 'add') {
                $stmt = $db->prepare("
                    INSERT INTO subjects (code, name_en, name_np, type, full_marks, pass_marks, credit_hours, school_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0)
                ");
                $stmt->execute([$code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours]);
                $success = "Universal subject added successfully.";
            } elseif ($action === 'edit') {
                $s_id = (int)$_POST['subject_id'];
                $stmt = $db->prepare("
                    UPDATE subjects 
                    SET code = ?, name_en = ?, name_np = ?, type = ?, full_marks = ?, pass_marks = ?, credit_hours = ?
                    WHERE id = ? AND school_id = 0
                ");
                $stmt->execute([$code, $name_en, $name_np, $type, $full_marks, $pass_marks, $credit_hours, $s_id]);
                $success = "Universal subject updated successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle Delete Subject Action
if (isset($_GET['delete_subject'])) {
    $s_id = (int)$_GET['delete_subject'];
    try {
        $stmt = $db->prepare("DELETE FROM subjects WHERE id = ? AND school_id = 0");
        $stmt->execute([$s_id]);
        $success = "Universal subject deleted successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle Add Class-Subject Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_map'])) {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $hours = (float)($_POST['credit_hours'] ?? 4.0);
    
    if (!$class_id || !$subject_id) {
        $error = "Please select both Class and Subject to map.";
    } else {
        try {
            // Check if already mapped
            $stmt = $db->prepare("SELECT COUNT(*) FROM class_subjects WHERE class_id = ? AND subject_id = ? AND school_id = 0");
            $stmt->execute([$class_id, $subject_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "This mapping already exists.";
            } else {
                $stmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, credit_hours, school_id) VALUES (?, ?, ?, 0)");
                $stmt->execute([$class_id, $subject_id, $hours]);
                $success = "Subject mapped to class successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle Delete Mapping
if (isset($_GET['delete_map'])) {
    $map_id = (int)$_GET['delete_map'];
    try {
        $stmt = $db->prepare("DELETE FROM class_subjects WHERE id = ? AND school_id = 0");
        $stmt->execute([$map_id]);
        $success = "Mapping removed successfully.";
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch all universal classes
$classes = $db->query("SELECT * FROM classes WHERE school_id = 0 ORDER BY level, name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all universal subjects
$subjects = $db->query("SELECT * FROM subjects WHERE school_id = 0 ORDER BY name_en")->fetchAll(PDO::FETCH_ASSOC);

// Fetch class-subject mappings
$mappings = $db->query("
    SELECT cs.id, c.name as class_name, s.name_en as subject_name, cs.credit_hours, cs.class_id, cs.subject_id
    FROM class_subjects cs
    JOIN classes c ON cs.class_id = c.id
    JOIN subjects s ON cs.subject_id = s.id
    WHERE cs.school_id = 0
    ORDER BY c.level, c.name, s.name_en
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
                <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-book"></i> Universal Subjects</h5>
                <button class="btn btn-sm btn-primary" onclick="showAddSubjectForm()"><i class="fas fa-plus"></i> Add Subject</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Credit</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $s): ?>
                            <tr>
                                <td><code><?= sanitize($s['code']) ?></code></td>
                                <td>
                                    <strong><?= sanitize($s['name_en']) ?></strong>
                                    <div class="text-muted small"><?= sanitize($s['name_np']) ?></div>
                                </td>
                                <td><span class="badge bg-secondary"><?= $s['type'] ?></span></td>
                                <td><?= $s['credit_hours'] ?> Hrs</td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-warning" onclick='editSubject(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button>
                                    <a href="?delete_subject=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure? This will delete this subject universally.');"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Subject</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mappings as $m): ?>
                            <tr>
                                <td><span class="badge bg-secondary"><?= sanitize($m['class_name']) ?></span></td>
                                <td><strong><?= sanitize($m['subject_name']) ?></strong></td>
                                <td class="text-end">
                                    <a href="?delete_map=<?= $m['id'] ?>" class="btn btn-xs btn-outline-danger" onclick="return confirm('Remove this mapping?');"><i class="fas fa-times"></i></a>
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

<!-- Add/Edit Subject Modal -->
<div class="modal fade" id="subjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
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
                        <input type="text" name="code" id="subjectCode" class="form-control" required placeholder="e.g. sci10">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Name (EN) *</label>
                        <input type="text" name="name_en" id="subjectNameEn" class="form-control" required placeholder="e.g. Science">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Name (NP)</label>
                        <input type="text" name="name_np" id="subjectNameNp" class="form-control" placeholder="e.g. विज्ञान">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Subject Type</label>
                        <select name="type" id="subjectType" class="form-select">
                            <option value="Theory">Theory</option>
                            <option value="Practical">Practical</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Full Marks</label>
                        <input type="number" name="full_marks" id="subjectFull" class="form-control" value="100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Pass Marks</label>
                        <input type="number" name="pass_marks" id="subjectPass" class="form-control" value="35">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Credit Hours</label>
                        <input type="number" step="0.5" name="credit_hours" id="subjectCredit" class="form-control" value="4">
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

function showAddSubjectForm() {
    document.getElementById('subjectAction').value = 'add';
    document.getElementById('subjectId').value = '';
    document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-book"></i> Add Universal Subject';
    
    document.getElementById('subjectCode').value = '';
    document.getElementById('subjectNameEn').value = '';
    document.getElementById('subjectNameNp').value = '';
    document.getElementById('subjectType').value = 'Theory';
    document.getElementById('subjectFull').value = '100';
    document.getElementById('subjectPass').value = '35';
    document.getElementById('subjectCredit').value = '4';
    
    subjectModal.show();
}

function editSubject(s) {
    document.getElementById('subjectAction').value = 'edit';
    document.getElementById('subjectId').value = s.id;
    document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Universal Subject';
    
    document.getElementById('subjectCode').value = s.code;
    document.getElementById('subjectNameEn').value = s.name_en;
    document.getElementById('subjectNameNp').value = s.name_np;
    document.getElementById('subjectType').value = s.type;
    document.getElementById('subjectFull').value = s.full_marks;
    document.getElementById('subjectPass').value = s.pass_marks;
    document.getElementById('subjectCredit').value = s.credit_hours;
    
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
