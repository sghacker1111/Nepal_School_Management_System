<?php
/** Additive upgrades: retain records, marks and uploaded files. */
function dbColumns(PDO $db, string $table): array {
    $rows = $db->query(DB_TYPE === 'sqlite' ? "PRAGMA table_info(`$table`)" : "SHOW COLUMNS FROM `$table`")->fetchAll();
    return array_column($rows, DB_TYPE === 'sqlite' ? 'name' : 'Field');
}
function migrateSchoolSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS app_meta (meta_key VARCHAR(100) PRIMARY KEY, meta_value VARCHAR(255) NOT NULL)');
    if ($db->query("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'")->fetchColumn() === '3') return;
    $pk = DB_TYPE === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tables = [
        'class_subjects' => 'class_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, teacher_id VARCHAR(255), credit_hours DECIMAL(6,2) DEFAULT 4',
        'teacher_details' => 'user_id INTEGER NOT NULL UNIQUE, join_date DATE, perm_issue_date DATE, temp_issue_date DATE, cit_no VARCHAR(50), family_details TEXT, dob DATE, bank_name VARCHAR(100), bank_account VARCHAR(50)',
        'questions' => "class_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, chapter VARCHAR(255) NOT NULL, type VARCHAR(30) DEFAULT 'Short', question_text TEXT, options TEXT, correct_option VARCHAR(5), marks DECIMAL(6,2) DEFAULT 1",
        'exam_student_attendance' => 'exam_id INTEGER NOT NULL, student_id INTEGER NOT NULL, present_days INTEGER DEFAULT 0',
        'class_routines' => 'class_id INTEGER NOT NULL, day_of_week INTEGER NOT NULL, period_number INTEGER NOT NULL, subject_id INTEGER, teacher_id INTEGER, school_id INTEGER NOT NULL',
        'class_routines_history' => 'version_id INTEGER, class_id INTEGER, day_of_week INTEGER, period_number INTEGER, subject_id INTEGER, teacher_id INTEGER, school_id INTEGER, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, changed_by_user_id INTEGER',
        'learning_notes' => 'class_level VARCHAR(50), subject_name VARCHAR(100), chapter VARCHAR(255), topic VARCHAR(255), title VARCHAR(255), content TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'specification_grids' => 'school_id INTEGER NOT NULL, class_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, name VARCHAR(255), config_json TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'chapters' => 'school_id INTEGER DEFAULT 0, class_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, name VARCHAR(255), name_np VARCHAR(255), sort_order INTEGER DEFAULT 0',
        'topics' => 'school_id INTEGER DEFAULT 0, class_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, chapter_id INTEGER NOT NULL, name VARCHAR(255), name_np VARCHAR(255), sort_order INTEGER DEFAULT 0',
        'student_subjects' => 'student_id INTEGER NOT NULL, subject_id INTEGER NOT NULL, UNIQUE(student_id, subject_id)',
        'login_attempts' => 'identity_key VARCHAR(64) NOT NULL, attempted_at INTEGER NOT NULL'
    ];
    foreach ($tables as $table => $definition) $db->exec("CREATE TABLE IF NOT EXISTS `$table` (id $pk, $definition)");
    $evaluation = ['th_credit_hours'=>'DECIMAL(6,2) DEFAULT 0', 'in_credit_hours'=>'DECIMAL(6,2) DEFAULT 0', 'th_full_marks'=>'DECIMAL(6,2) DEFAULT 75', 'th_pass_marks'=>'DECIMAL(6,2) DEFAULT 26.25', 'in_full_marks'=>'DECIMAL(6,2) DEFAULT 25', 'in_pass_marks'=>'DECIMAL(6,2) DEFAULT 10'];
    $columns = [
        'schools' => ['principal_name'=>'VARCHAR(100)', 'principal_mobile'=>'VARCHAR(20)', 'technical_person'=>'VARCHAR(100)', 'class_teacher_signature'=>'VARCHAR(255)', 'routine_periods_per_day'=>'INTEGER DEFAULT 8', 'routine_include_sunday'=>'INTEGER DEFAULT 1', 'subscription_type'=>"VARCHAR(30) DEFAULT 'full'", 'question_permission_status'=>"VARCHAR(20) DEFAULT 'none'", 'question_view_permission'=>"VARCHAR(20) DEFAULT 'none'", 'learning_hub_permission_status'=>"VARCHAR(20) DEFAULT 'none'", 'workflow_version'=>'INTEGER DEFAULT 0'],
        'users' => ['school_id'=>'INTEGER DEFAULT 1', 'signature'=>'VARCHAR(255)'],
        'classes' => ['school_id'=>'INTEGER DEFAULT 1', 'source_id'=>'INTEGER', 'class_teacher_id'=>'INTEGER', 'class_teacher_name'=>'VARCHAR(100)', 'class_teacher_signature'=>'VARCHAR(255)', 'is_active'=>'INTEGER DEFAULT 1', 'is_grading_teaching'=>'INTEGER DEFAULT 0'],
        'subjects' => ['school_id'=>'INTEGER DEFAULT 1', 'source_id'=>'INTEGER', 'is_hidden'=>'INTEGER DEFAULT 0'] + $evaluation,
        'sections' => ['school_id'=>'INTEGER DEFAULT 1'],
        'students' => ['school_id'=>'INTEGER DEFAULT 1', 'optional_subject_i'=>'INTEGER', 'optional_subject_ii'=>'INTEGER', 'optional_subject_iii'=>'INTEGER'],
        'exams' => ['school_id'=>'INTEGER DEFAULT 1', 'result_system'=>"VARCHAR(20) DEFAULT 'gpa'", 'has_internal'=>'INTEGER DEFAULT 0'],
        'academic_years' => ['school_id'=>'INTEGER DEFAULT 1', 'is_hidden'=>'INTEGER DEFAULT 0'],
        'class_subjects' => ['school_id'=>'INTEGER DEFAULT 1', 'section_id'=>'INTEGER', 'is_optional'=>'INTEGER DEFAULT 0', 'is_active'=>'INTEGER DEFAULT 1'] + $evaluation,
        'exam_subjects' => ['credit_hours'=>'DECIMAL(6,2)', 'internal_credit_hours'=>'DECIMAL(6,2)', 'external_credit_hours'=>'DECIMAL(6,2)', 'is_optional'=>'INTEGER'],
        'marks' => ['internal_breakdown'=>'TEXT'],
        'questions' => ['school_id'=>'INTEGER DEFAULT 1', 'answer'=>'TEXT', 'question_text_np'=>'TEXT', 'answer_np'=>'TEXT', 'options_np'=>'TEXT', 'topic_id'=>'INTEGER', 'cognitive_domain'=>"VARCHAR(50) DEFAULT 'Knowledge'"]
    ];
    foreach ($columns as $table => $fields) {
        $existing = dbColumns($db, $table);
        foreach ($fields as $name => $type) if (!in_array($name, $existing, true)) $db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $type");
    }
    if (DB_TYPE === 'mysql') {
        foreach (['subjects'=>['credit_hours','full_marks','pass_marks'], 'class_subjects'=>['credit_hours'], 'exam_subjects'=>['credit_hours','theory_full','theory_pass','practical_full','practical_pass']] as $table=>$fields) {
            foreach ($fields as $field) $db->exec("ALTER TABLE `$table` MODIFY `$field` DECIMAL(6,2) NULL");
        }
        $db->exec('ALTER TABLE class_subjects MODIFY teacher_id VARCHAR(255) NULL');
        $db->exec("ALTER TABLE students MODIFY status VARCHAR(20) DEFAULT 'active'");
    }
    require_once __DIR__ . '/school_setup.php';
    $db->beginTransaction();
    try {
        ensureUniversalClasses($db);
        require_once __DIR__ . '/curriculum.php';
        if (!(int)$db->query('SELECT COUNT(*) FROM class_subjects WHERE school_id=0')->fetchColumn()) seedUniversalCurriculum($db);
        foreach ($db->query('SELECT id FROM schools')->fetchAll(PDO::FETCH_COLUMN) as $schoolId) provisionSchool($db, (int)$schoolId);
        $db->exec("UPDATE exam_subjects SET is_optional=COALESCE((SELECT MAX(cs.is_optional) FROM class_subjects cs WHERE cs.class_id=exam_subjects.class_id AND cs.subject_id=exam_subjects.subject_id),(SELECT CASE WHEN s.type='optional' THEN 1 ELSE 0 END FROM subjects s WHERE s.id=exam_subjects.subject_id),0) WHERE is_optional IS NULL");
        $db->exec("UPDATE classes SET class_teacher_id = (SELECT MIN(u.id) FROM users u WHERE u.school_id=classes.school_id AND u.full_name=classes.class_teacher_name AND u.role='teacher') WHERE class_teacher_id IS NULL AND class_teacher_name IS NOT NULL");
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    $indexes = ['students_school_class_year'=>'students(school_id,class_id,academic_year_id,status)', 'sections_school_class'=>'sections(school_id,class_id)', 'users_school_role'=>'users(school_id,role,status)', 'exams_school_year'=>'exams(school_id,academic_year_id)', 'marks_exam_student_subject'=>'marks(exam_id,student_id,subject_id)', 'attendance_student_date'=>'attendances(student_id,date_ad)', 'mappings_school_class'=>'class_subjects(school_id,class_id,subject_id)', 'questions_scope'=>'questions(school_id,class_id,subject_id)', 'routines_school_slot'=>'class_routines(school_id,day_of_week,period_number)', 'attempts_identity_time'=>'login_attempts(identity_key,attempted_at)'];
    foreach ($indexes as $name=>$definition) {
        if (DB_TYPE === 'sqlite') $db->exec("CREATE INDEX IF NOT EXISTS idx_$name ON $definition");
        else {
            $table = strstr($definition, '(', true);
            if (!$db->query("SHOW INDEX FROM `$table` WHERE Key_name='idx_$name'")->fetch()) $db->exec("CREATE INDEX idx_$name ON $definition");
        }
    }
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM app_meta WHERE meta_key=?')->execute(['workflow_schema']);
        $db->prepare('INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?)')->execute(['workflow_schema','3']);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
