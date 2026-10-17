<?php
/**
 * Logout Handler
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
Auth::requireLogin();
if($_SERVER['REQUEST_METHOD']==='POST')Auth::logout();
$token=htmlspecialchars(Auth::generateCSRFToken(),ENT_QUOTES,'UTF-8');
echo '<!doctype html><meta charset="utf-8"><title>Sign out</title><main><h1>Sign out?</h1><form method="post"><input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$token.'"><button>Sign out</button> <a href="'.htmlspecialchars(APP_URL,ENT_QUOTES,'UTF-8').'">Cancel</a></form></main>';
