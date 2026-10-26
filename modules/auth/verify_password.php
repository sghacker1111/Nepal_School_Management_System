<?php
/**
 * Password Verification API Endpoint
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');
Auth::requireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Use POST to verify your password.']);exit;
}

if (!Auth::isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get JSON or POST body input
$input = json_decode(file_get_contents('php://input', false, null, 0, 4096), true);
$password = $input['password'] ?? $_POST['password'] ?? '';

if (!is_string($password) || $password === '' || strlen($password)>1024) {
    echo json_encode(['success' => false, 'message' => 'Password is required']);
    exit;
}

try {
    $db = getDB();
    $userId = Auth::getUserId();
    
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if ($user && schoolVerifySensitivePassword($db, (int)$userId, $password)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect password']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => schoolPublicError($e)]);
}
