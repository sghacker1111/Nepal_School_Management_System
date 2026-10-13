<?php
/**
 * Learning Hub - Notes & Practice Q&As
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireLogin();

$db = getDB();
$school_id = Auth::getSchoolId();
$is_admin = (Auth::getRole() === 'super_admin');
$success = '';
$error = '';

// 1. Fetch school details and permission status
$permission_status = 'none';
if ($school_id) {
    $permission_status = Auth::hasAccess('learning')?'approved':'none';
}

// Handle request access action for school users
if (!$is_admin && isset($_GET['action']) && $_GET['action'] === 'request_hub_permission') {
    $stmt = $db->prepare("UPDATE schools SET learning_hub_permission_status = 'requested' WHERE id = ?");
    $stmt->execute([$school_id]);
    Auth::logActivity(Auth::getUserId(), 'Request Learning Hub Access', 'schools', $school_id);
    redirect(APP_URL . '/modules/learning/index.php', 'Your request for access has been submitted.');
}

require_once __DIR__.'/../../includes/learning_content_service.php';
$contentActor=learningActor($db,(int)Auth::getUserId(),(int)$school_id,Auth::isReadOnly());
if($_SERVER['REQUEST_METHOD']==='POST'&&(isset($_POST['action_note'])||isset($_GET['delete_note']))){
    try{
        $action=$_POST['action_note']??'archive';
        if(!is_string($action)||!in_array($action,['add','edit','set_visibility','archive','restore'],true))throw new InvalidArgumentException('Choose one valid note action.');
        $id=$action==='add'?null:learningId($_POST['note_id']??$_GET['delete_note']??null);
        if(in_array($action,['add','edit'],true))learningSaveNote($db,$contentActor,$_POST,$id);
        elseif($action==='set_visibility')learningSetVisibility($db,$contentActor,'learning_notes',$id,learningVisibility($_POST['visibility']??null));
        else learningSetArchived($db,$contentActor,'learning_notes',$id,$action==='archive');
        redirect($_SERVER['REQUEST_URI'],'Note saved successfully.','success');
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){$error=schoolPublicError($e);}
}
$classes=learningReadClasses($db,$contentActor);
$class_id=(int)($_GET['class_id']??($classes[0]['id']??0));
$selected_class=null;foreach($classes as $row)if((int)$row['id']===$class_id)$selected_class=$row;
$subjects=learningReadSubjects($db,$contentActor,$class_id);
$subject_id=(int)($_GET['subject_id']??($subjects[0]['id']??0));
$selected_subject=null;foreach($subjects as $row)if((int)$row['id']===$subject_id)$selected_subject=$row;
$chapterRows=$selected_class&&$selected_subject?learningCatalogChapters($db,$contentActor,$class_id,$subject_id):[];
$chapters=array_column($chapterRows,'name');
$selected_chapter=$_GET['chapter']??($chapters[0]??'');
if(!is_string($selected_chapter)||($selected_chapter!==''&&!in_array($selected_chapter,$chapters,true)))denySchoolRequest('Choose an available chapter.',404);
$notes=[];$questions=[];
$filter_source=$_GET['source']??'all';$filter_archived=($_GET['archived']??'')==='1';
$canCreateNote=$selected_class&&$selected_subject&&learningCanCreate($db,$contentActor,$class_id,$subject_id);
if($selected_class&&$selected_subject&&$selected_chapter){
    $filters=['class_id'=>$class_id,'subject_id'=>$subject_id,'chapter'=>$selected_chapter,'source'=>$filter_source,'archived'=>$filter_archived];
    try{$notes=learningContentList($db,$contentActor,'learning_notes',$filters,'study');$questions=learningContentList($db,$contentActor,'questions',array_replace($filters,['archived'=>false]),'study');}
    catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
}
$noteTopics=[];foreach($chapterRows as $row)if($row['name']===$selected_chapter)$noteTopics=learningCatalogTopics($db,$contentActor,$class_id,$subject_id,(int)$row['id']);
$pageTitle = 'Learning Hub';
$activePage = 'learning';

ob_start();
?>
<style>
.learning-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.18);
    transition: transform 0.2s ease;
}
.chapter-badge {
    background: linear-gradient(135deg, #3498db, #2980b9);
    color: white;
}
.note-item {
    border-left: 4px solid #2ecc71;
    background-color: #fdfdfd;
    font-size: 1.125rem;
    line-height: 1.7;
}
.qa-item {
    border-left: 4px solid #e67e22;
    background-color: #fffaf0;
    font-size: 1.125rem;
    line-height: 1.7;
}
</style>

<!-- TinyMCE CDN for MS Word formatting and Full-screen editor -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>

<!-- MathJax for rendering equations -->
<script>
window.MathJax = {
    tex: {
        inlineMath: [['\\(', '\\)'], ['$', '$']],
        displayMath: [['\\[', '\\]']]
    },
    startup: {
        ready: () => {
            MathJax.startup.defaultReady();
            MathJax.startup.promise.then(() => {
                if (typeof fixDevanagariInMathJax === 'function') {
                    fixDevanagariInMathJax(document);
                }
            });
        }
    }
};
</script>
<script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js"></script>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-book-open text-primary"></i> Learning Hub</h2>
    <div>
        <a href="<?= APP_URL ?>/modules/learning/practice_mcqs.php" class="btn btn-success"><i class="fas fa-circle-check"></i> Practice MCQs</a>
        <?php if ($canCreateNote): ?>
        <button class="btn btn-primary" onclick="showAddNoteModal()">
            <i class="fas fa-plus"></i> Add Study Note
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<!-- Filtering Panel -->
<div class="card learning-card mb-4">
    <div class="card-body p-4">
        <form method="GET" id="hubFilterForm" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Class</label>
                <select name="class_id" class="form-select" onchange="this.form.elements.subject_id.value='';this.form.elements.chapter.value='';this.form.submit();" required>
                    <option value="">Choose Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Subject</label>
                <select name="subject_id" class="form-select" onchange="this.form.elements.chapter.value='';this.form.submit();" required>
                    <option value="">Choose Subject</option>
                    <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $subject_id == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label fw-bold">Chapter / Unit</label>
                <select name="chapter" class="form-select" onchange="document.getElementById('hubFilterForm').submit();">
                    <option value="">Choose Chapter</option>
                    <?php foreach ($chapters as $ch): ?>
                    <option value="<?= sanitize($ch) ?>" <?= $selected_chapter === $ch ? 'selected' : '' ?>><?= sanitize($ch) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6"><select name="source" class="form-select" onchange="this.form.submit()"><?php foreach(['all'=>'All content','shared'=>'Shared catalog','school'=>'This school','mine'=>'My content'] as $value=>$label): ?><option value="<?= $value ?>" <?= $filter_source===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <?php if($canCreateNote): ?><div class="col-md-6"><select name="archived" class="form-select" onchange="this.form.submit()"><option value="">Active notes</option><option value="1" <?= $filter_archived?'selected':'' ?>>My archived notes</option></select></div><?php endif; ?>
        </form>
    </div>
</div>

<!-- Main Learning Content Area -->
<?php if (!$is_admin && $permission_status !== 'approved'): ?>
    <!-- Gated Access Warning Banner -->
    <div class="card learning-card border-0 py-5 text-center shadow-lg">
        <div class="card-body">
            <i class="fas fa-lock fa-4x text-warning mb-3"></i>
            <h4 class="fw-bold">Access Restricted to Learning Hub</h4>
            <p class="text-muted mx-auto" style="max-width: 600px;">
                School teachers and students are not directly allowed to read study notes and question answers.
                Please request permission from the system administrator to unlock the materials for your school.
            </p>

            <?php if ($permission_status === 'none'): ?>
                <a href="?action=request_hub_permission" class="btn btn-warning btn-lg fw-bold shadow-sm px-5 mt-3">
                    <i class="fas fa-paper-plane"></i> Request Access to Notes & Q&As
                </a>
            <?php elseif ($permission_status === 'requested'): ?>
                <div class="badge bg-info p-3 fs-6 rounded shadow-sm mt-3 animate-pulse">
                    <i class="fas fa-clock"></i> Access Request is Pending Administrator Approval
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Access Approved or Admin -->
    <?php if ($class_id && $subject_id && $selected_chapter): ?>
        <div class="row g-4">
            <!-- 1. Notes Section -->
            <div class="col-lg-6">
                <div class="card learning-card h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-success fw-bold"><i class="fas fa-sticky-note"></i> Study Notes</h5>
                        <span class="badge bg-success"><?= count($notes) ?> Notes Available</span>
                    </div>
                    <div class="card-body p-4" style="max-height: 60vh; overflow-y: auto;">
                        <?php if (count($notes) > 0): ?>
                            <?php foreach ($notes as $note): ?>
                            <div class="card note-item mb-3 shadow-sm border-0">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="fw-bold text-success mb-1"><?= sanitize($note['title']) ?></h6>
                                        <?php if ($note['can_edit']): ?>
                                        <div>
                                            <button class="btn btn-xs btn-outline-warning py-0 px-1" onclick="editNote(<?= htmlspecialchars(json_encode($note)) ?>)" title="Edit"><i class="fas fa-edit fa-xs"></i></button>
                                            <form method="post" class="d-inline"><input type="hidden" name="action_note" value="<?= $filter_archived?'restore':'archive' ?>"><input type="hidden" name="note_id" value="<?= $note['id'] ?>"><button class="btn btn-sm btn-outline-danger"><?= $filter_archived?'Restore':'Archive' ?></button></form>
                                            <?php if(!$filter_archived): ?><form method="post" class="d-inline" onsubmit="return confirm('Change note visibility? Public means within this school only.')"><input type="hidden" name="action_note" value="set_visibility"><input type="hidden" name="note_id" value="<?= $note['id'] ?>"><input type="hidden" name="visibility" value="<?= $note['visibility']==='private'?'public':'private' ?>"><button class="btn btn-sm btn-outline-primary"><?= $note['visibility']==='private'?'Publish':'Make private' ?></button></form><?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small mb-2"><span class="badge bg-light text-dark"><?= $note['source_scope']==='shared'?'Shared catalog':'This school' ?> · <?= $note['visibility']==='private'?'Private':'Public' ?></span></div><div class="text-muted small mb-2"><i class="fas fa-tag"></i> Topic: <?= sanitize($note['topic']) ?></div>
                                    <div style="font-size:1.125rem; line-height: 1.7;" class="mathjax-render text-dark"><?= nl2br(safeRichHtml($note['content'])) ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 text-muted"></i>
                                <p class="mb-0">No study notes added for this chapter yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 2. Q&A Practice Questions -->
            <div class="col-lg-6">
                <div class="card learning-card h-100">
                    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-orange fw-bold" style="color: #e67e22;"><i class="fas fa-question-circle"></i> Questions & Answers</h5>
                        <span class="badge bg-warning text-dark"><?= count($questions) ?> Q&As</span>
                    </div>
                    <div class="card-body p-4" style="max-height: 60vh; overflow-y: auto;">
                        <?php if (count($questions) > 0): ?>
                            <?php foreach ($questions as $q): ?>
                            <div class="card qa-item mb-3 shadow-sm border-0">
                                <div class="card-body p-3">
                                    <span class="badge bg-secondary mb-2"><?= sanitize($q['type']) ?> (<?= $q['marks'] ?> Marks)</span>

                                    <div class="fw-bold mb-2">Q. <span class="mathjax-render"><?= safeRichHtml($q['question_text']) ?></span></div>
                                    <?php if ($q['question_text_np']): ?>
                                    <div class="text-muted mb-2 font-nepali">Q. <span class="mathjax-render"><?= safeRichHtml($q['question_text_np']) ?></span></div>
                                    <?php endif; ?>

                                    <!-- MCQ Options -->
                                    <?php if ($q['type'] === 'MCQ' && $q['options']):
                                        $opts = json_decode($q['options'], true);
                                    ?>
                                        <div class="row g-2 mb-3 bg-white p-2 rounded border border-light">
                                            <div class="col-6">A: <span class="mathjax-render"><?= safeRichHtml($opts['A'] ?? '') ?></span></div>
                                            <div class="col-6">B: <span class="mathjax-render"><?= safeRichHtml($opts['B'] ?? '') ?></span></div>
                                            <div class="col-6">C: <span class="mathjax-render"><?= safeRichHtml($opts['C'] ?? '') ?></span></div>
                                            <div class="col-6">D: <span class="mathjax-render"><?= safeRichHtml($opts['D'] ?? '') ?></span></div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bg-white p-2 rounded border border-light">
                                        <strong class="text-success"><i class="fas fa-check-circle"></i> Expected Answer / Key:</strong>
                                        <div class="mt-1 mathjax-render">
                                            <?php
                                            if ($q['type'] === 'MCQ') {
                                                echo 'Correct Option: <strong>' . sanitize($q['correct_option']) . '</strong>';
                                            } else {
                                                echo !empty($q['answer']) ? safeRichHtml($q['answer']) : 'No answer guide supplied.';
                                                if ($q['answer_np']) {
                                                    echo '<hr class="my-1"><span class="text-muted font-nepali">' . safeRichHtml($q['answer_np']) . '</span>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-lightbulb fa-3x mb-2 text-muted"></i>
                                <p class="mb-0">No Q&A items mapped to this chapter in the bank.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card learning-card text-center py-5">
            <div class="card-body">
                <i class="fas fa-hand-pointer fa-4x text-muted mb-3"></i>
                <h5 class="text-dark">Select Class, Subject & Chapter</h5>
                <p class="text-muted">Use the configuration panel above to select your topic and load study notes and Q&As.</p>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Creator-owned Note Modal -->
<?php if ($canCreateNote): ?>
<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="" onsubmit="tinymce.triggerSave()">
            <input type="hidden" name="action_note" id="modalActionNote" value="add">
            <input type="hidden" name="note_id" id="modalNoteId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalNoteTitle">Add Study Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="subject_id" value="<?= $subject_id ?>">
                        <div class="col-12"><p class="text-muted"><?= sanitize($selected_class['name']) ?> · <?= sanitize($selected_subject['name_en']) ?></p></div>
                        <div class="col-md-6"><label class="form-label">Chapter</label><select name="chapter" id="modalNoteChapter" class="form-select" required><?php foreach($chapters as $ch): ?><option value="<?= sanitize($ch) ?>" <?= $selected_chapter===$ch?'selected':'' ?>><?= sanitize($ch) ?></option><?php endforeach; ?></select><div class="form-text">Select another chapter in the filter before adding notes for it.</div></div>
                        <div class="col-md-6"><label class="form-label">Topic (optional)</label><select name="topic" id="modalNoteTopic" class="form-select"><option value="">No topic</option><?php foreach($noteTopics as $t): ?><option value="<?= sanitize($t['name']) ?>"><?= sanitize($t['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label">Visibility</label><select name="visibility" id="modalNoteVisibility" class="form-select"><option value="private">Private</option><option value="public"><?= $is_admin?'Shared catalog':'Public within this school' ?></option></select></div>
                        <div class="col-12">
                            <label class="form-label">Note Title *</label>
                            <input type="text" name="title" id="modalNoteTitleInput" class="form-control" required placeholder="e.g. Understanding Coefficients & Graphs">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Content Body (HTML & MathJax LaTeX Supported) *</label>
                            <textarea name="content" id="modalNoteContent" class="form-control" rows="8" required placeholder="Type notes content here..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Note</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let noteModalObj;
$(document).ready(function() {
    noteModalObj = new bootstrap.Modal(document.getElementById('noteModal'));

    // Initialize TinyMCE on note content
    tinymce.init({
        selector: '#modalNoteContent',
        height: 350,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | fullscreen code',
        branding: false,
        promotion: false,
        setup: function (editor) {
            editor.on('change keyup', function () {
                editor.save();
                if (window.MathJax && window.MathJax.typesetPromise) {
                    window.MathJax.typesetPromise().then(() => {
                        if (typeof fixDevanagariInMathJax === 'function') {
                            fixDevanagariInMathJax();
                        }
                    });
                }
            });
        }
    });
});

function showAddNoteModal() {
    document.getElementById('modalNoteVisibility').value=<?= json_encode($is_admin?'public':'private') ?>;
    document.getElementById('modalActionNote').value = 'add';
    document.getElementById('modalNoteId').value = '';
    document.getElementById('modalNoteTitle').innerText = 'Add Study Note';

    document.getElementById('modalNoteChapter').value = '<?= sanitize($selected_chapter) ?>';
    document.getElementById('modalNoteTopic').value = '';
    document.getElementById('modalNoteTitleInput').value = '';

    if (tinymce.get('modalNoteContent')) {
        tinymce.get('modalNoteContent').setContent('');
    } else {
        document.getElementById('modalNoteContent').value = '';
    }

    noteModalObj.show();
}

function editNote(note) {
    document.getElementById('modalNoteVisibility').value=note.visibility;
    document.getElementById('modalActionNote').value = 'edit';
    document.getElementById('modalNoteId').value = note.id;
    document.getElementById('modalNoteTitle').innerText = 'Edit Study Note';

    document.getElementById('modalNoteChapter').value = note.chapter;
    document.getElementById('modalNoteTopic').value = note.topic;
    document.getElementById('modalNoteTitleInput').value = note.title;

    if (tinymce.get('modalNoteContent')) {
        tinymce.get('modalNoteContent').setContent(note.content || '');
    } else {
        document.getElementById('modalNoteContent').value = note.content || '';
    }

    noteModalObj.show();
}
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
