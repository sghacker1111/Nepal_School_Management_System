<?php
/**
 * Auth Module - View / Edit Own Profile
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireLogin();

$db = getDB();
$error = '';
$success = '';
$user_id = Auth::getUserId();

// Fetch current user details
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    redirect(schoolRouteUrl('modules/auth/logout.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    
    if (empty($full_name)) {
        $error = 'Full name is required';
    } else {
        try {
            $stmt = $db->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $phone, $user_id]);
            
            // Update session data
            $_SESSION['full_name'] = $full_name;
            
            Auth::logActivity($user_id, 'Updated own profile details', 'users', $user_id);
            $success = 'Profile details updated successfully!';
            
            // Refresh local details
            $user['full_name'] = $full_name;
            $user['email'] = $email;
            $user['phone'] = $phone;
        } catch (PDOException $e) {
            $error = schoolPublicError($e);
        }
    }
}

$pageTitle = 'My Profile';
$activePage = 'profile';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-user-circle"></i> My Account Profile</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success mb-4"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>

<div class="card max-width-600 mx-auto">
    <div class="card-header bg-primary text-white py-3">
        <h5 class="mb-0"><i class="fas fa-id-card"></i> Profile Details</h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" class="form-control bg-light text-muted font-monospace" value="<?= sanitize($user['username']) ?>" disabled>
                <small class="text-muted">Username cannot be changed.</small>
            </div>
            
            <div class="mb-3">
                <label class="form-label">User Role</label>
                <span class="badge bg-info text-capitalize d-block text-start py-2 px-3 fs-6"><?= str_replace('_', ' ', $user['role']) ?></span>
            </div>
            
            <div class="mb-3">
                <label for="full_name" class="form-label">Full Name *</label>
                <input type="text" class="form-control" id="full_name" name="full_name" value="<?= sanitize($user['full_name']) ?>" required>
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= sanitize($user['email'] ?? '') ?>">
            </div>
            
            <div class="mb-4">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?= sanitize($user['phone'] ?? '') ?>">
            </div>
            
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-save"></i> Save Profile Details
            </button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
