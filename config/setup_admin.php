<?php
/** Fresh installations only. Does not reset an existing enabled administrator. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$password=getenv('ERP_ADMIN_PASSWORD')?:'';
if(strlen($password)<12) { fwrite(STDERR,"Set ERP_ADMIN_PASSWORD to a private password of at least 12 characters, then run this command again.\n");exit(1); }
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/database.php';
$db=getDB();
$q=$db->query("SELECT id,status,password FROM users WHERE username='admin' AND role='super_admin'");$admin=$q->fetch();
if(!$admin || $admin['status'] || $admin['password']!=='SETUP_REQUIRED') { fwrite(STDERR,"An administrator is already configured. Use authenticated account management; no password was changed.\n");exit(1); }
$db->prepare("UPDATE users SET password=?,status=1,school_id=NULL WHERE id=? AND status=0 AND password='SETUP_REQUIRED'")->execute([password_hash($password,PASSWORD_DEFAULT),$admin['id']]);
echo "Administrator initialized. Sign in as admin and remove ERP_ADMIN_PASSWORD from your environment.\n";
