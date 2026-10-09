<?php
/**
 * Exam Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();

require_once __DIR__ . '/../../includes/exam_service.php';

// Handle status updates
foreach (['publish','lock','reopen','delete'] as $action) {
    if (!isset($_GET[$action]) || !Auth::hasRole(['super_admin','school_admin','exam_officer'])) continue;
    $id=(int)$_GET[$action];
    try {
        changeExamState($db,(int)$school_id,$id,$action);
        Auth::logActivity(Auth::getUserId(),ucfirst($action).' Exam','exams',$id);
        redirect(APP_URL.'/modules/exams/index.php', $action==='reopen' ? 'Results withdrawn and marks reopened for correction.' : 'Exam action completed successfully.');
    } catch (InvalidArgumentException $e) {
        redirect(APP_URL.'/modules/exams/index.php',$e->getMessage(),'error');
    } catch (PDOException $e) {
        error_log($e->getMessage());
        redirect(APP_URL.'/modules/exams/index.php','The exam could not be updated. Please try again.','error');
    }
}

// Fetch active academic year
$stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? AND is_active = 1 LIMIT 1");
$stmt->execute([$school_id]);
$academicYear = $stmt->fetch();

// Fetch ALL exams for the school
$stmt = $db->prepare("
    SELECT e.*, y.year_bs, y.year_ad 
    FROM exams e
    JOIN academic_years y ON e.academic_year_id = y.id
    WHERE e.school_id = ?
    ORDER BY y.year_bs DESC, e.id DESC
");
$stmt->execute([$school_id]);
$exams = $stmt->fetchAll();

// Fetch all academic years for filter dropdown
$stmt_years = $db->prepare("SELECT DISTINCT year_bs FROM academic_years WHERE school_id = ? ORDER BY year_bs DESC");
$stmt_years->execute([$school_id]);
$all_years_filter = $stmt_years->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Exam Management';
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-file-alt"></i> Exam Management</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])): ?>
    <a href="<?= APP_URL ?>/modules/exams/add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create New Exam
    </a>
    <?php endif; ?>
</div>

<!-- Custom Filters -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body bg-light py-3">
        <div class="row g-3">
            <div class="col-md-6">
                <label for="filter_exam_name" class="form-label small fw-bold">Search Exam Name</label>
                <input type="text" class="form-control form-control-sm" id="filter_exam_name" placeholder="Type to search exam...">
            </div>
            <div class="col-md-6">
                <label for="filter_year" class="form-label small fw-bold">Filter By Year (BS)</label>
                <select class="form-select form-select-sm" id="filter_year">
                    <option value="">All Years</option>
                    <?php foreach ($all_years_filter as $year_bs): ?>
                    <option value="<?= sanitize($year_bs) ?>"><?= sanitize($year_bs) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Academic Exams for: <?= $academicYear ? getAcademicYearLabel($academicYear['year_bs'], $academicYear['year_ad']) : 'None' ?></h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" id="examsTable">
                <thead class="table-light">
                    <tr>
                        <th>Exam Name</th>
                        <th>Type</th>
                        <th>Year</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($exams) > 0): ?>
                        <?php foreach ($exams as $exam): ?>
                        <tr>
                            <td><strong><?= sanitize($exam['name']) ?></strong></td>
                            <td><span class="badge bg-info text-capitalize"><?= sanitize($exam['type']) ?></span></td>
                            <td><span class="badge bg-secondary"><?= sanitize($exam['year_bs']) ?> BS</span></td>
                            <td><?= formatDate($exam['start_date']) ?></td>
                            <td><?= formatDate($exam['end_date']) ?></td>
                            <td>
                                <?php if ($exam['is_published']): ?>
                                    <span class="badge bg-success"><i class="fas fa-eye"></i> Published</span>
                                <?php elseif ($exam['is_locked']): ?>
                                    <span class="badge bg-warning"><i class="fas fa-lock"></i> Marks Locked</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><i class="fas fa-edit"></i> Draft / Marks Open</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= APP_URL ?>/modules/exams/view.php?id=<?= $exam['id'] ?>" class="btn btn-primary" title="Configure Subjects">
                                        <i class="fas fa-cog"></i> Config
                                    </a>
                                    <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer']) && !$exam['is_locked']): ?>
                                    <a href="<?= APP_URL ?>/modules/exams/edit.php?id=<?= $exam['id'] ?>" class="btn btn-warning" title="Edit Exam Details">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if (Auth::hasRole(['super_admin', 'school_admin', 'exam_officer'])): ?>
                                        <?php if (!$exam['is_locked'] && !$exam['is_published']): ?>
                                            <a href="<?= APP_URL ?>/modules/exams/index.php?lock=<?= $exam['id'] ?>" class="btn btn-warning" title="Lock Marks Entry">
                                                <i class="fas fa-lock"></i> Lock
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($exam['is_locked'] && !$exam['is_published']): ?>
                                            <a href="<?= APP_URL ?>/modules/exams/index.php?publish=<?= $exam['id'] ?>" class="btn btn-success" title="Publish Results">
                                                <i class="fas fa-share-square"></i> Publish
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($exam['is_locked'] || $exam['is_published']): ?>
                                        <a href="<?= APP_URL ?>/modules/exams/index.php?reopen=<?= $exam['id'] ?>" class="btn btn-outline-warning" title="Withdraw results and reopen marks"><i class="fas fa-unlock"></i> Reopen marks</a>
                                        <?php endif; ?>
                                        <button onclick="confirmDeleteExam(<?= $exam['id'] ?>)" class="btn btn-danger" title="Delete Exam">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var table = $('#examsTable').DataTable({
        pageLength: 10,
        order: [[2, 'desc'], [0, 'asc']],
        language: {
            emptyTable: "No exams created yet."
        },
        dom: 'rtip'
    });

    $('#filter_exam_name').on('keyup change', function() {
        table.column(0).search(this.value).draw();
    });
    
    $('#filter_year').on('change', function() {
        table.column(2).search(this.value).draw();
    });
});

function confirmDeleteExam(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = '<?= APP_URL ?>/modules/exams/index.php?delete=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this exam (all marks and configurations will be lost):');
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
