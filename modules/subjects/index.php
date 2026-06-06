<?php
/**
 * Subject Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher']);

$db = getDB();
$school_id = Auth::getSchoolId();

// Handle delete
if (isset($_GET['delete']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete'];
    
    try {
        $db->beginTransaction();
        
        // Delete mappings first
        $db->prepare("DELETE FROM class_subjects WHERE subject_id = ? AND school_id = ?")->execute([$id, $school_id]);
        
        // Delete subject
        $stmt = $db->prepare("DELETE FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$id, $school_id]);
        
        $db->commit();
        Auth::logActivity(Auth::getUserId(), 'Delete Subject', 'subjects', $id);
        redirect(APP_URL . '/modules/subjects/index.php', 'Subject deleted successfully');
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        redirect(APP_URL . '/modules/subjects/index.php', 'Cannot delete subject. It might be linked to exams.', 'error');
    }
}

// Fetch all subjects with class name and teacher IDs from class_subjects mapping
$stmt = $db->prepare("
    SELECT s.*, c.name as class_name, cs.teacher_id as teacher_ids, cs.id as mapping_id
    FROM subjects s
    LEFT JOIN class_subjects cs ON s.id = cs.subject_id
    LEFT JOIN classes c ON cs.class_id = c.id
    WHERE s.school_id = ? AND (c.is_active = 1 OR cs.class_id IS NULL)
    ORDER BY c.name, s.code, s.name_en
");
$stmt->execute([$school_id]);
$subjects = $stmt->fetchAll();

// Fetch all school teachers to display their names
$stmt_teachers = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND school_id = ?");
$stmt_teachers->execute([$school_id]);
$all_teachers = $stmt_teachers->fetchAll(PDO::FETCH_KEY_PAIR);

// Fetch all classes for filter dropdown
$stmt_classes = $db->prepare("SELECT DISTINCT name FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY level, name");
$stmt_classes->execute([$school_id]);
$all_classes_filter = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Subject Management';
$activePage = 'subjects';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-book"></i> Subject Management</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
    <a href="<?= APP_URL ?>/modules/subjects/add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add New Subject
    </a>
    <?php endif; ?>
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

<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Subject Catalog (<?= count($subjects) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" id="subjectTable">
                <thead class="table-light">
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Class Mapped</th>
                        <th>Assigned Teachers</th>
                        <th>Type</th>
                        <th>Credit Hours</th>
                        <th>Default Full/Pass Marks</th>
                        <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                        <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($subjects) > 0): ?>
                        <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <td><code><?= sanitize($subject['code'] ?? 'N/A') ?></code></td>
                            <td>
                                <strong><?= sanitize($subject['name_en']) ?></strong><br>
                                <small class="text-muted"><?= sanitize($subject['name_np'] ?? '-') ?></small>
                            </td>
                            <td><?= sanitize($subject['class_name'] ?? 'Not Mapped') ?></td>
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
                                <?php
                                $typeBadge = [
                                    'compulsory' => 'bg-primary',
                                    'optional' => 'bg-warning text-dark',
                                    'local' => 'bg-info text-dark'
                                ][$subject['type']] ?? 'bg-secondary';
                                ?>
                                <span class="badge <?= $typeBadge ?> text-capitalize"><?= $subject['type'] ?></span>
                            </td>
                            <td><?= sanitize($subject['credit_hours']) ?> CH</td>
                            <td>
                                <span class="text-success font-monospace"><?= sanitize($subject['full_marks']) ?></span> 
                                / 
                                <span class="text-danger font-monospace"><?= sanitize($subject['pass_marks']) ?></span>
                            </td>
                            <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                            <td class="text-end">
                                <a href="<?= APP_URL ?>/modules/subjects/edit.php?id=<?= $subject['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <button onclick="confirmDeleteSubject(<?= $subject['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
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
        pageLength: 10,
        order: [[0, 'asc']],
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

function confirmDeleteSubject(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = '<?= APP_URL ?>/modules/subjects/index.php?delete=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this subject:');
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
