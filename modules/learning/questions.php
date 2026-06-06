<?php
/**
 * Learning Portal - Question Bank CRUD
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'teacher', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';
$success = '';

// Handle Add/Edit Question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $class_id = (int)$_POST['class_id'];
    $subject_id = (int)$_POST['subject_id'];
    $chapter = sanitize($_POST['chapter'] ?? '');
    $type = sanitize($_POST['type'] ?? 'MCQ');
    $question_text = sanitize($_POST['question_text'] ?? '');
    $question_text_np = sanitize($_POST['question_text_np'] ?? '');
    $marks = (int)($_POST['marks'] ?? 1);
    $answer = sanitize($_POST['answer'] ?? '');
    $answer_np = sanitize($_POST['answer_np'] ?? '');
    
    // MCQ Options
    $options_json = null;
    $options_np_json = null;
    $correct_option = null;
    if ($type === 'MCQ') {
        $opts = [
            'A' => sanitize($_POST['option_a'] ?? ''),
            'B' => sanitize($_POST['option_b'] ?? ''),
            'C' => sanitize($_POST['option_c'] ?? ''),
            'D' => sanitize($_POST['option_d'] ?? '')
        ];
        $options_json = json_encode($opts);
        
        $opts_np = [
            'A' => sanitize($_POST['option_a_np'] ?? ''),
            'B' => sanitize($_POST['option_b_np'] ?? ''),
            'C' => sanitize($_POST['option_c_np'] ?? ''),
            'D' => sanitize($_POST['option_d_np'] ?? '')
        ];
        $options_np_json = json_encode($opts_np);
        
        $correct_option = sanitize($_POST['correct_option'] ?? 'A');
    }
    
    if (empty($class_id) || empty($subject_id) || empty($chapter) || empty($question_text)) {
        $error = "All fields except options are required.";
    } else {
        try {
            // Verify class and subject ownership
            $chk_class = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
            $chk_class->execute([$class_id, $school_id]);
            $chk_sub = $db->prepare("SELECT id FROM subjects WHERE id = ? AND school_id = ?");
            $chk_sub->execute([$subject_id, $school_id]);
            
            if (!$chk_class->fetch() || !$chk_sub->fetch()) {
                $error = "Unauthorized class or subject selection.";
            } else {
                if ($action === 'add') {
                    $stmt = $db->prepare("
                        INSERT INTO questions (class_id, subject_id, chapter, type, question_text, question_text_np, options, options_np, correct_option, marks, answer, answer_np, school_id) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$class_id, $subject_id, $chapter, $type, $question_text, $question_text_np, $options_json, $options_np_json, $correct_option, $marks, $answer, $answer_np, $school_id]);
                    Auth::logActivity(Auth::getUserId(), 'Add Question to Bank', 'questions', $db->lastInsertId());
                    $success = "Question added to bank successfully.";
                } elseif ($action === 'edit') {
                    $question_id = (int)$_POST['question_id'];
                    $stmt = $db->prepare("
                        UPDATE questions 
                        SET class_id = ?, subject_id = ?, chapter = ?, type = ?, question_text = ?, question_text_np = ?, options = ?, options_np = ?, correct_option = ?, marks = ?, answer = ?, answer_np = ? 
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$class_id, $subject_id, $chapter, $type, $question_text, $question_text_np, $options_json, $options_np_json, $correct_option, $marks, $answer, $answer_np, $question_id, $school_id]);
                    Auth::logActivity(Auth::getUserId(), 'Edit Question in Bank', 'questions', $question_id);
                    $success = "Question updated successfully.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle Delete Question
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        $stmt = $db->prepare("DELETE FROM questions WHERE id = ? AND school_id = ?");
        $stmt->execute([$id, $school_id]);
        Auth::logActivity(Auth::getUserId(), 'Delete Question from Bank', 'questions', $id);
        redirect(APP_URL . '/modules/learning/questions.php', 'Question deleted successfully.');
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Fetch Filters
$filter_class = $_GET['class'] ?? '';
$filter_subject = $_GET['subject'] ?? '';

// Build Query
$query = "
    SELECT q.*, c.name as class_name, s.name_en as subject_name 
    FROM questions q 
    JOIN classes c ON q.class_id = c.id 
    JOIN subjects s ON q.subject_id = s.id 
    WHERE q.school_id = ? AND c.is_active = 1
";
$params = [$school_id];
if ($filter_class) {
    $query .= " AND q.class_id = ?";
    $params[] = $filter_class;
}
if ($filter_subject) {
    $query .= " AND q.subject_id = ?";
    $params[] = $filter_subject;
}
$query .= " ORDER BY q.id DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$questions = $stmt->fetchAll();

// Fetch classes and subjects for selects
$classes_stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY level, name");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

$subjects_stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY name_en");
$subjects_stmt->execute([$school_id]);
$subjects = $subjects_stmt->fetchAll();

$pageTitle = 'Question Bank CRUD';
$activePage = 'learning';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-database text-primary"></i> Question Bank CRUD</h2>
    <div>
        <a href="<?= APP_URL ?>/modules/learning/index.php" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Portal
        </a>
        <button class="btn btn-primary" onclick="showAddModal()">
            <i class="fas fa-plus"></i> Add Question
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Filter Class</label>
                <select name="class" class="form-select" onchange="this.form.submit()">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_class == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Filter Subject</label>
                <select name="subject" class="form-select" onchange="this.form.submit()">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $filter_subject == $s['id'] ? 'selected' : '' ?>><?= sanitize($s['name_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="<?= APP_URL ?>/modules/learning/questions.php" class="btn btn-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Questions List Table -->
<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Registered Questions List (<?= count($questions) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Class & Subject</th>
                        <th>Chapter</th>
                        <th>Type</th>
                        <th>Question Snippet</th>
                        <th>Marks</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($questions) > 0): ?>
                        <?php foreach ($questions as $q): ?>
                        <tr>
                            <td>
                                <strong><?= sanitize($q['class_name']) ?></strong><br>
                                <small class="text-muted"><?= sanitize($q['subject_name']) ?></small>
                            </td>
                            <td><?= sanitize($q['chapter']) ?></td>
                            <td>
                                <?php
                                $badge = [
                                    'MCQ' => 'bg-primary',
                                    'Short' => 'bg-success',
                                    'Long' => 'bg-danger'
                                ][$q['type']] ?? 'bg-secondary';
                                ?>
                                <span class="badge <?= $badge ?>"><?= $q['type'] ?></span>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 300px;" title="<?= sanitize($q['question_text']) ?>">
                                    <strong>EN:</strong> <?= sanitize($q['question_text']) ?>
                                </div>
                                <?php if (!empty($q['question_text_np'])): ?>
                                <div class="text-truncate text-muted small" style="max-width: 300px;" title="<?= sanitize($q['question_text_np']) ?>">
                                    <strong>NP:</strong> <?= sanitize($q['question_text_np']) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace fw-bold"><?= $q['marks'] ?></td>
                            <td class="text-end">
                                <button onclick="editQuestion(<?= htmlspecialchars(json_encode($q)) ?>)" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="<?= APP_URL ?>/modules/learning/questions.php?delete=<?= $q['id'] ?>" 
                                   class="btn btn-sm btn-outline-danger" title="Delete"
                                   onclick="return confirm('Are you sure you want to delete this question?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No questions found in database matching selection.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Question Modal -->
<div class="modal fade" id="questionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="" class="needs-validation" novalidate>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="question_id" id="modalQuestionId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Question</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Class *</label>
                            <select name="class_id" id="modalClass" class="form-select" required>
                                <option value="">Choose Class</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Subject *</label>
                            <select name="subject_id" id="modalSubject" class="form-select" required>
                                <option value="">Choose Subject</option>
                                <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= sanitize($s['name_en']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Chapter / Unit *</label>
                            <select id="modalChapterSelect" class="form-select" onchange="onChapterSelectChange()">
                                <!-- Populated dynamically -->
                            </select>
                            <input type="hidden" name="chapter" id="modalChapter">
                            <input type="text" id="modalChapterCustom" class="form-control mt-2" placeholder="Type custom chapter..." style="display:none;" oninput="updateChapterHidden()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Question Type *</label>
                            <select name="type" id="modalType" class="form-select" onchange="toggleMCQFields()" required>
                                <option value="MCQ">Multiple Choice (MCQ)</option>
                                <option value="Short">Short Answer</option>
                                <option value="Long">Long Answer</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Marks Weightage *</label>
                            <input type="number" name="marks" id="modalMarks" class="form-control" value="1" required min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Question Text (English) *</label>
                            <textarea name="question_text" id="modalQuestionText" class="form-control" rows="3" required placeholder="Type English question here..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Question Text (Nepali)</label>
                            <textarea name="question_text_np" id="modalQuestionTextNp" class="form-control" rows="3" placeholder="नेपालीमा प्रश्न यहाँ लेखन्होस्..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Short/Long Answer model answer box -->
                    <div id="answerDetails" class="mt-4 border-top pt-3" style="display:none;">
                        <h6 class="text-primary mb-3"><i class="fas fa-key"></i> Model Answer (Optional)</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Expected Answer (English)</label>
                                <textarea name="answer" id="modalAnswer" class="form-control" rows="3" placeholder="Type expected English answer here (optional)..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expected Answer (Nepali)</label>
                                <textarea name="answer_np" id="modalAnswerNp" class="form-control" rows="3" placeholder="नेपालीमा अपेक्षित उत्तर यहाँ लेख्नुहोस् (ऐच्छिक)..."></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- MCQ Details -->
                    <div id="mcqDetails" class="mt-4 border-top pt-3">
                        <h6 class="text-primary mb-3"><i class="fas fa-list-ol"></i> Multiple Choice Options & Key</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Option A (English) *</label>
                                <input type="text" name="option_a" id="modalOptA" class="form-control mcq-field" placeholder="Answer option A (English)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Option A (Nepali)</label>
                                <input type="text" name="option_a_np" id="modalOptANp" class="form-control" placeholder="उत्तर विकल्प क (नेपाली)">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Option B (English) *</label>
                                <input type="text" name="option_b" id="modalOptB" class="form-control mcq-field" placeholder="Answer option B (English)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Option B (Nepali)</label>
                                <input type="text" name="option_b_np" id="modalOptBNp" class="form-control" placeholder="उत्तर विकल्प ख (नेपाली)">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Option C (English) *</label>
                                <input type="text" name="option_c" id="modalOptC" class="form-control mcq-field" placeholder="Answer option C (English)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Option C (Nepali)</label>
                                <input type="text" name="option_c_np" id="modalOptCNp" class="form-control" placeholder="उत्तर विकल्प ग (नेपाली)">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Option D (English) *</label>
                                <input type="text" name="option_d" id="modalOptD" class="form-control mcq-field" placeholder="Answer option D (English)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Option D (Nepali)</label>
                                <input type="text" name="option_d_np" id="modalOptDNp" class="form-control" placeholder="उत्तर विकल्प घ (नेपाली)">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Correct Option Key *</label>
                                <select name="correct_option" id="modalCorrect" class="form-select">
                                    <option value="A">A / क</option>
                                    <option value="B">B / ख</option>
                                    <option value="C">C / ग</option>
                                    <option value="D">D / घ</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Question</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const classesMap = <?= json_encode(array_column($classes, 'name', 'id')) ?>;
const subjectsMap = <?= json_encode(array_column($subjects, 'name_en', 'id')) ?>;
const cdcCurriculum = <?= json_encode(require __DIR__ . '/../../config/cdc_curriculum.php') ?>;

let questionModalObj;

$(document).ready(function() {
    questionModalObj = new bootstrap.Modal(document.getElementById('questionModal'));
    
    document.getElementById('modalClass').addEventListener('change', () => updateChaptersDropdown());
    document.getElementById('modalSubject').addEventListener('change', () => updateChaptersDropdown());
});

function updateChaptersDropdown(selectedChapterValue = '') {
    const classId = document.getElementById('modalClass').value;
    const subjectId = document.getElementById('modalSubject').value;
    
    const className = classesMap[classId] || '';
    const subjectName = subjectsMap[subjectId] || '';
    
    const select = document.getElementById('modalChapterSelect');
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');
    
    // Clear options
    select.innerHTML = '';
    
    let chapters = [];
    if (cdcCurriculum[subjectName] && cdcCurriculum[subjectName][className]) {
        chapters = cdcCurriculum[subjectName][className];
    }
    
    if (chapters.length > 0) {
        // Add options
        chapters.forEach(ch => {
            const opt = document.createElement('option');
            opt.value = ch;
            opt.textContent = ch;
            select.appendChild(opt);
        });
        
        // Add custom option
        const optCustom = document.createElement('option');
        optCustom.value = '__custom__';
        optCustom.textContent = 'Other / Custom Chapter';
        select.appendChild(optCustom);
        
        // Set selected value
        if (selectedChapterValue) {
            if (chapters.includes(selectedChapterValue)) {
                select.value = selectedChapterValue;
                customInput.style.display = 'none';
                hiddenInput.value = selectedChapterValue;
            } else {
                select.value = '__custom__';
                customInput.style.display = 'block';
                customInput.value = selectedChapterValue;
                hiddenInput.value = selectedChapterValue;
            }
        } else {
            // default to first chapter
            hiddenInput.value = select.value;
            customInput.style.display = 'none';
        }
    } else {
        // No preloaded chapters, force custom text input
        const optCustom = document.createElement('option');
        optCustom.value = '__custom__';
        optCustom.textContent = 'Type Custom Chapter';
        select.appendChild(optCustom);
        
        select.value = '__custom__';
        customInput.style.display = 'block';
        customInput.value = selectedChapterValue || '';
        hiddenInput.value = selectedChapterValue || '';
    }
}

function onChapterSelectChange() {
    const select = document.getElementById('modalChapterSelect');
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');
    
    if (select.value === '__custom__') {
        customInput.style.display = 'block';
        hiddenInput.value = customInput.value;
    } else {
        customInput.style.display = 'none';
        hiddenInput.value = select.value;
    }
}

function updateChapterHidden() {
    const customInput = document.getElementById('modalChapterCustom');
    const hiddenInput = document.getElementById('modalChapter');
    hiddenInput.value = customInput.value;
}
function showAddModal() {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalQuestionId').value = '';
    document.getElementById('modalTitle').innerText = 'Add New Question';
    document.getElementById('modalClass').value = '';
    document.getElementById('modalSubject').value = '';
    document.getElementById('modalChapter').value = '';
    document.getElementById('modalType').value = 'MCQ';
    document.getElementById('modalMarks').value = '1';
    document.getElementById('modalQuestionText').value = '';
    document.getElementById('modalQuestionTextNp').value = '';
    document.getElementById('modalAnswer').value = '';
    document.getElementById('modalAnswerNp').value = '';
    
    document.getElementById('modalOptA').value = '';
    document.getElementById('modalOptANp').value = '';
    document.getElementById('modalOptB').value = '';
    document.getElementById('modalOptBNp').value = '';
    document.getElementById('modalOptC').value = '';
    document.getElementById('modalOptCNp').value = '';
    document.getElementById('modalOptD').value = '';
    document.getElementById('modalOptDNp').value = '';
    document.getElementById('modalCorrect').value = 'A';
    
    updateChaptersDropdown('');
    toggleMCQFields();
    questionModalObj.show();
}

function editQuestion(q) {
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalQuestionId').value = q.id;
    document.getElementById('modalTitle').innerText = 'Edit Question Details';
    document.getElementById('modalClass').value = q.class_id;
    document.getElementById('modalSubject').value = q.subject_id;
    document.getElementById('modalChapter').value = q.chapter;
    document.getElementById('modalType').value = q.type;
    document.getElementById('modalMarks').value = q.marks;
    document.getElementById('modalQuestionText').value = q.question_text;
    document.getElementById('modalQuestionTextNp').value = q.question_text_np || '';
    document.getElementById('modalAnswer').value = q.answer || '';
    document.getElementById('modalAnswerNp').value = q.answer_np || '';
    
    if (q.type === 'MCQ') {
        const opts = JSON.parse(q.options || '{}');
        document.getElementById('modalOptA').value = opts.A || '';
        document.getElementById('modalOptB').value = opts.B || '';
        document.getElementById('modalOptC').value = opts.C || '';
        document.getElementById('modalOptD').value = opts.D || '';
        
        const optsNp = JSON.parse(q.options_np || '{}');
        document.getElementById('modalOptANp').value = optsNp.A || '';
        document.getElementById('modalOptBNp').value = optsNp.B || '';
        document.getElementById('modalOptCNp').value = optsNp.C || '';
        document.getElementById('modalOptDNp').value = optsNp.D || '';
        
        document.getElementById('modalCorrect').value = q.correct_option || 'A';
    } else {
        document.getElementById('modalOptA').value = '';
        document.getElementById('modalOptANp').value = '';
        document.getElementById('modalOptB').value = '';
        document.getElementById('modalOptBNp').value = '';
        document.getElementById('modalOptC').value = '';
        document.getElementById('modalOptCNp').value = '';
        document.getElementById('modalOptD').value = '';
        document.getElementById('modalOptDNp').value = '';
    }
    
    updateChaptersDropdown(q.chapter);
    toggleMCQFields();
    questionModalObj.show();
}
function toggleMCQFields() {
    const type = document.getElementById('modalType').value;
    const mcqDiv = document.getElementById('mcqDetails');
    const ansDiv = document.getElementById('answerDetails');
    const marksField = document.getElementById('modalMarks');
    const mcqInputs = mcqDiv.querySelectorAll('.mcq-field');
    
    if (type === 'MCQ') {
        mcqDiv.style.display = 'block';
        ansDiv.style.display = 'none';
        mcqInputs.forEach(i => i.setAttribute('required', 'required'));
        if (marksField.value == '4' || marksField.value == '8') {
            marksField.value = '1';
        }
    } else {
        mcqDiv.style.display = 'none';
        ansDiv.style.display = 'block';
        mcqInputs.forEach(i => i.removeAttribute('required'));
        if (type === 'Short' && marksField.value == '1') {
            marksField.value = '4';
        } else if (type === 'Long' && marksField.value == '1') {
            marksField.value = '8';
        }
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
