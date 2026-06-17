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
                
                // Auto-initialize SQLite database if users table is missing
                $table_exists = $this->conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();
                if (!$table_exists) {
                    $this->initializeSqliteDatabase();
                }
            } catch (PDOException $sqlite_err) {
                die("Database connection failed: (MySQL) " . (!empty($mysql_err) ? $mysql_err : 'forced') . " | (SQLite fallback) " . $sqlite_err->getMessage());
            }
        }
    }
    
    /**
     * Auto-initialize the SQLite database using database_sqlite.sql and apply all migrations
     */
    private function initializeSqliteDatabase() {
        // 1. Run schema from database_sqlite.sql
        $sql_file = dirname(__DIR__) . '/database_sqlite.sql';
        if (file_exists($sql_file)) {
            $sql = file_get_contents($sql_file);
            $this->conn->exec($sql);
        } else {
            throw new Exception("SQLite schema file not found at: " . $sql_file);
        }

        // 2. Run migrations silently
        
        // A. migrate.php logic
        // 1. Insert Class 11 and Class 12 if they don't exist
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM classes WHERE name = ?");
        
        $stmt->execute(['Class 11']);
        if ($stmt->fetchColumn() == 0) {
            $this->conn->prepare("INSERT INTO classes (name, name_np, level, grading_type) VALUES ('Class 11', 'कक्षा ११', 'Secondary', 'gpa')")->execute();
        }
        
        $stmt->execute(['Class 12']);
        if ($stmt->fetchColumn() == 0) {
            $this->conn->prepare("INSERT INTO classes (name, name_np, level, grading_type) VALUES ('Class 12', 'कक्षा १२', 'Secondary', 'gpa')")->execute();
        }

        // 2. Insert Academic Year 2083 if it doesn't exist
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM academic_years WHERE year_bs = ?");
        $stmt->execute(['2083']);
        if ($stmt->fetchColumn() == 0) {
            $this->conn->prepare("INSERT INTO academic_years (year_bs, year_ad, is_active) VALUES ('2083', '2026', 0)")->execute();
        }

        // 3. Create class_subjects table
        $sql = "CREATE TABLE IF NOT EXISTS class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            credit_hours INTEGER DEFAULT 4,
            teacher_id INTEGER DEFAULT NULL,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
        )";
        $this->conn->exec($sql);

        // 4. Create teacher_details table
        $sql = "CREATE TABLE IF NOT EXISTS teacher_details (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            join_date DATE DEFAULT NULL,
            perm_issue_date DATE DEFAULT NULL,
            temp_issue_date DATE DEFAULT NULL,
            cit_no VARCHAR(50) DEFAULT NULL,
            family_details TEXT DEFAULT NULL,
            dob DATE DEFAULT NULL,
            bank_name VARCHAR(100) DEFAULT NULL,
            bank_account VARCHAR(50) DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $this->conn->exec($sql);

        // 5. Create questions table
        $sql = "CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            chapter VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL, -- MCQ, Short, Long
            question_text TEXT NOT NULL,
            options TEXT DEFAULT NULL, -- JSON array
            correct_option VARCHAR(5) DEFAULT NULL,
            marks INTEGER DEFAULT 1,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        )";
        $this->conn->exec($sql);

        // 6. Alter marks table - Add internal_breakdown if not exists
        try {
            $this->conn->exec("ALTER TABLE marks ADD COLUMN internal_breakdown TEXT DEFAULT NULL");
        } catch (PDOException $e) {
            // Suppress error if column already exists
        }

        // 7. Alter exams table - Add result_system if not exists
        try {
            $this->conn->exec("ALTER TABLE exams ADD COLUMN result_system VARCHAR(50) DEFAULT 'gpa'");
        } catch (PDOException $e) {
            // Suppress error if column already exists
        }

        // B. migrate_multi_tenant.php logic
        // 1. Ensure 'schools' table exists and contains the default school
        $stmt = $this->conn->query("SELECT COUNT(*) FROM schools WHERE id = 1");
        if ($stmt->fetchColumn() == 0) {
            $this->conn->exec("INSERT INTO schools (id, name_en, name_np, emis_code) VALUES (1, 'Model Secondary School', 'नमूना माध्यमिक विद्यालय', '12345678')");
        }

        // 2. Add 'school_id' column to various tables
        $tables = [
            'users',
            'classes',
            'sections',
            'subjects',
            'students',
            'exams',
            'academic_years',
            'questions',
            'class_subjects'
        ];

        foreach ($tables as $table) {
            // Check if school_id already exists in table
            $has_column = false;
            $cols = $this->conn->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if ($col['name'] === 'school_id') {
                    $has_column = true;
                    break;
                }
            }

            if (!$has_column) {
                $this->conn->exec("ALTER TABLE `$table` ADD COLUMN school_id INTEGER DEFAULT 1");
            }
        }

        // 3. Make sure all existing data is updated to school_id = 1
        foreach ($tables as $table) {
            if ($table !== 'users') {
                $this->conn->exec("UPDATE `$table` SET school_id = 1 WHERE school_id IS NULL OR school_id = 0");
            }
        }

        // 4. Update the global super_admin user (username: admin)
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            $this->conn->exec("UPDATE users SET school_id = NULL, role = 'super_admin' WHERE username = 'admin'");
        } else {
            // Insert admin user if not exists
            $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare("INSERT INTO users (username, password, role, full_name, school_id) VALUES ('admin', ?, 'super_admin', 'System Administrator', NULL)");
            $stmt->execute([$admin_pass]);
        }

        // 5. Create default school_admin for School 1 (username: schooladmin, password: admin123)
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM users WHERE username = 'schooladmin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $school_admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $this->conn->prepare("INSERT INTO users (username, password, role, full_name, school_id) VALUES ('schooladmin', ?, 'school_admin', 'Model School Administrator', 1)");
            $stmt->execute([$school_admin_pass]);
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
