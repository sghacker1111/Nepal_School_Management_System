<?php
/**
 * Database Migration Script
 * Phase 2 - Advanced ERP Features
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = getDB();
$db_type = DB_TYPE; // 'sqlite' or 'mysql'

echo "<h2>Starting Database Migration (Database Type: " . strtoupper($db_type) . ")...</h2>";
echo "<ul>";

try {
    // 1. Insert Class 11 and Class 12 if they don't exist
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE name = ?");
    
    $stmt->execute(['Class 11']);
    if ($stmt->fetchColumn() == 0) {
        $db->prepare("INSERT INTO classes (name, name_np, level, grading_type) VALUES ('Class 11', 'कक्षा ११', 'Secondary', 'gpa')")->execute();
        echo "<li>Added Class 11.</li>";
    }
    
    $stmt->execute(['Class 12']);
    if ($stmt->fetchColumn() == 0) {
        $db->prepare("INSERT INTO classes (name, name_np, level, grading_type) VALUES ('Class 12', 'कक्षा १२', 'Secondary', 'gpa')")->execute();
        echo "<li>Added Class 12.</li>";
    }

    // 2. Insert Academic Year 2083 if it doesn't exist
    $stmt = $db->prepare("SELECT COUNT(*) FROM academic_years WHERE year_bs = ?");
    $stmt->execute(['2083']);
    if ($stmt->fetchColumn() == 0) {
        $db->prepare("INSERT INTO academic_years (year_bs, year_ad, is_active) VALUES ('2083', '2026', 0)")->execute();
        echo "<li>Added Academic Year 2083.</li>";
    }

    // 3. Create class_subjects table
    if ($db_type === 'sqlite') {
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
    } else {
        $sql = "CREATE TABLE IF NOT EXISTS class_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            credit_hours INT DEFAULT 4,
            teacher_id INT DEFAULT NULL,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
    $db->exec($sql);
    echo "<li>Ensured table: `class_subjects`</li>";

    // 4. Create teacher_details table
    if ($db_type === 'sqlite') {
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
    } else {
        $sql = "CREATE TABLE IF NOT EXISTS teacher_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL UNIQUE,
            join_date DATE DEFAULT NULL,
            perm_issue_date DATE DEFAULT NULL,
            temp_issue_date DATE DEFAULT NULL,
            cit_no VARCHAR(50) DEFAULT NULL,
            family_details TEXT DEFAULT NULL,
            dob DATE DEFAULT NULL,
            bank_name VARCHAR(100) DEFAULT NULL,
            bank_account VARCHAR(50) DEFAULT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
    $db->exec($sql);
    echo "<li>Ensured table: `teacher_details`</li>";

    // 5. Create questions table
    if ($db_type === 'sqlite') {
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
    } else {
        $sql = "CREATE TABLE IF NOT EXISTS questions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            chapter VARCHAR(100) NOT NULL,
            type VARCHAR(20) NOT NULL,
            question_text TEXT NOT NULL,
            options TEXT DEFAULT NULL,
            correct_option VARCHAR(5) DEFAULT NULL,
            marks INT DEFAULT 1,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
    $db->exec($sql);
    echo "<li>Ensured table: `questions`</li>";

    // 6. Alter marks table - Add internal_breakdown if not exists
    try {
        if ($db_type === 'sqlite') {
            $db->exec("ALTER TABLE marks ADD COLUMN internal_breakdown TEXT DEFAULT NULL");
        } else {
            $db->exec("ALTER TABLE marks ADD COLUMN internal_breakdown TEXT DEFAULT NULL");
        }
        echo "<li>Added column `internal_breakdown` to `marks` table.</li>";
    } catch (PDOException $e) {
        // Suppress error if column already exists
        echo "<li>Column `internal_breakdown` already exists or not added: " . $e->getMessage() . " (Safe to ignore).</li>";
    }

    // 7. Alter exams table - Add result_system if not exists
    try {
        $db->exec("ALTER TABLE exams ADD COLUMN result_system VARCHAR(50) DEFAULT 'gpa'");
        echo "<li>Added column `result_system` to `exams` table.</li>";
    } catch (PDOException $e) {
        // Suppress error if column already exists
        echo "<li>Column `result_system` already exists or not added: " . $e->getMessage() . " (Safe to ignore).</li>";
    }

    // 8. Alter schools table - Add principal_name, principal_mobile, technical_person if they don't exist
    $school_columns = [
        'principal_name' => 'VARCHAR(100) DEFAULT NULL',
        'principal_mobile' => 'VARCHAR(20) DEFAULT NULL',
        'technical_person' => 'VARCHAR(100) DEFAULT NULL'
    ];
    foreach ($school_columns as $col => $col_definition) {
        try {
            // We check if column exists first to be safe
            $has_col = false;
            if ($db_type === 'sqlite') {
                $cols = $db->query("PRAGMA table_info(`schools`)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($cols as $c) {
                    if ($c['name'] === $col) {
                        $has_col = true;
                        break;
                    }
                }
            } else {
                $stmt = $db->prepare("SHOW COLUMNS FROM `schools` LIKE ?");
                $stmt->execute([$col]);
                if ($stmt->fetch()) {
                    $has_col = true;
                }
            }

            if (!$has_col) {
                $db->exec("ALTER TABLE `schools` ADD COLUMN `$col` $col_definition");
                echo "<li>Added column `$col` to `schools` table.</li>";
            }
        } catch (PDOException $e) {
            echo "<li>Column `$col` already exists or not added: " . $e->getMessage() . " (Safe to ignore).</li>";
        }
    }


    echo "</ul>";
    echo "<h3 style='color:green;'>Migration completed successfully!</h3>";
    echo "<p><a href='" . APP_URL . "/index.php'>Go to Dashboard</a></p>";

} catch (Exception $e) {
    echo "</ul>";
    echo "<h3 style='color:red;'>Migration failed: " . $e->getMessage() . "</h3>";
}
