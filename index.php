<?php
/**
 * Dashboard - Main landing page after login
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireLogin();
if(Auth::getRole()==='student'){
    $studentPage=Auth::hasAccess('learning')?'/modules/learning/index.php':(Auth::hasAccess('exam_questions')?'/modules/learning/exam_questions.php':null);
    if($studentPage===null)denySchoolRequest('No student learning section is enabled for this school. Contact your school administrator.');
    header('Location: '.APP_URL.$studentPage);exit;
}

if (Auth::getRole() === 'super_admin' && !Auth::isReadOnly()) {
    header("Location: " . APP_URL . "/modules/super_admin/index.php");
    exit;
}

$db = getDB();
$userRole = Auth::getRole();

// Get statistics based on role
$stats = [];
$school_id = Auth::getSchoolId();

if (Auth::hasRole(['school_admin', 'principal', 'super_admin']) && $school_id) {
    // Total students
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE school_id = ? AND status = 'active'");
    $stmt->execute([$school_id]);
    $stats['students'] = $stmt->fetch()['count'];
    
    // Total teachers
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ? AND role = 'teacher' AND status = 1");
    $stmt->execute([$school_id]);
    $stats['teachers'] = $stmt->fetch()['count'];
    
    // Total classes
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM classes WHERE (school_id = ?) AND is_active = 1");
    $stmt->execute([$school_id]);
    $stats['classes'] = $stmt->fetch()['count'];
    
    // Today's attendance percentage
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT 
        COUNT(CASE WHEN a.status = 'P' THEN 1 END) as present,
        COUNT(*) as total
        FROM attendances a 
        JOIN students s ON a.student_id = s.id
        WHERE s.school_id = ? AND a.date_ad = ?");
    $stmt->execute([$school_id, $today]);
    $attendance = $stmt->fetch();
    $stats['attendance_percent'] = $attendance['total'] > 0 
        ? round(($attendance['present'] / $attendance['total']) * 100, 2) 
        : 0;
}

// Get recent exams
$recentExams = [];
if ($school_id) {
    $stmt = $db->prepare("SELECT * FROM exams WHERE school_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$school_id]);
    $recentExams = $stmt->fetchAll();
}

$pageTitle = 'Dashboard';
$activePage = 'dashboard';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-home"></i> Dashboard</h2>
    <span class="text-muted"><?= date('l, F d, Y') ?></span>
</div>

<!-- Statistics Cards -->
<?php if (Auth::hasRole(['super_admin', 'school_admin', 'principal'])): ?>
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Students</h6>
                        <h3 class="mb-0"><?= number_format($stats['students'] ?? 0) ?></h3>
                    </div>
                    <div class="text-primary">
                        <i class="fas fa-user-graduate fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Teachers</h6>
                        <h3 class="mb-0"><?= number_format($stats['teachers'] ?? 0) ?></h3>
                    </div>
                    <div class="text-success">
                        <i class="fas fa-chalkboard-teacher fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Classes</h6>
                        <h3 class="mb-0"><?= number_format($stats['classes'] ?? 0) ?></h3>
                    </div>
                    <div class="text-warning">
                        <i class="fas fa-school fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Attendance Today</h6>
                        <h3 class="mb-0"><?= $stats['attendance_percent'] ?? 0 ?>%</h3>
                    </div>
                    <div class="text-danger">
                        <i class="fas fa-calendar-check fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="card mb-4">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/students/add.php" class="btn btn-outline-primary w-100">
                    <i class="fas fa-user-plus"></i><br>Add Student
                </a>
            </div>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/exams/add.php" class="btn btn-outline-success w-100">
                    <i class="fas fa-file-alt"></i><br>Create Exam
                </a>
            </div>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/attendance/index.php" class="btn btn-outline-info w-100">
                    <i class="fas fa-calendar-check"></i><br>Take Attendance
                </a>
            </div>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/results/marks_entry.php" class="btn btn-outline-warning w-100">
                    <i class="fas fa-edit"></i><br>Enter Marks
                </a>
            </div>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/reports/index.php" class="btn btn-outline-danger w-100">
                    <i class="fas fa-chart-bar"></i><br>View Reports
                </a>
            </div>
            <div class="col-md-2">
                <a href="<?= APP_URL ?>/modules/learning/index.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-book-open"></i><br>Learning Hub
                </a>
            </div>
            <?php else: ?>
            <div class="col-md-3">
                <a href="<?= APP_URL ?>/modules/attendance/index.php" class="btn btn-outline-info w-100">
                    <i class="fas fa-calendar-check"></i><br>Attendance
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= APP_URL ?>/modules/results/index.php" class="btn btn-outline-warning w-100">
                    <i class="fas fa-poll"></i><br>Results
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= APP_URL ?>/modules/learning/index.php" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-laptop-code"></i><br>Learning
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= APP_URL ?>/modules/reports/index.php" class="btn btn-outline-danger w-100">
                    <i class="fas fa-chart-bar"></i><br>Reports
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Exams -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0"><i class="fas fa-clock"></i> Recent Exams</h5>
    </div>
    <div class="card-body">
        <?php if (count($recentExams) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Exam Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentExams as $exam): ?>
                    <tr>
                        <td><?= sanitize($exam['name']) ?></td>
                        <td><span class="badge bg-info"><?= ucfirst($exam['type']) ?></span></td>
                        <td>
                            <?php if ($exam['is_published']): ?>
                                <span class="badge bg-success">Published</span>
                            <?php elseif ($exam['is_locked']): ?>
                                <span class="badge bg-warning">Locked</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Draft</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/modules/exams/view.php?id=<?= $exam['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center mb-0">No exams found.</p>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/header.php';
?>
