<?php
/**
 * Settings - School Details & Logo / Signatures Management Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';
$success = '';

// Fetch active school
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

if (!$school) {
    die("School profile not found.");
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name_en = sanitize($_POST['name_en'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $emis_code = sanitize($_POST['emis_code'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $principal_name = sanitize($_POST['principal_name'] ?? '');
    $principal_mobile = sanitize($_POST['principal_mobile'] ?? '');
    $technical_person = sanitize($_POST['technical_person'] ?? '');

    if (empty($name_en)) {
        $error = "School name in English is required.";
    } else {
        try {
            $db->beginTransaction();

            // 1. Update basic school info
            $stmt_update = $db->prepare("
                UPDATE schools 
                SET name_en = ?, name_np = ?, emis_code = ?, address = ?, phone = ?, email = ?, 
                    principal_name = ?, principal_mobile = ?, technical_person = ?
                WHERE id = ?
            ");
            $stmt_update->execute([
                $name_en, $name_np, $emis_code, $address, $phone, $email, 
                $principal_name, $principal_mobile, $technical_person, $school_id
            ]);

            // Update active classes status
            $active_classes = $_POST['active_classes'] ?? [];
            $stmt_deact = $db->prepare("UPDATE classes SET is_active = 0 WHERE (school_id = ? OR school_id = 0)");
            $stmt_deact->execute([$school_id]);
            
            if (!empty($active_classes)) {
                $placeholders = implode(',', array_fill(0, count($active_classes), '?'));
                $stmt_act = $db->prepare("UPDATE classes SET is_active = 1 WHERE id IN ($placeholders) AND (school_id = ? OR school_id = 0)");
                $params = array_keys($active_classes);
                $params[] = $school_id;
                $stmt_act->execute($params);
            }

            // 2. Handle File Uploads (School Logo & Signatures)
            $upload_dir = UPLOAD_DIR;
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            // Ensure school subfolder exists for compatibility
            $school_sub_dir = $upload_dir . 'school/';
            if (!file_exists($school_sub_dir)) {
                mkdir($school_sub_dir, 0777, true);
            }

            // Helper closure to copy from local drive path
            $copyFromDrive = function($drive_path, $prefix) use ($school_id, $upload_dir, $school_sub_dir, &$error) {
                if (empty($drive_path)) return null;
                // Trim quotes and whitespace
                $drive_path = trim($drive_path, "\"' ");
                if (!file_exists($drive_path) || !is_file($drive_path)) {
                    $error = "The specified file path does not exist or is not a file: " . sanitize($drive_path);
                    return null;
                }
                $ext = strtolower(pathinfo($drive_path, PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                    $error = "Only PNG, JPG, and JPEG files are allowed. Invalid file: " . sanitize($drive_path);
                    return null;
                }
                $filename = $prefix . '_' . $school_id . '_' . time() . '.' . $ext;
                if (copy($drive_path, $upload_dir . $filename)) {
                    copy($upload_dir . $filename, $school_sub_dir . $filename);
                    return $filename;
                } else {
                    $error = "Failed to copy file from path: " . sanitize($drive_path);
                    return null;
                }
            };

            // (a) School Logo
            if (!empty($_POST['logo_drive_path'])) {
                $logo_filename = $copyFromDrive($_POST['logo_drive_path'], 'logo');
                if ($logo_filename) {
                    $stmt_logo = $db->prepare("UPDATE schools SET logo = ? WHERE id = ?");
                    $stmt_logo->execute([$logo_filename, $school_id]);
                }
            } elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                    $logo_filename = 'logo_' . $school_id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_filename)) {
                        copy($upload_dir . $logo_filename, $school_sub_dir . $logo_filename);
                        $stmt_logo = $db->prepare("UPDATE schools SET logo = ? WHERE id = ?");
                        $stmt_logo->execute([$logo_filename, $school_id]);
                    }
                } else {
                    $error = "Only PNG, JPG, and JPEG files are allowed for the school logo.";
                }
            }

            // (b) Principal Signature
            if (empty($error)) {
                if (!empty($_POST['principal_sig_drive_path'])) {
                    $filename = $copyFromDrive($_POST['principal_sig_drive_path'], 'principal');
                    if ($filename) {
                        $stmt_sig = $db->prepare("UPDATE schools SET principal_signature = ? WHERE id = ?");
                        $stmt_sig->execute([$filename, $school_id]);
                    }
                } elseif (isset($_FILES['principal_sig']) && $_FILES['principal_sig']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['principal_sig']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        $filename = 'principal_' . $school_id . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['principal_sig']['tmp_name'], $upload_dir . $filename)) {
                            copy($upload_dir . $filename, $school_sub_dir . $filename);
                            $stmt_sig = $db->prepare("UPDATE schools SET principal_signature = ? WHERE id = ?");
                            $stmt_sig->execute([$filename, $school_id]);
                        }
                    } else {
                        $error = "Only PNG, JPG, and JPEG signatures are allowed.";
                    }
                }
            }

            // (c) Class Teacher Signature
            if (empty($error)) {
                if (!empty($_POST['class_teacher_sig_drive_path'])) {
                    $filename = $copyFromDrive($_POST['class_teacher_sig_drive_path'], 'class_teacher');
                    if ($filename) {
                        $stmt_sig = $db->prepare("UPDATE schools SET class_teacher_signature = ? WHERE id = ?");
                        $stmt_sig->execute([$filename, $school_id]);
                    }
                } elseif (isset($_FILES['class_teacher_sig']) && $_FILES['class_teacher_sig']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['class_teacher_sig']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        $filename = 'class_teacher_' . $school_id . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['class_teacher_sig']['tmp_name'], $upload_dir . $filename)) {
                            copy($upload_dir . $filename, $school_sub_dir . $filename);
                            $stmt_sig = $db->prepare("UPDATE schools SET class_teacher_signature = ? WHERE id = ?");
                            $stmt_sig->execute([$filename, $school_id]);
                        }
                    } else {
                        $error = "Only PNG, JPG, and JPEG signatures are allowed.";
                    }
                }
            }

            // (d) Exam Coordinator Signature
            if (empty($error)) {
                if (!empty($_POST['exam_coordinator_sig_drive_path'])) {
                    $filename = $copyFromDrive($_POST['exam_coordinator_sig_drive_path'], 'exam_coordinator');
                    if ($filename) {
                        $stmt_sig = $db->prepare("UPDATE schools SET exam_coordinator_signature = ? WHERE id = ?");
                        $stmt_sig->execute([$filename, $school_id]);
                    }
                } elseif (isset($_FILES['exam_coordinator_sig']) && $_FILES['exam_coordinator_sig']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['exam_coordinator_sig']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        $filename = 'exam_coordinator_' . $school_id . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['exam_coordinator_sig']['tmp_name'], $upload_dir . $filename)) {
                            copy($upload_dir . $filename, $school_sub_dir . $filename);
                            $stmt_sig = $db->prepare("UPDATE schools SET exam_coordinator_signature = ? WHERE id = ?");
                            $stmt_sig->execute([$filename, $school_id]);
                        }
                    } else {
                        $error = "Only PNG, JPG, and JPEG signatures are allowed.";
                    }
                }
            }

            if (empty($error)) {
                $db->commit();
                $success = "School configuration settings updated successfully!";
                Auth::logActivity(Auth::getUserId(), 'Update School Settings', 'schools', $school_id);
                
                // Reload school details
                $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
                $stmt->execute([$school_id]);
                $school = $stmt->fetch();
            } else {
                $db->rollBack();
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'School Profile Settings';
$activePage = 'school_settings';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-school text-primary"></i> School Profile Settings</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success mb-4"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
    <div class="row">
        <!-- School Information Column -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-university"></i> Basic Institution Profile</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name_en" class="form-label fw-bold">School Name (English) *</label>
                            <input type="text" class="form-control" id="name_en" name="name_en" value="<?= sanitize($school['name_en']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="name_np" class="form-label fw-bold">School Name (Nepali)</label>
                            <input type="text" class="form-control" id="name_np" name="name_np" value="<?= sanitize($school['name_np'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="emis_code" class="form-label fw-bold">EMIS Code</label>
                            <input type="text" class="form-control" id="emis_code" name="emis_code" value="<?= sanitize($school['emis_code'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-bold">School Email</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= sanitize($school['email'] ?? '') ?>">
                        </div>

                        <div class="col-md-6">
                            <label for="phone" class="form-label fw-bold">School Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?= sanitize($school['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="address" class="form-label fw-bold">School Address</label>
                            <input type="text" class="form-control" id="address" name="address" value="<?= sanitize($school['address'] ?? '') ?>">
                        </div>

                        <hr class="my-4 text-muted">
                        <h5 class="mb-3 text-secondary"><i class="fas fa-user-tie"></i> Administration Personnel Details</h5>
                        
                        <div class="col-md-6">
                            <label for="principal_name" class="form-label fw-bold">Principal / Headmaster Name</label>
                            <input type="text" class="form-control" id="principal_name" name="principal_name" value="<?= sanitize($school['principal_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="principal_mobile" class="form-label fw-bold">Principal Mobile Number</label>
                            <input type="text" class="form-control" id="principal_mobile" name="principal_mobile" value="<?= sanitize($school['principal_mobile'] ?? '') ?>">
                        </div>
                        
                        <div class="col-md-6">
                            <label for="technical_person" class="form-label fw-bold">Technical / Computer Operator Person</label>
                            <input type="text" class="form-control" id="technical_person" name="technical_person" value="<?= sanitize($school['technical_person'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Active Classes Configuration -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-warning text-dark py-3">
                    <h5 class="mb-0"><i class="fas fa-toggle-on"></i> Taught Classes Settings</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">Check the classes that are active and taught in your school. Unchecked classes will be hidden from all modules (Students, Exams, Results, Attendance, Routine, etc.).</p>
                    <?php
                    $stmt_c = $db->prepare("SELECT id, name, is_active FROM classes WHERE (school_id = ? OR school_id = 0) ORDER BY id ASC");
                    $stmt_c->execute([$school_id]);
                    $school_classes = $stmt_c->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="row g-2">
                        <?php foreach ($school_classes as $sc): ?>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch p-2 border rounded bg-light d-flex justify-content-between align-items-center">
                                <label class="form-check-label ms-2 fw-bold" for="class_active_<?= $sc['id'] ?>">
                                    <?= sanitize($sc['name']) ?>
                                </label>
                                <input class="form-check-input me-2" type="checkbox" role="switch" id="class_active_<?= $sc['id'] ?>" name="active_classes[<?= $sc['id'] ?>]" value="1" <?= $sc['is_active'] ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary btn-lg w-100 shadow-sm py-2">
                <i class="fas fa-save me-2"></i> Save Profile Settings
            </button>
        </div>

        <!-- School Logo & Signatures Column -->
        <div class="col-lg-4 mb-4">
            <!-- School Logo -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-success text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-image"></i> Official Institution Logo</h5>
                </div>
                <div class="card-body text-center p-4">
                    <div class="mb-3">
                        <?php if (!empty($school['logo'])): ?>
                        <div class="mb-3 bg-light p-3 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['logo'] ?>" alt="Logo" class="img-fluid" style="max-height: 120px; object-fit: contain;">
                        </div>
                        <?php else: ?>
                        <div class="mb-3 bg-light p-4 border rounded text-muted">
                            <i class="fas fa-school fa-3x mb-2"></i>
                            <span class="d-block small">No logo uploaded yet</span>
                        </div>
                        <?php endif; ?>
                        <label class="form-label d-block text-start small fw-bold">Upload New Logo (JPG/PNG)</label>
                        <input type="file" name="logo" class="form-control form-control-sm mb-2" accept="image/png, image/jpeg, image/jpg">
                        <span class="text-muted d-block text-start small mb-1">OR Enter Local Drive Path:</span>
                        <input type="text" name="logo_drive_path" class="form-control form-control-sm" placeholder="e.g. C:\images\logo.png">
                    </div>
                </div>
            </div>

            <!-- Authorized Signatures -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-file-signature"></i> Authorized Signatures</h5>
                </div>
                <div class="card-body p-4">
                    <!-- Principal Signature -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-uppercase">Principal / Head Teacher Signature (Principle Signature)</label>
                        <?php if (!empty($school['principal_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['principal_signature'] ?>" alt="Principal Signature" class="img-fluid" style="max-height: 50px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="principal_sig" class="form-control form-control-sm mb-2" accept="image/png, image/jpeg, image/jpg">
                        <span class="text-muted d-block text-start small mb-1">OR Enter Local Drive Path:</span>
                        <input type="text" name="principal_sig_drive_path" class="form-control form-control-sm" placeholder="e.g. C:\images\sig_p.png">
                    </div>

                    <!-- Class Teacher Signature -->
                    <div class="mb-4">
                        <label class="form-label fw-bold small text-uppercase">Class Teacher Signature (Default)</label>
                        <small class="text-muted d-block mb-2">Note: You can configure individual class teachers and signature images for each class separately in the <a href="../classes/index.php" class="fw-bold">Classes</a> settings.</small>
                        <?php if (!empty($school['class_teacher_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['class_teacher_signature'] ?>" alt="Class Teacher Signature" class="img-fluid" style="max-height: 50px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="class_teacher_sig" class="form-control form-control-sm mb-2" accept="image/png, image/jpeg, image/jpg">
                        <span class="text-muted d-block text-start small mb-1">OR Enter Local Drive Path:</span>
                        <input type="text" name="class_teacher_sig_drive_path" class="form-control form-control-sm" placeholder="e.g. C:\images\sig_t.png">
                    </div>

                    <!-- Exam Coordinator Signature -->
                    <div class="mb-2">
                        <label class="form-label fw-bold small text-uppercase">Exam Coordinator Signature</label>
                        <?php if (!empty($school['exam_coordinator_signature'])): ?>
                        <div class="mb-2 text-center bg-light p-2 border rounded">
                            <img src="<?= APP_URL ?>/uploads/<?= $school['exam_coordinator_signature'] ?>" alt="Exam Coordinator Signature" class="img-fluid" style="max-height: 50px; object-fit: contain;">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="exam_coordinator_sig" class="form-control form-control-sm mb-2" accept="image/png, image/jpeg, image/jpg">
                        <span class="text-muted d-block text-start small mb-1">OR Enter Local Drive Path:</span>
                        <input type="text" name="exam_coordinator_sig_drive_path" class="form-control form-control-sm" placeholder="e.g. C:\images\sig_ec.png">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
