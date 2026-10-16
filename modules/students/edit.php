<?php
/**
 * Edit Student Profile Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$errors = [];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch student details
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND school_id = ?");
$stmt->execute([$id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    redirect(schoolRouteUrl('modules/students/index.php'), 'Student not found', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try{$emisRegistrationNumber=studentEmisRegistrationNumber($_POST['emis_registration_number']??$student['emis_registration_number']);}catch(InvalidArgumentException $e){$errors[]=$e->getMessage();}
    // Validate required fields
    if (empty($_POST['first_name_en'])) {
        $errors[] = "First name (English) is required";
    }
    
    if (empty($_POST['class_id'])) {
        $errors[] = "Class is required";
    }
    $assessment = $db->prepare('SELECT COUNT(*) FROM marks WHERE student_id=?');
    $assessment->execute([$id]);
    if ((int)$assessment->fetchColumn()>0) {
        foreach (['class_id','optional_subject_i','optional_subject_ii','optional_subject_iii'] as $field) {
            if ((int)($_POST[$field]??0)!==(int)($student[$field]??0)) {
                $errors[] = 'Class and elective enrollment cannot change after marks have been recorded. Keep this enrollment for historical results; create a new enrollment for a new academic year.';
                break;
            }
        }
    }
    
    if (empty($errors)) {
        // Handle photo upload
        $photoName = $student['photo']; // Keep old photo by default
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $newPhotoName = uploadFile($_FILES['photo'], 'students');
            if ($newPhotoName === false) {
                $errors[] = "Photo upload failed. Check file size and type.";
            } else {
                $photoName = $newPhotoName;
            }
        }
        
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("UPDATE students SET 
                    emis_id = ?, class_id = ?, section_id = ?, roll_number = ?, exam_roll_number = ?,
                    first_name_en = ?, last_name_en = ?, first_name_np = ?, last_name_np = ?, gender = ?, dob_bs = ?, dob_ad = ?, blood_group = ?, photo = ?,
                    caste_ethnicity = ?, religion = ?, disability_status = ?, scholarship_type = ?,
                    perm_province = ?, perm_district = ?, perm_municipality = ?, perm_ward_no = ?, perm_tole = ?,
                    father_name = ?, father_phone = ?, mother_name = ?, mother_phone = ?, guardian_name = ?, guardian_phone = ?,
                    status = ?, optional_subject_i = ?, optional_subject_ii = ?, optional_subject_iii = ?, emis_registration_number = ?
                    WHERE id = ?");
                
                $stmt->execute([
                    sanitize($_POST['emis_id'] ?? ''),
                    $_POST['class_id'],
                    ($_POST['section_id'] ?? '') ?: null,
                    ($_POST['roll_number'] ?? '') ?: null,
                    $student['exam_roll_number'] ?? null,
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
                    $_POST['status'],
                    ($_POST['optional_subject_i'] ?? '') ?: null,
                    ($_POST['optional_subject_ii'] ?? '') ?: null,
                    ($_POST['optional_subject_iii'] ?? '') ?: null,
                    $emisRegistrationNumber,
                    $id
                ]);
                
                Auth::logActivity(Auth::getUserId(), 'Edit Student', 'students', $id);
                redirect(schoolRouteUrl('modules/students/view.php') . '?id=' . $id, 'Student profile updated successfully!');
                
            } catch (PDOException $e) {
                $errors[] = schoolPublicError($e);
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

$pageTitle = 'Edit Student: ' . $student['first_name_en'];
$activePage = 'students';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-user-edit"></i> Edit Student Profile</h2>
    <a href="<?= schoolRouteUrl('modules/students/view.php') ?>?id=<?= $student['id'] ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Cancel and Back
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
                    <div class="mb-2">
                        <?php if ($student['photo']): ?>
                            <img src="<?= APP_URL ?>/uploads/students/<?= $student['photo'] ?>" class="rounded-circle border" width="60" height="60" style="object-fit: cover;">
                        <?php endif; ?>
                    </div>
                    <input type="file" name="photo" class="form-control" accept="image/*">
                    <small class="text-muted">Upload to replace current photo</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">First Name (English) <span class="text-danger">*</span></label>
                    <input type="text" name="first_name_en" class="form-control" required value="<?= sanitize($student['first_name_en']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last Name (English)</label>
                    <input type="text" name="last_name_en" class="form-control" value="<?= sanitize($student['last_name_en'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">First Name (Nepali)</label>
                    <input type="text" name="first_name_np" class="form-control" value="<?= sanitize($student['first_name_np'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Last Name (Nepali)</label>
                    <input type="text" name="last_name_np" class="form-control" value="<?= sanitize($student['last_name_np'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select name="gender" class="form-select" required>
                        <option value="Male" <?= $student['gender'] == 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $student['gender'] == 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Other" <?= $student['gender'] == 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth (AD)</label>
                    <input type="date" name="dob_ad" class="form-control" value="<?= $student['dob_ad'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date of Birth (BS)</label>
                    <input type="text" name="dob_bs" class="form-control" placeholder="YYYY-MM-DD" value="<?= sanitize($student['dob_bs'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="">Select</option>
                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'] as $bg): ?>
                        <option value="<?= $bg ?>" <?= $student['blood_group'] == $bg ? 'selected' : '' ?>><?= $bg ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" required>
                        <option value="active" <?= $student['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="transferred" <?= $student['status'] == 'transferred' ? 'selected' : '' ?>>Transferred</option>
                        <option value="dropout" <?= $student['status'] == 'dropout' ? 'selected' : '' ?>>Dropout</option>
                        <option value="passed" <?= $student['status'] == 'passed' ? 'selected' : '' ?>>Passed Out</option>
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
                    <input type="text" name="emis_id" class="form-control" value="<?= sanitize($student['emis_id'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label for="emis_registration_number" class="form-label">EMIS Registration Number</label>
                    <input type="text" id="emis_registration_number" name="emis_registration_number" class="form-control" maxlength="100" value="<?= sanitize($_POST['emis_registration_number']??$student['emis_registration_number']??'') ?>">
                    <div class="form-text">Separate from EMIS ID; existing records may be completed later.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Class <span class="text-danger">*</span></label>
                    <select name="class_id" class="form-select" required>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?= $class['id'] ?>" <?= $student['class_id'] == $class['id'] ? 'selected' : '' ?>>
                            <?= sanitize($class['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Section</label>
                    <select name="section_id" class="form-select">
                        <option value="">Select Section</option>
                        <?php foreach ($sections as $section): ?>
                        <option value="<?= $section['id'] ?>" <?= $student['section_id'] == $section['id'] ? 'selected' : '' ?>>
                            <?= sanitize($section['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Roll Number</label>
                    <input type="number" name="roll_number" class="form-control" value="<?= sanitize($student['roll_number'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Exam Symbol No.</label>
                    <p class="form-text">Assigned separately for each examination in Exam → Manage Exam Symbol Numbers.</p>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Caste/Ethnicity</label>
                    <input type="text" name="caste_ethnicity" class="form-control" value="<?= sanitize($student['caste_ethnicity'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Religion</label>
                    <input type="text" name="religion" class="form-control" value="<?= sanitize($student['religion'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Scholarship Type</label>
                    <select name="scholarship_type" class="form-select">
                        <option value="">None</option>
                        <option value="Full Scholarship" <?= $student['scholarship_type'] == 'Full Scholarship' ? 'selected' : '' ?>>Full Scholarship</option>
                        <option value="Partial Scholarship" <?= $student['scholarship_type'] == 'Partial Scholarship' ? 'selected' : '' ?>>Partial Scholarship</option>
                        <option value="Free Education" <?= $student['scholarship_type'] == 'Free Education' ? 'selected' : '' ?>>Free Education</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="disability_status" id="disabilityStatus" <?= $student['disability_status'] == 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="disabilityStatus">Disability Status</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Optional Subject I</label>
                    <select name="optional_subject_i" class="form-select">
                        <option value="">Select Optional Subject I</option>
                        <?php foreach ($optional_subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $student['optional_subject_i'] == $sub['id'] ? 'selected' : '' ?>><?= sanitize($sub['name_en']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Optional Subject II</label>
                    <select name="optional_subject_ii" class="form-select">
                        <option value="">Select Optional Subject II</option>
                        <?php foreach ($optional_subjects as $sub): ?>
                        <option value="<?= $sub['id'] ?>" <?= $student['optional_subject_ii'] == $sub['id'] ? 'selected' : '' ?>><?= sanitize($sub['name_en']) ?></option>
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
                    <input type="text" name="perm_province" class="form-control" value="<?= sanitize($student['perm_province'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">District</label>
                    <input type="text" name="perm_district" class="form-control" value="<?= sanitize($student['perm_district'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Municipality/Rural Municipality</label>
                    <input type="text" name="perm_municipality" class="form-control" value="<?= sanitize($student['perm_municipality'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Ward No.</label>
                    <input type="text" name="perm_ward_no" class="form-control" value="<?= sanitize($student['perm_ward_no'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tole/Street</label>
                    <input type="text" name="perm_tole" class="form-control" value="<?= sanitize($student['perm_tole'] ?? '') ?>">
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
                    <input type="text" name="father_name" class="form-control" value="<?= sanitize($student['father_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Father's Phone</label>
                    <input type="text" name="father_phone" class="form-control" value="<?= sanitize($student['father_phone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mother's Name</label>
                    <input type="text" name="mother_name" class="form-control" value="<?= sanitize($student['mother_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mother's Phone</label>
                    <input type="text" name="mother_phone" class="form-control" value="<?= sanitize($student['mother_phone'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian's Name</label>
                    <input type="text" name="guardian_name" class="form-control" value="<?= sanitize($student['guardian_name'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Guardian's Phone</label>
                    <input type="text" name="guardian_phone" class="form-control" value="<?= sanitize($student['guardian_phone'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>
    
    <div class="text-end">
        <button type="submit" class="btn btn-primary btn-lg px-5">
            <i class="fas fa-save"></i> Save Changes
        </button>
    </div>
</form>

<?php
include __DIR__ . '/../../includes/enrollment_selectors.php';
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
