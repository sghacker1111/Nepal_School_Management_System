<?php
/**
 * Subject Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}

// Handle hide / unhide toggle
if (isset($_GET['toggle_hide']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['toggle_hide'];
    $stmt = $db->prepare("SELECT is_hidden, name_en FROM subjects WHERE id = ? AND (school_id = ?)");
    $stmt->execute([$id, $school_id]);
    $curr = $stmt->fetch();
    if ($curr) {
        $new_state = empty($curr['is_hidden']) ? 1 : 0;
        $stmt_up = $db->prepare("UPDATE subjects SET is_hidden = ? WHERE id = ? AND (school_id = ?)");
        $stmt_up->execute([$new_state, $id, $school_id]);
        
        $msg = $new_state ? ("Subject '" . htmlspecialchars($curr['name_en']) . "' is now hidden from lists and student forms.") : ("Subject '" . htmlspecialchars($curr['name_en']) . "' is now visible and active.");
        redirect(APP_URL . '/modules/subjects/index.php', $msg);
    }
}

// Disallow hard delete to protect exams and marks data integrity
if (isset($_GET['delete'])) {
    redirect(APP_URL . '/modules/subjects/index.php', 'Permanent deletion is disabled to protect existing marks, exams, and student result history. Please use the Hide option instead.', 'error');
}

$show_hidden = isset($_GET['show_hidden']) && $_GET['show_hidden'] == '1';

// Fetch all subjects with class name and teacher IDs from class_subjects mapping
$hidden_clause = $show_hidden ? "" : " AND (s.is_hidden = 0 OR s.is_hidden IS NULL)";
$stmt = $db->prepare("
    SELECT s.*, c.name as class_name, c.id as class_id, cs.teacher_id as teacher_ids, cs.id as mapping_id, cs.is_optional, cs.is_active
    FROM subjects s
    LEFT JOIN class_subjects cs ON s.id = cs.subject_id AND (cs.school_id = ?)
    LEFT JOIN classes c ON cs.class_id = c.id
    WHERE (s.school_id = ?) $hidden_clause AND (c.is_active = 1 OR cs.class_id IS NULL)
    ORDER BY c.id ASC, s.code ASC, s.name_en ASC
");
$stmt->execute([$school_id, $school_id]);
$subjects = $stmt->fetchAll();

// Fetch all school teachers to display their names
$stmt_teachers = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND (school_id = ?)");
$stmt_teachers->execute([$school_id]);
$all_teachers = $stmt_teachers->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch all classes for filter dropdown
$stmt_classes = $db->prepare("SELECT DISTINCT name FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt_classes->execute([$school_id]);
$all_classes_filter = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Subject Management';
$activePage = 'subjects';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><i class="fas fa-book"></i> Subject Management</h2>
        <p class="text-muted small mb-0">Theory (TH) and Internal (IN) components configured per subject and class.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="?show_hidden=<?= $show_hidden ? '0' : '1' ?>" class="btn <?= $show_hidden ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>">
            <i class="fas <?= $show_hidden ? 'fa-eye-slash' : 'fa-eye' ?>"></i> <?= $show_hidden ? 'Hide Inactive Subjects' : 'Show Hidden Subjects' ?>
        </a>
        <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
        <a href="<?= APP_URL ?>/modules/subjects/add.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Subject
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Custom Filters -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body bg-light py-3">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="filter_subject" class="form-label small fw-bold">Search Subject (Name/Code)</label>
                <input type="text" class="form-control form-control-sm" id="filter_subject" placeholder="Type to search subject...">
            </div>
            <div class="col-md-4">
                <label for="filter_class" class="form-label small fw-bold">Filter By Class</label>
                <select class="form-select form-select-sm" id="filter_class">
                    <option value="">All Classes</option>
                    <?php foreach ($all_classes_filter as $c_name): ?>
                    <option value="<?= sanitize($c_name) ?>"><?= sanitize($c_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="filter_teacher" class="form-label small fw-bold">Filter By Teacher</label>
                <select class="form-select form-select-sm" id="filter_teacher">
                    <option value="">All Teachers</option>
                    <?php foreach ($all_teachers as $t_name): ?>
                    <option value="<?= sanitize($t_name) ?>"><?= sanitize($t_name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 text-primary">Subject Catalog (<?= count($subjects) ?> <?= $show_hidden ? 'including hidden' : 'active' ?>)</h5>
        <span class="badge bg-light text-dark border">No duplicate subjects allowed per class</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0" id="subjectTable">
                <thead class="table-light">
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Class Mapped</th>
                        <th>Assigned Teachers</th>
                        <th>Credit Hours (TH / IN)</th>
                        <th>Evaluation Marks (TH / IN)</th>
                        <th>Status</th>
                        <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                        <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($subjects) > 0): ?>
                        <?php foreach ($subjects as $subject): ?>
                        <tr class="<?= !empty($subject['is_hidden']) ? 'table-secondary opacity-75' : '' ?>">
                            <td><code><?= sanitize($subject['code'] ?? 'N/A') ?></code></td>
                            <td>
                                <strong><?= sanitize($subject['name_en']) ?></strong><br>
                                <small class="text-muted"><?= sanitize($subject['name_np'] ?? '-') ?></small>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">
                                    <?= sanitize($subject['class_name'] ?? 'Not Mapped') ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                if (!empty($subject['teacher_ids'])) {
                                    $t_ids = explode(',', $subject['teacher_ids']);
                                    $t_names = [];
                                    foreach ($t_ids as $tid) {
                                        if (isset($all_teachers[$tid])) {
                                            $t_names[] = sanitize($all_teachers[$tid]);
                                        }
                                    }
                                    echo implode(', ', $t_names);
                                } else {
                                    echo '<span class="text-muted small">Not Assigned</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <strong><?= sanitize($subject['credit_hours']) ?> CH Total</strong>
                                <div class="small mt-1">
                                    <span class="badge bg-light text-dark border" title="Theory Credit Hours">TH: <?= $subject['th_credit_hours'] !== null ? $subject['th_credit_hours'] : '-' ?></span>
                                    <span class="badge bg-light text-primary border" title="Internal Credit Hours">IN: <?= $subject['in_credit_hours'] !== null ? $subject['in_credit_hours'] : '-' ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="small">
                                    <span class="fw-bold text-dark">TH:</span> 
                                    <span class="text-success"><?= $subject['th_full_marks'] !== null ? $subject['th_full_marks'] : '-' ?></span>/<span class="text-danger"><?= $subject['th_pass_marks'] !== null ? $subject['th_pass_marks'] : '-' ?></span>
                                </div>
                                <div class="small mt-1">
                                    <span class="fw-bold text-dark">IN:</span> 
                                    <span class="text-success"><?= $subject['in_full_marks'] !== null ? $subject['in_full_marks'] : '-' ?></span>/<span class="text-danger"><?= $subject['in_pass_marks'] !== null ? $subject['in_pass_marks'] : '-' ?></span>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($subject['is_hidden'])): ?>
                                    <span class="badge bg-secondary"><i class="fas fa-eye-slash me-1"></i> Hidden</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i> Visible</span>
                                <?php endif; ?>
                            </td>
                            <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                            <td class="text-end text-nowrap">
                                <a href="<?= APP_URL ?>/modules/subjects/edit.php?id=<?= $subject['id'] ?>" class="btn btn-sm btn-outline-warning me-1" title="Edit Subject Details">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <?php if (!empty($subject['is_hidden'])): ?>
                                <a href="?toggle_hide=<?= $subject['id'] ?>" class="btn btn-sm btn-outline-success" title="Restore and show subject">
                                    <i class="fas fa-eye"></i> Show
                                </a>
                                <?php else: ?>
                                <a href="?toggle_hide=<?= $subject['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Hide subject from list" onclick="return confirm('Hide this subject from student forms and exam lists? Historical marks remain safe.');">
                                    <i class="fas fa-eye-slash"></i> Hide
                                </a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
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
    var table = $('#subjectTable').DataTable({
        pageLength: 25,
        order: [[2, 'asc'], [0, 'asc']],
        language: {
            emptyTable: "No subjects configured."
        },
        dom: 'rtip'
    });

    $('#filter_subject').on('keyup change', function() {
        table.search(this.value).draw();
    });
    
    $('#filter_class').on('change', function() {
        table.column(2).search(this.value).draw();
    });
    
    $('#filter_teacher').on('change', function() {
        table.column(3).search(this.value).draw();
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
