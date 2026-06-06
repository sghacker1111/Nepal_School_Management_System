<?php
/**
 * Teacher Management - Add Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $status = isset($_POST['status']) ? 1 : 0;
    
    // Extra details
    $dob_ad = $_POST['dob_ad'] ?: null;
    $join_date = $_POST['join_date'] ?: null;
    $perm_issue_date = $_POST['perm_issue_date'] ?: null;
    $temp_issue_date = $_POST['temp_issue_date'] ?: null;
    $cit_no = sanitize($_POST['cit_no'] ?? '');
    $bank_name = sanitize($_POST['bank_name'] ?? '');
    $bank_account = sanitize($_POST['bank_account'] ?? '');
    
    $family_arr = [
        'father_name' => sanitize($_POST['father_name'] ?? ''),
        'mother_name' => sanitize($_POST['mother_name'] ?? ''),
        'spouse_name' => sanitize($_POST['spouse_name'] ?? ''),
    ];
    $family_details = json_encode($family_arr);

    if (empty($full_name) || empty($username) || empty($password)) {
        $error = 'Full name, username, and password are required';
    } else {
        try {
            $db->beginTransaction();
            
            // Check if username already exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Username already exists. Please choose a different one.';
                $db->rollBack();
            } else {
                // Signature uploading
                $signature = null;
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

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name, email, phone, status, school_id, signature) VALUES (?, ?, 'teacher', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed_password, $full_name, $email, $phone, $status, $school_id, $signature]);
                $new_id = $db->lastInsertId();
                
                // Insert into teacher_details
                $stmt = $db->prepare("INSERT INTO teacher_details (user_id, join_date, perm_issue_date, temp_issue_date, cit_no, family_details, dob, bank_name, bank_account) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$new_id, $join_date, $perm_issue_date, $temp_issue_date, $cit_no, $family_details, $dob_ad, $bank_name, $bank_account]);
                
                $db->commit();
                
                Auth::logActivity(Auth::getUserId(), 'Add Teacher', 'users', $new_id);
                redirect(APP_URL . '/modules/teachers/index.php', 'Teacher added successfully');
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Add New Teacher';
$activePage = 'teachers';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-plus-circle"></i> Add New Teacher</h2>
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
            <!-- Basic Information & Account Details -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-user-circle"></i> Account & Contact Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="full_name" class="form-label">Full Name *</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required placeholder="e.g. Ram Bahadur Thapa" value="<?= sanitize($_POST['full_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="e.g. ram@school.edu.np" value="<?= sanitize($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="phone" class="form-label">Phone Number</label>
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g. 9841234567" value="<?= sanitize($_POST['phone'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="join_date" class="form-label">Join Date</label>
                            <input type="date" class="form-control" id="join_date" name="join_date" value="<?= sanitize($_POST['join_date'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    <h6 class="text-muted mb-3"><i class="fas fa-key"></i> Login Credentials</h6>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" required placeholder="e.g. ram_teacher" value="<?= sanitize($_POST['username'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="password" class="form-label">Password *</label>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="e.g. SecurePass123">
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
                            <input type="date" class="form-control" id="dob_ad" name="dob_ad" value="<?= sanitize($_POST['dob_ad'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="dob_bs" class="form-label">Date of Birth (BS)</label>
                            <input type="text" class="form-control" id="dob_bs" name="dob_bs" placeholder="YYYY-MM-DD" value="<?= sanitize($_POST['dob_bs'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="cit_no" class="form-label">Citizenship Number</label>
                            <input type="text" class="form-control" id="cit_no" name="cit_no" placeholder="e.g. 12-01-78-12345" value="<?= sanitize($_POST['cit_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="perm_issue_date" class="form-label">Permanent Teaching License Issue Date</label>
                            <input type="date" class="form-control" id="perm_issue_date" name="perm_issue_date" value="<?= sanitize($_POST['perm_issue_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="temp_issue_date" class="form-label">Temporary License Issue Date</label>
                            <input type="date" class="form-control" id="temp_issue_date" name="temp_issue_date" value="<?= sanitize($_POST['temp_issue_date'] ?? '') ?>">
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
                            <input type="text" class="form-control" id="father_name" name="father_name" placeholder="Father's full name" value="<?= sanitize($_POST['father_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="mother_name" class="form-label">Mother's Name</label>
                            <input type="text" class="form-control" id="mother_name" name="mother_name" placeholder="Mother's full name" value="<?= sanitize($_POST['mother_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="spouse_name" class="form-label">Spouse's Name</label>
                            <input type="text" class="form-control" id="spouse_name" name="spouse_name" placeholder="Spouse's full name (if applicable)" value="<?= sanitize($_POST['spouse_name'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Options Column -->
        <div class="col-md-4">
            <!-- Bank Account Info -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-university"></i> Bank Account Details</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="bank_name" class="form-label">Bank Name</label>
                        <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="e.g. Rastriya Banijya Bank" value="<?= sanitize($_POST['bank_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="bank_account" class="form-label">Bank Account Number</label>
                        <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="e.g. 109001234567" value="<?= sanitize($_POST['bank_account'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Signature Image File -->
            <div class="card mb-4">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-signature"></i> Teacher Signature</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label for="signature" class="form-label font-monospace small">Signature Image File (JPG/PNG)</label>
                        <input type="file" class="form-control" id="signature" name="signature" accept="image/png, image/jpeg, image/jpg">
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
                        <input class="form-check-input" type="checkbox" id="status" name="status" checked>
                        <label class="form-check-label" for="status">Account Active</label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 mb-2">
                        <i class="fas fa-save"></i> Save Teacher
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
