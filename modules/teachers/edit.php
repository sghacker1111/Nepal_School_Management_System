<?php
/**
 * Teacher Management - Edit Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch teacher details with profile extension
$stmt = $db->prepare("
    SELECT u.*, td.join_date, td.perm_issue_date, td.temp_issue_date, td.cit_no, td.family_details, td.dob as dob_ad, td.bank_name, td.bank_account 
    FROM users u 
    LEFT JOIN teacher_details td ON u.id = td.user_id 
    WHERE u.id = ? AND u.role = 'teacher' AND u.school_id = ?
");
$school_id = Auth::getSchoolId();
$stmt->execute([$id, $school_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    redirect(APP_URL . '/modules/teachers/index.php', 'Teacher not found', 'error');
}

// Parse family details JSON
$family = json_decode($teacher['family_details'] ?? '', true) ?: [];
$father_name = $family['father_name'] ?? '';
$mother_name = $family['mother_name'] ?? '';
$spouse_name = $family['spouse_name'] ?? '';

// Fetch currently assigned subjects for this teacher
$stmt = $db->prepare("
    SELECT cs.*, c.name as class_name, s.name_en as subject_name 
    FROM class_subjects cs 
    JOIN classes c ON cs.class_id = c.id 
    JOIN subjects s ON cs.subject_id = s.id 
    WHERE cs.school_id = ? AND c.is_active = 1 AND cs.is_active = 1 AND (s.is_hidden = 0 OR s.is_hidden IS NULL)
");
$stmt->execute([$school_id]);
$teaching_subjects = array_values(array_filter($stmt->fetchAll(), static fn($row)=>in_array((string)$id,explode(',',(string)$row['teacher_id']),true)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Extra details
    $dob_ad = ($_POST['dob_ad'] ?? '') ?: null;
    $join_date = ($_POST['join_date'] ?? '') ?: null;
    $perm_issue_date = ($_POST['perm_issue_date'] ?? '') ?: null;
    $temp_issue_date = ($_POST['temp_issue_date'] ?? '') ?: null;
    $cit_no = sanitize($_POST['cit_no'] ?? '');
    $bank_name = sanitize($_POST['bank_name'] ?? '');
    $bank_account = sanitize($_POST['bank_account'] ?? '');
    
    $family_arr = [
        'father_name' => sanitize($_POST['father_name'] ?? ''),
        'mother_name' => sanitize($_POST['mother_name'] ?? ''),
        'spouse_name' => sanitize($_POST['spouse_name'] ?? ''),
    ];
    $family_details = json_encode($family_arr);

    if (empty($full_name) || empty($username)) {
        $error = 'Full name and username are required';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'Passwords must contain at least 8 characters.';
    } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,50}$/', $username) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $error = 'Enter a valid username (3–50 characters) and email address.';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if username is taken by another user
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $id]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already exists. Please choose a different one.';
                $db->rollBack();
            } else {
                // Signature uploading
                $signature = $teacher['signature'];
                if (isset($_FILES['signature']) && $_FILES['signature']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['signature']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        $upload_dir = UPLOAD_DIR;
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        $filename = 'teacher_sig_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['signature']['tmp_name'], $upload_dir . $filename)) {
                            $signature = $filename;
                            
                            $school_sub_dir = $upload_dir . 'school/';
                            if (!file_exists($school_sub_dir)) {
                                mkdir($school_sub_dir, 0777, true);
                            }
                            copy($upload_dir . $filename, $school_sub_dir . $filename);
                        }
                    }
                }

                if (!empty($password)) {
                    // Update password too
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET username = ?, password = ?, full_name = ?, email = ?, phone = ?, status = ?, signature = ? WHERE id = ? AND school_id = ?");
                    $stmt->execute([$username, $hashed_password, $full_name, $email, $phone, $status, $signature, $id, $school_id]);
                } else {
                    // Keep old password
                    $stmt = $db->prepare("UPDATE users SET username = ?, full_name = ?, email = ?, phone = ?, status = ?, signature = ? WHERE id = ? AND school_id = ?");
                    $stmt->execute([$username, $full_name, $email, $phone, $status, $signature, $id, $school_id]);
                }
                
                // Update teacher_details table
                $stmt = $db->prepare("SELECT COUNT(*) FROM teacher_details WHERE user_id = ?");
                $stmt->execute([$id]);
                $exists = $stmt->fetchColumn() > 0;
                
                if ($exists) {
                    $stmt = $db->prepare("UPDATE teacher_details SET join_date = ?, perm_issue_date = ?, temp_issue_date = ?, cit_no = ?, family_details = ?, dob = ?, bank_name = ?, bank_account = ? WHERE user_id = ?");
                    $stmt->execute([$join_date, $perm_issue_date, $temp_issue_date, $cit_no, $family_details, $dob_ad, $bank_name, $bank_account, $id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO teacher_details (user_id, join_date, perm_issue_date, temp_issue_date, cit_no, family_details, dob, bank_name, bank_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$id, $join_date, $perm_issue_date, $temp_issue_date, $cit_no, $family_details, $dob_ad, $bank_name, $bank_account]);
                }
                
                // Clear teacher's old name from any classes
                $stmt_clear = $db->prepare("UPDATE classes SET class_teacher_name = NULL, class_teacher_id = NULL WHERE class_teacher_id = ? AND (school_id = ?)");
                $stmt_clear->execute([$id, $school_id]);

                // Assign to new class if selected
                $assigned_class_id = isset($_POST['assigned_class_id']) ? (int)$_POST['assigned_class_id'] : 0;
                if ($assigned_class_id > 0) {
                    $stmt_set = $db->prepare("UPDATE classes SET class_teacher_name = ?, class_teacher_id = ? WHERE id = ? AND (school_id = ?)");
                    $stmt_set->execute([$full_name, $id, $assigned_class_id, $school_id]);
                    
                    // Handle signature file upload
                    $class_teacher_signature = null;
                    
                    if (!empty($_POST['class_teacher_sig_drive_path'])) {
                        $drive_path = trim($_POST['class_teacher_sig_drive_path'], "\"' ");
                        if (file_exists($drive_path) && is_file($drive_path)) {
                            $ext = strtolower(pathinfo($drive_path, PATHINFO_EXTENSION));
                            if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                                $filename = 'class_teacher_class_' . $assigned_class_id . '_' . time() . '.' . $ext;
                                if (copy($drive_path, UPLOAD_DIR . $filename)) {
                                    $class_teacher_signature = $filename;
                                    $school_sub_dir = UPLOAD_DIR . 'school/';
                                    if (!file_exists($school_sub_dir)) {
                                        mkdir($school_sub_dir, 0777, true);
                                    }
                                    copy(UPLOAD_DIR . $filename, $school_sub_dir . $filename);
                                }
                            }
                        }
                    } elseif (isset($_FILES['class_teacher_sig']) && $_FILES['class_teacher_sig']['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES['class_teacher_sig']['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                            $filename = 'class_teacher_class_' . $assigned_class_id . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['class_teacher_sig']['tmp_name'], UPLOAD_DIR . $filename)) {
                                $class_teacher_signature = $filename;
                                $school_sub_dir = UPLOAD_DIR . 'school/';
                                if (!file_exists($school_sub_dir)) {
                                    mkdir($school_sub_dir, 0777, true);
                                }
                                copy(UPLOAD_DIR . $filename, $school_sub_dir . $filename);
                            }
                        }
                    }
                    
                    if ($class_teacher_signature !== null) {
                        $stmt_sig_up = $db->prepare("UPDATE classes SET class_teacher_signature = ? WHERE id = ? AND school_id = ?");
                        $stmt_sig_up->execute([$class_teacher_signature, $assigned_class_id, $school_id]);
                    }
                }
                
                $db->commit();
                
                Auth::logActivity(Auth::getUserId(), 'Edit Teacher', 'users', $id);
                redirect(APP_URL . '/modules/teachers/index.php', 'Teacher updated successfully');
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Teacher';
$activePage = 'teachers';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit"></i> Edit Teacher Profile</h2>
    <a href="<?= APP_URL ?>/modules/teachers/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
    <div class="row">
        <!-- Main Form Column -->
        <div class="col-md-8">
            <!-- Account & Contact Details -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-user-circle"></i> Account & Contact Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="full_name" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required value="<?= sanitize($teacher['full_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= sanitize($teacher['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?= sanitize($teacher['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="join_date" class="form-label">Join Date</label>
                            <input type="date" class="form-control" id="join_date" name="join_date" value="<?= sanitize($teacher['join_date'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    <h6 class="text-muted mb-3"><i class="fas fa-key"></i> Credentials (Leave password blank to keep current)</h6>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" required value="<?= sanitize($teacher['username']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="password" name="password" minlength="8" placeholder="Fill only to change">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Biographical & Personal Details -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-id-card"></i> Biographical & License Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="dob_ad" class="form-label">Date of Birth (AD)</label>
                            <input type="date" class="form-control" id="dob_ad" name="dob_ad" value="<?= sanitize($teacher['dob_ad'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="dob_bs" class="form-label">Date of Birth (BS)</label>
                            <input type="text" class="form-control" id="dob_bs" name="dob_bs" placeholder="YYYY-MM-DD" value="">
                        </div>
                        <div class="col-md-6">
                            <label for="cit_no" class="form-label">Citizenship Number</label>
                            <input type="text" class="form-control" id="cit_no" name="cit_no" value="<?= sanitize($teacher['cit_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="perm_issue_date" class="form-label">Permanent Teaching License Issue Date</label>
                            <input type="date" class="form-control" id="perm_issue_date" name="perm_issue_date" value="<?= sanitize($teacher['perm_issue_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="temp_issue_date" class="form-label">Temporary License Issue Date</label>
                            <input type="date" class="form-control" id="temp_issue_date" name="temp_issue_date" value="<?= sanitize($teacher['temp_issue_date'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Family Information -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-users"></i> Family Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="father_name" class="form-label">Father's Name</label>
                            <input type="text" class="form-control" id="father_name" name="father_name" value="<?= sanitize($father_name) ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="mother_name" class="form-label">Mother's Name</label>
                            <input type="text" class="form-control" id="mother_name" name="mother_name" value="<?= sanitize($mother_name) ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="spouse_name" class="form-label">Spouse's Name</label>
                            <input type="text" class="form-control" id="spouse_name" name="spouse_name" value="<?= sanitize($spouse_name) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="col-md-4">
            <!-- Bank Account Info -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-university"></i> Bank Account Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="bank_name" class="form-label">Bank Name</label>
                        <input type="text" class="form-control" id="bank_name" name="bank_name" value="<?= sanitize($teacher['bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="bank_account" class="form-label">Bank Account Number</label>
                        <input type="text" class="form-control" id="bank_account" name="bank_account" value="<?= sanitize($teacher['bank_account'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Assigned Subjects List -->
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark py-3">
                    <h5 class="mb-0"><i class="fas fa-book"></i> Assigned Subjects</h5>
                </div>
                <div class="card-body p-4">
                    <?php if (count($teaching_subjects) > 0): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($teaching_subjects as $sub): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <strong><?= sanitize($sub['class_name']) ?></strong> - <?= sanitize($sub['subject_name']) ?>
                                    </div>
                                    <span class="badge bg-primary rounded-pill"><?= $sub['credit_hours'] ?> CH</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="text-muted mb-0 small"><i class="fas fa-info-circle"></i> No subjects mapped to this teacher yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Class Teacher Assignment -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-school"></i> Class Teacher Assignment</h5>
                </div>
                <div class="card-body p-4">
                    <?php
                    // Fetch all classes
                    $stmt_cls = $db->prepare("SELECT id, name FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
                    $stmt_cls->execute([$school_id]);
                    $classes_list = $stmt_cls->fetchAll();
                    
                    // Find if this teacher is currently assigned to a class
                    $stmt_curr = $db->prepare("SELECT id FROM classes WHERE class_teacher_id = ? AND (school_id = ?)");
                    $stmt_curr->execute([$id, $school_id]);
                    $assigned_class_id = $stmt_curr->fetchColumn();
                    ?>
                    <div class="mb-3">
                        <label for="assigned_class_id" class="form-label">Assign as Class Teacher of:</label>
                        <select class="form-select" id="assigned_class_id" name="assigned_class_id">
                            <option value="">None / Not Assigned</option>
                            <?php foreach ($classes_list as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $assigned_class_id == $cls['id'] ? 'selected' : '' ?>>
                                <?= sanitize($cls['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="class_teacher_sig" class="form-label">Signature File (JPG/PNG)</label>
                        <input type="file" class="form-control form-control-sm mb-2" id="class_teacher_sig" name="class_teacher_sig" accept="image/png, image/jpeg, image/jpg">
                        <span class="text-muted small d-block mb-1">OR Enter Local Drive Path:</span>
                        <input type="text" class="form-control form-control-sm" name="class_teacher_sig_drive_path" placeholder="e.g. C:\images\sig_teacher.png">
            </div>

            <!-- Teacher Signature File -->
            <div class="card mb-4">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-signature"></i> Teacher Signature</h5>
                </div>
                <div class="card-body p-4">
                    <?php if (!empty($teacher['signature'])): ?>
                    <div class="mb-2 bg-light p-2 border rounded text-center">
                        <img src="<?= APP_URL ?>/uploads/<?= $teacher['signature'] ?>" alt="Teacher Signature" class="img-fluid" style="max-height: 50px; object-fit: contain;">
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="signature" class="form-label font-monospace small">Update Signature Image File (JPG/PNG)</label>
                        <input type="file" class="form-control form-control-sm" id="signature" name="signature" accept="image/png, image/jpeg, image/jpg">
                    </div>
                </div>
            </div>

            <!-- Publish Status & Actions -->
            <div class="card mb-4">
                <div class="card-header bg-light py-3">
                    <h5 class="mb-0 text-dark"><i class="fas fa-sliders-h"></i> Options</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="status" name="status" <?= $teacher['status'] == 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="status">Account Active</label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 mb-2">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <a href="<?= APP_URL ?>/modules/teachers/index.php" class="btn btn-outline-secondary w-100 py-2">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
