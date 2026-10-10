<?php
/**
 * Add New Student Form
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$errors = [];

// Get active academic year
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$school_id]);
$academicYear = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try{$emisRegistrationNumber=studentEmisRegistrationNumber($_POST['emis_registration_number']??'',true);}catch(InvalidArgumentException $e){$errors[]=$e->getMessage();}
    // Validate required fields
    if (empty($_POST['first_name_en'])) {
        $errors[] = "First name (English) is required";
    }
    
    if (empty($_POST['class_id'])) {
        $errors[] = "Class is required";
    }
    
    if (empty($academicYear)) {
        $errors[] = "No active academic year found. Please create one first.";
    }
    
    if (empty($errors)) {
        // Handle photo upload
        $photoName = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $photoName = uploadFile($_FILES['photo'], 'students');
            if ($photoName === false) {
                $errors[] = "Photo upload failed. Check file size and type.";
            }
        }
        
        if (empty($errors)) {
            try {
                $studentId = generateStudentId($_POST['class_id'], $academicYear['id']);
                
                $stmt = $db->prepare("INSERT INTO students (
                    student_id, emis_id, academic_year_id, class_id, section_id, roll_number, exam_roll_number,
                    first_name_en, last_name_en, first_name_np, last_name_np, gender, dob_bs, dob_ad, blood_group, photo,
                    caste_ethnicity, religion, disability_status, scholarship_type,
                    perm_province, perm_district, perm_municipality, perm_ward_no, perm_tole,
                    father_name, father_phone, mother_name, mother_phone, guardian_name, guardian_phone,
                    status, school_id, optional_subject_i, optional_subject_ii, optional_subject_iii, emis_registration_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $stmt->execute([
                    $studentId,
                    sanitize($_POST['emis_id'] ?? ''),
                    $academicYear['id'],
                    $_POST['class_id'],
                    ($_POST['section_id'] ?? '') ?: null,
                    ($_POST['roll_number'] ?? '') ?: null,
                    null,
                    sanitize($_POST['first_name_en']),
                    sanitize($_POST['last_name_en'] ?? ''),
                    sanitize($_POST['first_name_np'] ?? ''),
                    sanitize($_POST['last_name_np'] ?? ''),
                    $_POST['gender'],
                    sanitize($_POST['dob_bs'] ?? ''),
                    ($_POST['dob_ad'] ?? '') ?: null,
                    sanitize($_POST['blood_group'] ?? ''),
                    $photoName,
                    sanitize($_POST['caste_ethnicity'] ?? ''),
                    sanitize($_POST['religion'] ?? ''),
                    isset($_POST['disability_status']) ? 1 : 0,
                    sanitize($_POST['scholarship_type'] ?? ''),
                    sanitize($_POST['perm_province'] ?? ''),
                    sanitize($_POST['perm_district'] ?? ''),
                    sanitize($_POST['perm_municipality'] ?? ''),
                    sanitize($_POST['perm_ward_no'] ?? ''),
                    sanitize($_POST['perm_tole'] ?? ''),
                    sanitize($_POST['father_name'] ?? ''),
                    sanitize($_POST['father_phone'] ?? ''),
                    sanitize($_POST['mother_name'] ?? ''),
                    sanitize($_POST['mother_phone'] ?? ''),
                    sanitize($_POST['guardian_name'] ?? ''),
                    sanitize($_POST['guardian_phone'] ?? ''),
                    'active',
                    $school_id,
                    ($_POST['optional_subject_i'] ?? '') ?: null,
                    ($_POST['optional_subject_ii'] ?? '') ?: null,
                    ($_POST['optional_subject_iii'] ?? '') ?: null,
                    $emisRegistrationNumber
                ]);
                
                $studentId_db = $db->lastInsertId();
                Auth::logActivity(Auth::getUserId(), 'Add Student', 'students', $studentId_db);
                
                redirect(APP_URL . '/modules/students/view.php?id=' . $studentId_db, 'Student added successfully!');
                
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get classes and sections
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM sections WHERE school_id = ? ORDER BY name");
$stmt->execute([$school_id]);
$sections = $stmt->fetchAll();

$stmt = $db->prepare("SELECT * FROM subjects WHERE (school_id = ?) AND (is_hidden = 0 OR is_hidden IS NULL) AND type = 'optional' ORDER BY name_en");
$stmt->execute([$school_id]);
$optional_subjects = $stmt->fetchAll();

$pageTitle = 'Add New Student';
$activePage = 'students';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-user-plus"></i> Add New Student</h2>
    <a href="<?= APP_URL ?>/modules/students/index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?= sanitize($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="needs-validation" novalidate>
    <!-- Basic Information -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-user"></i> Basic Information</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                    <small class="text-muted">Max 5MB (JPG, PNG)</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">First Name (English) <span class="text-danger">*</span></label>
                    <input type="text" name="first_name_en" class="form-control" required value="<?= sanitize($_POST['first_name_en'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last Name (English)</label>
                    <input type="text" name="last_name_en" class="form-control" value="<?= sanitize($_POST['last_name_en'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">First Name (Nepali)</label>
                    <input type="text" name="first_name_np" class="form-control" value="<?= sanitize($_POST['first_name_np'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last Name (Nepali)</label>
                    <input type="text" name="last_name_np" class="form-control" value="<?= sanitize($_POST['last_name_np'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select name="gender" class="form-select" required>
                        <option value="">Select</option>
                        <option value="Male" <?= ($_POST['gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= ($_POST['gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Other" <?= ($_POST['gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth (AD)</label>
                    <input type="date" name="dob_ad" class="form-control" value="<?= sanitize($_POST['dob_ad'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth (BS)</label>
                    <input type="text" name="dob_bs" class="form-control" placeholder="YYYY-MM-DD" value="<?= sanitize($_POST['dob_bs'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="">Select</option>
                        <option value="A+" <?= ($_POST['blood_group'] ?? '') == 'A+' ? 'selected' : '' ?>>A+</option>
                        <option value="A-" <?= ($_POST['blood_group'] ?? '') == 'A-' ? 'selected' : '' ?>>A-</option>
                        <option value="B+" <?= ($_POST['blood_group'] ?? '') == 'B+' ? 'selected' : '' ?>>B+</option>
                        <option value="B-" <?= ($_POST['blood_group'] ?? '') == 'B-' ? 'selected' : '' ?>>B-</option>
                        <option value="O+" <?= ($_POST['blood_group'] ?? '') == 'O+' ? 'selected' : '' ?>>O+</option>
                        <option value="O-" <?= ($_POST['blood_group'] ?? '') == 'O-' ? 'selected' : '' ?>>O-</option>
                        <option value="AB+" <?= ($_POST['blood_group'] ?? '') == 'AB+' ? 'selected' : '' ?>>AB+</option>
                        <option value="AB-" <?= ($_POST['blood_group'] ?? '') == 'AB-' ? 'selected' : '' ?>>AB-</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Academic Information -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Academic Information</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">EMIS ID</label>
                    <input type="text" name="emis_id" class="form-control" placeholder="If available" value="<?= sanitize($_POST['emis_id'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="emis_registration_number" class="form-label">EMIS Registration Number <span class="text-danger">*</span></label>
                    <input type="text" id="emis_registration_number" name="emis_registration_number" class="form-control" maxlength="100" required value="<?= sanitize($_POST['emis_registration_number']??'') ?>" placeholder="Enter registration number">
                    <div class="form-text">Separate from EMIS ID. Leading zeros are retained.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Class <span class="text-danger">*</span></label>
                    <select name="class_id" id="classSelect" class="form-select" required onchange="loadSections()">
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= ($_POST['class_id'] ?? '') == $class['id'] ? 'selected' : '' ?>>
                            <?= sanitize($class['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Section</label>
                    <select name="section_id" id="sectionSelect" class="form-select">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $section): ?>
                        <option value="<?= $section['id'] ?>" <?= ($_POST['section_id'] ?? '') == $section['id'] ? 'selected' : '' ?>>
                            <?= sanitize($section['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Roll Number</label>
                    <input type="number" name="roll_number" class="form-control" value="<?= sanitize($_POST['roll_number'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Exam Symbol No.</label>
                    <p class="form-text">Assigned separately for each examination in Exam → Manage Exam Symbol Numbers.</p>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Caste/Ethnicity</label>
                    <input type="text" name="caste_ethnicity" class="form-control" value="<?= sanitize($_POST['caste_ethnicity'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Religion</label>
                    <input type="text" name="religion" class="form-control" value="<?= sanitize($_POST['religion'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Scholarship Type</label>
                    <select name="scholarship_type" class="form-select">
                        <option value="">None</option>
                        <option value="Full Scholarship" <?= ($_POST['scholarship_type'] ?? '') == 'Full Scholarship' ? 'selected' : '' ?>>Full Scholarship</option>
                        <option value="Partial Scholarship" <?= ($_POST['scholarship_type'] ?? '') == 'Partial Scholarship' ? 'selected' : '' ?>>Partial Scholarship</option>
                        <option value="Free Education" <?= ($_POST['scholarship_type'] ?? '') == 'Free Education' ? 'selected' : '' ?>>Free Education</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="disability_status" id="disabilityStatus" <?= isset($_POST['disability_status']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="disabilityStatus">Disability Status</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Optional Subject I</label>
                    <select name="optional_subject_i" class="form-select">
                        <option value="">Select Optional Subject I</option>
                        <?php foreach ($optional_subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= ($_POST['optional_subject_i'] ?? '') == $sub['id'] ? 'selected' : '' ?>><?= sanitize($sub['name_en']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Optional Subject II</label>
                    <select name="optional_subject_ii" class="form-select">
                        <option value="">Select Optional Subject II</option>
                        <?php foreach ($optional_subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= ($_POST['optional_subject_ii'] ?? '') == $sub['id'] ? 'selected' : '' ?>><?= sanitize($sub['name_en']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card mb-4 optional-third"><div class="card-body"><label class="form-label" for="optionalThird">Optional Subject III — Grades 11–12</label><select id="optionalThird" name="optional_subject_iii" class="form-select"><option value="">No optional subject</option><?php foreach($optional_subjects as $sub): ?><option value="<?= $sub['id'] ?>" <?= ($_POST['optional_subject_iii']??$student['optional_subject_iii']??'')==$sub['id']?'selected':'' ?>><?= sanitize($sub['name_en']) ?></option><?php endforeach; ?></select><small class="text-muted">Confirm the allowed subject group combination for your school's approved curriculum.</small></div></div>
    <!-- Address Information -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-map-marker-alt"></i> Permanent Address</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Province</label>
                    <input type="text" name="perm_province" class="form-control" value="<?= sanitize($_POST['perm_province'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">District</label>
                    <input type="text" name="perm_district" class="form-control" value="<?= sanitize($_POST['perm_district'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Municipality/Rural Municipality</label>
                    <input type="text" name="perm_municipality" class="form-control" value="<?= sanitize($_POST['perm_municipality'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ward No.</label>
                    <input type="text" name="perm_ward_no" class="form-control" value="<?= sanitize($_POST['perm_ward_no'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tole/Street</label>
                    <input type="text" name="perm_tole" class="form-control" value="<?= sanitize($_POST['perm_tole'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>
    
    <!-- Parent/Guardian Information -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-users"></i> Parent/Guardian Information</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Father's Name</label>
                    <input type="text" name="father_name" class="form-control" value="<?= sanitize($_POST['father_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Father's Phone</label>
                    <input type="text" name="father_phone" class="form-control" value="<?= sanitize($_POST['father_phone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mother's Name</label>
                    <input type="text" name="mother_name" class="form-control" value="<?= sanitize($_POST['mother_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mother's Phone</label>
                    <input type="text" name="mother_phone" class="form-control" value="<?= sanitize($_POST['mother_phone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian's Name</label>
                    <input type="text" name="guardian_name" class="form-control" value="<?= sanitize($_POST['guardian_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian's Phone</label>
                    <input type="text" name="guardian_phone" class="form-control" value="<?= sanitize($_POST['guardian_phone'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>
    
    <div class="text-end">
        <button type="reset" class="btn btn-secondary me-2">
            <i class="fas fa-redo"></i> Reset
        </button>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Student
        </button>
    </div>
</form>



<?php
include __DIR__ . '/../../includes/enrollment_selectors.php';
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
