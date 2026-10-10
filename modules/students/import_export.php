<?php
/**
 * Student Management - EMIS CSV Import & Export
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';
$success = '';

// Active Academic Year
$stmt_yr = $db->prepare("SELECT id FROM academic_years WHERE school_id = ? AND is_active = 1 LIMIT 1");
$stmt_yr->execute([$school_id]);
$academic_year_id = $stmt_yr->fetchColumn() ?: null;

// Normalizer helper for class names
function normalizeName($name) {
    $name = strtolower(trim($name));
    $name = str_replace(['class', 'grade', ' ', '-', '_'], '', $name);
    return $name;
}

// 1. Handle Template Download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=student_import_template.csv');
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    writeCsvRow($output, [
        'EMIS ID',
        'EMIS Registration Number',
        'Student ID',
        'Roll Number',
        'Exam Roll Number',
        'Student Full Name',
        'Class Name',
        'Section Name',
        'Gender',
        'Date of Birth (BS)',
        'Date of Birth (AD)',
        'Blood Group',
        'Father Name',
        'Father Phone',
        'Mother Name',
        'Guardian Name',
        'Guardian Phone',
        'Status'
    ]);
    fclose($output);
    exit;
}

// 2. Handle Export
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_export_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    writeCsvRow($output, [
        'EMIS ID',
        'EMIS Registration Number',
        'Student ID',
        'Roll Number',
        'Exam Roll Number',
        'First Name (EN)',
        'Last Name (EN)',
        'First Name (NP)',
        'Last Name (NP)',
        'Class',
        'Section',
        'Gender',
        'Date of Birth (BS)',
        'Date of Birth (AD)',
        'Blood Group',
        'Father Name',
        'Father Phone',
        'Mother Name',
        'Guardian Name',
        'Guardian Phone',
        'Status'
    ]);
    
    $stmt = $db->prepare("
        SELECT s.*, c.name as class_name, sc.name as section_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN sections sc ON s.section_id = sc.id
        WHERE s.school_id = ?
        ORDER BY c.name, sc.name, s.roll_number
    ");
    $stmt->execute([$school_id]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        writeCsvRow($output, [
            $row['emis_id'],
            $row['emis_registration_number'],
            $row['student_id'],
            $row['roll_number'],
            $row['exam_roll_number'],
            $row['first_name_en'],
            $row['last_name_en'],
            $row['first_name_np'],
            $row['last_name_np'],
            $row['class_name'],
            $row['section_name'],
            $row['gender'],
            $row['dob_bs'],
            $row['dob_ad'],
            $row['blood_group'],
            $row['father_name'],
            $row['father_phone'],
            $row['mother_name'],
            $row['guardian_name'],
            $row['guardian_phone'],
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

// 3. Handle Import POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed with error code ' . $_FILES['csv_file']['error'];
    } else {
        $file_path = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            $error = 'Please upload a valid CSV file.';
        } else {
            // Load classes & sections mapping
            $stmt_cl = $db->prepare("SELECT id, name FROM classes WHERE (school_id = ?) AND is_active = 1");
            $stmt_cl->execute([$school_id]);
            $classes_db = $stmt_cl->fetchAll(PDO::FETCH_ASSOC);
            
            $classes_map = [];
            foreach ($classes_db as $c) {
                $classes_map[normalizeName($c['name'])] = (int)$c['id'];
            }
            
            // Map table column names to possible CSV headings (lowercase aliases)
            $headers_map = [
                'emis_id' => ['emis id', 'emis_id', 'emiscode', 'emis_code', 'emis', 'student emis id'],
                'emis_registration_number' => ['emis registration number','emis_registration_number','emis registration no','emis reg no','emis_reg_no'],
                'student_id' => ['student id', 'student_id', 'student ID', 'reg no', 'registration no', 'registration_no', 'reg_no'],
                'roll_number' => ['roll number', 'roll_number', 'roll no', 'roll_no', 'rollno', 'class roll', 'roll'],
                'exam_roll_number' => ['exam roll number', 'exam_roll_number', 'exam roll', 'exam_roll', 'symbol no', 'symbol_no', 'symbol number', 'symbol_number'],
                'first_name_en' => ['first name', 'first_name', 'first_name_en', 'name', 'full name', 'fullname', 'full_name', 'student name', 'student_name', 'student full name'],
                'last_name_en' => ['last name', 'last_name', 'last_name_en', 'surname', 'family name'],
                'first_name_np' => ['first name np', 'first_name_np', 'name np', 'full name np'],
                'last_name_np' => ['last name np', 'last_name_np'],
                'class' => ['class', 'grade', 'class_name', 'class name', 'class/grade'],
                'section' => ['section', 'sec', 'section_name', 'section name'],
                'gender' => ['gender', 'sex', 'gender (m/f)'],
                'dob_bs' => ['dob bs', 'dob_bs', 'date of birth bs', 'dob (bs)', 'birth date bs', 'birth_date_bs'],
                'dob_ad' => ['dob ad', 'dob_ad', 'date of birth ad', 'dob (ad)', 'birth date ad', 'dob', 'date of birth', 'birth_date_ad'],
                'blood_group' => ['blood group', 'blood_group', 'blood_grp', 'bg'],
                'father_name' => ['father name', 'father_name', 'father\'s name', 'father_full_name', 'fathers name'],
                'father_phone' => ['father phone', 'father_phone', 'father\'s phone', 'father contact', 'father mobile'],
                'mother_name' => ['mother name', 'mother_name', 'mother\'s name', 'mother_full_name', 'mothers name'],
                'guardian_name' => ['guardian name', 'guardian_name', 'guardian\'s name', 'guardian name', 'local guardian'],
                'guardian_phone' => ['guardian phone', 'guardian_phone', 'guardian contact', 'guardian mobile'],
                'status' => ['status', 'student status']
            ];
            
            if (($handle = fopen($file_path, 'r')) !== FALSE) {
                // Strip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
                    rewind($handle);
                }
                
                // Read headings
                $headings = fgetcsv($handle, 0, ',', '"', '');
                if (!$headings) {
                    $error = 'CSV file is empty.';
                } else {
                    // Match CSV headings to our schema keys
                    $matched_indices = [];
                    foreach ($headings as $index => $heading) {
                        $heading_clean = strtolower(trim($heading));
                        foreach ($headers_map as $key => $aliases) {
                            if (in_array($heading_clean, $aliases)) {
                                $matched_indices[$key] = $index;
                                break;
                            }
                        }
                    }
                    
                    if (!isset($matched_indices['first_name_en'])) {
                        $error = 'Could not find a student name or first name column in CSV file. Please align headers.';
                    } else {
                        // Prepare database statements
                        $stmt_ins = $db->prepare("
                            INSERT INTO students (
                                emis_id, student_id, academic_year_id, class_id, section_id, roll_number, exam_roll_number,
                                first_name_en, last_name_en, first_name_np, last_name_np, gender, dob_bs, dob_ad,
                                blood_group, father_name, father_phone, mother_name, guardian_name, guardian_phone,
                                status, school_id, emis_registration_number
                            ) VALUES (
                                :emis_id, :student_id, :academic_year_id, :class_id, :section_id, :roll_number, :exam_roll_number,
                                :first_name_en, :last_name_en, :first_name_np, :last_name_np, :gender, :dob_bs, :dob_ad,
                                :blood_group, :father_name, :father_phone, :mother_name, :guardian_name, :guardian_phone,
                                :status, :school_id, :emis_registration_number
                            )
                        ");
                        
                        $imported_count = 0;
                        $skipped_count = 0;
                        $line_num = 1;
                        
                        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== FALSE) {
                            $line_num++;
                            
                            // Map values according to indices
                            $get_val = function($key) use ($row, $matched_indices) {
                                if (isset($matched_indices[$key]) && isset($row[$matched_indices[$key]])) {
                                    return trim($row[$matched_indices[$key]]);
                                }
                                return null;
                            };
                            
                            $full_name_raw = $get_val('first_name_en');
                            if (empty($full_name_raw)) {
                                $skipped_count++;
                                continue;
                            }
                            
                            // Separate first & last name if full name was matched to first_name_en
                            $last_name_match = $get_val('last_name_en');
                            if ($last_name_match === null || $last_name_match === '') {
                                $parts = explode(' ', $full_name_raw);
                                if (count($parts) > 1) {
                                    $last_name_en = array_pop($parts);
                                    $first_name_en = implode(' ', $parts);
                                } else {
                                    $first_name_en = $full_name_raw;
                                    $last_name_en = ''; // Do not invent a family name.
                                }
                            } else {
                                $first_name_en = $full_name_raw;
                                $last_name_en = $last_name_match;
                            }
                            
                            // Class matching
                            $class_val = $get_val('class');
                            $class_id = null;
                            if (!empty($class_val)) {
                                $norm_class = normalizeName($class_val);
                                if (isset($classes_map[$norm_class])) {
                                    $class_id = $classes_map[$norm_class];
                                } else {
                                    // Try numeric matching (e.g. "1" matches "Class 1")
                                    foreach ($classes_db as $cdb) {
                                        if (normalizeName($cdb['name']) == 'class' . $norm_class || normalizeName($cdb['name']) == $norm_class) {
                                            $class_id = (int)$cdb['id'];
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            if (!$class_id) { $skipped_count++; continue; }

                            // Section matching
                            $sec_val = $get_val('section');
                            $section_id = null;
                            if ($class_id && !empty($sec_val)) {
                                $stmt_sec = $db->prepare("SELECT id FROM sections WHERE class_id = ? AND school_id = ? AND LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                                $stmt_sec->execute([$class_id, $school_id, $sec_val]);
                                $section_id = $stmt_sec->fetchColumn() ?: null;
                                
                                // Create section if not found
                                if (!$section_id && in_array(strtolower(trim($sec_val)), ['a', 'b', 'c', 'd'])) {
                                    $stmt_create_sec = $db->prepare("INSERT INTO sections (class_id, name, school_id) VALUES (?, ?, ?)");
                                    $stmt_create_sec->execute([$class_id, strtoupper(trim($sec_val)), $school_id]);
                                    $section_id = $db->lastInsertId();
                                }
                            }
                            
                            $gender=strtolower($get_val('gender')??'');
                            $gender=['m'=>'Male','male'=>'Male','f'=>'Female','female'=>'Female','o'=>'Other','other'=>'Other'][$gender]??null;
                            $status=strtolower($get_val('status')?:'active');
                            if(!$gender || !in_array($status,['active','inactive','transferred','dropout','passed'],true) || (!empty($sec_val) && !$section_id)) { $skipped_count++; continue; }
                            try { $dob_ad=importDateValue($get_val('dob_ad'));$emisRegistrationNumber=studentEmisRegistrationNumber($get_val('emis_registration_number')); }
                            catch(InvalidArgumentException $e) { $skipped_count++; continue; }
                            $dob_bs=$get_val('dob_bs')?:'';
                            
                            try {
                                $stmt_ins->execute([
                                    ':emis_id' => $get_val('emis_id'),
                                    ':student_id' => $get_val('student_id') ?: generateStudentId($class_id, $academic_year_id),
                                    ':academic_year_id' => $academic_year_id,
                                    ':class_id' => $class_id,
                                    ':section_id' => $section_id,
                                    ':roll_number' => $get_val('roll_number') !== null ? (int)$get_val('roll_number') : null,
                                    ':exam_roll_number' => $get_val('exam_roll_number'),
                                    ':first_name_en' => $first_name_en,
                                    ':last_name_en' => $last_name_en,
                                    ':first_name_np' => $get_val('first_name_np') ?: '',
                                    ':last_name_np' => $get_val('last_name_np') ?: '',
                                    ':gender' => $gender,
                                    ':dob_bs' => $dob_bs,
                                    ':dob_ad' => $dob_ad,
                                    ':blood_group' => $get_val('blood_group'),
                                    ':father_name' => $get_val('father_name'),
                                    ':father_phone' => $get_val('father_phone'),
                                    ':mother_name' => $get_val('mother_name'),
                                    ':guardian_name' => $get_val('guardian_name'),
                                    ':guardian_phone' => $get_val('guardian_phone'),
                                    ':status' => strtolower($status),
                                    ':school_id' => $school_id,
                                    ':emis_registration_number' => $emisRegistrationNumber
                                ]);
                                $imported_count++;
                            } catch (PDOException $e) {
                                $skipped_count++;
                                // Log or skip error
                            }
                        }
                        
                        $success = "CSV Import complete! Successfully registered {$imported_count} students. (Skipped/Failed: {$skipped_count})";
                        Auth::logActivity(Auth::getUserId(), 'EMIS Bulk Import Students', 'students');
                    }
                }
                fclose($handle);
            } else {
                $error = 'Failed to open uploaded CSV file.';
            }
        }
    }
}

$pageTitle = 'EMIS Student Import / Export';
$activePage = 'students';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-file-import text-primary"></i> EMIS Student Import & Export</h2>
    <a href="<?= APP_URL ?>/modules/students/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Students List
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show mb-4">
    <i class="fas fa-check-circle me-2"></i> <?= $success ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <!-- CSV Import Card -->
    <div class="col-md-7 mb-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><i class="fas fa-upload me-2"></i> Import Students from EMIS (CSV)</h5>
            </div>
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <p class="text-muted small">
                        Upload a CSV file containing your student data. The system scans the CSV headings dynamically and matches them to student profiles automatically.
                    </p>
                    
                    <div class="alert alert-info border-start border-info border-4">
                        <h6 class="fw-bold mb-1"><i class="fas fa-info-circle me-1"></i> Tip: Use matching header aliases</h6>
                        <p class="mb-0 small text-dark">
                            Make sure your CSV columns use names like: 
                            <strong>Full Name</strong> (or <em>First Name</em> and <em>Last Name</em>), 
                            <strong>Class Name</strong>, 
                            <strong>EMIS ID</strong>, 
                            <strong>EMIS Registration Number</strong>,
                            <strong>Gender</strong>, 
                            <strong>Roll Number</strong>. Older CSV files without the new registration-number column are still supported.
                        </p>
                    </div>
                </div>
                
                <form method="POST" action="" enctype="multipart/form-data" class="mt-4">
                    <div class="mb-3">
                        <label for="csv_file" class="form-label fw-bold">Select CSV File</label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="?action=template" class="btn btn-outline-secondary">
                            <i class="fas fa-download me-1"></i> Download Template CSV
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-file-import me-1"></i> Start Import Process
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Data Export Card -->
    <div class="col-md-5 mb-4">
        <div class="card h-100 shadow-sm border-0 bg-light">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0"><i class="fas fa-file-download me-2"></i> Backup & Export Data</h5>
            </div>
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <p class="text-muted">
                        Export your current database records for students in Excel/CSV format. This creates a fully matching backup of student profiles, registration IDs, roll numbers, and parent details.
                    </p>
                    
                    <div class="p-3 bg-white rounded border mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Current Active Year:</span>
                            <span class="fw-bold text-dark"><?= $academic_year_id ? 'BS Year Active' : 'None Set' ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary">Compatible Formats:</span>
                            <span class="fw-bold text-success">UTF-8 CSV (Excel)</span>
                        </div>
                    </div>
                </div>
                
                <a href="?action=export" class="btn btn-dark btn-lg w-100 py-3 mt-4">
                    <i class="fas fa-file-csv me-2"></i> Export All Students (CSV)
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
