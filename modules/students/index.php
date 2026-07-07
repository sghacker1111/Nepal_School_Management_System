<?php
/**
 * Student Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('students');

$db = getDB();

// Handle delete
if (isset($_GET['delete']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete'];
    $school_id = Auth::getSchoolId();
    try {
        $db->beginTransaction();
        
        // Delete related marks
        $stmt_marks = $db->prepare("DELETE FROM marks WHERE student_id = ?");
        $stmt_marks->execute([$id]);
        
        // Delete related attendances
        $stmt_att = $db->prepare("DELETE FROM attendances WHERE student_id = ?");
        $stmt_att->execute([$id]);
        
        // Delete student record
        $stmt_stud = $db->prepare("DELETE FROM students WHERE id = ? AND school_id = ?");
        $stmt_stud->execute([$id, $school_id]);
        
        $db->commit();
        Auth::logActivity(Auth::getUserId(), 'Delete Student', 'students', $id);
        redirect(APP_URL . '/modules/students/index.php', 'Student deleted successfully');
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        redirect(APP_URL . '/modules/students/index.php', 'Error deleting student: ' . $e->getMessage(), 'error');
    }
}

// Build query
$school_id = Auth::getSchoolId();
$query = "SELECT s.*, c.name as class_name, sc.name as section_name 
          FROM students s 
          LEFT JOIN classes c ON s.class_id = c.id 
          LEFT JOIN sections sc ON s.section_id = sc.id 
          WHERE s.school_id = ? AND (c.is_active = 1 OR s.class_id IS NULL)
          ORDER BY s.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute([$school_id]);
$students = $stmt->fetchAll();

// Get classes for filter
$stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

$pageTitle = 'Student Management';
$activePage = 'students';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-user-graduate"></i> Student Management</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
    <div>
        <a href="<?= APP_URL ?>/modules/students/import_export.php" class="btn btn-outline-primary me-2">
            <i class="fas fa-file-import"></i> EMIS Import/Export
        </a>
        <a href="<?= APP_URL ?>/modules/students/add.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Student
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body bg-light py-3">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="filter_class" class="form-label small fw-bold">Filter By Class</label>
                <select id="filter_class" class="form-select form-select-sm">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                    <option value="<?= sanitize($class['name']) ?>">
                        <?= sanitize($class['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="filter_status" class="form-label small fw-bold">Filter By Status</label>
                <select id="filter_status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="Active">Active</option>
                    <option value="Transferred">Transferred</option>
                    <option value="Dropout">Dropout</option>
                    <option value="Passed">Passed Out</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="filter_search" class="form-label small fw-bold">Search Student (Name, ID, EMIS...)</label>
                <div class="input-group input-group-sm">
                    <input type="text" id="filter_search" class="form-control" placeholder="Type name, ID, EMIS to filter...">
                    <button id="btn_reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Student List -->
<div class="card">
    <div class="card-header bg-white">
        <h5 class="mb-0">Students List (<?= count($students) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped" id="studentTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Photo</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Roll No</th>
                        <th>Gender</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= sanitize($student['student_id']) ?></td>
                            <td>
                                <?php if ($student['photo']): ?>
                                    <img src="<?= APP_URL ?>/uploads/students/<?= $student['photo'] ?>" 
                                         alt="Photo" class="rounded-circle" width="40" height="40" style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" 
                                         style="width: 40px; height: 40px;">
                                        <i class="fas fa-user text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?></strong><br>
                                <small class="text-muted"><?= sanitize($student['emis_id'] ?? '') ?></small>
                            </td>
                            <td><?= sanitize($student['class_name']) ?></td>
                            <td><?= sanitize($student['roll_number'] ?? '-') ?></td>
                            <td><?= sanitize($student['gender']) ?></td>
                            <td>
                                <?php
                                $badgeClass = [
                                    'active' => 'success',
                                    'transferred' => 'info',
                                    'dropout' => 'danger',
                                    'passed' => 'primary',
                                    'inactive' => 'secondary'
                                ][$student['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $badgeClass ?>"><?= ucfirst($student['status']) ?></span>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="<?= APP_URL ?>/modules/students/view.php?id=<?= $student['id'] ?>" 
                                       class="btn btn-info" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                                    <a href="<?= APP_URL ?>/modules/students/edit.php?id=<?= $student['id'] ?>" 
                                       class="btn btn-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button onclick="confirmDeleteStudent(<?= $student['id'] ?>)" 
                                             class="btn btn-danger" title="Delete">
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
        
        <?php if (count($students) > 0): ?>
        <div class="mt-3">
            <button onclick="exportToExcel('studentTable')" class="btn btn-success btn-sm">
                <i class="fas fa-file-excel"></i> Export to Excel
            </button>
            <button onclick="window.print()" class="btn btn-secondary btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    var table = $('#studentTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            emptyTable: "No students found"
        },
        dom: 'rtip'
    });

    $('#filter_search').on('keyup change', function() {
        table.search(this.value).draw();
    });

    $('#filter_class').on('change', function() {
        table.column(3).search(this.value).draw();
    });

    $('#filter_status').on('change', function() {
        table.column(6).search(this.value).draw();
    });

    $('#btn_reset').on('click', function() {
        $('#filter_search').val('');
        $('#filter_class').val('');
        $('#filter_status').val('');
        table.search('').columns().search('').draw();
    });
});

function confirmDeleteStudent(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = '<?= APP_URL ?>/modules/students/index.php?delete=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this student:');
}

function exportToExcel(tableId) {
    // Simple table to CSV export
    let downloadLink;
    const dataType = 'application/vnd.ms-excel';
    const tableSelect = document.getElementById(tableId);
    const tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    
    if(navigator.msSaveOrOpenBlob){
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob(blob, 'students_list.xls');
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = 'students_list.xls';
        downloadLink.click();
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
