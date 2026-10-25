<?php
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
Auth::requireRole(['super_admin','school_admin']);
header('Cache-Control: no-store, private');header('Pragma: no-cache');
$db=getDB();$error='';$codes=[];$id=(int)Auth::getUserId();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(Auth::isReadOnly())throw new InvalidArgumentException('Leave school preview before changing account security.');
        if(!Auth::verifyCSRFToken($_POST[CSRF_TOKEN_NAME]??null))throw new InvalidArgumentException('The security form expired. Reload and try again.');
        if(($_POST['action']??'')!=='generate_backup_codes')throw new InvalidArgumentException('Choose a valid security action.');
        $codes=schoolGenerateBackupCodes($db,$id,$_POST['current_password']??null);
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$remaining=Auth::getRole()==='super_admin'?count(schoolBackupCodes($db,$id)):0;
require_once __DIR__.'/../../includes/frontend.php';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account security - <?= APP_NAME ?></title><link href="<?= schoolAsset('app.css') ?>" rel="stylesheet"></head><body class="bg-light"><main class="container py-5" style="max-width:860px"><a href="<?= schoolRouteUrl('index.php') ?>">Return to dashboard</a><h1 class="h3 mt-3">Account security</h1>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<div class="card card-body mb-3"><h3 class="h5">Sign-in protection</h3><p>Five consecutive incorrect passwords suspend sign-in for 30 minutes. After the wait, two more incorrect passwords lock the account until it is recovered. A successful sign-in clears the failure count.</p><p class="mb-0">School administrators can unlock users in their own school. Only the main administrator can unlock school administrators. Main administrators recover their own account using a backup code and a new password.</p></div>
<?php if(Auth::getRole()==='super_admin'): ?>
<div class="card card-body"><h3 class="h5">Administrator backup codes</h3><p><?= $remaining ?> unused codes available. Each code can reset your password once, including when your account is locked. Store these codes securely offline.</p>
<?php if($codes): ?><div class="alert alert-warning"><strong>Save these codes now. They are shown only in this response.</strong> Generating another set replaces all previous codes.</div><div class="row" id="backupCodes"><?php foreach($codes as $code): ?><div class="col-md-6 mb-2"><code><?= sanitize($code) ?></code></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" data-erp-full-page="1"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRFToken() ?>"><input type="hidden" name="action" value="generate_backup_codes"><label for="backupPassword" class="form-label">Confirm your current password</label><input class="form-control mb-3" id="backupPassword" type="password" name="current_password" autocomplete="current-password" required maxlength="1024"><p>Generating a new set invalidates every unused old code. Save the new set before leaving this page.</p><button class="btn btn-primary" type="submit">Generate 10 new backup codes</button></form></div>
<?php endif; ?>
</main></body></html>
