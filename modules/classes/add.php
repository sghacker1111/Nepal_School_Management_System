<?php
/**
 * Classes & Sections Management - Add Page
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
$error = '';
$success = '';

// Handle class creation
if (isset($_POST['add_class'])) {
    $name = sanitize($_POST['name'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $level = sanitize($_POST['level'] ?? 'Basic');
    $grading_type = 'gpa';
    $class_teacher_name = sanitize($_POST['class_teacher_name'] ?? '');
    $is_grading_teaching = isset($_POST['is_grading_teaching']) ? 1 : 0;
    
    if (empty($name)) {
        $error = 'Class name is required';
    } else {
        $stmt_check = $db->prepare("SELECT COUNT(*) FROM classes WHERE LOWER(name) = LOWER(?) AND (school_id = ?)");
        $stmt_check->execute([$name, $school_id]);
        if ($stmt_check->fetchColumn() > 0) {
            $error = 'Class with this name already exists. Class names must be unique.';
        }
    }
    
    if (empty($error)) {
        try {
            $stmt = $db->prepare("INSERT INTO classes (name, name_np, level, grading_type, class_teacher_name, school_id, is_grading_teaching) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $name_np, $level, $grading_type, $class_teacher_name, $school_id, $is_grading_teaching]);
            $new_id = $db->lastInsertId();
            
            // Signature uploading/copying
            $class_teacher_signature = null;
            $upload_dir = UPLOAD_DIR;
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            if (!empty($_POST['class_teacher_sig_drive_path'])) {
                $drive_path = trim($_POST['class_teacher_sig_drive_path'], "\"' ");
                if (file_exists($drive_path) && is_file($drive_path)) {
                    $ext = strtolower(pathinfo($drive_path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        $filename = 'class_teacher_class_' . $new_id . '_' . time() . '.' . $ext;
                        if (copy($drive_path, $upload_dir . $filename)) {
                            $class_teacher_signature = $filename;
                            // Copy to school folder for backward compatibility
                            $school_sub_dir = $upload_dir . 'school/';
                            if (!file_exists($school_sub_dir)) {
                                mkdir($school_sub_dir, 0777, true);
                            }
                            copy($upload_dir . $filename, $school_sub_dir . $filename);
                        }
                    }
                }
            } elseif (isset($_FILES['class_teacher_sig']) && $_FILES['class_teacher_sig']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['class_teacher_sig']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                    $filename = 'class_teacher_class_' . $new_id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['class_teacher_sig']['tmp_name'], $upload_dir . $filename)) {
                        $class_teacher_signature = $filename;
                        // Copy to school folder for backward compatibility
                        $school_sub_dir = $upload_dir . 'school/';
                        if (!file_exists($school_sub_dir)) {
                            mkdir($school_sub_dir, 0777, true);
                        }
                        copy($upload_dir . $filename, $school_sub_dir . $filename);
                    }
                }
            }
            
            if ($class_teacher_signature) {
                $stmt_up = $db->prepare("UPDATE classes SET class_teacher_signature = ? WHERE id = ?");
                $stmt_up->execute([$class_teacher_signature, $new_id]);
            }
            
            Auth::logActivity(Auth::getUserId(), 'Add Class', 'classes', $new_id);
            redirect(APP_URL . '/modules/classes/index.php', 'Class added successfully');
        } catch (PDOException $e) {
            $error = schoolPublicError($e);
        }
    }
}

// Handle section creation
if (isset($_POST['add_section'])) {
    $section_name = sanitize($_POST['section_name'] ?? '');
    $class_id = (int)($_POST['class_id'] ?? 0);
    $teacher_id = $_POST['teacher_id'] !== '' ? (int)$_POST['teacher_id'] : null;
    
    if (empty($section_name) || empty($class_id)) {
        $error = 'Section name and Class are required';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO sections (class_id, name, class_teacher_id, school_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$class_id, $section_name, $teacher_id, $school_id]);
            $new_id = $db->lastInsertId();
            Auth::logActivity(Auth::getUserId(), 'Add Section', 'sections', $new_id);
            redirect(APP_URL . '/modules/classes/index.php', 'Section added successfully');
        } catch (PDOException $e) {
            $error = schoolPublicError($e);
        }
    }
}

// Fetch classes for dropdown
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch teachers for dropdown
$stmt = $db->prepare("SELECT * FROM users WHERE role = 'teacher' AND status = 1 AND school_id = ? ORDER BY full_name");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

$pageTitle = 'Add Class & Section';
$activePage = 'classes';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-plus-circle"></i> Add Class / Section</h2>
    <a href="<?= APP_URL ?>/modules/classes/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
</div>
<?php endif; ?>

<div class="row">
    <!-- Add Class Form -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><i class="fas fa-school"></i> Create New Class</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="name" class="form-label">Class Name (English) *</label>
                        <input type="text" class="form-control" id="name" name="name" placeholder="e.g. Class 2" required>
                    </div>
                    <div class="mb-3">
                        <label for="name_np" class="form-label">Class Name (Nepali)</label>
                        <input type="text" class="form-control" id="name_np" name="name_np" placeholder="e.g. कक्षा २">
                    </div>
                    <div class="mb-3">
                        <label for="level" class="form-label">Education Level *</label>
                        <select class="form-select" id="level" name="level" required>
                            <option value="Basic" selected>Basic (Nursery - Class 8)</option>
                            <option value="Secondary">Secondary (Class 9 - Class 12)</option>
                        </select>
                        <small class="text-muted">Nursery to Class 8 are Basic Level; Class 9 to 12 are Secondary Level.</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_grading_teaching" id="is_grading_teaching" value="1">
                            <label class="form-check-label fw-bold" for="is_grading_teaching">
                                Enable Grading Teaching System (Class taught mainly by one Class Teacher)
                            </label>
                        </div>
                    </div>
                    <hr>
                    <h5 class="text-secondary mb-3"><i class="fas fa-user-tie"></i> Class Teacher Settings</h5>
                    <div class="mb-3">
                        <label for="class_teacher_name" class="form-label">Class Teacher Name</label>
                        <select class="form-select" id="class_teacher_name" name="class_teacher_name">
                            <option value="">Select Class Teacher</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= sanitize($t['full_name']) ?>">
                                <?= sanitize($t['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="class_teacher_sig" class="form-label">Class Teacher Signature File (JPG/PNG)</label>
                        <input type="file" class="form-control form-control-sm mb-2" id="class_teacher_sig" name="class_teacher_sig" accept="image/png, image/jpeg, image/jpg">
                        <span class="text-muted small d-block mb-1">OR Enter Local Drive Path:</span>
                        <input type="text" class="form-control form-control-sm" name="class_teacher_sig_drive_path" placeholder="e.g. C:\images\sig_teacher.png">
                    </div>
                    <button type="submit" name="add_class" class="btn btn-primary w-100 mt-3">
                        <i class="fas fa-save"></i> Save Class
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Section Form -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header bg-success text-white py-3">
                <h5 class="mb-0"><i class="fas fa-columns"></i> Create New Section</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="class_id" class="form-label">Class *</label>
                        <select class="form-select" id="class_id" name="class_id" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['id'] ?>"><?= sanitize($class['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="section_name" class="form-label">Section Name *</label>
                        <input type="text" class="form-control" id="section_name" name="section_name" placeholder="e.g. A, B, C, Red, Green" required>
                    </div>
                    <div class="mb-4">
                        <label for="teacher_id" class="form-label">Class Teacher (Optional)</label>
                        <select class="form-select" id="teacher_id" name="teacher_id">
                            <option value="">Select Class Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= $teacher['id'] ?>"><?= sanitize($teacher['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="add_section" class="btn btn-success w-100">
                        <i class="fas fa-save"></i> Save Section
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
