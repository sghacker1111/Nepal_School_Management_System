<?php
/**
 * Authentication & Session Management
 */
require_once __DIR__.'/http_security.php';
require_once __DIR__.'/routes.php';

class Auth {
    
    /**
     * Start session if not already started
     */
    public static function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies','1');
            session_set_cookie_params(['httponly' => true, 'secure' => schoolRequestIsHttps(), 'samesite' => 'Lax', 'path' => '/']);
            session_start();
        }
    }
    
    /**
     * Login user
     */
    public static function login($username, $password) {
        self::startSession();
        if(!is_string($username)||!is_string($password)||strlen($username)>100||strlen($password)>1024)return false;
        
        $db = getDB();
        $identity = hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $accountIdentity=hash('sha256','account|'.strtolower(trim($username)));
        $networkIdentity=hash('sha256','network|'.($_SERVER['REMOTE_ADDR']??'local'));
        $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 900]);
        $attempts = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identity_key=?');
        $attempts->execute([$identity]);
        if ((int)$attempts->fetchColumn() >= 10) return false;
        foreach([$accountIdentity=>25,$networkIdentity=>100] as $key=>$limit){$attempts->execute([$key]);if((int)$attempts->fetchColumn()>=$limit)return false;}
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND status = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION = [];
            $db->prepare('DELETE FROM login_attempts WHERE identity_key=?')->execute([$identity]);
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
        
        $failure=$db->prepare('INSERT INTO login_attempts (identity_key, attempted_at) VALUES (?, ?)');
        foreach([$identity,$accountIdentity,$networkIdentity] as $key)$failure->execute([$key,time()]);
        return false;
    }
    
    /**
     * Logout user
     */
    public static function logout() {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', ['expires'=>time()-42000, 'path'=>$cookie['path'], 'secure'=>$cookie['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
        }
        session_destroy();
        schoolAppUnauthenticated();
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
        return self::getRole() === 'super_admin' ? 0 : ($_SESSION['school_id'] ?? null);
    }

    /**
     * Check if the current session is in read-only mode for the Super Admin
     */
    public static function isReadOnly() {
        self::startSession();
        if (self::getRole() === 'super_admin' && !empty($_SESSION['viewing_school_id'])) {
            $script = schoolControllerPath();
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
        static $checked = false;
        if ($checked) return;
        if (!self::isLoggedIn()) {
            schoolAppUnauthenticated();
        }
        self::checkTimeout();
        $stmt = getDB()->prepare('SELECT id, role, school_id, full_name, status FROM users WHERE id=?');
        $stmt->execute([self::getUserId()]);
        $current = $stmt->fetch();
        if (!$current || !(int)$current['status']) self::logout();
        foreach (['role','school_id','full_name'] as $key) $_SESSION[$key] = $current[$key];
        unset($_SESSION['school_subscription_type']);
        require_once __DIR__ . '/request_guard.php';
        guardSchoolRequest();
        schoolRedirectLegacyDocument();
        $checked = true;
    }
    
    /**
     * Require specific role
     */
    public static function requireRole($roles) {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            denySchoolRequest("Access denied. You don't have permission to access this page.");
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
        if(($_SERVER['REQUEST_METHOD']??'')==='POST' && $action!=='Login')$_SESSION['action_notice']=$action.' completed successfully.';
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
        return is_string($token) && !empty($_SESSION[CSRF_TOKEN_NAME]) && $token !== '' && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }

    /**
     * Get current school's subscription/access package
     * Resolve the current database value, never a stale session entitlement.
     */
    public static function getSubscriptionType() {
        self::startSession();
        if (self::getRole() === 'super_admin' && empty($_SESSION['viewing_school_id'])) {
            return 'full';
        }
        
        $school_id = self::getSchoolId();
        if (!$school_id) {
            return 'unavailable';
        }
        
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $school = $stmt->fetch();
            require_once __DIR__.'/packages.php';
            $packages=$school?schoolSelectedPackages($school):[];
            return in_array('full',$packages,true)?'full':(count($packages)===1?$packages[0]:'unavailable');
        } catch (Throwable $e) {
            error_log('School package lookup failed; access denied.');
            return 'unavailable';
        }
    }

    /**
     * Check if current subscription package allows access to a module
     */
    public static function hasAccess($module) {
        require_once __DIR__.'/packages.php';
        if(self::getRole()==='student'&&!in_array($module,['learning','exam_questions','auth'],true))return false;
        if(self::getRole()==='super_admin'&&!self::getSchoolId())return true;
        $school=self::getSubscription();
        return $school&&schoolSubscriptionAllows($school,(string)$module);
    }

    public static function getSubscription():array {
        try{$q=getDB()->prepare('SELECT * FROM schools WHERE id=?');$q->execute([self::getSchoolId()]);return $q->fetch()?:[];}
        catch(Throwable $e){error_log('School subscription lookup failed; access denied.');return [];}
    }
    public static function canAddQuestions():bool {
        require_once __DIR__.'/packages.php';
        if(self::getRole()==='student')return false;
        return self::getRole()==='super_admin'||schoolSubscriptionCanAddQuestions(self::getSubscription());
    }

    /**
     * Block execution if user lacks access to module
     */
    public static function requireAccess($module) {
        self::requireLogin();
        if (!self::hasAccess($module)) {
            require_once __DIR__.'/request_guard.php';
            denySchoolRequest('This module is not included in your school package.');
        }
    }
}
