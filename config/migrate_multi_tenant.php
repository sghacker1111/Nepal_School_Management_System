<?php
/**
 * Database Migration Script
 * Phase 3 - Multi-School Tenancy & Roles
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = getDB();
$db_type = DB_TYPE; // 'sqlite' or 'mysql'

echo "<h2>Starting Multi-School Tenancy Migration...</h2>";
echo "<ul>";

try {
    // 1. Ensure 'schools' table exists and contains the default school
    $stmt = $db->query("SELECT COUNT(*) FROM schools WHERE id = 1");
    if ($stmt->fetchColumn() == 0) {
        $db->exec("INSERT INTO schools (id, name_en, name_np, emis_code) VALUES (1, 'Model Secondary School', 'नमूना माध्यमिक विद्यालय', '12345678')");
        echo "<li>Ensured default school exists (ID = 1).</li>";
    } else {
        echo "<li>Default school already exists.</li>";
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
        if ($db_type === 'sqlite') {
            $cols = $db->query("PRAGMA table_info(`$table`)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if ($col['name'] === 'school_id') {
                    $has_column = true;
                    break;
                }
            }
        } else {
            // MySQL check
            $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE 'school_id'");
            $stmt->execute();
            if ($stmt->fetch()) {
                $has_column = true;
            }
        }

        if (!$has_column) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN school_id INTEGER DEFAULT 1");
            echo "<li>Added column `school_id` to table `$table`.</li>";
        } else {
            echo "<li>Column `school_id` already exists in table `$table`.</li>";
        }
    }

    // 3. Make sure all existing data is updated to school_id = 1
    foreach ($tables as $table) {
        if ($table !== 'users') {
            $db->exec("UPDATE `$table` SET school_id = 1 WHERE school_id IS NULL OR school_id = 0");
        }
    }
    echo "<li>Scoped existing data to School ID 1.</li>";

    // 4. Update the global super_admin user (username: admin)
    // Make sure 'admin' is role = 'super_admin' and school_id = NULL
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() > 0) {
        $db->exec("UPDATE users SET school_id = NULL, role = 'super_admin' WHERE username = 'admin'");
        echo "<li>Updated 'admin' user to global Super Admin (school_id = NULL).</li>";
    } else {
        // Insert admin user if not exists
        $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name, school_id) VALUES ('admin', ?, 'super_admin', 'System Administrator', NULL)");
        $stmt->execute([$admin_pass]);
        echo "<li>Created default 'admin' user as global Super Admin.</li>";
    }

    // 5. Create default school_admin for School 1 (username: schooladmin, password: admin123)
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'schooladmin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $school_admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name, school_id) VALUES ('schooladmin', ?, 'school_admin', 'Model School Administrator', 1)");
        $stmt->execute([$school_admin_pass]);
        echo "<li>Created default 'schooladmin' (password: admin123) for School ID 1.</li>";
    } else {
        echo "<li>'schooladmin' user already exists.</li>";
    }

    echo "</ul>";
    echo "<h3 style='color:green;'>Multi-School Tenancy Migration completed successfully!</h3>";
    echo "<p><a href='" . APP_URL . "/index.php'>Go to Dashboard</a></p>";

} catch (Exception $e) {
    echo "</ul>";
    echo "<h3 style='color:red;'>Migration failed: " . $e->getMessage() . "</h3>";
}
