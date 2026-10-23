<?php
/**
 * Student Management - List Page
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__.'/../../includes/student_search.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('students');

$db = getDB();

// Handle delete
if (isset($_GET['delete']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete'];
    $school_id = Auth::getSchoolId();
    try {
        $db->beginTransaction();
        
        // Archive the student without destroying assessment or attendance history.
        $stmt_stud = $db->prepare("UPDATE students SET status='inactive' WHERE id=? AND school_id=?");
        $stmt_stud->execute([$id,$school_id]);
        
        $db->commit();
        Auth::logActivity(Auth::getUserId(), 'Archive Student', 'students', $id);
        redirect(schoolRouteUrl('modules/students/index.php'), 'Student archived; marks and attendance retained.');
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        redirect(schoolRouteUrl('modules/students/index.php'), 'Error archiving student: ' . $e->getMessage(), 'error');
    }
}

// Build query
$school_id = Auth::getSchoolId();
$class_id=(int)($_GET['class']??0);$section_id=(int)($_GET['section']??0);
$status_filter=$_GET['status']??'';
try{$search=studentSearchTerm($_GET['search']??'');}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
if(!in_array($status_filter,['','active','transferred','dropout','passed','inactive'],true))denySchoolRequest('Choose a valid student status.',422);
$query = "SELECT s.*, c.name as class_name, sc.name as section_name 
          FROM students s 
          LEFT JOIN classes c ON s.class_id = c.id 
          LEFT JOIN sections sc ON s.section_id = sc.id 
          WHERE s.school_id = ? AND (c.is_active = 1 OR s.class_id IS NULL)";
$params=[$school_id];
if($class_id){$query.=' AND s.class_id=?';$params[]=$class_id;}
if($section_id){$query.=' AND s.section_id=?';$params[]=$section_id;}
if($status_filter!==''){$query.=' AND s.status=?';$params[]=$status_filter;}
try{$query.=studentSearchWhere($search,$params);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
$query.=' ORDER BY s.created_at DESC,s.id DESC';

$stmt = $db->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Get classes for filter
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();
$sections=[];
if($class_id){$stmt=$db->prepare('SELECT id,name FROM sections WHERE school_id=? AND class_id=? ORDER BY name');$stmt->execute([$school_id,$class_id]);$sections=$stmt->fetchAll();}

$pageTitle = 'Student Management';
$activePage = 'students';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-user-graduate"></i> Student Management</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
    <div>
        <a href="<?= schoolRouteUrl('modules/students/import_export.php') ?>" class="btn btn-outline-primary me-2">
            <i class="fas fa-file-import"></i> EMIS Import/Export
        </a>
        <a href="<?= schoolRouteUrl('modules/students/add.php') ?>" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add New Student
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4 shadow-sm border-0">
    <div class="card-body bg-light py-3">
        <form method="GET" class="row g-3" id="studentFilters">
            <div class="col-md-3">
                <label for="filter_class" class="form-label small fw-bold">Filter By Class</label>
                <select id="filter_class" name="class" class="form-select form-select-sm" data-erp-change="students-index-action-0" data-erp-change-args="<?= schoolPageActionArgs("students-index",[]) ?>">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>" <?= $class_id===(int)$class['id']?'selected':'' ?>>
                        <?= sanitize($class['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter_section" class="form-label small fw-bold">Section</label>
                <select id="filter_section" name="section" class="form-select form-select-sm" data-erp-change="students-index-action-1" data-erp-change-args="<?= schoolPageActionArgs("students-index",[]) ?>">
                    <option value="">All Sections</option>
                    <?php foreach($sections as $section): ?><option value="<?= (int)$section['id'] ?>" <?= $section_id===(int)$section['id']?'selected':'' ?>><?= sanitize($section['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter_status" class="form-label small fw-bold">Filter By Status</label>
                <select id="filter_status" name="status" class="form-select form-select-sm" data-erp-change="students-index-action-2" data-erp-change-args="<?= schoolPageActionArgs("students-index",[]) ?>">
                    <option value="">All Statuses</option>
                    <?php foreach(['active'=>'Active','transferred'=>'Transferred','dropout'=>'Dropout','passed'=>'Passed Out','inactive'=>'Inactive'] as $value=>$label): ?><option value="<?= $value ?>" <?= $status_filter===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label for="filter_search" class="form-label small fw-bold">Search name, roll, student ID or EMIS number</label>
                <div class="input-group input-group-sm">
                    <input type="search" name="search" id="filter_search" class="form-control" maxlength="100" value="<?= sanitize($search) ?>" placeholder="Name, roll, student ID or EMIS">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a id="btn_reset" href="<?= schoolRouteUrl('modules/students/index.php') ?>" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
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
                                <?php if(!empty($student['emis_registration_number'])): ?><br><small class="text-muted">EMIS Reg: <?= sanitize($student['emis_registration_number']) ?></small><?php endif; ?>
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
                                    <a href="<?= schoolRouteUrl('modules/students/view.php') ?>?id=<?= $student['id'] ?>"
                                       class="btn btn-info" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (Auth::hasRole(['super_admin', 'school_admin'])): ?>
                                    <a href="<?= schoolRouteUrl('modules/students/edit.php') ?>?id=<?= $student['id'] ?>"
                                       class="btn btn-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button data-erp-click="students-index-action-3" data-erp-click-args="<?= schoolPageActionArgs("students-index",[(string)($student['id'])]) ?>"
                                             class="btn btn-danger" title="Archive">
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
            <button data-erp-click="students-index-action-4" data-erp-click-args="<?= schoolPageActionArgs("students-index",[]) ?>" class="btn btn-success btn-sm">
                <i class="fas fa-file-excel"></i> Export to Excel
            </button>
            <button data-erp-click="students-index-action-5" data-erp-click-args="<?= schoolPageActionArgs("students-index",[]) ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php schoolPageModule("students-index");schoolPageValue("students-index-value-0",(string)(schoolRouteUrl('modules/students/index.php'))); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
