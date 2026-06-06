<?php
/**
 * Super Admin School Administrators Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole('super_admin');

$db = getDB();
$error = '';
$success = '';

// Handle Add/Edit User Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $full_name = sanitize($_POST['full_name'] ?? '');
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $school_id = (int)($_POST['school_id'] ?? 0);
    $status = isset($_POST['status']) ? 1 : 0;
    
    if (empty($full_name) || empty($username) || empty($school_id)) {
        $error = "Full Name, Username, and School assignment are required.";
    } else {
        try {
            if ($action === 'add') {
                if (empty($password)) {
                    $error = "Password is required for new users.";
                } else {
                    // Check if username exists
                    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetchColumn() > 0) {
                        $error = "Username already exists.";
                    } else {
                        $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            INSERT INTO users (username, password, role, full_name, email, phone, school_id, status) 
                            VALUES (?, ?, 'school_admin', ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$username, $pass_hash, $full_name, $email, $phone, $school_id, $status]);
                        Auth::logActivity(Auth::getUserId(), 'Create School Admin', 'users', $db->lastInsertId());
                        $success = "School administrator created successfully.";
                    }
                }
            } elseif ($action === 'edit') {
                $user_id = (int)$_POST['user_id'];
                
                // Check username uniqueness if changed
                $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $user_id]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "Username already exists.";
                } else {
                    if (!empty($password)) {
                        $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET username = ?, password = ?, full_name = ?, email = ?, phone = ?, school_id = ?, status = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $pass_hash, $full_name, $email, $phone, $school_id, $status, $user_id]);
                    } else {
                        $stmt = $db->prepare("
                            UPDATE users 
                            SET username = ?, full_name = ?, email = ?, phone = ?, school_id = ?, status = ? 
                            WHERE id = ?
                        ");
                        $stmt->execute([$username, $full_name, $email, $phone, $school_id, $status, $user_id]);
                    }
                    Auth::logActivity(Auth::getUserId(), 'Edit School Admin Details', 'users', $user_id);
                    $success = "School administrator updated successfully.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch all school administrators
$admins = $db->query("
    SELECT u.*, s.name_en as school_name 
    FROM users u 
    LEFT JOIN schools s ON u.school_id = s.id 
    WHERE u.role = 'school_admin' 
    ORDER BY u.id DESC
")->fetchAll();

// Fetch all schools for select dropdown
$schools = $db->query("SELECT id, name_en FROM schools ORDER BY name_en")->fetchAll();

$pageTitle = 'School Administrators';
$activePage = 'super_admin_users';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-users-cog text-success"></i> School Administrators</h2>
    <div>
        <a href="<?= APP_URL ?>/modules/super_admin/index.php" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Control Center
        </a>
        <button class="btn btn-success" onclick="showAddModal()">
            <i class="fas fa-plus"></i> Add New School Admin
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
        <h5 class="mb-0 text-success">School Administrators List (<?= count($admins) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Assigned School</th>
                        <th>Email & Phone</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($admins) > 0): ?>
                        <?php foreach ($admins as $admin): ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($admin['full_name']) ?></strong>
                            </td>
                            <td><code><?= sanitize($admin['username']) ?></code></td>
                            <td>
                                <span class="badge bg-primary"><i class="fas fa-school"></i> <?= sanitize($admin['school_name'] ?: 'Unknown School') ?></span>
                            </td>
                            <td>
                                <small><?= sanitize($admin['email'] ?: '-') ?></small><br>
                                <small><?= sanitize($admin['phone'] ?: '-') ?></small>
                            </td>
                            <td>
                                <?php if ($admin['status'] == 1): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button onclick="editAdmin(<?= htmlspecialchars(json_encode($admin)) ?>)" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No school administrators found in database.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Admin Modal -->
<div class="modal fade" id="adminModal" aria-hidden="true">
    <div class="modal-dialog">
        <form id="adminForm" method="POST" action="" class="needs-validation" novalidate>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New School Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="full_name" id="modalFullName" class="form-control" required placeholder="e.g. Ram Bahadur">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Assigned School *</label>
                            <select name="school_id" id="modalSchoolId" class="form-select" required>
                                <option value="">Choose School</option>
                                <?php foreach ($schools as $school): ?>
                                <option value="<?= $school['id'] ?>"><?= sanitize($school['name_en']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" id="modalUsername" class="form-control" required placeholder="e.g. ramschool">
                        </div>
                        <div class="col-12">
                            <label class="form-label" id="passwordLabel">Password *</label>
                            <input type="password" name="password" id="modalPassword" class="form-control" placeholder="Enter password (leave blank to keep current when editing)">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" id="modalEmail" class="form-control" placeholder="e.g. ram@school.com">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" id="modalPhone" class="form-control" placeholder="e.g. 9841000000">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="status" id="modalStatus" value="1" checked>
                                <label class="form-check-label" for="modalStatus">Status Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Admin</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let adminModalObj;

$(document).ready(function() {
    adminModalObj = new bootstrap.Modal(document.getElementById('adminModal'), {
        focus: false
    });
    
    $('#adminForm').on('submit', function(e) {
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
            }, 'Confirm Admin Edit', 'Please enter your administrator password to save the school administrator details:');
        }
    });
});

function showAddModal() {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalUserId').value = '';
    document.getElementById('modalTitle').innerText = 'Add New School Admin';
    document.getElementById('modalFullName').value = '';
    document.getElementById('modalSchoolId').value = '';
    document.getElementById('modalUsername').value = '';
    document.getElementById('modalPassword').value = '';
    document.getElementById('modalPassword').setAttribute('required', 'required');
    document.getElementById('passwordLabel').innerText = 'Password *';
    document.getElementById('modalEmail').value = '';
    document.getElementById('modalPhone').value = '';
    document.getElementById('modalStatus').checked = true;
    adminModalObj.show();
}

function editAdmin(a) {
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalUserId').value = a.id;
    document.getElementById('modalTitle').innerText = 'Edit School Admin';
    document.getElementById('modalFullName').value = a.full_name;
    document.getElementById('modalSchoolId').value = a.school_id || '';
    document.getElementById('modalUsername').value = a.username;
    document.getElementById('modalPassword').value = '';
    document.getElementById('modalPassword').removeAttribute('required');
    document.getElementById('passwordLabel').innerText = 'Password (leave empty to keep current)';
    document.getElementById('modalEmail').value = a.email || '';
    document.getElementById('modalPhone').value = a.phone || '';
    document.getElementById('modalStatus').checked = (a.status == 1);
    adminModalObj.show();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
