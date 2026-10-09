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
    $stmt = $db->prepare("SELECT learning_hub_permission_status FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $permission_status = $stmt->fetchColumn() ?: 'none';
}

// Handle request access action for school users
if (!$is_admin && isset($_GET['action']) && $_GET['action'] === 'request_hub_permission') {
    $stmt = $db->prepare("UPDATE schools SET learning_hub_permission_status = 'requested' WHERE id = ?");
    $stmt->execute([$school_id]);
    Auth::logActivity(Auth::getUserId(), 'Request Learning Hub Access', 'schools', $school_id);
    redirect(APP_URL . '/modules/learning/index.php', 'Your request for access has been submitted.');
}

// 2. Handle Admin Note CRUD
if ($is_admin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_note'])) {
        $action_note = $_POST['action_note'];
        $class_level = sanitize($_POST['class_level'] ?? '');
        $subject_name = sanitize($_POST['subject_name'] ?? '');
        $chapter = sanitize($_POST['chapter'] ?? '');
        $topic = sanitize($_POST['topic'] ?? '');
        $title = sanitize($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        
        if ($action_note === 'add') {
            $stmt = $db->prepare("
                INSERT INTO learning_notes (class_level, subject_name, chapter, topic, title, content)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$class_level, $subject_name, $chapter, $topic, $title, $content]);
            $success = "Learning note added successfully!";
        } elseif ($action_note === 'edit') {
            $note_id = (int)$_POST['note_id'];
            $stmt = $db->prepare("
                UPDATE learning_notes 
                SET class_level = ?, subject_name = ?, chapter = ?, topic = ?, title = ?, content = ?
                WHERE id = ?
            ");
            $stmt->execute([$class_level, $subject_name, $chapter, $topic, $title, $content, $note_id]);
            $success = "Learning note updated successfully!";
        }
    }
    
    if (isset($_GET['delete_note'])) {
        $note_id = (int)$_GET['delete_note'];
        $stmt = $db->prepare("DELETE FROM learning_notes WHERE id = ?");
        $stmt->execute([$note_id]);
        $success = "Learning note deleted successfully!";
    }
}

// 3. Classes and subjects configuration based on active school context
$classes_stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY id ASC");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

$class_id = $_GET['class_id'] ?? ($classes[0]['id'] ?? '');

$selected_class = null;
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND (school_id = ? OR school_id = 0) AND is_active = 1");
    $stmt->execute([$class_id, $school_id]);
    $selected_class = $stmt->fetch();
}

