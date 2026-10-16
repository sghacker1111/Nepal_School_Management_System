<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';

Auth::requireRole('super_admin');

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

if ($school_id === 0) {
    unset($_SESSION['viewing_school_id']);
    unset($_SESSION['school_subscription_type']);
    schoolAppRedirect(schoolRouteUrl('modules/super_admin/index.php'));
} else {
    $check=getDB()->prepare('SELECT id FROM schools WHERE id=?');
    $check->execute([$school_id]);
    if(!$check->fetchColumn())denySchoolRequest('School not found.',404);
    $_SESSION['viewing_school_id'] = $school_id;
    unset($_SESSION['school_subscription_type']);
    schoolAppRedirect(schoolRouteUrl('index.php'));
}
exit;
