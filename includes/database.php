<?php
/**
 * Database Connection Class
 * Uses PDO for secure database operations
 */

class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        // Option to force SQLite via config, or auto-fallback if MySQL fails
        $use_sqlite = defined('DB_TYPE') && DB_TYPE === 'sqlite';
        $mysql_err = '';
        
        if (!$use_sqlite) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                $mysql_err = $e->getMessage();
                // If MySQL fails, fallback to SQLite for local development
                $use_sqlite = true;
            }
        }
        
        if ($use_sqlite) {
            try {
                $db_file = dirname(__DIR__) . '/database.sqlite';
                $this->conn = new PDO("sqlite:" . $db_file);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
                // Register custom SUBSTRING_INDEX function for compatibility with MySQL
                @$this->conn->sqliteCreateFunction('SUBSTRING_INDEX', function($string, $delim, $count) {
                    if ($string === null) return null;
                    $parts = explode($delim, $string);
                    if ($count > 0) {
                        return implode($delim, array_slice($parts, 0, $count));
                    } else {
                        return implode($delim, array_slice($parts, $count));
                    }
                }, 3);
            } catch (PDOException $sqlite_err) {
                die("Database connection failed: (MySQL) " . (!empty($mysql_err) ? $mysql_err : 'forced') . " | (SQLite fallback) " . $sqlite_err->getMessage());
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database connection
 */
function getDB() {
    return Database::getInstance()->getConnection();
}