$subjects = [];
if ($class_id) {
    $stmt = $db->prepare("
        SELECT s.* FROM subjects s 
        JOIN class_subjects cs ON cs.subject_id = s.id 
        WHERE cs.class_id = ? AND (cs.school_id = ?)
        ORDER BY s.name_en
    ");
    $stmt->execute([$class_id, $school_id]);
    $subjects = $stmt->fetchAll();
}

$subject_id = $_GET['subject_id'] ?? ($subjects[0]['id'] ?? '');

$selected_subject = null;
if ($subject_id) {
    $stmt = $db->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
    $stmt->execute([$subject_id, $school_id]);
    $selected_subject = $stmt->fetch();
}

// Load dynamic chapters based on CDC curriculum mapping
$curriculum = require __DIR__ . '/../../config/cdc_curriculum.php';
$sub_name = $selected_subject ? $selected_subject['name_en'] : '';
$class_lvl_name = $selected_class ? $selected_class['name'] : '';
$chapters = $curriculum[$sub_name][$class_lvl_name] ?? [];

$selected_chapter = $_GET['chapter'] ?? ($chapters[0] ?? '');

// 4. Fetch Notes and Q&As matching selection
$notes = [];
$questions = [];

if ($class_id && $subject_id && $selected_chapter && ($is_admin || $permission_status === 'approved')) {
    // Fetch Notes
    $class_lvl = $selected_class ? $selected_class['level'] : '';
    $stmt = $db->prepare("
        SELECT * FROM learning_notes 
        WHERE class_level = ? AND subject_name = ? AND chapter = ? 
        ORDER BY id DESC
    ");
    $stmt->execute([$class_lvl, $sub_name, $selected_chapter]);
    $notes = $stmt->fetchAll();

    // Fetch Q&As from Question Bank (both Universal and school-specific)
    $stmt = $db->prepare("
        SELECT q.* FROM questions q
        LEFT JOIN classes c ON q.class_id = c.id
        LEFT JOIN subjects s ON q.subject_id = s.id
        WHERE q.chapter = ? AND (
            (q.school_id = ? AND q.class_id = ? AND q.subject_id = ?)
            OR
            (q.school_id = 0 AND c.name = ? AND (s.code = ? OR s.name_en = ?))
        )
        ORDER BY q.id DESC
    ");
    $stmt->execute([
        $selected_chapter, 
        $school_id, 
        $class_id, 
        $subject_id, 
        $selected_class['name'] ?? '',
        $selected_subject ? $selected_subject['code'] : '', 
        $sub_name
    ]);
    $questions = $stmt->fetchAll();
}

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
}
.qa-item {
    border-left: 4px solid #e67e22;
    background-color: #fffaf0;
}
</style>

<!-- TinyMCE CDN for MS Word formatting and Full-screen editor -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>

<!-- MathJax for rendering equations -->
<script>
window.MathJax = {
    tex: {
        inlineMath: [['\\(', '\\)']],
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
        <?php if ($is_admin): ?>
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

<!-- Filtering Panel -->
<div class="card learning-card mb-4">
    <div class="card-body p-4">
        <form method="GET" id="hubFilterForm" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-bold">Class</label>
                <select name="class_id" class="form-select" onchange="document.getElementById('hubFilterForm').submit();" required>
                    <option value="">Choose Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label fw-bold">Subject</label>
                <select name="subject_id" class="form-select" onchange="document.getElementById('hubFilterForm').submit();" required>
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
                    <option value="<?= $ch ?>" <?= $selected_chapter === $ch ? 'selected' : '' ?>><?= sanitize($ch) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                                        <?php if ($is_admin): ?>
                                        <div>
                                            <button class="btn btn-xs btn-outline-warning py-0 px-1" onclick="editNote(<?= htmlspecialchars(json_encode($note)) ?>)" title="Edit"><i class="fas fa-edit fa-xs"></i></button>
                                            <a href="?class_id=<?= $class_id ?>&subject_id=<?= $subject_id ?>&chapter=<?= urlencode($selected_chapter) ?>&delete_note=<?= $note['id'] ?>" class="btn btn-xs btn-outline-danger py-0 px-1" title="Delete" onclick="return confirm('Are you sure you want to delete this study note?')"><i class="fas fa-trash fa-xs"></i></a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small mb-2"><i class="fas fa-tag"></i> Topic: <?= sanitize($note['topic']) ?></div>
                                    <div style="font-size:0.9rem; line-height: 1.6;" class="mathjax-render text-dark"><?= nl2br(safeRichHtml($note['content'])) ?></div>
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
                                            <div class="col-6">A: <span class="mathjax-render"><?= sanitize($opts['A'] ?? '') ?></span></div>
                                            <div class="col-6">B: <span class="mathjax-render"><?= sanitize($opts['B'] ?? '') ?></span></div>
                                            <div class="col-6">C: <span class="mathjax-render"><?= sanitize($opts['C'] ?? '') ?></span></div>
                                            <div class="col-6">D: <span class="mathjax-render"><?= sanitize($opts['D'] ?? '') ?></span></div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="bg-white p-2 rounded border border-light">
                                        <strong class="text-success"><i class="fas fa-check-circle"></i> Expected Answer / Key:</strong>
                                        <div class="mt-1 mathjax-render">
                                            <?php 
                                            if ($q['type'] === 'MCQ') {
                                                echo 'Correct Option: <strong>' . sanitize($q['correct_option']) . '</strong>';
                                            } else {
                                                echo !empty($q['answer']) ? nl2br($q['answer']) : 'No answer guide supplied.';
                                                if ($q['answer_np']) {
                                                    echo '<hr class="my-1"><span class="text-muted font-nepali">' . nl2br($q['answer_np']) . '</span>';
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

<!-- Admin Note CRUD Modal -->
<?php if ($is_admin): ?>
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
                        <div class="col-md-6">
                            <label class="form-label">Class Level (e.g. 1, 2, 3...) *</label>
                            <input type="text" name="class_level" id="modalNoteClassLevel" class="form-control" required placeholder="e.g. 9">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Subject Name (e.g. Mathematics) *</label>
                            <input type="text" name="subject_name" id="modalNoteSubjectName" class="form-control" required placeholder="e.g. Mathematics">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Chapter / Unit *</label>
                            <input type="text" name="chapter" id="modalNoteChapter" class="form-control" required placeholder="e.g. Unit 1: Algebra">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Topic *</label>
                            <input type="text" name="topic" id="modalNoteTopic" class="form-control" required placeholder="e.g. Quadratic Equations">
                        </div>
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
    document.getElementById('modalActionNote').value = 'add';
    document.getElementById('modalNoteId').value = '';
    document.getElementById('modalNoteTitle').innerText = 'Add Study Note';
    
    document.getElementById('modalNoteClassLevel').value = '<?= $selected_class ? $selected_class['level'] : '' ?>';
    document.getElementById('modalNoteSubjectName').value = '<?= $selected_subject ? $selected_subject['name_en'] : '' ?>';
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
    document.getElementById('modalActionNote').value = 'edit';
    document.getElementById('modalNoteId').value = note.id;
    document.getElementById('modalNoteTitle').innerText = 'Edit Study Note';
    
    document.getElementById('modalNoteClassLevel').value = note.class_level;
    document.getElementById('modalNoteSubjectName').value = note.subject_name;
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
