<?php
/**
 * Curriculum & Topics Manager
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('curriculum');
require_once __DIR__.'/../../includes/teaching_scope.php';
require_once __DIR__.'/../../includes/catalog_validation.php';
require_once __DIR__.'/../../includes/curriculum_catalog.php';
require_once __DIR__.'/../../includes/learning_content.php';

$db = getDB();
$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}
$contentActor=learningActor($db,(int)Auth::getUserId(),(int)$school_id,Auth::isReadOnly());
$canManageCatalog=learningCanManageCatalog($contentActor);

// --- AJAX API Handlers ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    if($action==='get_deleted'){
        if(!$canManageCatalog)denySchoolRequest('Only super admin may review deleted curriculum.',403);
        try{echo json_encode(['success'=>true,'items'=>curriculumDeletedList($db,(int)$school_id,(int)($_GET['class_id']??0),(int)($_GET['subject_id']??0))]);}
        catch(InvalidArgumentException $e){echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}exit;
    }
    
    if ($action === 'get_subjects') {
        $class_id = (int)($_GET['class_id'] ?? 0);
        try {
            $subjects=learningReadSubjects($db,$contentActor,$class_id);
            echo json_encode(['success' => true, 'subjects' => $subjects]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => schoolPublicError($e)]);
        }
        exit;
    }
    
    if ($action === 'get_all_subjects') {
        // Return all subjects to map to class
        try {
            $stmt = $db->prepare("SELECT id, name_en, code FROM subjects WHERE school_id = ? AND (is_hidden = 0 OR is_hidden IS NULL) ORDER BY name_en");
            $stmt->execute([$school_id]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'subjects' => $subjects]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => schoolPublicError($e)]);
        }
        exit;
    }

    if ($action === 'get_chapters') {
        $class_id = (int)($_GET['class_id'] ?? 0);
        $subject_id = (int)($_GET['subject_id'] ?? 0);
        try {
            $chapters=learningCatalogChapters($db,$contentActor,$class_id,$subject_id);
            echo json_encode(['success' => true, 'chapters' => $chapters]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => schoolPublicError($e)]);
        }
        exit;
    }

    if ($action === 'get_topics') {
        $chapter_id = (int)($_GET['chapter_id'] ?? 0);
        try {
            $scope=learningCatalogChapterScope($db,$contentActor,$chapter_id);
            $topics=learningCatalogTopics($db,$contentActor,$scope['class_id'],$scope['subject_id'],$chapter_id);
            echo json_encode(['success' => true, 'topics' => $topics]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => schoolPublicError($e)]);
        }
        exit;
    }
}

// --- POST Actions Handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
        if(!$canManageCatalog)denySchoolRequest('Only super admin may change chapters and topics.',403);
        if(in_array($action,['delete_chapter','delete_topic','restore_chapter','restore_topic'],true)&&(!is_scalar($_POST['id']??null)||!preg_match('/^[1-9][0-9]*$/',(string)$_POST['id'])))throw new InvalidArgumentException('Choose a valid chapter or topic record.');
        if(in_array($action,['restore_chapter','restore_topic'],true)){
            curriculumWrite($db,function()use($db,$school_id,$action){curriculumRestore($db,(int)$school_id,$action==='restore_chapter'?'chapters':'topics',(int)$_POST['id']);Auth::logActivity(Auth::getUserId(),'Restore curriculum',$action==='restore_chapter'?'chapters':'topics',(int)$_POST['id']);});
            echo json_encode(['success'=>true,'message'=>'Curriculum restored successfully.']);exit;
        }
        if ($action === 'add_chapter') {
            $class_id = (int)$_POST['class_id'];
            $subject_id = (int)$_POST['subject_id'];
            $name = sanitize($_POST['name'] ?? '');
            $name_np = sanitize($_POST['name_np'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Chapter name is required.']);
                exit;
            }

            $id=curriculumEnsureChapter($db,(int)$school_id,$class_id,$subject_id,$name,$name_np,$sort_order);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Chapter saved successfully.']);
            exit;
        }

        if ($action === 'edit_chapter') {
            $id = (int)$_POST['id'];
            $name = sanitize($_POST['name'] ?? '');
            $name_np = sanitize($_POST['name_np'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Chapter name is required.']);
                exit;
            }

            curriculumRenameChapter($db,(int)$school_id,$id,$name,$name_np,$sort_order);
            echo json_encode(['success' => true, 'message' => 'Chapter updated successfully.']);
            exit;
        }

        if ($action === 'delete_chapter') {
            $id = (int)$_POST['id'];
            curriculumWrite($db,function()use($db,$school_id,$id){curriculumDelete($db,(int)$school_id,'chapters',$id);Auth::logActivity(Auth::getUserId(),'Delete unused chapter','chapters',$id);});
            echo json_encode(['success' => true, 'message' => 'Chapter and its empty topics deleted successfully. Restore is available in Deleted curriculum.']);
            exit;
        }

        if ($action === 'add_topic') {
            $class_id = (int)$_POST['class_id'];
            $subject_id = (int)$_POST['subject_id'];
            $chapter_id = (int)$_POST['chapter_id'];
            $name = sanitize($_POST['name'] ?? '');
            $name_np = sanitize($_POST['name_np'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Topic name is required.']);
                exit;
            }

            $id=curriculumEnsureTopic($db,(int)$school_id,$chapter_id,$name,$name_np,$sort_order);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Topic saved successfully.']);
            exit;
        }

        if ($action === 'edit_topic') {
            $id = (int)$_POST['id'];
            $name = sanitize($_POST['name'] ?? '');
            $name_np = sanitize($_POST['name_np'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Topic name is required.']);
                exit;
            }

            curriculumRenameTopic($db,(int)$school_id,$id,$name,$name_np,$sort_order);
            echo json_encode(['success' => true, 'message' => 'Topic updated successfully.']);
            exit;
        }

        if ($action === 'delete_topic') {
            $id = (int)$_POST['id'];
            curriculumWrite($db,function()use($db,$school_id,$id){curriculumDelete($db,(int)$school_id,'topics',$id);Auth::logActivity(Auth::getUserId(),'Delete unused topic','topics',$id);});
            echo json_encode(['success' => true, 'message' => 'Unused topic deleted successfully. Restore is available in Deleted curriculum.']);
            exit;
        }

        if ($action === 'map_subject') {
            if(!Auth::hasRole(['super_admin','school_admin']))denySchoolRequest('Only administrators may map class subjects.',403);
            $class_id = (int)$_POST['class_id'];
            $subject_id = (int)$_POST['subject_id'];
            $target=schoolRecord($db,'subjects',$subject_id,(int)$school_id);
            $db->beginTransaction();catalogWriteLock($db,(int)$school_id);
            validateUniqueClassSubject($db,(int)$school_id,$class_id,$target);
            
            // Check mapping
            $stmt = $db->prepare("SELECT COUNT(*) FROM class_subjects WHERE class_id = ? AND subject_id = ? AND school_id = ?");
            $stmt->execute([$class_id, $subject_id, $school_id]);
            if ($stmt->fetchColumn() > 0) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Subject is already mapped to this class.']);
                exit;
            }
            
            $stmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, credit_hours, school_id) VALUES (?, ?, 4, ?)");
            $stmt->execute([$class_id, $subject_id, $school_id]);
            syncAllSchoolOfferings($db);$db->commit();
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (CurriculumDeleteBlocked $e) {
        echo json_encode(['success'=>false,'blocked'=>true,'message'=>$e->getMessage(),'dependencies'=>$e->report]);
    } catch (Exception $e) {
        if($db->inTransaction())$db->rollBack();
        echo json_encode(['success' => false, 'message' => $e instanceof InvalidArgumentException?$e->getMessage():schoolPublicError($e)]);
    }
    exit;
}

// Fetch all classes for filter
$classes=learningReadClasses($db,$contentActor);

$pageTitle = 'Curriculum & Topics Manager';
$activePage = 'curriculum';

ob_start();
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-primary mb-1"><i class="fas fa-sitemap me-2"></i> Curriculum & Topics Manager</h2>
            <p class="text-muted mb-0">Connect Classes and Subjects, then manage Chapters and Topics for school syllabus structure and Question Bank mapping.</p>
        </div>
        <a href="<?= schoolRouteUrl('index.php') ?>" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <div class="row g-4">
        <!-- Sidebar Navigation Filters -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; background: #fff;">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 fw-bold text-secondary"><i class="fas fa-filter me-2 text-primary"></i> Scope Filter</h5>
                </div>
                <div class="card-body">
                    <!-- Class Filter -->
                    <div class="mb-3">
                        <label for="curriculumClass" class="form-label fw-bold text-muted small">Select Class</label>
                        <select id="curriculumClass" class="form-select" data-erp-change="learning-curriculum-action-0" data-erp-change-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subject Filter -->
                    <div id="subjectSection" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="curriculumSubject" class="form-label fw-bold text-muted small mb-0">Select Subject</label>
                            <?php if($canManageCatalog): ?><button type="button" class="btn btn-link p-0 text-primary small text-decoration-none fw-bold" data-erp-click="learning-curriculum-action-1" data-erp-click-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>"><i class="fas fa-link"></i> Map Subject</button><?php endif; ?>
                        </div>
                        <select id="curriculumSubject" class="form-select" data-erp-change="learning-curriculum-action-2" data-erp-change-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">
                            <option value="">-- Choose Subject --</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Subject Quick-Mapping Info -->
            <div class="card border-0 shadow-sm d-none" id="curriculumInstructions" style="border-radius: 12px; background: #f8fafc;">
                <div class="card-body p-4 text-center">
                    <i class="fas fa-info-circle fa-2x text-primary mb-3"></i>
                    <h6 class="fw-bold mb-2">Hierarchical Curriculum Mapping</h6>
                    <p class="text-muted small text-start mb-0">
                        First connect Class and Subject. Then you can structure the curriculum tree by adding Chapters, and detail each Chapter with specific Topics. This hierarchy will automatically link when generating exam papers or mapping Question Bank items.
                    </p>
                </div>
            </div>
        </div>

        <!-- Syllabus Tree Content -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm min-h-100" style="border-radius: 12px; background: #fff;">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-secondary" id="curriculumTreeTitle"><i class="fas fa-list-ol me-2 text-primary"></i> Syllabus Tree</h5>
                    <div class="d-flex flex-wrap gap-2"><?php if($canManageCatalog): ?><button type="button" class="btn btn-outline-secondary btn-sm" data-erp-click="learning-curriculum-action-3" data-erp-click-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">Deleted curriculum</button><?php endif; ?><button type="button" class="btn btn-success btn-sm px-3 d-none" id="btnAddChapter" data-erp-click="learning-curriculum-action-4" data-erp-click-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">
                        <i class="fas fa-plus me-1"></i> Add Chapter
                    </button></div>
                </div>
                <div class="card-body" id="curriculumTreeBody">
                    <div class="text-center text-muted py-5" id="placeholderView">
                        <i class="fas fa-sitemap fa-3x mb-3 text-muted"></i>
                        <h5>Select Class and Subject to load syllabus structure</h5>
                        <p class="text-muted small">Choose options in the filter panel to display, create, and manage Chapters and Topics.</p>
                    </div>

                    <!-- Chapters & Topics Tree View Container -->
                    <div id="treeContainer" style="display: none;">
                        <!-- Accordion for chapters -->
                        <div class="accordion accordion-flush" id="chaptersAccordion">
                            <!-- Loaded via JS -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Map Subject Modal -->
<?php if($canManageCatalog): ?>
<div class="modal fade" id="mapSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content border-0 shadow-lg" style="border-radius:16px;" data-erp-submit="learning-curriculum-action-5" data-erp-submit-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-link"></i> Map Subject to Class</h5>
                <button type="button" class="btn-close btn-close-white" data-ui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Subject to Map</label>
                    <select id="modalMapSubjectSelect" class="form-select" required>
                        <!-- Loaded dynamically -->
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-ui-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4">Map Subject</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Chapter Modal -->
<div class="modal fade" id="chapterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content border-0 shadow-lg" style="border-radius:16px;" data-erp-submit="learning-curriculum-action-6" data-erp-submit-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">
            <input type="hidden" id="chapterModalAction" value="add">
            <input type="hidden" id="chapterModalId">
            <div class="modal-header bg-success text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="chapterModalTitle"><i class="fas fa-plus"></i> Add Chapter</h5>
                <button type="button" class="btn-close btn-close-white" data-ui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Chapter Name (English) *</label>
                    <input type="text" id="modalChapterName" class="form-control" placeholder="e.g. Force and Motion" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Chapter Name (Nepali - optional)</label>
                    <input type="text" id="modalChapterNameNp" class="form-control" placeholder="e.g. बल र गति">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Sort Order</label>
                    <input type="number" id="modalChapterSort" class="form-control" value="0">
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-ui-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success px-4" id="chapterModalBtn">Save Chapter</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Topic Modal -->
<div class="modal fade" id="topicModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content border-0 shadow-lg" style="border-radius:16px;" data-erp-submit="learning-curriculum-action-7" data-erp-submit-args="<?= schoolPageActionArgs("learning-curriculum",[]) ?>">
            <input type="hidden" id="topicModalAction" value="add">
            <input type="hidden" id="topicModalId">
            <input type="hidden" id="topicModalChapterId">
            <div class="modal-header bg-info text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="topicModalTitle"><i class="fas fa-plus"></i> Add Topic</h5>
                <button type="button" class="btn-close btn-close-white" data-ui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Topic Name (English) *</label>
                    <input type="text" id="modalTopicName" class="form-control" placeholder="e.g. Newton's Laws of Motion" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Topic Name (Nepali - optional)</label>
                    <input type="text" id="modalTopicNameNp" class="form-control" placeholder="e.g. न्युटनका गतिको नियमहरू">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold small text-muted">Sort Order</label>
                    <input type="number" id="modalTopicSort" class="form-control" value="0">
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-ui-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info text-white px-4" id="topicModalBtn">Save Topic</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php schoolPageModule("learning-curriculum");schoolPageValue("learning-curriculum-value-0",(string)(json_encode($canManageCatalog)));
schoolPageValue("learning-curriculum-value-1",(string)(json_encode(APP_URL)));
schoolPageValue("learning-curriculum-value-2",(string)(json_encode(APP_URL))); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
