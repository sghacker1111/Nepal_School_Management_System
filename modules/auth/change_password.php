<?php
/**
 * Auth Module - Change Own Password
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireLogin();

$db = getDB();
$error = '';
$success = '';
$user_id = Auth::getUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old_pass = $_POST['old_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    
    if (empty($old_pass) || empty($new_pass) || empty($confirm_pass)) {
        $error = 'All password fields are required';
    } elseif (strlen($new_pass) < 8) {
        $error = 'Use at least 8 characters for the new password';
    } elseif ($new_pass !== $confirm_pass) {
        $error = 'New password and confirm password do not match';
    } else {
        // Fetch current user details
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($old_pass, $user['password'])) {
            try {
                $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user_id]);
                
                Auth::logActivity($user_id, 'Changed own account password', 'users', $user_id);
                $success = 'Password changed successfully!';
            } catch (PDOException $e) {
                $error = schoolPublicError($e);
            }
        } else {
            $error = 'Incorrect old password';
        }
    }
}

$pageTitle = 'Change Password';
$activePage = 'profile';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-key"></i> Change Password</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success mb-4"><i class="fas fa-check-circle"></i> <?= $success ?></div>
<?php endif; ?>

<div class="card max-width-600 mx-auto">
    <div class="card-header bg-primary text-white py-3">
        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Update Security Credentials</h5>
    </div>
    <div class="card-body p-4">
        <form method="POST" action="">
            <div class="mb-3">
                <label for="old_password" class="form-label">Current Password *</label>
                <input type="password" class="form-control" id="old_password" name="old_password" required>
            </div>
            
            <hr class="my-4">
            
            <div class="mb-3">
                <label for="new_password" class="form-label">New Password *</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required>
            </div>
            
            <div class="mb-4">
                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-save"></i> Update Password
            </button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
