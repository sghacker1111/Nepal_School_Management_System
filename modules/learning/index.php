<?php
/**
 * Multi-Subject Learning Portal - Lessons & Quizzes
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireLogin();

$db = getDB();

$school_id = Auth::getSchoolId();

// Fetch active class list
$classes_stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY level, name");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

$class_id = $_GET['class'] ?? ($classes[0]['id'] ?? '');

// Fetch details for selected class
$selected_class = null;
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ? AND is_active = 1");
    $stmt->execute([$class_id, $school_id]);
    $selected_class = $stmt->fetch();
}

// Fetch subjects mapped to selected class
$class_subjects = [];
if ($class_id) {
    $stmt = $db->prepare("
        SELECT cs.*, s.name_en as subject_name, s.code as subject_code
        FROM class_subjects cs
        JOIN subjects s ON cs.subject_id = s.id
        WHERE cs.class_id = ? AND cs.school_id = ?
        ORDER BY s.name_en
    ");
    $stmt->execute([$class_id, $school_id]);
    $class_subjects = $stmt->fetchAll();
}

$subject_id = $_GET['subject'] ?? ($class_subjects[0]['subject_id'] ?? '');

// Fetch active subject details
$selected_subject = null;
if ($subject_id) {
    $stmt = $db->prepare("SELECT * FROM subjects WHERE id = ? AND school_id = ?");
    $stmt->execute([$subject_id, $school_id]);
    $selected_subject = $stmt->fetch();
}

// Load CDC curriculum dynamically from config catalog
$curriculum = require __DIR__ . '/../../config/cdc_curriculum.php';
$subject_key = $selected_subject ? $selected_subject['name_en'] : '';
$class_key = $selected_class ? $selected_class['name'] : '';

$chapters = [];
if (isset($curriculum[$subject_key][$class_key])) {
    $chapters_list = $curriculum[$subject_key][$class_key];
    foreach ($chapters_list as $index => $chap_title) {
        $num = $index + 1;
        $chapters[$num] = [
            'title' => $chap_title,
            'desc' => "This unit covers " . sanitize($chap_title) . " according to the official Curriculum Development Center (CDC) Nepal standards for " . sanitize($class_key) . " " . sanitize($subject_key) . ".",
            'key_points' => [
                "Understand the core concepts of " . sanitize($chap_title) . ".",
                "Review practice questions based on CDC specification grids.",
                "Solve exercises and tasks in the textbook."
            ],
            'q_text' => "What is the primary topic of " . sanitize($chap_title) . "?",
            'opts' => [
                'A' => "Understanding " . sanitize($chap_title),
                'B' => "Ignoring it",
                'C' => "Studying irrelevant topics",
                'D' => "None of the above"
            ],
            'correct' => 'A'
        ];
    }
}

// Fallback dynamic chapters if subject not in dict
if (empty($chapters)) {
    $chapters = [
        1 => [
            'title' => 'Unit 1: Fundamentals of ' . ($selected_subject ? sanitize($selected_subject['name_en']) : 'Subject'),
            'desc' => 'This unit introduces the basic foundations, core terms, and curriculum expectations of ' . ($selected_subject ? sanitize($selected_subject['name_en']) : 'Subject') . '.',
            'key_points' => [
                'Understand foundational vocabulary.',
                'Review key achievements and figures.',
                'Attempt exercises at the end of each session.'
            ],
            'q_text' => 'What is the first step in mastering a new academic subject?',
            'opts' => ['A'=>'Skipping basic theory', 'B'=>'Understanding basic terminologies', 'C'=>'Taking final exams directly', 'D'=>'None of the above'],
            'correct' => 'B'
        ],
        2 => [
            'title' => 'Unit 2: Applied Concepts',
            'desc' => 'This unit focuses on applying theories learned in Unit 1 to practical problem solving and real-world scenarios.',
            'key_points' => [
                'Learn to relate theories to daily observations.',
                'Conduct practical exercises.',
                'Collaborate with classmates on project assignments.'
            ],
            'q_text' => 'Which learning technique improves long-term memory retrieval?',
            'opts' => ['A'=>'Cramming the night before', 'B'=>'Practical application & self-testing', 'C'=>'Avoiding notes revision', 'D'=>'Reading passively'],
            'correct' => 'B'
        ]
    ];
}

// Fetch matching questions from database for active chapter
$active_chapter_num = isset($_GET['chapter']) ? (int)$_GET['chapter'] : 1;
$active_chapter = $chapters[$active_chapter_num] ?? ($chapters[1] ?? null);
$db_questions = [];

// Fetch all questions for the selected Class & Subject from Question Bank
$all_subject_questions = [];
if ($class_id && $subject_id) {
    $stmt = $db->prepare("
        SELECT * FROM questions 
        WHERE school_id = ? AND class_id = ? AND subject_id = ?
        ORDER BY chapter ASC, id ASC
    ");
    $stmt->execute([$school_id, $class_id, $subject_id]);
    $all_subject_questions = $stmt->fetchAll();
}

if ($class_id && $subject_id && $active_chapter) {
    // Attempt to parse chapter shortname (e.g. Algebra or Number System) from title
    $chap_title = $active_chapter['title'];
    $short_name = trim(explode('(', $chap_title)[0]);
    $short_name = trim(explode(':', $short_name)[0]);
    
    $stmt = $db->prepare("
        SELECT * FROM questions 
        WHERE school_id = ? AND class_id = ? AND subject_id = ? AND (chapter LIKE ? OR chapter LIKE ?)
    ");
    $stmt->execute([$school_id, $class_id, $subject_id, "%" . $short_name . "%", "%" . $active_chapter_num . "%"]);
    $db_questions = $stmt->fetchAll();
}

$pageTitle = 'Learning Portal';
$activePage = 'learning';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-laptop-code text-primary"></i> Learning Portal</h2>
    <?php if (Auth::hasRole(['super_admin', 'school_admin', 'teacher', 'exam_officer'])): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/learning/questions.php" class="btn btn-outline-primary">
            <i class="fas fa-database"></i> Question Bank
        </a>
        <a href="<?= APP_URL ?>/modules/learning/question_grid.php" class="btn btn-outline-success">
            <i class="fas fa-file-alt"></i> Paper Generator
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filters Panel -->
<div class="card mb-4 border-0 shadow-sm">
    <div class="card-body bg-light rounded">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-5">
                <label class="form-label font-weight-bold text-secondary">Step 1: Select Class Level</label>
                <select name="class" class="form-select" onchange="this.form.submit()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-5">
                <label class="form-label font-weight-bold text-secondary">Step 2: Select Course Subject</label>
                <select name="subject" class="form-select" onchange="this.form.submit()">
                    <?php if (empty($class_subjects)): ?>
                        <option value="">No Subjects Mapped to Class</option>
                    <?php else: ?>
                        <?php foreach ($class_subjects as $cs): ?>
                        <option value="<?= $cs['subject_id'] ?>" <?= $subject_id == $cs['subject_id'] ? 'selected' : '' ?>><?= sanitize($cs['subject_name']) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-book-reader"></i> Browse Notes
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_class && $selected_subject): ?>
<div class="row">
    <!-- Chapters Sidebar List -->
    <div class="col-md-4 mb-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary bg-opacity-10 text-primary py-3">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-list-ol"></i> Curriculum Chapters</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($chapters as $num => $chap): ?>
                <a href="<?= APP_URL ?>/modules/learning/index.php?class=<?= $class_id ?>&subject=<?= $subject_id ?>&chapter=<?= $num ?>" 
                   class="list-group-item list-group-item-action <?= $active_chapter_num == $num ? 'active' : '' ?>">
                    <?= sanitize($chap['title']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Chapter Content & Quizzes -->
    <div class="col-md-8 mb-4">
        <?php if ($active_chapter): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><?= sanitize($active_chapter['title']) ?></h5>
            </div>
            <div class="card-body p-4">
                <!-- Course Notes Section -->
                <h5 class="text-primary border-bottom pb-2 font-weight-bold"><i class="fas fa-file-alt"></i> Curriculum Course Notes</h5>
                <p class="mt-3"><?= sanitize($active_chapter['desc']) ?></p>
                
                <div class="bg-light p-3 rounded mb-4 mt-3 border-start border-primary border-3">
                    <h6 class="fw-bold"><i class="fas fa-lightbulb text-warning"></i> Key Learning Pillars:</h6>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($active_chapter['key_points'] as $kp): ?>
                        <li><?= sanitize($kp) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <!-- Practice Quiz Section -->
                <h5 class="text-success border-bottom pb-2 mb-3 mt-5 font-weight-bold"><i class="fas fa-question-circle"></i> Quick Practice Quiz</h5>
                <div class="quiz-container p-3 border rounded" id="quiz-chapter">
                    <p class="fw-bold"><?= sanitize($active_chapter['q_text']) ?></p>
                    <div class="options-container mt-2">
                        <?php foreach ($active_chapter['opts'] as $key => $val): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="q_ans" id="opt_<?= $key ?>" value="<?= $key === $active_chapter['correct'] ? 'correct' : 'incorrect' ?>">
                            <label class="form-check-label" for="opt_<?= $key ?>">
                                <strong><?= $key ?>)</strong> <?= sanitize($val) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="checkQuizAnswer('quiz-chapter')" class="btn btn-success mt-3">
                        Submit Answer
                    </button>
                    <div class="result-message alert mt-3" style="display:none;"></div>
                </div>

                <!-- Database Questions (If any added to Question Bank) -->
                <?php if (count($db_questions) > 0): ?>
                    <h5 class="text-info border-bottom pb-2 mb-3 mt-5 font-weight-bold"><i class="fas fa-cubes"></i> Additional Practice Questions</h5>
                    <div class="row g-3">
                        <?php 
                        $db_q_count = 0;
                        foreach ($db_questions as $dq): 
                            $db_q_count++;
                        ?>
                        <div class="col-12">
                            <div class="border rounded p-3 bg-white hover-shadow-sm">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="badge bg-info text-dark"><?= $dq['type'] ?></span>
                                    <span class="text-muted small">Marks: <?= $dq['marks'] ?></span>
                                </div>
                                <div class="mb-2">
                                    <p class="fw-bold mb-1">Q<?= $db_q_count ?>: <?= sanitize($dq['question_text']) ?></p>
                                    <?php if (!empty($dq['question_text_np'])): ?>
                                    <p class="text-muted mb-0 font-italic" style="font-family: inherit;">नेपाली: <?= sanitize($dq['question_text_np']) ?></p>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($dq['type'] === 'MCQ' && $dq['options']): ?>
                                    <?php 
                                    $opts_en = json_decode($dq['options'], true); 
                                    $opts_np = !empty($dq['options_np']) ? json_decode($dq['options_np'], true) : [];
                                    ?>
                                    <div class="quiz-container p-2 bg-light rounded" id="quiz-db-<?= $dq['id'] ?>">
                                        <div class="row g-2 ps-2 mt-1">
                                            <?php foreach ($opts_en as $k => $v): 
                                                $val_np = $opts_np[$k] ?? '';
                                            ?>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" name="dq_ans_<?= $dq['id'] ?>" id="opt_dq_<?= $dq['id'] ?>_<?= $k ?>" value="<?= $k === $dq['correct_option'] ? 'correct' : 'incorrect' ?>">
                                                    <label class="form-check-label" for="opt_dq_<?= $dq['id'] ?>_<?= $k ?>">
                                                        <strong><?= $k ?>)</strong> <?= sanitize($v) ?>
                                                        <?php if (!empty($val_np)): ?>
                                                        <br><span class="text-muted small">नेपाली: <?= sanitize($val_np) ?></span>
                                                        <?php endif; ?>
                                                    </label>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button onclick="checkQuizAnswer('quiz-db-<?= $dq['id'] ?>')" class="btn btn-sm btn-outline-info mt-2">
                                            Verify Option
                                        </button>
                                        <div class="result-message alert mt-2" style="display:none; padding: 0.5rem 1rem;"></div>
                                    </div>
                                <?php elseif ($dq['answer'] || $dq['answer_np']): ?>
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-outline-secondary" onclick="toggleAnswerVisibility(<?= $dq['id'] ?>)">
                                            Show/Hide Model Answer
                                        </button>
                                        <div id="ans-box-<?= $dq['id'] ?>" class="p-3 bg-light rounded mt-2 border-start border-secondary border-3" style="display: none;">
                                            <?php if ($dq['answer']): ?>
                                            <p class="mb-1 text-dark"><strong>Answer (EN):</strong> <?= nl2br(sanitize($dq['answer'])) ?></p>
                                            <?php endif; ?>
                                            <?php if ($dq['answer_np']): ?>
                                            <p class="mb-0 text-secondary border-top pt-2 mt-2"><strong>उत्तर (नेपाली):</strong> <?= nl2br(sanitize($dq['answer_np'])) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- All Class/Subject Questions Panel -->
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0"><i class="fas fa-database text-warning"></i> Question Bank for this Subject</h5>
            </div>
            <div class="card-body p-4">
                <?php if (count($all_subject_questions) > 0): ?>
                    <?php 
                    // Group by chapter
                    $grouped_qs = [];
                    foreach ($all_subject_questions as $q) {
                        $grouped_qs[$q['chapter']][] = $q;
                    }
                    ?>
                    <?php foreach ($grouped_qs as $chap_name => $qs): ?>
                    <div class="mb-4">
                        <h6 class="text-secondary bg-light p-2 rounded mb-3 border-start border-secondary border-3 fw-bold">
                            <i class="fas fa-folder-open me-2"></i> <?= sanitize($chap_name) ?>
                        </h6>
                        <div class="row g-3">
                            <?php 
                            $count = 0;
                            foreach ($qs as $q): 
                                $count++;
                            ?>
                            <div class="col-12">
                                <div class="border rounded p-3 bg-white hover-shadow-sm">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="badge bg-secondary"><?= sanitize($q['type']) ?></span>
                                        <span class="text-muted small">Marks: <?= $q['marks'] ?></span>
                                    </div>
                                    <p class="fw-bold mb-2"><?= $count ?>. <?= sanitize($q['question_text']) ?></p>
                                    <?php if ($q['type'] === 'MCQ' && $q['options']): ?>
                                        <?php $opts = json_decode($q['options'], true); ?>
                                        <div class="row g-2 ps-2" style="font-size: 0.9rem;">
                                            <?php foreach ($opts as $k => $v): ?>
                                            <div class="col-md-6 text-dark"><strong><?= $k ?>)</strong> <?= sanitize($v) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mt-2 small text-success fw-bold"><i class="fas fa-check-circle"></i> Correct Option: <?= sanitize($q['correct_option'] ?? 'N/A') ?></div>
                                    <?php elseif ($q['answer']): ?>
                                        <div class="mt-2 p-2 bg-light rounded text-dark small" style="font-family: inherit;">
                                            <strong>Answer Guide / Text:</strong><br>
                                            <?= nl2br(sanitize($q['answer'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2 text-warning"></i>
                        <p class="mb-0 small">No extra questions found in the School Question Bank for this class & subject.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card text-center py-5">
    <div class="card-body">
        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
        <h5>Please select a Class Level and Course Subject to browse notes and interactive learning portals.</h5>
    </div>
</div>
<?php endif; ?>

<script>
function checkQuizAnswer(quizId) {
    const container = document.getElementById(quizId);
    const selected = container.querySelector('input[type="radio"]:checked');
    const resultBox = container.querySelector('.result-message');
    
    resultBox.style.display = 'block';
    
    if (!selected) {
        resultBox.className = 'result-message alert alert-warning mt-3';
        resultBox.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Please choose an answer option first!';
        return;
    }
    
    if (selected.value === 'correct') {
        resultBox.className = 'result-message alert alert-success mt-3';
        resultBox.innerHTML = '<i class="fas fa-check-circle"></i> Excellent! That is the correct answer!';
        Swal.fire({
            title: 'Correct Answer!',
            text: 'Well done! Your selection is 100% correct.',
            icon: 'success',
            confirmButtonText: 'Great!'
        });
    } else {
        resultBox.className = 'result-message alert alert-danger mt-3';
        resultBox.innerHTML = '<i class="fas fa-times-circle"></i> Incorrect answer. Review the learning notes above and try again!';
    }
}

function toggleAnswerVisibility(id) {
    const box = document.getElementById('ans-box-' + id);
    if (box.style.display === 'none') {
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
