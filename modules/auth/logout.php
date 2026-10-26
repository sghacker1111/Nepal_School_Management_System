<?php
/**
 * Logout Handler
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
if($_SERVER['REQUEST_METHOD']==='POST')Auth::logout();
require_once __DIR__ . '/../../includes/frontend.php';
header('Cache-Control: no-store, private');
$token=htmlspecialchars(Auth::generateCSRFToken(),ENT_QUOTES,'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign out - <?= htmlspecialchars(APP_NAME,ENT_QUOTES,'UTF-8') ?></title>
<link rel="stylesheet" href="<?= schoolAsset('app.css') ?>">
<style>
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%)}
.signout-card{width:100%;max-width:400px;background:#fff;border-radius:15px;box-shadow:0 10px 40px rgba(0,0,0,.2);overflow:hidden;text-align:center}
.signout-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:30px}.signout-header h1{font-size:1.5rem;margin:0 0 10px}.signout-body{padding:32px}.signout-actions{display:grid;gap:12px}.btn-signout{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border:0;color:#fff;padding:12px}.btn-signout:hover{opacity:.9}
</style>
</head>
<body>
<main class="signout-card">
<header class="signout-header"><h1>Sign out?</h1><p class="mb-0"><?= htmlspecialchars(APP_NAME,ENT_QUOTES,'UTF-8') ?></p></header>
<div class="signout-body"><p>You can sign in again whenever you need to continue.</p>
<form method="post" class="signout-actions">
<input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $token ?>">
<button type="submit" class="btn btn-signout">Sign out</button>
<a class="btn btn-outline-secondary" href="<?= htmlspecialchars(schoolRouteUrl('index.php'),ENT_QUOTES,'UTF-8') ?>">Cancel</a>
</form></div>
</main>
</body></html>
