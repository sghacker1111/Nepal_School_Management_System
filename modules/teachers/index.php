<?php
/**
 * Teacher Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal']);
Auth::requireAccess('teachers');

$db = getDB();
$school_id = Auth::getSchoolId();

// Handle toggle status (activate/deactivate)
if (isset($_GET['toggle_status']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['toggle_status'];
    
    // Get current status with school verification
    $stmt = $db->prepare("SELECT status FROM users WHERE id = ? AND role = 'teacher' AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    $teacher = $stmt->fetch();
    
    if ($teacher) {
        $new_status = $teacher['status'] == 1 ? 0 : 1;
        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND school_id = ?");
        $stmt->execute([$new_status, $id, $school_id]);
        
        $status_label = $new_status == 1 ? 'activated' : 'deactivated';
        Auth::logActivity(Auth::getUserId(), "Toggle Teacher Status ($status_label)", 'users', $id);
        redirect(schoolRouteUrl('modules/teachers/index.php'), "Teacher $status_label successfully");
    }
}

// Handle delete
if (isset($_GET['delete']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete'];
    
    // Verify first that teacher belongs to the school
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    $teacher = $stmt->fetch();
    
    if ($teacher) {
        try {
            $db->beginTransaction();
            
            // Keep teaching assignments and historical results; disable sign-in.
            $db->prepare("UPDATE users SET status=0 WHERE id=? AND role='teacher' AND school_id=?")->execute([$id,$school_id]);
            
            $db->commit();
            Auth::logActivity(Auth::getUserId(), 'Archive Teacher', 'users', $id);
            redirect(schoolRouteUrl('modules/teachers/index.php'), 'Teacher archived; assignments and history retained.');
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            redirect(schoolRouteUrl('modules/teachers/index.php'), 'Error archiving teacher: ' . $e->getMessage(), 'error');
        }
    } else {
        redirect(schoolRouteUrl('modules/teachers/index.php'), 'Teacher not found or unauthorized', 'error');
    }
}

// Fetch all teachers
$stmt = $db->prepare("SELECT * FROM users WHERE role = 'teacher' AND school_id = ? ORDER BY created_at DESC");
$stmt->execute([$school_id]);
$teachers = $stmt->fetchAll();

$pageTitle = 'Teacher Management';
$activePage = 'teachers';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-chalkboard-teacher"></i> Teacher Management</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
    <div>
        <a href="<?= schoolRouteUrl('modules/teachers/import_export.php') ?>" class="btn btn-outline-primary me-2">
            <i class="fas fa-file-import"></i> EMIS Import/Export
        </a>
        <a href="<?= schoolRouteUrl('modules/teachers/add.php') ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Teacher
        </a>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Teachers List (<?= count($teachers) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" id="teacherTable">
                <thead class="table-light">
                    <tr>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Joined Date</th>
                        <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                        <th class="text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($teachers) > 0): ?>
                        <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                        <i class="fas fa-user-tie"></i>
                                    </div>
                                    <div>
                                        <strong><?= sanitize($teacher['full_name']) ?></strong>
                                    </div>
                                </div>
                            </td>
                            <td><code><?= sanitize($teacher['username']) ?></code></td>
                            <td><?= sanitize($teacher['email'] ?? '-') ?></td>
                            <td><?= sanitize($teacher['phone'] ?? '-') ?></td>
                            <td>
                                <?php if ($teacher['status'] == 1): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDate($teacher['created_at']) ?></td>
                            <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                            <td class="text-end">
                                <a href="<?= schoolRouteUrl('modules/teachers/edit.php') ?>?id=<?= $teacher['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= schoolRouteUrl('modules/teachers/index.php') ?>?toggle_status=<?= $teacher['id'] ?>"
                                   class="btn btn-sm <?= $teacher['status'] == 1 ? 'btn-outline-secondary' : 'btn-outline-success' ?>" 
                                   title="<?= $teacher['status'] == 1 ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fas <?= $teacher['status'] == 1 ? 'fa-ban' : 'fa-check' ?>"></i>
                                </a>
                                <button data-erp-click="teachers-index-action-0" data-erp-click-args="<?= schoolPageActionArgs("teachers-index",[(string)($teacher['id'])]) ?>" class="btn btn-sm btn-outline-danger" title="Archive">
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

<?php schoolPageModule("teachers-index");schoolPageValue("teachers-index-value-0",(string)(schoolRouteUrl('modules/teachers/index.php'))); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
