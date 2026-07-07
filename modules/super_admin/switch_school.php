<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';

Auth::requireRole('super_admin');

$school_id = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;

if ($school_id === 0) {
    unset($_SESSION['viewing_school_id']);
    unset($_SESSION['school_subscription_type']);
    header("Location: " . APP_URL . "/modules/super_admin/index.php");
} else {
    $_SESSION['viewing_school_id'] = $school_id;
    unset($_SESSION['school_subscription_type']);
    header("Location: " . APP_URL . "/index.php");
}
exit;
