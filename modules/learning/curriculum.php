<?php
/**
 * Curriculum & Topics Manager
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('paper_generator');

$db = getDB();
$school_id = Auth::getSchoolId();
if (Auth::getRole() === 'super_admin' && !$school_id) {
    $school_id = 0;
}

// --- AJAX API Handlers ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    if ($action === 'get_subjects') {
        $class_id = (int)($_GET['class_id'] ?? 0);
        try {
            $stmt = $db->prepare("
                SELECT s.id, s.name_en, s.code 
                FROM subjects s
                JOIN class_subjects cs ON s.id = cs.subject_id
                WHERE cs.class_id = ? AND (cs.school_id = ? OR cs.school_id = 0) AND cs.is_active = 1 AND (s.is_hidden = 0 OR s.is_hidden IS NULL)
                ORDER BY s.name_en
            ");
            $stmt->execute([$class_id, $school_id]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'subjects' => $subjects]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_all_subjects') {
        // Return all subjects to map to class
        try {
            $stmt = $db->prepare("SELECT id, name_en, code FROM subjects WHERE (school_id = ? OR school_id = 0) AND (is_hidden = 0 OR is_hidden IS NULL) ORDER BY name_en");
            $stmt->execute([$school_id]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'subjects' => $subjects]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_chapters') {
        $class_id = (int)($_GET['class_id'] ?? 0);
        $subject_id = (int)($_GET['subject_id'] ?? 0);
        try {
            $stmt = $db->prepare("
                SELECT * FROM chapters 
                WHERE class_id = ? AND subject_id = ? AND (school_id = ? OR school_id = 0)
                ORDER BY sort_order, id
            ");
            $stmt->execute([$class_id, $subject_id, $school_id]);
            $chapters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'chapters' => $chapters]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_topics') {
        $chapter_id = (int)($_GET['chapter_id'] ?? 0);
        try {
            $stmt = $db->prepare("
                SELECT * FROM topics 
                WHERE chapter_id = ? AND (school_id = ? OR school_id = 0)
                ORDER BY sort_order, id
            ");
            $stmt->execute([$chapter_id, $school_id]);
            $topics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'topics' => $topics]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// --- POST Actions Handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    try {
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

            $stmt = $db->prepare("
                INSERT INTO chapters (school_id, class_id, subject_id, name, name_np, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$school_id, $class_id, $subject_id, $name, $name_np, $sort_order]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
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

            $stmt = $db->prepare("
                UPDATE chapters 
                SET name = ?, name_np = ?, sort_order = ? 
                WHERE id = ? AND (school_id = ? OR school_id = 0)
            ");
            $stmt->execute([$name, $name_np, $sort_order, $id, $school_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete_chapter') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("DELETE FROM chapters WHERE id = ? AND (school_id = ? OR school_id = 0)");
            $stmt->execute([$id, $school_id]);
            echo json_encode(['success' => true]);
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

            $stmt = $db->prepare("
                INSERT INTO topics (school_id, class_id, subject_id, chapter_id, name, name_np, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$school_id, $class_id, $subject_id, $chapter_id, $name, $name_np, $sort_order]);
            echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
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

            $stmt = $db->prepare("
                UPDATE topics 
                SET name = ?, name_np = ?, sort_order = ? 
                WHERE id = ? AND (school_id = ? OR school_id = 0)
            ");
            $stmt->execute([$name, $name_np, $sort_order, $id, $school_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'delete_topic') {
            $id = (int)$_POST['id'];
            $stmt = $db->prepare("DELETE FROM topics WHERE id = ? AND (school_id = ? OR school_id = 0)");
            $stmt->execute([$id, $school_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($action === 'map_subject') {
            $class_id = (int)$_POST['class_id'];
            $subject_id = (int)$_POST['subject_id'];
            
            // Check mapping
            $stmt = $db->prepare("SELECT COUNT(*) FROM class_subjects WHERE class_id = ? AND subject_id = ? AND school_id = ?");
            $stmt->execute([$class_id, $subject_id, $school_id]);
            if ($stmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Subject is already mapped to this class.']);
                exit;
            }
            
            $stmt = $db->prepare("INSERT INTO class_subjects (class_id, subject_id, credit_hours, school_id) VALUES (?, ?, 4, ?)");
            $stmt->execute([$class_id, $subject_id, $school_id]);
            echo json_encode(['success' => true]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch all classes for filter
$stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? OR school_id = 0 ORDER BY level, name");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

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
        <a href="<?= APP_URL ?>/index.php" class="btn btn-outline-secondary">
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
                        <label class="form-label fw-bold text-muted small">Select Class</label>
                        <select id="curriculumClass" class="form-select" onchange="onClassChange()">
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Subject Filter -->
                    <div id="subjectSection" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label fw-bold text-muted small mb-0">Select Subject</label>
                            <button type="button" class="btn btn-link p-0 text-primary small text-decoration-none fw-bold" onclick="showMapSubjectModal()"><i class="fas fa-link"></i> Map Subject</button>
                        </div>
                        <select id="curriculumSubject" class="form-select" onchange="onSubjectChange()">
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
                    <button type="button" class="btn btn-success btn-sm px-3 d-none" id="btnAddChapter" onclick="showAddChapterModal()">
                        <i class="fas fa-plus me-1"></i> Add Chapter
                    </button>
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
<div class="modal fade" id="mapSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content border-0 shadow-lg" style="border-radius:16px;" onsubmit="mapSubjectSubmit(event)">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-link"></i> Map Subject to Class</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4">Map Subject</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Chapter Modal -->
<div class="modal fade" id="chapterModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content border-0 shadow-lg" style="border-radius:16px;" onsubmit="chapterSubmit(event)">
            <input type="hidden" id="chapterModalAction" value="add">
            <input type="hidden" id="chapterModalId">
            <div class="modal-header bg-success text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="chapterModalTitle"><i class="fas fa-plus"></i> Add Chapter</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success px-4" id="chapterModalBtn">Save Chapter</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Topic Modal -->
<div class="modal fade" id="topicModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content border-0 shadow-lg" style="border-radius:16px;" onsubmit="topicSubmit(event)">
            <input type="hidden" id="topicModalAction" value="add">
            <input type="hidden" id="topicModalId">
            <input type="hidden" id="topicModalChapterId">
            <div class="modal-header bg-info text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="topicModalTitle"><i class="fas fa-plus"></i> Add Topic</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info text-white px-4" id="topicModalBtn">Save Topic</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentClassId = 0;
let currentSubjectId = 0;

function onClassChange() {
    const classSelect = document.getElementById('curriculumClass');
    currentClassId = parseInt(classSelect.value) || 0;
    
    const subjectSection = document.getElementById('subjectSection');
    const subjectSelect = document.getElementById('curriculumSubject');
    
    if (currentClassId === 0) {
        subjectSection.style.display = 'none';
        resetTreeContainer();
        return;
    }
    
    // Load subjects for selected class
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_subjects', class_id: currentClassId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                subjectSelect.innerHTML = '<option value="">-- Choose Subject --</option>';
                res.subjects.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name_en + ' (' + sub.code + ')';
                    subjectSelect.appendChild(opt);
                });
                subjectSection.style.display = 'block';
                document.getElementById('curriculumInstructions').classList.remove('d-none');
                resetTreeContainer();
            } else {
                alert("Error loading subjects: " + res.message);
            }
        }
    });
}

function onSubjectChange() {
    const subjectSelect = document.getElementById('curriculumSubject');
    currentSubjectId = parseInt(subjectSelect.value) || 0;
    
    if (currentSubjectId === 0) {
        resetTreeContainer();
        return;
    }
    
    loadChaptersTree();
}

function resetTreeContainer() {
    document.getElementById('placeholderView').style.display = 'block';
    document.getElementById('treeContainer').style.display = 'none';
    document.getElementById('btnAddChapter').classList.add('d-none');
}

function loadChaptersTree() {
    document.getElementById('placeholderView').style.display = 'none';
    document.getElementById('treeContainer').style.display = 'block';
    document.getElementById('btnAddChapter').classList.remove('d-none');
    
    const accordion = document.getElementById('chaptersAccordion');
    accordion.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';
    
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_chapters', class_id: currentClassId, subject_id: currentSubjectId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.chapters.length === 0) {
                    accordion.innerHTML = `
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-folder-open fa-2x mb-2"></i>
                            <p class="mb-0">No chapters added yet for this subject.</p>
                            <button class="btn btn-sm btn-outline-success mt-2" onclick="showAddChapterModal()"><i class="fas fa-plus"></i> Add First Chapter</button>
                        </div>
                    `;
                    return;
                }
                
                accordion.innerHTML = '';
                res.chapters.forEach(ch => {
                    const chapterItem = document.createElement('div');
                    chapterItem.className = 'accordion-item border mb-3 rounded-3 overflow-hidden shadow-sm';
                    chapterItem.innerHTML = `
                        <h2 class="accordion-header" id="headingCh_${ch.id}">
                            <div class="accordion-button collapsed bg-light d-flex justify-content-between align-items-center" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCh_${ch.id}">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-folder text-warning me-2"></i>
                                    <div>
                                        <span class="fw-bold">${ch.name}</span>
                                        ${ch.name_np ? `<span class="text-muted ms-2">(${ch.name_np})</span>` : ''}
                                    </div>
                                </div>
                                <div class="ms-auto me-3 no-print d-flex gap-1" onclick="event.stopPropagation()">
                                    <button class="btn btn-xs btn-outline-primary px-2" onclick="showEditChapterModal(${ch.id}, '${ch.name}', '${ch.name_np || ''}', ${ch.sort_order})">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-xs btn-outline-danger px-2" onclick="deleteChapter(${ch.id})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="btn btn-xs btn-success px-2 ms-2 text-white" onclick="showAddTopicModal(${ch.id})">
                                        <i class="fas fa-plus"></i> Add Topic
                                    </button>
                                </div>
                            </div>
                        </h2>
                        <div id="collapseCh_${ch.id}" class="accordion-collapse collapse" data-bs-parent="#chaptersAccordion" onshow="loadTopicsList(${ch.id})">
                            <div class="accordion-body bg-white" id="topicsContainer_${ch.id}">
                                <div class="text-center py-2"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div></div>
                            </div>
                        </div>
                    `;
                    accordion.appendChild(chapterItem);
                });
            } else {
                accordion.innerHTML = '<div class="alert alert-danger">Error: ' + res.message + '</div>';
            }
        }
    });
}

function loadTopicsList(chapterId) {
    const container = document.getElementById('topicsContainer_' + chapterId);
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_topics', chapter_id: chapterId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.topics.length === 0) {
                    container.innerHTML = `
                        <div class="text-center text-muted py-3">
                            <p class="mb-0 small">No topics added for this chapter.</p>
                            <button class="btn btn-xs btn-outline-success mt-2" onclick="showAddTopicModal(${chapterId})"><i class="fas fa-plus"></i> Add First Topic</button>
                        </div>
                    `;
                    return;
                }
                
                let listHtml = '<ul class="list-group list-group-flush">';
                res.topics.forEach(t => {
                    listHtml += `
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-1">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file-alt text-info me-2 small"></i>
                                <span>${t.name}</span>
                                ${t.name_np ? `<span class="text-muted ms-2">(${t.name_np})</span>` : ''}
                            </div>
                            <div class="no-print d-flex gap-1">
                                <button class="btn btn-xs btn-link text-primary p-0" onclick="showEditTopicModal(${t.id}, ${chapterId}, '${t.name}', '${t.name_np || ''}', ${t.sort_order})" title="Edit Topic">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-xs btn-link text-danger p-0" onclick="deleteTopic(${t.id}, ${chapterId})" title="Delete Topic">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </li>
                    `;
                });
                listHtml += '</ul>';
                container.innerHTML = listHtml;
            } else {
                container.innerHTML = '<span class="text-danger small">Error loading topics.</span>';
            }
        }
    });
}

// Modal Triggers & Submissions
function showMapSubjectModal() {
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_all_subjects' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                const select = document.getElementById('modalMapSubjectSelect');
                select.innerHTML = '';
                res.subjects.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name_en + ' (' + sub.code + ')';
                    select.appendChild(opt);
                });
                const modal = new bootstrap.Modal(document.getElementById('mapSubjectModal'));
                modal.show();
            }
        }
    });
}

function mapSubjectSubmit(e) {
    e.preventDefault();
    const subjectId = document.getElementById('modalMapSubjectSelect').value;
    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: {
            action: 'map_subject',
            class_id: currentClassId,
            subject_id: subjectId
        },
        dataType: 'json',
        success: function(res) {
            bootstrap.Modal.getInstance(document.getElementById('mapSubjectModal')).hide();
            if (res.success) {
                Swal.fire('Success', 'Subject mapped successfully to class.', 'success');
                onClassChange();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

// Chapter modals
function showAddChapterModal() {
    document.getElementById('chapterModalAction').value = 'add';
    document.getElementById('chapterModalTitle').textContent = 'Add Chapter';
    document.getElementById('modalChapterName').value = '';
    document.getElementById('modalChapterNameNp').value = '';
    document.getElementById('modalChapterSort').value = '0';
    
    const modal = new bootstrap.Modal(document.getElementById('chapterModal'));
    modal.show();
}

function showEditChapterModal(id, name, nameNp, sortOrder) {
    document.getElementById('chapterModalAction').value = 'edit';
    document.getElementById('chapterModalId').value = id;
    document.getElementById('chapterModalTitle').textContent = 'Edit Chapter';
    document.getElementById('modalChapterName').value = name;
    document.getElementById('modalChapterNameNp').value = nameNp;
    document.getElementById('modalChapterSort').value = sortOrder;
    
    const modal = new bootstrap.Modal(document.getElementById('chapterModal'));
    modal.show();
}

function chapterSubmit(e) {
    e.preventDefault();
    const action = document.getElementById('chapterModalAction').value;
    const name = document.getElementById('modalChapterName').value;
    const nameNp = document.getElementById('modalChapterNameNp').value;
    const sort = document.getElementById('modalChapterSort').value;
    const id = document.getElementById('chapterModalId').value;
    
    const postData = {
        action: action === 'add' ? 'add_chapter' : 'edit_chapter',
        class_id: currentClassId,
        subject_id: currentSubjectId,
        name: name,
        name_np: nameNp,
        sort_order: sort,
        id: id
    };
    
    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(res) {
            bootstrap.Modal.getInstance(document.getElementById('chapterModal')).hide();
            if (res.success) {
                loadChaptersTree();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

function deleteChapter(id) {
    Swal.fire({
        title: 'Delete Chapter',
        text: 'Are you sure you want to delete this chapter and all its topics? This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                data: { action: 'delete_chapter', id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        loadChaptersTree();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

// Topic Modals
function showAddTopicModal(chapterId) {
    document.getElementById('topicModalAction').value = 'add';
    document.getElementById('topicModalChapterId').value = chapterId;
    document.getElementById('topicModalTitle').textContent = 'Add Topic';
    document.getElementById('modalTopicName').value = '';
    document.getElementById('modalTopicNameNp').value = '';
    document.getElementById('modalTopicSort').value = '0';
    
    const modal = new bootstrap.Modal(document.getElementById('topicModal'));
    modal.show();
}

function showEditTopicModal(id, chapterId, name, nameNp, sortOrder) {
    document.getElementById('topicModalAction').value = 'edit';
    document.getElementById('topicModalId').value = id;
    document.getElementById('topicModalChapterId').value = chapterId;
    document.getElementById('topicModalTitle').textContent = 'Edit Topic';
    document.getElementById('modalTopicName').value = name;
    document.getElementById('modalTopicNameNp').value = nameNp;
    document.getElementById('modalTopicSort').value = sortOrder;
    
    const modal = new bootstrap.Modal(document.getElementById('topicModal'));
    modal.show();
}

function topicSubmit(e) {
    e.preventDefault();
    const action = document.getElementById('topicModalAction').value;
    const name = document.getElementById('modalTopicName').value;
    const nameNp = document.getElementById('modalTopicNameNp').value;
    const sort = document.getElementById('modalTopicSort').value;
    const id = document.getElementById('topicModalId').value;
    const chapterId = document.getElementById('topicModalChapterId').value;
    
    const postData = {
        action: action === 'add' ? 'add_topic' : 'edit_topic',
        class_id: currentClassId,
        subject_id: currentSubjectId,
        chapter_id: chapterId,
        name: name,
        name_np: nameNp,
        sort_order: sort,
        id: id
    };
    
    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(res) {
            bootstrap.Modal.getInstance(document.getElementById('topicModal')).hide();
            if (res.success) {
                // Reload specific topics container
                loadTopicsList(chapterId);
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

function deleteTopic(id, chapterId) {
    Swal.fire({
        title: 'Delete Topic',
        text: 'Are you sure you want to delete this topic? This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                data: { action: 'delete_topic', id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        loadTopicsList(chapterId);
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
