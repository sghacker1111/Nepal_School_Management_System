<?php
/**
 * Database Connection Class
 * Uses PDO for secure database operations
 */

class Database {
    private const SCHEMA_VERSION = 7;
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
                
                // Concurrency and lock timeout optimization to eliminate OpenResty 502 Bad Gateway
                $this->conn->exec("PRAGMA journal_mode = WAL;");
                $this->conn->exec("PRAGMA synchronous = NORMAL;");
                $this->conn->exec("PRAGMA busy_timeout = 5000;");
                
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
                
                // Run silent migrations if any schema modifications are pending
                $this->checkAndRunMigrations();
            } catch (PDOException $sqlite_err) {
                die("Database connection failed: (MySQL) " . (!empty($mysql_err) ? $mysql_err : 'forced') . " | (SQLite fallback) " . $sqlite_err->getMessage());
            }
        }
    }
    
    /**
     * Auto-initialize the SQLite database using database_sqlite.sql
     */
    private function initializeSqliteDatabase() {
        $sql_file = dirname(__DIR__) . '/database_sqlite.sql';
        if (file_exists($sql_file)) {
            $sql = file_get_contents($sql_file);
            $this->conn->exec($sql);
        } else {
            throw new Exception("SQLite schema file not found at: " . $sql_file);
        }
    }

    /**
     * Check if database schema has all current columns/tables, if not, execute migrations.
     */
    private function checkAndRunMigrations() {
        try {
            $ver = (int)$this->conn->query("PRAGMA user_version")->fetchColumn();
            if ($ver >= self::SCHEMA_VERSION) {
                return; // Schema already verified, avoid expensive PRAGMA checks on every page load
            }
        } catch (PDOException $e) {
            // Proceed with checks if query fails
        }
        
        $run_migrations = false;
        
        // List of checks: [table, column] or [table, null] to check table existence
        $checks = [
            ['class_subjects', null],
            ['teacher_details', null],
            ['questions', null],
            ['exam_student_attendance', null],
            ['class_routines', null],
            ['class_routines_history', null],
            ['learning_notes', null],
            ['exams', 'result_system'],
            ['exams', 'has_internal'],
            ['schools', 'principal_name'],
            ['schools', 'class_teacher_signature'],
            ['schools', 'routine_periods_per_day'],
            ['schools', 'subscription_type'],
            ['schools', 'question_permission_status'],
            ['schools', 'question_view_permission'],
            ['schools', 'learning_hub_permission_status'],
            ['users', 'signature'],
            ['classes', 'is_active'],
            ['classes', 'class_teacher_name'],
            ['students', 'optional_subject_i'],
            ['exam_subjects', 'credit_hours'],
            ['questions', 'answer'],
            ['specification_grids', null],
            ['chapters', null],
            ['class_subjects', 'section_id'],
            ['class_subjects', 'is_optional'],
            ['academic_years', 'is_hidden'],
            ['topics', null],
            ['questions', 'topic_id'],
            ['questions', 'cognitive_domain']
        ];
        
        foreach ($checks as $check) {
            $table = $check[0];
            $col = $check[1];
            
            if ($col === null) {
                // Check if table exists
                $exists = $this->conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
                if (!$exists) {
                    $run_migrations = true;
                    break;
                }
            } else {
                // Check if column exists in table
                // First check if table itself exists
                $table_exists = $this->conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
                if (!$table_exists) {
                    $run_migrations = true;
                    break;
                }
                
                $cols = $this->conn->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
                $has_col = false;
                foreach ($cols as $c) {
                    if ($c['name'] === $col) {
                        $has_col = true;
                        break;
                    }
                }
                if (!$has_col) {
                    $run_migrations = true;
                    break;
                }
            }
        }
        
        if ($run_migrations) {
            $this->runAllMigrations();
        }
        
        try {
            $this->conn->exec("PRAGMA user_version = " . self::SCHEMA_VERSION);
        } catch (PDOException $e) {}
    }

    /**
     * Run all migrations silently
     */
    private function runAllMigrations() {
        // A. Create tables if they don't exist
        
        // 1. class_subjects
        $sql = "CREATE TABLE IF NOT EXISTS class_subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            section_id INTEGER DEFAULT NULL,
            credit_hours INTEGER DEFAULT 4,
            teacher_id INTEGER DEFAULT NULL,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL
        )";
        $this->conn->exec($sql);

        // 2. teacher_details
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

        // 3. questions
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
            cognitive_domain VARCHAR(50) DEFAULT 'Knowledge',
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        )";
        $this->conn->exec($sql);

        // 4. exam_student_attendance
        $sql = "CREATE TABLE IF NOT EXISTS exam_student_attendance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            exam_id INTEGER NOT NULL,
            student_id INTEGER NOT NULL,
            present_days INTEGER DEFAULT 0,
            FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        )";
        $this->conn->exec($sql);

        // 5. class_routines
        $sql = "CREATE TABLE IF NOT EXISTS class_routines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_id INTEGER NOT NULL,
            day_of_week INTEGER NOT NULL,
            period_number INTEGER NOT NULL,
            subject_id INTEGER DEFAULT NULL,
            teacher_id INTEGER DEFAULT NULL,
            school_id INTEGER DEFAULT 1,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
        )";
        $this->conn->exec($sql);

        // 6. class_routines_history
        $sql = "CREATE TABLE IF NOT EXISTS class_routines_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version_id INTEGER DEFAULT NULL,
            class_id INTEGER NOT NULL,
            day_of_week INTEGER NOT NULL,
            period_number INTEGER NOT NULL,
            subject_id INTEGER DEFAULT NULL,
            teacher_id INTEGER DEFAULT NULL,
            school_id INTEGER DEFAULT 1,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            changed_by_user_id INTEGER DEFAULT NULL
        )";
        $this->conn->exec($sql);

        // 7. learning_notes
        $sql = "CREATE TABLE IF NOT EXISTS learning_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            class_level VARCHAR(50) NOT NULL,
            subject_name VARCHAR(100) NOT NULL,
            chapter VARCHAR(255) NOT NULL,
            topic VARCHAR(255) NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP
        )";
        $this->conn->exec($sql);

        // 8. specification_grids
        $sql = "CREATE TABLE IF NOT EXISTS specification_grids (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_id INTEGER NOT NULL,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            config_json TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->conn->exec($sql);

        // 9. chapters
        $sql = "CREATE TABLE IF NOT EXISTS chapters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_id INTEGER DEFAULT 0,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_np VARCHAR(255) DEFAULT NULL,
            sort_order INTEGER DEFAULT 0
        )";
        $this->conn->exec($sql);

        // 10. topics
        $sql = "CREATE TABLE IF NOT EXISTS topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_id INTEGER DEFAULT 0,
            class_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            chapter_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            name_np VARCHAR(255) DEFAULT NULL,
            sort_order INTEGER DEFAULT 0,
            FOREIGN KEY (chapter_id) REFERENCES chapters(id) ON DELETE CASCADE
        )";
        $this->conn->exec($sql);

        // Seed universal classes if not present
        $stmt = $this->conn->query("SELECT COUNT(*) FROM classes WHERE school_id = 0");
        if ($stmt->fetchColumn() == 0) {
            $uni_classes = [
                ['Class 1', 'कक्षा १', 'Primary'],
                ['Class 2', 'कक्षा २', 'Primary'],
                ['Class 3', 'कक्षा ३', 'Primary'],
                ['Class 4', 'कक्षा ४', 'Primary'],
                ['Class 5', 'कक्षा ५', 'Primary'],
                ['Class 6', 'कक्षा ६', 'Lower Secondary'],
                ['Class 7', 'कक्षा ७', 'Lower Secondary'],
                ['Class 8', 'कक्षा ८', 'Lower Secondary'],
                ['Class 9', 'कक्षा ९', 'Secondary'],
                ['Class 10', 'कक्षा १०', 'Secondary'],
                ['Class 11', 'कक्षा ११', 'Higher Secondary'],
                ['Class 12', 'कक्षा १२', 'Higher Secondary']
            ];
            $ins = $this->conn->prepare("INSERT INTO classes (name, name_np, level, grading_type, school_id, is_active) VALUES (?, ?, ?, 'gpa', 0, 1)");
            foreach ($uni_classes as $uc) {
                $ins->execute($uc);
            }
        }

        // B. Alter tables - Add columns dynamically
        
        $alters = [
            'marks' => [
                'internal_breakdown' => 'TEXT DEFAULT NULL'
            ],
            'exams' => [
                'result_system' => "VARCHAR(50) DEFAULT 'gpa'",
                'has_internal' => 'INTEGER DEFAULT 0'
            ],
            'schools' => [
                'principal_name' => 'VARCHAR(100) DEFAULT NULL',
                'principal_mobile' => 'VARCHAR(20) DEFAULT NULL',
                'technical_person' => 'VARCHAR(100) DEFAULT NULL',
                'class_teacher_signature' => 'VARCHAR(255) DEFAULT NULL',
                'routine_periods_per_day' => 'INTEGER DEFAULT 8',
                'routine_include_sunday' => 'INTEGER DEFAULT 0',
                'subscription_type' => "VARCHAR(50) DEFAULT 'full'",
                'question_permission_status' => "VARCHAR(20) DEFAULT 'none'",
                'question_view_permission' => "VARCHAR(20) DEFAULT 'none'",
                'learning_hub_permission_status' => "VARCHAR(20) DEFAULT 'none'"
            ],
            'academic_years' => [
                'is_hidden' => 'INTEGER DEFAULT 0'
            ],
            'users' => [
                'signature' => 'VARCHAR(255) DEFAULT NULL'
            ],
            'classes' => [
                'class_teacher_name' => 'VARCHAR(100) DEFAULT NULL',
                'class_teacher_signature' => 'VARCHAR(255) DEFAULT NULL',
                'is_active' => 'INTEGER DEFAULT 1',
                'is_grading_teaching' => 'INTEGER DEFAULT 0'
            ],
            'students' => [
                'optional_subject_i' => 'INTEGER DEFAULT NULL',
                'optional_subject_ii' => 'INTEGER DEFAULT NULL'
            ],
            'exam_subjects' => [
                'credit_hours' => 'INTEGER DEFAULT NULL',
                'internal_credit_hours' => 'DECIMAL(4,2) DEFAULT NULL',
                'external_credit_hours' => 'DECIMAL(4,2) DEFAULT NULL'
            ],
            'questions' => [
                'answer' => 'TEXT DEFAULT NULL',
                'question_text_np' => 'TEXT DEFAULT NULL',
                'answer_np' => 'TEXT DEFAULT NULL',
                'options_np' => 'TEXT DEFAULT NULL',
                'school_id' => 'INTEGER DEFAULT 1',
                'topic_id' => 'INTEGER DEFAULT NULL',
                'cognitive_domain' => "VARCHAR(50) DEFAULT 'Knowledge'"
            ],
            'class_subjects' => [
                'section_id' => 'INTEGER DEFAULT NULL',
                'is_optional' => 'INTEGER DEFAULT 0',
                'is_active' => 'INTEGER DEFAULT 1'
            ]
        ];

        foreach ($alters as $table => $cols) {
            foreach ($cols as $col => $col_def) {
                try {
                    $has_col = false;
                    $cols_info = $this->conn->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($cols_info as $c) {
                        if ($c['name'] === $col) {
                            $has_col = true;
                            break;
                        }
                    }
                    if (!$has_col) {
                        $this->conn->exec("ALTER TABLE `$table` ADD COLUMN `$col` $col_def");
                    }
                } catch (PDOException $e) {
                    // Suppress error if column already exists
                }
            }
        }

        // C. migrate.php inserts
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

        // D. migrate_multi_tenant.php logic
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
            'class_subjects',
            'class_routines',
            'class_routines_history'
        ];

        foreach ($tables as $table) {
            try {
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
            } catch (PDOException $e) {
                // Suppress if table doesn't exist
            }
        }

        // 3. Make sure all existing data is updated to school_id = 1 (exclude universal school_id = 0)
        foreach ($tables as $table) {
            try {
                if ($table !== 'users') {
                    $this->conn->exec("UPDATE `$table` SET school_id = 1 WHERE school_id IS NULL");
                }
            } catch (PDOException $e) {
                // Suppress if table doesn't exist
            }
        }

        // Restore standard classes to school_id = 0 (universal) so all schools can see them
        try {
            $std_names = ['Nursery', 'LKG', 'UKG', 'ECD', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11', 'Class 12'];
            $placeholders = implode(',', array_fill(0, count($std_names), '?'));
            $stmt_restore = $this->conn->prepare("UPDATE classes SET school_id = 0 WHERE name IN ($placeholders) AND school_id = 1");
            $stmt_restore->execute($std_names);
            $this->conn->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_classes_name_school ON classes(name, school_id)");
        } catch (PDOException $e) {
            // Suppress
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
