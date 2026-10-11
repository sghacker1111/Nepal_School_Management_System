<?php
/**
 * Database Configuration
 * Nepal Mathematics Learning & School Result Management System
 */

// Database credentials
define('DB_TYPE', getenv('ERP_DB_TYPE') ?: 'sqlite');
define('DB_HOST', getenv('ERP_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('ERP_DB_NAME') ?: 'nepal_school_erp');
define('DB_USER', getenv('ERP_DB_USER') ?: 'root');
define('DB_PASS', getenv('ERP_DB_PASS') ?: '');
define('DB_SQLITE_PATH', getenv('ERP_DB_PATH') ?: dirname(__DIR__) . '/database.sqlite');
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'Nepal School ERP');

// Dynamically detect APP_URL to support localhost, local php server (port 8000), and custom domains
require_once dirname(__DIR__).'/includes/http_security.php';
if (!defined('APP_URL')) {
    $protocol = "http://";
    if (schoolRequestIsHttps()) {
        $protocol = "https://";
    }
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost:8000');
    $allowedHosts=array_filter(array_map('trim',explode(',',getenv('ERP_ALLOWED_HOSTS')?:'')));
    if($allowedHosts&&!in_array(strtolower($host),array_map('strtolower',$allowedHosts),true)){http_response_code(400);exit('Invalid host.');}
    
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
ini_set('display_errors', getenv('ERP_DEBUG') === '1' ? '1' : '0');
ini_set('log_errors', '1');
