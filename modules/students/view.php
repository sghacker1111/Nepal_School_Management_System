<?php
/**
 * View Student Details Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch student details
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("
    SELECT s.*, c.name as class_name, sc.name as section_name, y.year_bs, y.year_ad
    FROM students s
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN sections sc ON s.section_id = sc.id
    LEFT JOIN academic_years y ON s.academic_year_id = y.id
    WHERE s.id = ? AND s.school_id = ?
");
$stmt->execute([$id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    redirect(schoolRouteUrl('modules/students/index.php'), 'Student not found', 'error');
}

$pageTitle = 'Student Profile: ' . $student['first_name_en'] . ' ' . $student['last_name_en'];
$activePage = 'students';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2><i class="fas fa-user-graduate"></i> Student Profile</h2>
    <div>
        <a href="<?= schoolRouteUrl('modules/students/index.php') ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to List
        </a>
        <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
        <a href="<?= schoolRouteUrl('modules/students/edit.php') ?>?id=<?= $student['id'] ?>" class="btn btn-warning me-2">
            <i class="fas fa-edit"></i> Edit Profile
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-dark">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<div class="row">
    <!-- Left Column: Photo & Core Details -->
    <div class="col-md-4 mb-4">
        <div class="card text-center h-100">
            <div class="card-body py-5">
                <div class="mb-4">
                    <?php if ($student['photo']): ?>
                        <img src="<?= APP_URL ?>/uploads/students/<?= $student['photo'] ?>" 
                             alt="Student Photo" class="rounded-circle border border-4 border-light shadow-sm" width="150" height="150" style="object-fit: cover;">
                    <?php else: ?>
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center border shadow-sm" 
                             style="width: 150px; height: 150px;">
                            <i class="fas fa-user fa-5x text-muted"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <h4 class="mb-1"><?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?></h4>
                <p class="text-muted mb-3"><?= sanitize($student['first_name_np'] . ' ' . $student['last_name_np']) ?></p>
                
                <span class="badge bg-<?= $student['status'] == 'active' ? 'success' : 'secondary' ?> px-3 py-2 text-capitalize mb-4">
                    <?= $student['status'] ?>
                </span>
                
                <div class="border-top pt-4 text-start">
                    <div class="row mb-2">
                        <div class="col-6 text-muted">Student ID:</div>
                        <div class="col-6"><strong><?= sanitize($student['student_id']) ?></strong></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 text-muted">EMIS ID:</div>
                        <div class="col-6"><strong><?= sanitize($student['emis_id'] ?: '-') ?></strong></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 text-muted">EMIS Registration Number:</div>
                        <div class="col-6"><strong><?= sanitize($student['emis_registration_number']?:'-') ?></strong></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 text-muted">Class:</div>
                        <div class="col-6"><strong><?= sanitize($student['class_name']) ?></strong></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 text-muted">Section:</div>
                        <div class="col-6"><strong><?= sanitize($student['section_name'] ?: '-') ?></strong></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6 text-muted">Roll Number:</div>
                        <div class="col-6"><strong><?= sanitize($student['roll_number'] ?: '-') ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Tabs with details -->
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <ul class="nav nav-tabs card-header-tabs" id="profileTab" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" id="personal-tab" data-ui-toggle="tab" data-ui-target="#personal" type="button" role="tab">
                            Personal & Demographics
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="address-tab" data-ui-toggle="tab" data-ui-target="#address" type="button" role="tab">
                            Address & Guardian
                        </button>
                    </li>
                </ul>
            </div>
            
            <div class="card-body p-4">
                <div class="tab-content" id="profileTabContent">
                    <!-- Personal Info Tab -->
                    <div class="tab-pane fade show active" id="personal" role="tabpanel">
                        <h5 class="text-primary mb-4 border-bottom pb-2">Personal Information</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <span class="text-muted d-block">Gender</span>
                                <strong><?= sanitize($student['gender']) ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Blood Group</span>
                                <strong><?= sanitize($student['blood_group'] ?: 'Not Specified') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Date of Birth (AD)</span>
                                <strong><?= formatDate($student['dob_ad']) ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Date of Birth (BS)</span>
                                <strong><?= sanitize($student['dob_bs'] ?: '-') ?></strong>
                            </div>
                        </div>

                        <h5 class="text-primary mb-4 border-bottom pb-2">Demographic Profile (EMIS Standards)</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <span class="text-muted d-block">Caste / Ethnicity</span>
                                <strong><?= sanitize($student['caste_ethnicity'] ?: '-') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Religion</span>
                                <strong><?= sanitize($student['religion'] ?: '-') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Disability Status</span>
                                <strong>
                                    <?php if ($student['disability_status']): ?>
                                        <span class="text-danger"><i class="fas fa-wheelchair"></i> Yes</span>
                                    <?php else: ?>
                                        <span class="text-success">No</span>
                                    <?php endif; ?>
                                </strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Scholarship Category</span>
                                <strong><?= sanitize($student['scholarship_type'] ?: 'None') ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Address & Guardian Tab -->
                    <div class="tab-pane fade" id="address" role="tabpanel">
                        <h5 class="text-primary mb-4 border-bottom pb-2">Permanent Address</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <span class="text-muted d-block">Province</span>
                                <strong><?= sanitize($student['perm_province'] ?: '-') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">District</span>
                                <strong><?= sanitize($student['perm_district'] ?: '-') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Municipality / Rural Municipality</span>
                                <strong><?= sanitize($student['perm_municipality'] ?: '-') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Ward Number & Tole</span>
                                <strong>Ward <?= sanitize($student['perm_ward_no'] ?: '-') ?>, <?= sanitize($student['perm_tole'] ?: '-') ?></strong>
                            </div>
                        </div>

                        <h5 class="text-primary mb-4 border-bottom pb-2">Parent & Guardian Information</h5>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <span class="text-muted d-block">Father's Name</span>
                                <strong><?= sanitize($student['father_name'] ?: '-') ?></strong>
                                <span class="text-muted d-block mt-1">Phone: <?= sanitize($student['father_phone'] ?: '-') ?></span>
                            </div>
                            <div class="col-md-6">
                                <span class="text-muted d-block">Mother's Name</span>
                                <strong><?= sanitize($student['mother_name'] ?: '-') ?></strong>
                                <span class="text-muted d-block mt-1">Phone: <?= sanitize($student['mother_phone'] ?: '-') ?></span>
                            </div>
                            <div class="col-md-12 border-top pt-3">
                                <span class="text-muted d-block">Local Guardian Name</span>
                                <strong><?= sanitize($student['guardian_name'] ?: '-') ?></strong>
                                <span class="text-muted d-block mt-1">Guardian Phone: <?= sanitize($student['guardian_phone'] ?: '-') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
