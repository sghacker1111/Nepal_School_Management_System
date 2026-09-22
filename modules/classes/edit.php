<?php
/**
 * Classes Management - Edit Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$error = '';
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

// Fetch active teachers
$stmt_t = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND school_id = ? ORDER BY full_name");
$stmt_t->execute([$school_id]);
$teachers_list = $stmt_t->fetchAll();

// Handle class update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $level = sanitize($_POST['level'] ?? 'Basic');
    $grading_type = $class['grading_type'] ?? 'gpa';
    $class_teacher_name = sanitize($_POST['class_teacher_name'] ?? '');
    
    $is_grading_teaching = isset($_POST['is_grading_teaching']) ? 1 : 0;
    
    $class_teacher_signature = $class['class_teacher_signature'];
    
    if (empty($name)) {
        $error = 'Class name is required';
    } else {
        $stmt_check = $db->prepare("SELECT COUNT(*) FROM classes WHERE LOWER(name) = LOWER(?) AND (school_id = ? OR school_id = 0) AND id != ?");
        $stmt_check->execute([$name, $school_id, $class_id]);
        if ($stmt_check->fetchColumn() > 0) {
            $error = 'Class with this name already exists. Class names must be unique.';
        }
    }
    
    if (empty($error)) {
        try {
            $upload_dir = UPLOAD_DIR;
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Check drive path first
            if (!empty($_POST['class_teacher_sig_drive_path'])) {
                $drive_path = trim($_POST['class_teacher_sig_drive_path'], "\"' ");
                if (file_exists($drive_path) && is_file($drive_path)) {
                    $ext = strtolower(pathinfo($drive_path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        $filename = 'class_teacher_class_' . $class_id . '_' . time() . '.' . $ext;
                        if (copy($drive_path, $upload_dir . $filename)) {
                            $class_teacher_signature = $filename;
                            // Copy to school folder for backward compatibility
                            $school_sub_dir = $upload_dir . 'school/';
                            if (!file_exists($school_sub_dir)) {
                                mkdir($school_sub_dir, 0777, true);
                            }
                            copy($upload_dir . $filename, $school_sub_dir . $filename);
                        } else {
                            $error = "Failed to copy signature from local drive path.";
                        }
                    } else {
                        $error = "Invalid drive file type. Only PNG, JPG, JPEG allowed.";
                    }
                } else {
                    $error = "Drive path file does not exist: " . sanitize($drive_path);
                }
            } elseif (isset($_FILES['class_teacher_sig']) && $_FILES['class_teacher_sig']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['class_teacher_sig']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                    $filename = 'class_teacher_class_' . $class_id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['class_teacher_sig']['tmp_name'], $upload_dir . $filename)) {
                        $class_teacher_signature = $filename;
                        // Copy to school folder for backward compatibility
                        $school_sub_dir = $upload_dir . 'school/';
                        if (!file_exists($school_sub_dir)) {
                            mkdir($school_sub_dir, 0777, true);
                        }
                        copy($upload_dir . $filename, $school_sub_dir . $filename);
                    } else {
                        $error = "Failed to upload signature file.";
                    }
                } else {
                    $error = "Invalid file type. Only PNG, JPG, JPEG allowed.";
                }
            }
            
            if (empty($error)) {
                $stmt = $db->prepare("UPDATE classes SET name = ?, name_np = ?, level = ?, grading_type = ?, class_teacher_name = ?, class_teacher_signature = ?, is_grading_teaching = ? WHERE id = ?");
                $stmt->execute([$name, $name_np, $level, $grading_type, $class_teacher_name, $class_teacher_signature, $is_grading_teaching, $class_id]);
                Auth::logActivity(Auth::getUserId(), 'Edit Class Details', 'classes', $class_id);
                redirect(APP_URL . '/modules/classes/index.php', 'Class updated successfully');
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Class Details';
$activePage = 'classes';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit"></i> Edit Class Details</h2>
    <a href="<?= APP_URL ?>/modules/classes/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
</div>
<?php endif; ?>

<div class="card max-width-600 mx-auto shadow-sm border-0">
    <div class="card-header bg-primary text-white py-3">
        <h5 class="mb-0"><i class="fas fa-school"></i> Modify Class Attributes: <?= sanitize($class['name']) ?></h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
            <div class="mb-3">
                <label for="name" class="form-label">Class Name (English) *</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= sanitize($class['name']) ?>" required>
            </div>
            <div class="mb-3">
                <label for="name_np" class="form-label">Class Name (Nepali)</label>
                <input type="text" class="form-control" id="name_np" name="name_np" value="<?= sanitize($class['name_np'] ?? '') ?>" placeholder="e.g. कक्षा २">
            </div>
            <div class="mb-3">
                <label for="level" class="form-label">Education Level *</label>
                <select class="form-select" id="level" name="level" required>
                    <option value="Basic" <?= $class['level'] === 'Basic' ? 'selected' : '' ?>>Basic (Nursery - Class 8)</option>
                    <option value="Secondary" <?= $class['level'] === 'Secondary' ? 'selected' : '' ?>>Secondary (Class 9 - Class 12)</option>
                </select>
                <small class="text-muted">Nursery to Class 8 are Basic Level; Class 9 to 12 are Secondary Level.</small>
            </div>
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_grading_teaching" id="is_grading_teaching" value="1" <?= (isset($class['is_grading_teaching']) && $class['is_grading_teaching'] == 1) ? 'checked' : '' ?>>
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
                    <?php foreach ($teachers_list as $t): ?>
                    <option value="<?= sanitize($t['full_name']) ?>" <?= ($class['class_teacher_name'] ?? '') === $t['full_name'] ? 'selected' : '' ?>>
                        <?= sanitize($t['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label for="class_teacher_sig" class="form-label">Class Teacher Signature File (JPG/PNG)</label>
                <?php if (!empty($class['class_teacher_signature'])): ?>
                <div class="mb-2 bg-light p-2 border rounded text-center">
                    <img src="<?= APP_URL ?>/uploads/<?= $class['class_teacher_signature'] ?>" alt="Teacher Signature" class="img-fluid" style="max-height: 50px; object-fit: contain;">
                </div>
                <?php endif; ?>
                <input type="file" class="form-control form-control-sm mb-2" id="class_teacher_sig" name="class_teacher_sig" accept="image/png, image/jpeg, image/jpg">
                <span class="text-muted small d-block mb-1">OR Enter Local Drive Path:</span>
                <input type="text" class="form-control form-control-sm" name="class_teacher_sig_drive_path" placeholder="e.g. C:\images\sig_teacher.png">
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2 mt-3">
                <i class="fas fa-save"></i> Save Class Changes
            </button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
