<?php
/**
 * CDC Nepal National Curriculum Seeder
 * 
 * Recreates the 16 exact standard classes:
 * - Basic Level: Nursery, LKG, UKG, ECD, Class 1 to Class 8
 * - Secondary Level: Class 9, Class 10, Class 11, Class 12
 * 
 * Seeds official CDC Nepal Compulsory Subjects and Optional Subjects.
 * Configures class_subjects mapping with is_optional and is_active flags.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = Database::getInstance()->getConnection();

echo "=== Starting CDC Nepal Curriculum & Class Seeding ===\n\n";

// 1. Ensure Schema has required columns & unique index
echo "1. Updating Schema...\n";

// Add is_optional and is_active to class_subjects if not present
$cs_cols = $db->query("PRAGMA table_info(class_subjects)")->fetchAll(PDO::FETCH_ASSOC);
$col_names = array_column($cs_cols, 'name');

if (!in_array('is_optional', $col_names)) {
    $db->exec("ALTER TABLE class_subjects ADD COLUMN is_optional INTEGER DEFAULT 0");
    echo " - Added `is_optional` column to `class_subjects`\n";
}
if (!in_array('is_active', $col_names)) {
    $db->exec("ALTER TABLE class_subjects ADD COLUMN is_active INTEGER DEFAULT 1");
    echo " - Added `is_active` column to `class_subjects`\n";
}

// Ensure subjects has type column
$sub_cols = $db->query("PRAGMA table_info(subjects)")->fetchAll(PDO::FETCH_ASSOC);
$sub_col_names = array_column($sub_cols, 'name');
if (!in_array('type', $sub_col_names)) {
    $db->exec("ALTER TABLE subjects ADD COLUMN type VARCHAR(50) DEFAULT 'compulsory'");
    echo " - Added `type` column to `subjects`\n";
}

// 2. Clear previous classes, subjects, and class_subjects mappings
echo "\n2. Clearing old classes, subjects, and class_subjects mappings...\n";
$db->exec("DELETE FROM class_subjects");
$db->exec("DELETE FROM subjects");
$db->exec("DELETE FROM classes");
$db->exec("DELETE FROM sqlite_sequence WHERE name IN ('classes', 'subjects', 'class_subjects')");

// Create UNIQUE index on classes (LOWER(name), school_id)
$db->exec("DROP INDEX IF EXISTS idx_classes_name_school");
$db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_classes_name_school ON classes(name, school_id)");
echo " - Unique index `idx_classes_name_school` established\n";

// 3. Recreate the 16 exact standard classes
echo "\n3. Creating 16 Standard Classes (Nursery to Class 12)...\n";
$classes_data = [
    // Basic Level (Early Childhood & Primary / Lower Secondary)
    ['id' => 1,  'name' => 'Nursery',  'name_np' => 'नर्सरी',                              'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 2,  'name' => 'LKG',      'name_np' => 'एल.के.जी.',                          'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 3,  'name' => 'UKG',      'name_np' => 'यु.के.जी.',                          'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 4,  'name' => 'ECD',      'name_np' => 'प्रारम्भिक बाल विकास (ECD)',           'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 5,  'name' => 'Class 1',  'name_np' => 'कक्षा १',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 6,  'name' => 'Class 2',  'name_np' => 'कक्षा २',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 7,  'name' => 'Class 3',  'name_np' => 'कक्षा ३',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 8,  'name' => 'Class 4',  'name_np' => 'कक्षा ४',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 9,  'name' => 'Class 5',  'name_np' => 'कक्षा ५',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 10, 'name' => 'Class 6',  'name_np' => 'कक्षा ६',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 11, 'name' => 'Class 7',  'name_np' => 'कक्षा ७',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    ['id' => 12, 'name' => 'Class 8',  'name_np' => 'कक्षा ८',                             'level' => 'Basic', 'grading_type' => 'gpa'],
    // Secondary Level
    ['id' => 13, 'name' => 'Class 9',  'name_np' => 'कक्षा ९',                             'level' => 'Secondary', 'grading_type' => 'gpa'],
    ['id' => 14, 'name' => 'Class 10', 'name_np' => 'कक्षा १०',                            'level' => 'Secondary', 'grading_type' => 'gpa'],
    ['id' => 15, 'name' => 'Class 11', 'name_np' => 'कक्षा ११',                            'level' => 'Secondary', 'grading_type' => 'gpa'],
    ['id' => 16, 'name' => 'Class 12', 'name_np' => 'कक्षा १२',                            'level' => 'Secondary', 'grading_type' => 'gpa']
];

$stmt_class = $db->prepare("INSERT INTO classes (id, name, name_np, level, grading_type, school_id, is_active) VALUES (?, ?, ?, ?, ?, 0, 1)");
foreach ($classes_data as $c) {
    $stmt_class->execute([$c['id'], $c['name'], $c['name_np'], $c['level'], $c['grading_type']]);
    echo " - Class ID {$c['id']}: {$c['name']} ({$c['name_np']}) -> Level: {$c['level']}\n";
}

// 4. Seed Official CDC Nepal Subjects Catalog
echo "\n4. Creating CDC Nepal Subjects Catalog...\n";

// Helper map to insert subject
$stmt_sub = $db->prepare("INSERT INTO subjects (code, name_en, name_np, type, full_marks, pass_marks, credit_hours, school_id) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");

$subjects_catalog = [
    // Pre-Primary (Nursery, LKG, UKG, ECD) Compulsory
    'pre_nep'   => ['code' => 'NEP-00', 'en' => 'Compulsory Nepali', 'np' => 'अनिवार्य नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'pre_eng'   => ['code' => 'ENG-00', 'en' => 'Compulsory English', 'np' => 'अनिवार्य अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'pre_mat'   => ['code' => 'MAT-00', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'pre_env'   => ['code' => 'ENV-00', 'en' => 'Our Environment & GK', 'np' => 'हाम्रो वातावरण तथा सामान्य ज्ञान', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'pre_art'   => ['code' => 'ART-00', 'en' => 'Creative Arts', 'np' => 'सिर्जनात्मक कला', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 2],

    // Grades 1-3 Compulsory (Integrated Curriculum - एकीकृत पाठ्यक्रम)
    'g13_nep'   => ['code' => 'NEP-123', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g13_eng'   => ['code' => 'ENG-123', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g13_mat'   => ['code' => 'MAT-123', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g13_ser'   => ['code' => 'SER-123', 'en' => 'Hamro Serophero', 'np' => 'हाम्रो सेरोफेरो', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 8],

    // Grades 4-5 Compulsory
    'g45_nep'   => ['code' => 'NEP-45', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g45_eng'   => ['code' => 'ENG-45', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_mat'   => ['code' => 'MAT-45', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_sci'   => ['code' => 'SCI-45', 'en' => 'Science and Technology', 'np' => 'विज्ञान तथा प्रविधि', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_soc'   => ['code' => 'SOC-45', 'en' => 'Social Studies and Human Value Education', 'np' => 'सामाजिक अध्ययन तथा मानव मूल्य शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g45_hpc'   => ['code' => 'HPC-45', 'en' => 'Health, Physical and Creative Arts', 'np' => 'स्वास्थ्य, शारीरिक तथा सिर्जनात्मक कला', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],

    // Grades 6-8 Compulsory
    'g68_nep'   => ['code' => 'NEP-68', 'en' => 'Nepali', 'np' => 'नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g68_eng'   => ['code' => 'ENG-68', 'en' => 'English', 'np' => 'अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_mat'   => ['code' => 'MAT-68', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_sci'   => ['code' => 'SCI-68', 'en' => 'Science and Technology', 'np' => 'विज्ञान तथा प्रविधि', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_soc'   => ['code' => 'SOC-68', 'en' => 'Social Studies and Human Value Education', 'np' => 'सामाजिक अध्ययन तथा मानव मूल्य शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g68_hpc'   => ['code' => 'HPC-68', 'en' => 'Health, Physical and Creative Arts', 'np' => 'स्वास्थ्य, शारीरिक तथा सिर्जनात्मक कला', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'g68_loc'   => ['code' => 'LOC-68', 'en' => 'Local Subject / Mother Tongue', 'np' => 'स्थानीय विषय / मातृभाषा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 9-10 Compulsory (SEE)
    'g910_nep'  => ['code' => 'NEP-910', 'en' => 'Compulsory Nepali', 'np' => 'अनिवार्य नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_eng'  => ['code' => 'ENG-910', 'en' => 'Compulsory English', 'np' => 'अनिवार्य अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_mat'  => ['code' => 'MAT-910', 'en' => 'Compulsory Mathematics', 'np' => 'अनिवार्य गणित', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_sci'  => ['code' => 'SCI-910', 'en' => 'Science and Technology', 'np' => 'विज्ञान तथा प्रविधि', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'g910_soc'  => ['code' => 'SOC-910', 'en' => 'Social Studies', 'np' => 'सामाजिक अध्ययन', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 9-10 Optional Subjects (Electives)
    'opt_mat'   => ['code' => 'OPT-MAT', 'en' => 'Optional Mathematics', 'np' => 'ऐच्छिक गणित', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_com'   => ['code' => 'OPT-COM', 'en' => 'Computer Science', 'np' => 'कम्प्युटर विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_acc'   => ['code' => 'OPT-ACC', 'en' => 'Accountancy', 'np' => 'लेखा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_eco'   => ['code' => 'OPT-ECO', 'en' => 'Economics', 'np' => 'अर्थशास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_hpe'   => ['code' => 'OPT-HPE', 'en' => 'Health and Physical Education', 'np' => 'स्वास्थ्य तथा शारीरिक शिक्षा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_geo'   => ['code' => 'OPT-GEO', 'en' => 'Geography', 'np' => 'भूगोल', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_agr'   => ['code' => 'OPT-AGR', 'en' => 'Agriculture', 'np' => 'कृषि विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'opt_edu'   => ['code' => 'OPT-EDU', 'en' => 'Education', 'np' => 'शिक्षा', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 4],

    // Grades 11-12 Compulsory (NEB)
    'g1112_eng' => ['code' => 'ENG-1112', 'en' => 'Compulsory English', 'np' => 'अनिवार्य अंग्रेजी', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 4],
    'g1112_nep' => ['code' => 'NEP-1112', 'en' => 'Compulsory Nepali', 'np' => 'अनिवार्य नेपाली', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 3],
    'g1112_sol' => ['code' => 'SOL-1112', 'en' => 'Social Studies and Life Skills', 'np' => 'सामाजिक अध्ययन तथा जीवनोपयोगी शिक्षा', 'type' => 'compulsory', 'fm' => 100, 'pm' => 35, 'cr' => 5],

    // Grades 11-12 Optional Subjects (Science, Management, Humanities, Law Clusters)
    'opt11_phy' => ['code' => 'OPT-PHY', 'en' => 'Physics', 'np' => 'भौतिक विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_che' => ['code' => 'OPT-CHE', 'en' => 'Chemistry', 'np' => 'रसायन विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_bio' => ['code' => 'OPT-BIO', 'en' => 'Biology', 'np' => 'जीव विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_mat' => ['code' => 'OPT-MAT11', 'en' => 'Mathematics', 'np' => 'गणित', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_com' => ['code' => 'OPT-COM11', 'en' => 'Computer Science', 'np' => 'कम्प्युटर विज्ञान', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_acc' => ['code' => 'OPT-ACC11', 'en' => 'Accounting', 'np' => 'लेखाविधि', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_eco' => ['code' => 'OPT-ECO11', 'en' => 'Economics', 'np' => 'अर्थशास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_bst' => ['code' => 'OPT-BST', 'en' => 'Business Studies', 'np' => 'व्यवसाय अध्ययन', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_bmt' => ['code' => 'OPT-BMT', 'en' => 'Business Mathematics', 'np' => 'व्यापारिक गणित', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_hmg' => ['code' => 'OPT-HMG', 'en' => 'Hotel Management', 'np' => 'होटल व्यवस्थापन', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_soc' => ['code' => 'OPT-SOC', 'en' => 'Sociology', 'np' => 'समाजशास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_pol' => ['code' => 'OPT-POL', 'en' => 'Political Science', 'np' => 'राजनीति शास्त्र', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5],
    'opt11_law' => ['code' => 'OPT-LAW', 'en' => 'Jurisprudence and Legal Theories', 'np' => 'कानून', 'type' => 'optional', 'fm' => 100, 'pm' => 35, 'cr' => 5]
];

$subject_ids = [];
foreach ($subjects_catalog as $key => $s) {
    $stmt_sub->execute([$s['code'], $s['en'], $s['np'], $s['type'], $s['fm'], $s['pm'], $s['cr']]);
    $subject_ids[$key] = $db->lastInsertId();
    echo " - Subject: [{$s['code']}] {$s['en']} ({$s['np']}) [{$s['type']}]\n";
}

// 5. Map Compulsory and Optional Subjects to Classes
echo "\n5. Mapping Compulsory & Optional Subjects to Classes in `class_subjects`...\n";
$stmt_map = $db->prepare("
    INSERT INTO class_subjects (class_id, subject_id, credit_hours, school_id, is_optional, is_active)
    VALUES (?, ?, ?, 0, ?, ?)
");

// Pre-primary classes: Nursery (1), LKG (2), UKG (3), ECD (4)
$pre_classes = [1, 2, 3, 4];
$pre_subs = ['pre_nep', 'pre_eng', 'pre_mat', 'pre_env', 'pre_art'];
foreach ($pre_classes as $cid) {
    foreach ($pre_subs as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 0, 1]);
    }
}
echo " - Mapped 5 Compulsory Subjects to Pre-Primary classes (Nursery, LKG, UKG, ECD)\n";

// Grades 1-3: Class 1 (5), Class 2 (6), Class 3 (7)
$g13_classes = [5, 6, 7];
$g13_subs = ['g13_nep', 'g13_eng', 'g13_mat', 'g13_ser'];
foreach ($g13_classes as $cid) {
    foreach ($g13_subs as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 0, 1]);
    }
}
echo " - Mapped 4 Integrated Compulsory Subjects to Grades 1-3 (Classes 1, 2, 3)\n";

// Grades 4-5: Class 4 (8), Class 5 (9)
$g45_classes = [8, 9];
$g45_subs = ['g45_nep', 'g45_eng', 'g45_mat', 'g45_sci', 'g45_soc', 'g45_hpc'];
foreach ($g45_classes as $cid) {
    foreach ($g45_subs as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 0, 1]);
    }
}
echo " - Mapped 6 Compulsory Subjects to Grades 4-5 (Classes 4, 5)\n";

// Grades 6-8: Class 6 (10), Class 7 (11), Class 8 (12)
$g68_classes = [10, 11, 12];
$g68_subs = ['g68_nep', 'g68_eng', 'g68_mat', 'g68_sci', 'g68_soc', 'g68_hpc', 'g68_loc'];
foreach ($g68_classes as $cid) {
    foreach ($g68_subs as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 0, 1]);
    }
}
echo " - Mapped 7 Compulsory Subjects to Grades 6-8 (Classes 6, 7, 8)\n";

// Grades 9-10: Class 9 (13), Class 10 (14)
$g910_classes = [13, 14];
$g910_comp = ['g910_nep', 'g910_eng', 'g910_mat', 'g910_sci', 'g910_soc'];
$g910_opt  = ['opt_mat', 'opt_com', 'opt_acc', 'opt_eco', 'opt_hpe', 'opt_geo', 'opt_agr', 'opt_edu'];
foreach ($g910_classes as $cid) {
    // Compulsory (always active, not optional)
    foreach ($g910_comp as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 0, 1]); // is_optional=0, is_active=1
    }
    // Optional (active by default, can be unticked to hide)
    foreach ($g910_opt as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 1, 1]); // is_optional=1, is_active=1
    }
}
echo " - Mapped 5 Compulsory + 8 Optional Subjects to Grades 9-10 (Classes 9, 10)\n";

// Grades 11-12: Class 11 (15), Class 12 (16)
$g1112_classes = [15, 16];
$g1112_comp = ['g1112_eng', 'g1112_nep', 'g1112_sol'];
$g1112_opt  = [
    'opt11_phy', 'opt11_che', 'opt11_bio', 'opt11_mat', 'opt11_com',
    'opt11_acc', 'opt11_eco', 'opt11_bst', 'opt11_bmt', 'opt11_hmg',
    'opt11_soc', 'opt11_pol', 'opt11_law'
];
foreach ($g1112_classes as $cid) {
    // Compulsory
    foreach ($g1112_comp as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 0, 1]); // is_optional=0, is_active=1
    }
    // Optional
    foreach ($g1112_opt as $skey) {
        $s = $subjects_catalog[$skey];
        $sid = $subject_ids[$skey];
        $stmt_map->execute([$cid, $sid, $s['cr'], 1, 1]); // is_optional=1, is_active=1
    }
}
echo " - Mapped 3 Compulsory + 13 Optional Subjects to Grades 11-12 (Classes 11, 12)\n";

echo "\n=== Seeding Completed Successfully! ===\n";
