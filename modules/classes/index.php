<?php
/**
 * Classes & Sections Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal']);

$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}
$db = getDB();

// Handle deletes
if (isset($_GET['delete_class']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete_class'];
    $stmt = $db->prepare("DELETE FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    Auth::logActivity(Auth::getUserId(), 'Delete Class', 'classes', $id);
    redirect(APP_URL . '/modules/classes/index.php', 'Class deleted successfully');
}

if (isset($_GET['delete_section']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete_section'];
    $stmt = $db->prepare("DELETE FROM sections WHERE id = ? AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    Auth::logActivity(Auth::getUserId(), 'Delete Section', 'sections', $id);
    redirect(APP_URL . '/modules/classes/index.php', 'Section deleted successfully');
}

// Fetch classes
$stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? ORDER BY level, name");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

// Fetch sections with class name and teacher name
$stmt = $db->prepare("
    SELECT s.*, c.name as class_name, u.full_name as teacher_name 
    FROM sections s 
    JOIN classes c ON s.class_id = c.id 
    LEFT JOIN users u ON s.class_teacher_id = u.id AND u.role = 'teacher'
    WHERE s.school_id = ?
    ORDER BY c.name, s.name
");
$stmt->execute([$school_id]);
$sections = $stmt->fetchAll();

$pageTitle = 'Class & Section Management';
$activePage = 'classes';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-school"></i> Class & Section Management</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
    <a href="<?= APP_URL ?>/modules/classes/add.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Add Class / Section
    </a>
    <?php endif; ?>
</div>

<div class="row">
    <!-- Classes Column -->
    <div class="col-md-7 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 text-primary"><i class="fas fa-layer-group"></i> Academic Classes</h5>
                <span class="badge bg-primary rounded-pill"><?= count($classes) ?> Classes</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Class Name</th>
                                <th>Nepali Name</th>
                                <th>Level</th>
                                <th>Grading Type</th>
                                <th>Class Teacher</th>
                                <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                                <th class="text-end">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($classes) > 0): ?>
                                <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td><strong><?= sanitize($class['name']) ?></strong></td>
                                    <td><?= sanitize($class['name_np'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $levelBadge = [
                                            'ECD' => 'bg-secondary',
                                            'Basic' => 'bg-info text-dark',
                                            'Secondary' => 'bg-success'
                                        ][$class['level']] ?? 'bg-light text-dark';
                                        ?>
                                        <span class="badge <?= $levelBadge ?>"><?= $class['level'] ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark text-capitalize border">
                                            <?= $class['grading_type'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($class['class_teacher_name'])): ?>
                                            <span class="text-dark small"><i class="fas fa-user-tie text-muted me-1"></i><?= sanitize($class['class_teacher_name']) ?></span>
                                            <?php if (!empty($class['class_teacher_signature'])): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success ms-1 small" style="font-size: 0.65rem;"><i class="fas fa-check-double"></i> Sig</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                     <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                                     <td class="text-end">
                                         <a href="<?= APP_URL ?>/modules/classes/edit.php?class_id=<?= $class['id'] ?>" class="btn btn-sm btn-outline-warning me-1" title="Edit Class Details">
                                             <i class="fas fa-edit"></i> Edit
                                         </a>
                                         <a href="<?= APP_URL ?>/modules/classes/subjects.php?class_id=<?= $class['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Configure Subjects">
                                             <i class="fas fa-book"></i> Subjects
                                         </a>
                                         <button onclick="confirmDeleteClass(<?= $class['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Delete">
                                             <i class="fas fa-trash"></i>
                                         </button>
                                     </td>
                                     <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No classes configured.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Sections Column -->
    <div class="col-md-5 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 text-success"><i class="fas fa-columns"></i> Class Sections</h5>
                <span class="badge bg-success rounded-pill"><?= count($sections) ?> Sections</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Section</th>
                                <th>Class</th>
                                <th>Class Teacher</th>
                                <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                                <th class="text-end">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($sections) > 0): ?>
                                <?php foreach ($sections as $section): ?>
                                <tr>
                                    <td><span class="badge bg-success bg-opacity-10 text-success px-3 py-2">Section <?= sanitize($section['name']) ?></span></td>
                                    <td><strong><?= sanitize($section['class_name']) ?></strong></td>
                                    <td><small class="text-muted"><i class="fas fa-user-tie"></i> <?= sanitize($section['teacher_name'] ?? 'Not Assigned') ?></small></td>
                                    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                                    <td class="text-end">
                                        <button onclick="confirmDeleteSection(<?= $section['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No sections configured.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDeleteClass(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = '<?= APP_URL ?>/modules/classes/index.php?delete_class=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this class and all associated data:');
}

function confirmDeleteSection(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = '<?= APP_URL ?>/modules/classes/index.php?delete_section=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this section:');
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
