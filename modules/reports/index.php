<?php
/**
 * Reports Module - General Statistic Reports
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal']);
Auth::requireAccess('reports');

$db = getDB();
$school_id = Auth::getSchoolId();

// General Stats
$stmt = $db->prepare("SELECT COUNT(*) FROM students WHERE school_id = ? AND status = 'active'");
$stmt->execute([$school_id]);
$students_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE school_id = ? AND role = 'teacher' AND status = 1");
$stmt->execute([$school_id]);
$teachers_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE school_id = ? AND is_active = 1");
$stmt->execute([$school_id]);
$classes_count = $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM sections WHERE school_id = ?");
$stmt->execute([$school_id]);
$sections_count = $stmt->fetchColumn();

// Fetch Student Counts Grouped by Class & Gender
$stmt = $db->prepare("
    SELECT c.name as class_name, c.level,
           COUNT(CASE WHEN s.gender = 'Male' THEN 1 END) as male_count,
           COUNT(CASE WHEN s.gender = 'Female' THEN 1 END) as female_count,
           COUNT(CASE WHEN s.gender = 'Other' THEN 1 END) as other_count,
           COUNT(s.id) as total_count
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.id AND s.status = 'active' AND s.school_id = ?
    WHERE c.school_id = ? AND c.is_active = 1
    GROUP BY c.id
    ORDER BY c.id ASC
");
$stmt->execute([$school_id, $school_id]);
$class_reports = $stmt->fetchAll();

// Calculate total sums for footer
$total_males = 0;
$total_females = 0;
$total_others = 0;
$total_students = 0;
foreach ($class_reports as $row) {
    $total_males += $row['male_count'];
    $total_females += $row['female_count'];
    $total_others += $row['other_count'];
    $total_students += $row['total_count'];
}

$pageTitle = 'Statistical Reports';
$activePage = 'reports';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-chart-bar text-primary"></i> School Statistical Reports</h2>
    <button data-erp-click="reports-index-action-0" data-erp-click-args="<?= schoolPageActionArgs("reports-index",[]) ?>" class="btn btn-secondary no-print">
        <i class="fas fa-print"></i> Print Report Sheet
    </button>
</div>

<!-- Core Counter Grid -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card bg-primary bg-opacity-10 border-0 h-100 text-center py-4">
            <div class="card-body">
                <i class="fas fa-user-graduate fa-3x text-primary mb-3"></i>
                <h6 class="text-muted text-uppercase mb-1">Active Students</h6>
                <h2 class="mb-0 fw-bold"><?= number_format($students_count) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card bg-success bg-opacity-10 border-0 h-100 text-center py-4">
            <div class="card-body">
                <i class="fas fa-chalkboard-teacher fa-3x text-success mb-3"></i>
                <h6 class="text-muted text-uppercase mb-1">Active Teachers</h6>
                <h2 class="mb-0 fw-bold"><?= number_format($teachers_count) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card bg-warning bg-opacity-10 border-0 h-100 text-center py-4">
            <div class="card-body">
                <i class="fas fa-school fa-3x text-warning mb-3"></i>
                <h6 class="text-muted text-uppercase mb-1">Total Classes</h6>
                <h2 class="mb-0 fw-bold"><?= number_format($classes_count) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card bg-danger bg-opacity-10 border-0 h-100 text-center py-4">
            <div class="card-body">
                <i class="fas fa-columns fa-3x text-danger mb-3"></i>
                <h6 class="text-muted text-uppercase mb-1">Total Sections</h6>
                <h2 class="mb-0 fw-bold"><?= number_format($sections_count) ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Student Demographics Report -->
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-primary"><i class="fas fa-users"></i> Enrollment Report by Class & Gender</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Class Name</th>
                                <th>Education Level</th>
                                <th class="text-center text-primary">Boys (Male)</th>
                                <th class="text-center text-danger">Girls (Female)</th>
                                <th class="text-center text-secondary">Other</th>
                                <th class="text-center font-monospace table-primary">Total Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($class_reports) > 0): ?>
                                <?php foreach ($class_reports as $row): ?>
                                <tr>
                                    <td><strong><?= sanitize($row['class_name']) ?></strong></td>
                                    <td><span class="badge bg-light text-dark text-capitalize"><?= $row['level'] ?></span></td>
                                    <td class="text-center font-monospace text-primary"><?= $row['male_count'] ?></td>
                                    <td class="text-center font-monospace text-danger"><?= $row['female_count'] ?></td>
                                    <td class="text-center font-monospace text-secondary"><?= $row['other_count'] ?></td>
                                    <td class="text-center font-monospace table-primary fw-bold"><?= $row['total_count'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Grand Total Summary Footer -->
                                <tr class="table-dark fw-bold">
                                    <td colspan="2">GRAND TOTAL:</td>
                                    <td class="text-center font-monospace"><?= $total_males ?></td>
                                    <td class="text-center font-monospace"><?= $total_females ?></td>
                                    <td class="text-center font-monospace"><?= $total_others ?></td>
                                    <td class="text-center font-monospace"><?= $total_students ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No data available.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
