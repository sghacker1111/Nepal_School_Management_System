<?php
/**
 * Classes & Sections Management - List Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/catalog_validation.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}
$db = getDB();
$showArchived=Auth::hasRole(['super_admin','school_admin'])&&(string)($_GET['show_archived']??'0')==='1';
$cleanupError='';
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($_POST['action']??'', ['remove_legacy_seven','restore_class'],true)){
    if(!Auth::hasRole(['super_admin','school_admin'])||Auth::isReadOnly())denySchoolRequest('Only administrators may change classes.',403);
    try{
        $globalAdmin=Auth::getRole()==='super_admin';
        $q=$db->prepare('SELECT password FROM users WHERE id=? AND status=1'.($globalAdmin?" AND role='super_admin'":' AND school_id=?'));
        $q->execute($globalAdmin?[Auth::getUserId()]:[Auth::getUserId(),$school_id]);
        if(!is_string($_POST['password']??null)||!password_verify($_POST['password'],$q->fetchColumn()?:''))throw new InvalidArgumentException('Enter your current administrator password.');
        $db->beginTransaction();catalogWriteLock($db,(int)$school_id);$id=(int)($_POST['class_id']??0);
        if($_POST['action']==='remove_legacy_seven'){
            if(($_POST['confirm_cleanup']??'')!=='1')throw new InvalidArgumentException('Confirm removal of the legacy class from active lists.');
            archiveLegacySevenClass($db,(int)$school_id,$id);$message='Legacy class 7 removed from active lists. All linked records are retained and recoverable.';
            Auth::logActivity(Auth::getUserId(),'Archive legacy class named exactly 7; retain all linked records','classes',$id);
        }else{
            $row=schoolRecord($db,'classes',$id,(int)$school_id);validateUniqueClassName($db,(int)$school_id,$row['name'],$id);
            $db->prepare('UPDATE classes SET is_active=1 WHERE id=? AND school_id=?')->execute([$id,$school_id]);$message='Class restored successfully.';
            Auth::logActivity(Auth::getUserId(),'Restore archived class','classes',$id);
        }
        $db->commit();redirect(APP_URL.'/modules/classes/index.php',$message,'success');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$cleanupError=$e instanceof InvalidArgumentException?$e->getMessage():schoolPublicError($e);}
}

// Handle deletes
if (isset($_GET['delete_class']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete_class'];
    $stmt = $db->prepare("UPDATE classes SET is_active=0 WHERE id = ? AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    Auth::logActivity(Auth::getUserId(), 'Archive Class', 'classes', $id);
    redirect(APP_URL . '/modules/classes/index.php', 'Class archived. Its records are retained.');
}

if (isset($_GET['delete_section']) && Auth::hasRole(['super_admin', 'school_admin'])) {
    $id = (int)$_GET['delete_section'];
    $used=$db->prepare('SELECT COUNT(*) FROM students WHERE section_id=? AND school_id=?');$used->execute([$id,$school_id]);
    if($used->fetchColumn())redirect(APP_URL.'/modules/classes/index.php','This section has student records and cannot be deleted.','error');
    $stmt = $db->prepare("DELETE FROM sections WHERE id = ? AND school_id = ?");
    $stmt->execute([$id, $school_id]);
    Auth::logActivity(Auth::getUserId(), 'Delete Section', 'sections', $id);
    redirect(APP_URL . '/modules/classes/index.php', 'Section deleted successfully');
}

// Fetch classes
$stmt = $db->prepare('SELECT * FROM classes WHERE school_id = ?'.($showArchived?'':' AND is_active=1').' ORDER BY id ASC');
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();
$legacyClasses=[];
if(Auth::hasRole(['super_admin','school_admin'])&&$school_id>0){$q=$db->prepare("SELECT c.id FROM classes c WHERE c.school_id=? AND c.name='7' AND c.is_active=1 AND EXISTS(SELECT 1 FROM users u WHERE u.school_id=c.school_id AND u.username='sgsunil' AND u.role='teacher')");$q->execute([$school_id]);$legacyClasses=$q->fetchAll(PDO::FETCH_COLUMN);}

// Fetch sections with class name and teacher name
$stmt = $db->prepare("
    SELECT s.*, c.name as class_name, u.full_name as teacher_name 
    FROM sections s 
    JOIN classes c ON s.class_id = c.id 
    LEFT JOIN users u ON s.class_teacher_id = u.id AND u.role = 'teacher'
    WHERE s.school_id = ? ".($showArchived?'':' AND c.is_active=1')."
    ORDER BY c.id, s.name
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
    <?php if($cleanupError): ?><div class="alert alert-danger" role="alert"><?= sanitize($cleanupError) ?></div><?php endif; ?>
    <?php if(Auth::hasRole(['super_admin','school_admin'])): ?><div class="mb-3"><a href="?show_archived=<?= $showArchived?'0':'1' ?>" class="btn btn-outline-secondary"><?= $showArchived?'Hide archived classes':'Show archived classes' ?></a></div><?php endif; ?>
    <?php foreach($legacyClasses as $legacyId): ?><div class="col-12 mb-3"><div class="alert alert-warning"><h5>Remove legacy class “7” (ID <?= (int)$legacyId ?>)</h5><p>This school contains teacher sgsunil. Remove this incorrect class from active lists without deleting its students, marks, fees, subjects or history.</p><form method="POST" onsubmit="return confirm('Remove only this class named exactly 7 from active lists? Its linked records will be retained.')"><input type="hidden" name="action" value="remove_legacy_seven"><input type="hidden" name="class_id" value="<?= (int)$legacyId ?>"><label class="d-block mb-2"><input type="checkbox" name="confirm_cleanup" value="1" required> Confirm this is the incorrect class</label><label>Current administrator password<input class="form-control" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-danger ms-2">Remove legacy class 7</button></form></div></div><?php endforeach; ?>
    <!-- Classes Column -->
    <div class="col-md-7 mb-4">
        <div class="card h-100 shadow-sm border-0">
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
                                    <td><?= sanitize($class['name_np'] ?? '-') ?><?php if(!$class['is_active']): ?><span class="badge bg-secondary ms-1">Archived</span><?php endif; ?></td>
                                    <td>
                                        <?php
                                        $levelBadge = ($class['level'] === 'Secondary') ? 'bg-success' : 'bg-primary';
                                        ?>
                                        <span class="badge <?= $levelBadge ?>"><?= $class['level'] ?></span>
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
                                         <?php if(!$class['is_active']): ?><details class="d-inline-block"><summary class="btn btn-sm btn-outline-success">Restore</summary><form method="POST"><input type="hidden" name="action" value="restore_class"><input type="hidden" name="class_id" value="<?= (int)$class['id'] ?>"><label>Current administrator password<input type="password" class="form-control" name="password" autocomplete="current-password" required></label><button class="btn btn-sm btn-success">Confirm restore</button></form></details><?php else: ?>
                                         <button onclick="confirmDeleteClass(<?= $class['id'] ?>)" class="btn btn-sm btn-outline-danger" title="Archive class">
                                             <i class="fas fa-trash"></i>
                                         </button>
                                         <?php endif; ?>
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
    }, 'Confirm Archive', 'Please enter your administrator password to archive this class. Its students, marks and all linked records will be retained:');
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
