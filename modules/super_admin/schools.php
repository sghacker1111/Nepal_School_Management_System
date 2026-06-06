<?php
/**
 * Super Admin School Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole('super_admin');

$db = getDB();
$error = '';
$success = '';

// Handle Add/Edit School Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $name_en = sanitize($_POST['name_en'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $emis_code = sanitize($_POST['emis_code'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $academic_year_current = sanitize($_POST['academic_year_current'] ?? '2081/2025');
    $principal_name = sanitize($_POST['principal_name'] ?? '');
    $principal_mobile = sanitize($_POST['principal_mobile'] ?? '');
    $technical_person = sanitize($_POST['technical_person'] ?? '');
    
    $admin_full_name = sanitize($_POST['admin_full_name'] ?? '');
    $admin_username = sanitize($_POST['admin_username'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';
    
    if (empty($name_en) || empty($name_np) || empty($emis_code) || empty($address) || empty($phone) || empty($email) || empty($principal_name) || empty($principal_mobile) || empty($technical_person) || empty($admin_full_name) || empty($admin_username)) {
        $error = "All fields are required.";
    } else {
        try {
            if ($action === 'add') {
                if (empty($admin_password)) {
                    $error = "Administrator password is required for new schools.";
                } else {
                    // Check username uniqueness
                    $stmt_u = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt_u->execute([$admin_username]);
                    if ($stmt_u->fetchColumn() > 0) {
                        $error = "Administrator username already exists. Please choose a unique one.";
                    } else {
                        $db->beginTransaction();
                        
                        $stmt = $db->prepare("
                            INSERT INTO schools (name_en, name_np, emis_code, address, phone, email, academic_year_current, principal_name, principal_mobile, technical_person) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$name_en, $name_np, $emis_code, $address, $phone, $email, $academic_year_current, $principal_name, $principal_mobile, $technical_person]);
                        $new_school_id = $db->lastInsertId();
                        
                        // Add default academic years for this new school automatically!
                        $stmt = $db->prepare("INSERT INTO academic_years (year_bs, year_ad, is_active, school_id) VALUES ('2081', '2024', 1, ?)");
                        $stmt->execute([$new_school_id]);
                        
                        $stmt = $db->prepare("INSERT INTO academic_years (year_bs, year_ad, is_active, school_id) VALUES ('2083', '2026', 0, ?)");
                        $stmt->execute([$new_school_id]);
                        
                        // Create admin user
                        $pass_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            INSERT INTO users (username, password, role, full_name, email, phone, school_id, status) 
                            VALUES (?, ?, 'school_admin', ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$admin_username, $pass_hash, $admin_full_name, $email, $phone, $new_school_id]);
                        
                        $db->commit();
                        Auth::logActivity(Auth::getUserId(), 'Create School & Admin', 'schools', $new_school_id);
                        $success = "School registered and administrator account created successfully.";
                    }
                }
            } elseif ($action === 'edit') {
                $school_id = (int)$_POST['school_id'];
                
                // Find existing admin user ID for this school
                $stmt_admin = $db->prepare("SELECT id FROM users WHERE school_id = ? AND role = 'school_admin' LIMIT 1");
                $stmt_admin->execute([$school_id]);
                $admin_record = $stmt_admin->fetch();
                $admin_user_id = $admin_record ? $admin_record['id'] : null;
                
                $username_taken = false;
                if ($admin_user_id) {
                    $stmt_u = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                    $stmt_u->execute([$admin_username, $admin_user_id]);
                    $username_taken = ($stmt_u->fetchColumn() > 0);
                } else {
                    $stmt_u = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt_u->execute([$admin_username]);
                    $username_taken = ($stmt_u->fetchColumn() > 0);
                }
                
                if ($username_taken) {
                    $error = "Administrator username already exists. Please choose a unique one.";
                } else {
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("
                        UPDATE schools 
                        SET name_en = ?, name_np = ?, emis_code = ?, address = ?, phone = ?, email = ?, academic_year_current = ?, principal_name = ?, principal_mobile = ?, technical_person = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name_en, $name_np, $emis_code, $address, $phone, $email, $academic_year_current, $principal_name, $principal_mobile, $technical_person, $school_id]);
                    
                    if ($admin_user_id) {
                        if (!empty($admin_password)) {
                            $pass_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET username = ?, password = ?, full_name = ?, email = ?, phone = ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$admin_username, $pass_hash, $admin_full_name, $email, $phone, $admin_user_id]);
                        } else {
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET username = ?, full_name = ?, email = ?, phone = ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$admin_username, $admin_full_name, $email, $phone, $admin_user_id]);
                        }
                    } else {
                        // Create one since it was missing
                        $pass_hash = password_hash(!empty($admin_password) ? $admin_password : 'admin123', PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            INSERT INTO users (username, password, role, full_name, email, phone, school_id, status) 
                            VALUES (?, ?, 'school_admin', ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$admin_username, $pass_hash, $admin_full_name, $email, $phone, $school_id]);
                    }
                    
                    $db->commit();
                    Auth::logActivity(Auth::getUserId(), 'Edit School Details & Admin', 'schools', $school_id);
                    $success = "School details and administrator account updated successfully.";
                }
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch all schools with their main administrator
$schools = $db->query("
    SELECT s.*, u.username as admin_username, u.full_name as admin_full_name 
    FROM schools s
    LEFT JOIN users u ON u.school_id = s.id AND u.role = 'school_admin'
    GROUP BY s.id
    ORDER BY s.id DESC
")->fetchAll();

$pageTitle = 'School Management';
$activePage = 'super_admin_schools';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-university text-primary"></i> School Management</h2>
    <div>
        <a href="<?= APP_URL ?>/modules/super_admin/index.php" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Control Center
        </a>
        <button class="btn btn-primary" onclick="showAddModal()">
            <i class="fas fa-plus"></i> Register New School
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Registered Schools List (<?= count($schools) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>School Name (English & Nepali)</th>
                        <th>EMIS Code</th>
                        <th>Contact info</th>
                        <th>Address</th>
                        <th>Current Year</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($schools) > 0): ?>
                        <?php foreach ($schools as $school): ?>
                        <tr>
                            <td><code>#<?= $school['id'] ?></code></td>
                            <td>
                                <strong><?= sanitize($school['name_en']) ?></strong><br>
                                <span class="text-muted small"><?= sanitize($school['name_np'] ?: '-') ?></span>
                            </td>
                            <td><code><?= sanitize($school['emis_code'] ?: '-') ?></code></td>
                            <td>
                                <i class="fas fa-phone fa-xs text-muted me-1"></i> <?= sanitize($school['phone'] ?: '-') ?><br>
                                <i class="fas fa-envelope fa-xs text-muted me-1"></i> <?= sanitize($school['email'] ?: '-') ?>
                            </td>
                            <td><?= sanitize($school['address'] ?: '-') ?></td>
                            <td><span class="badge bg-info"><?= sanitize($school['academic_year_current'] ?: '2081/2025') ?></span></td>
                            <td class="text-end">
                                <button onclick="editSchool(<?= htmlspecialchars(json_encode($school)) ?>)" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No schools registered in the database.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit School Modal -->
<div class="modal fade" id="schoolModal" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form id="schoolForm" method="POST" action="" class="needs-validation" novalidate>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="school_id" id="modalSchoolId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Register New School</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    <div class="row g-3">
                        <!-- School Profile Details -->
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-university"></i> School Profile Details</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Name (English) *</label>
                            <input type="text" name="name_en" id="modalNameEn" class="form-control" required placeholder="e.g. Kathmandu Public School">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Name (Nepali) *</label>
                            <input type="text" name="name_np" id="modalNameNp" class="form-control" required placeholder="e.g. काठमाडौं पब्लिक स्कुल">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">EMIS Code *</label>
                            <input type="text" name="emis_code" id="modalEmisCode" class="form-control" required placeholder="e.g. 23812739">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Academic Year *</label>
                            <input type="text" name="academic_year_current" id="modalAcademicYear" class="form-control" value="2081/2025" placeholder="e.g. 2081/2025" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">School Address *</label>
                            <textarea name="address" id="modalAddress" class="form-control" rows="2" placeholder="e.g. Putalisadak, Kathmandu" required></textarea>
                        </div>

                        <!-- Contact & Leadership -->
                        <div class="col-12 mt-4">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-id-card"></i> Contact & Leadership</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Phone Number *</label>
                            <input type="text" name="phone" id="modalPhone" class="form-control" placeholder="e.g. 01-445566" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Email Address *</label>
                            <input type="email" name="email" id="modalEmail" class="form-control" placeholder="e.g. info@school.edu.np" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Principal Name *</label>
                            <input type="text" name="principal_name" id="modalPrincipalName" class="form-control" placeholder="e.g. Dr. Ramesh Gupta" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Principal Mobile Number *</label>
                            <input type="text" name="principal_mobile" id="modalPrincipalMobile" class="form-control" placeholder="e.g. 9851000000" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technical Contact Person *</label>
                            <input type="text" name="technical_person" id="modalTechnicalPerson" class="form-control" placeholder="e.g. Sharad Acharya" required>
                        </div>

                        <!-- Administrator Credentials -->
                        <div class="col-12 mt-4">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-user-shield"></i> School Administrator Credentials</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administrator Full Name *</label>
                            <input type="text" name="admin_full_name" id="modalAdminFullName" class="form-control" placeholder="e.g. School Administrator" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administrator Username *</label>
                            <input type="text" name="admin_username" id="modalAdminUsername" class="form-control" placeholder="e.g. suryodaya_admin" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" id="adminPasswordLabel">Administrator Password *</label>
                            <input type="password" name="admin_password" id="modalAdminPassword" class="form-control" placeholder="Password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save School</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let schoolModalObj;

$(document).ready(function() {
    schoolModalObj = new bootstrap.Modal(document.getElementById('schoolModal'), {
        focus: false
    });
    
    $('#schoolForm').on('submit', function(e) {
        const form = this;
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }
        
        const action = document.getElementById('modalAction').value;
        if (action === 'edit') {
            e.preventDefault();
            e.stopPropagation();
            
            verifyPasswordAndProceed(function() {
                form.submit();
            }, 'Confirm School Edit', 'Please enter your administrator password to save the school details:');
        }
    });
});

function showAddModal() {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalSchoolId').value = '';
    document.getElementById('modalTitle').innerText = 'Register New School';
    document.getElementById('modalNameEn').value = '';
    document.getElementById('modalNameNp').value = '';
    document.getElementById('modalEmisCode').value = '';
    document.getElementById('modalAcademicYear').value = '2081/2025';
    document.getElementById('modalPhone').value = '';
    document.getElementById('modalEmail').value = '';
    document.getElementById('modalAddress').value = '';
    
    document.getElementById('modalPrincipalName').value = '';
    document.getElementById('modalPrincipalMobile').value = '';
    document.getElementById('modalTechnicalPerson').value = '';
    
    document.getElementById('modalAdminFullName').value = '';
    document.getElementById('modalAdminUsername').value = '';
    document.getElementById('modalAdminPassword').value = '';
    document.getElementById('modalAdminPassword').setAttribute('required', 'required');
    document.getElementById('adminPasswordLabel').innerText = 'Administrator Password *';
    schoolModalObj.show();
}

function editSchool(s) {
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalSchoolId').value = s.id;
    document.getElementById('modalTitle').innerText = 'Edit School Details';
    document.getElementById('modalNameEn').value = s.name_en;
    document.getElementById('modalNameNp').value = s.name_np || '';
    document.getElementById('modalEmisCode').value = s.emis_code || '';
    document.getElementById('modalAcademicYear').value = s.academic_year_current || '2081/2025';
    document.getElementById('modalPhone').value = s.phone || '';
    document.getElementById('modalEmail').value = s.email || '';
    document.getElementById('modalAddress').value = s.address || '';
    
    document.getElementById('modalPrincipalName').value = s.principal_name || '';
    document.getElementById('modalPrincipalMobile').value = s.principal_mobile || '';
    document.getElementById('modalTechnicalPerson').value = s.technical_person || '';
    
    document.getElementById('modalAdminFullName').value = s.admin_full_name || '';
    document.getElementById('modalAdminUsername').value = s.admin_username || '';
    document.getElementById('modalAdminPassword').value = '';
    document.getElementById('modalAdminPassword').removeAttribute('required');
    document.getElementById('adminPasswordLabel').innerText = 'Administrator Password (leave empty to keep current)';
    schoolModalObj.show();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
