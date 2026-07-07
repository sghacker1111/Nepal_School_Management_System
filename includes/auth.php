<?php
/**
 * Authentication & Session Management
 */

class Auth {
    
    /**
     * Start session if not already started
     */
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    /**
     * Login user
     */
    public static function login($username, $password) {
        self::startSession();
        
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['school_id'] = $user['school_id'];
            $_SESSION['login_time'] = time();
            
            // Log activity
            self::logActivity($user['id'], 'Login', 'users', $user['id']);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Logout user
     */
    public static function logout() {
        self::startSession();
        session_destroy();
        header("Location: " . APP_URL . "/index.php");
        exit;
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        self::startSession();
        return isset($_SESSION['user_id']);
    }
    
    /**
     * Check session timeout
     */
    public static function checkTimeout() {
        self::startSession();
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_TIMEOUT)) {
            self::logout();
        }
        // Update login time
        $_SESSION['login_time'] = time();
    }
    
    /**
     * Get current user ID
     */
    public static function getUserId() {
        self::startSession();
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user's school ID
     */
    public static function getSchoolId() {
        self::startSession();
        if (self::getRole() === 'super_admin' && isset($_SESSION['viewing_school_id'])) {
            return $_SESSION['viewing_school_id'];
        }
        return $_SESSION['school_id'] ?? null;
    }

    /**
     * Check if the current session is in read-only mode for the Super Admin
     */
    public static function isReadOnly() {
        self::startSession();
        if (self::getRole() === 'super_admin' && !empty($_SESSION['viewing_school_id'])) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (strpos($script, '/modules/super_admin/') !== false) {
                return false;
            }
            return true;
        }
        return false;
    }
    
    /**
     * Get current user role
     */
    public static function getRole() {
        self::startSession();
        return $_SESSION['role'] ?? null;
    }
    
    /**
     * Check if user has specific role
     */
    public static function hasRole($roles) {
        self::startSession();
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        return in_array($_SESSION['role'] ?? '', $roles);
    }
    
    /**
     * Require login - redirect if not logged in
     */
    public static function requireLogin() {
        if (!self::isLoggedIn()) {
            header("Location: " . APP_URL . "/modules/auth/login.php");
            exit;
        }
        self::checkTimeout();
        
        // Write blocker for read-only sessions
        if (self::isReadOnly()) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete']) || isset($_GET['action'])) {
                $script = $_SERVER['SCRIPT_NAME'];
                if (strpos($script, 'switch_school.php') === false && strpos($script, 'logout.php') === false) {
                    die("<div style='font-family:sans-serif;padding:30px;text-align:center;'><h2 style='color:#e74c3c;'>Access Denied</h2><p>You are viewing this school's ERP in read-only mode as the System Administrator.</p><p><a href='javascript:history.back()'>Go Back</a></p></div>");
                }
            }
        }
    }
    
    /**
     * Require specific role
     */
    public static function requireRole($roles) {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            die("Access denied. You don't have permission to access this page.");
        }
    }
    
    /**
     * Log user activity
     */
    public static function logActivity($userId, $action, $tableName = null, $recordId = null) {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, table_name, record_id, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $tableName, $recordId, $ip]);
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        self::startSession();
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }
    
    /**
     * Verify CSRF token
     */
    public static function verifyCSRFToken($token) {
        self::startSession();
        return hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
    }

    /**
     * Get current school's subscription/access package
     * Returns: 'full', 'exam_generator', or 'result_system'
     */
    public static function getSubscriptionType() {
        self::startSession();
        if (self::getRole() === 'super_admin' && empty($_SESSION['viewing_school_id'])) {
            return 'full';
        }
        
        $school_id = self::getSchoolId();
        if (!$school_id) {
            return 'full';
        }
        
        if (isset($_SESSION['school_subscription_type'])) {
            return $_SESSION['school_subscription_type'];
        }
        
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT subscription_type FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $sub = $stmt->fetchColumn();
            $_SESSION['school_subscription_type'] = $sub ? $sub : 'full';
        } catch (Exception $e) {
            $_SESSION['school_subscription_type'] = 'full';
        }
        
        return $_SESSION['school_subscription_type'];
    }

    /**
     * Check if current subscription package allows access to a module
     */
    public static function hasAccess($module) {
        $sub = self::getSubscriptionType();
        if ($sub === 'full') {
            return true;
        }
        
        // Modules mapped to Result System
        $result_modules = ['students', 'teachers', 'results', 'attendance', 'reports', 'settings'];
        // Modules mapped to Exam Generator
        $exam_modules = ['paper_generator', 'questions', 'learning'];
        
        if (in_array($module, $result_modules)) {
            return ($sub === 'result_system');
        }
        if (in_array($module, $exam_modules)) {
            return ($sub === 'exam_generator');
        }
        
        return true; // Default to true for common modules (classes, subjects, exams)
    }

    /**
     * Block execution if user lacks access to module
     */
    public static function requireAccess($module) {
        self::requireLogin();
        if (!self::hasAccess($module)) {
            die("<div style='font-family:sans-serif;padding:30px;text-align:center;'><h2 style='color:#e74c3c;'>Access Denied</h2><p>Your school's active subscription package does not allow access to the <strong>" . htmlspecialchars($module) . "</strong> module.</p><p><a href='" . APP_URL . "/index.php'>Go to Dashboard</a></p></div>");
        }
    }
}

