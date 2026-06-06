<?php
/**
 * Database Configuration
 * Nepal Mathematics Learning & School Result Management System
 */

// Database credentials
define('DB_TYPE', 'sqlite'); // Options: 'mysql' or 'sqlite'. Currently set to 'sqlite' so it works locally without a MySQL server!
define('DB_HOST', 'localhost');
define('DB_NAME', 'nepal_school_erp');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'Nepal School ERP');

// Dynamically detect APP_URL to support localhost, local php server (port 8000), and custom domains
if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    
    // Detect subdirectory path (e.g. for XAMPP /nepal_school_erp/)
    $doc_root = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $project_root = str_replace('\\', '/', realpath(dirname(__DIR__)));
    
    $base_path = '';
    if (!empty($doc_root) && strpos($project_root, $doc_root) === 0) {
        $base_path = substr($project_root, strlen($doc_root));
    }
    $base_path = '/' . trim($base_path, '/');
    if ($base_path === '/') {
        $base_path = '';
    }
    
    define('APP_URL', $protocol . $host . $base_path);
}

define('TIMEZONE', 'Asia/Kathmandu');

// File upload settings - points to root uploads directory
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

// Session settings
define('SESSION_TIMEOUT', 3600); // 1 hour

// Security
define('CSRF_TOKEN_NAME', 'csrf_token');

// Date formats
define('DATE_FORMAT_AD', 'Y-m-d');
define('DATE_FORMAT_BS', 'Y-m-d'); // Simplified, use library for actual BS conversion

// Set timezone
date_default_timezone_set(TIMEZONE);

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
