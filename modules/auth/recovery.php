<?php
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
Auth::startSession();header('Cache-Control: no-store, private');header('Pragma: no-cache');
$error='';$success=false;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if(!Auth::verifyCSRFToken($_POST[CSRF_TOKEN_NAME]??null))$error='The recovery form expired. Reload and try again.';
    elseif(!is_string($_POST['new_password']??null)||$_POST['new_password']!==($_POST['confirm_password']??null))$error='Enter matching new passwords of 8–72 bytes.';
    elseif(schoolRecoverAdministrator(getDB(),$_POST['username']??null,$_POST['backup_code']??null,$_POST['new_password'])){
        // Do not authenticate on recovery. Revoke every existing browser session.
        session_regenerate_id(true);$_SESSION=[];$success=true;
    }else $error='Recovery failed. Check the username, unused backup code and a new password of 8–72 bytes. After repeated failed recovery attempts, wait 30 minutes.';
}
require_once __DIR__.'/../../includes/frontend.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administrator recovery - <?= APP_NAME ?></title><link href="<?= schoolAsset('app.css') ?>" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:620px"><div class="card card-body"><h1 class="h3">Administrator account recovery</h1><p>Use one backup code previously generated in Account security. School users should contact their school administrator; school administrators should contact the main administrator.</p>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php if($success): ?><div class="alert alert-success">Password reset and account unlocked. This backup code has been used. Sign in with your new password.</div><?php else: ?>
<form method="post"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRFToken() ?>">
<label for="recoveryUsername" class="form-label">Administrator username</label><input id="recoveryUsername" class="form-control mb-3" name="username" autocomplete="username" maxlength="100" required>
<label for="backupCode" class="form-label">Unused backup code</label><input id="backupCode" class="form-control mb-3" name="backup_code" autocomplete="off" maxlength="100" spellcheck="false" required>
<label for="newPassword" class="form-label">New password</label><input id="newPassword" type="password" class="form-control mb-3" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" required>
<label for="confirmPassword" class="form-label">Confirm new password</label><input id="confirmPassword" type="password" class="form-control mb-3" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" required>
<button class="btn btn-primary" type="submit">Reset password and unlock account</button></form><?php endif; ?>
<a class="mt-3" href="<?= schoolRouteUrl('modules/auth/login.php') ?>">Return to sign in</a></div></main></body></html>
