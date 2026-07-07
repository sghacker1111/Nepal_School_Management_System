<?php
/**
 * Specification Grids Management Section
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('specification_grids');

$db = getDB();
$school_id = Auth::getSchoolId();
$is_admin = (Auth::getRole() === 'super_admin');
$error = '';
$success = '';

// Handle Delete Action
if (isset($_GET['delete']) && $school_id) {
    $delete_id = (int)$_GET['delete'];
    try {
        $stmt = $db->prepare("DELETE FROM specification_grids WHERE id = ? AND school_id = ?");
        $stmt->execute([$delete_id, $school_id]);
        Auth::logActivity(Auth::getUserId(), 'Delete Specification Grid', 'specification_grids', $delete_id);
        redirect(APP_URL . '/modules/learning/specification_grids.php', 'Specification grid deleted successfully.', 'success');
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle Add/Edit Grid Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $grid_name = sanitize($_POST['name'] ?? '');
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    
    // Parse grid configuration counts and marks
    $config = [];
    $types = ['MCQ', 'Very Short', 'Short', 'Long', 'Reasoning', 'Difference Between'];
    foreach ($types as $type) {
        $safe_type = str_replace(' ', '_', $type);
        $count = (int)($_POST["count_$safe_type"] ?? 0);
        $marks = (int)($_POST["marks_$safe_type"] ?? 1);
        $config[$type] = [
            'count' => $count,
            'marks' => $marks
        ];
    }
    $config_json = json_encode($config);
    
    if (empty($grid_name) || !$class_id || !$subject_id) {
        $error = "Grid name, Class, and Subject are required.";
    } else {
        try {
            if ($action === 'add') {
                $stmt = $db->prepare("
                    INSERT INTO specification_grids (school_id, class_id, subject_id, name, config_json)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$school_id, $class_id, $subject_id, $grid_name, $config_json]);
                $grid_id = $db->lastInsertId();
                Auth::logActivity(Auth::getUserId(), 'Create Specification Grid', 'specification_grids', $grid_id);
                redirect(APP_URL . '/modules/learning/specification_grids.php', 'Specification grid created successfully.', 'success');
            } elseif ($action === 'edit') {
                $grid_id = (int)($_POST['grid_id'] ?? 0);
                $stmt = $db->prepare("
                    UPDATE specification_grids 
                    SET name = ?, class_id = ?, subject_id = ?, config_json = ? 
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$grid_name, $class_id, $subject_id, $config_json, $grid_id, $school_id]);
                Auth::logActivity(Auth::getUserId(), 'Update Specification Grid', 'specification_grids', $grid_id);
                redirect(APP_URL . '/modules/learning/specification_grids.php', 'Specification grid updated successfully.', 'success');
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch all saved grids for current school
$grids = [];
if ($school_id) {
    $stmt = $db->prepare("
        SELECT sg.*, c.name as class_name, s.name_en as subject_name 
        FROM specification_grids sg
        LEFT JOIN classes c ON sg.class_id = c.id
        LEFT JOIN subjects s ON sg.subject_id = s.id
        WHERE sg.school_id = ?
        ORDER BY sg.id DESC
    ");
    $stmt->execute([$school_id]);
    $grids = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch classes and subjects for select elements
$classes = [];
$subjects = [];
if ($school_id) {
    $stmt = $db->prepare("SELECT * FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY level, name");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT * FROM subjects WHERE school_id = ? ORDER BY name_en");
    $stmt->execute([$school_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Specification Grids';
$activePage = 'specification_grids';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-border-all text-primary"></i> Saved Specification Grids</h2>
    <div>
        <button class="btn btn-primary" onclick="showAddForm()">
            <i class="fas fa-plus"></i> Create New Grid Layout
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<div id="gridsListSection">
    <!-- Saved Grids List Table -->
    <div class="card border-0 shadow-sm" style="border-radius:16px; overflow:hidden;">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 text-primary fw-bold">Active Presets List (<?= count($grids) ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 5%;">ID</th>
                            <th style="width: 25%;">Grid Layout Name</th>
                            <th style="width: 20%;">Class Level</th>
                            <th style="width: 20%;">Subject</th>
                            <th style="width: 15%;">Total Weightage</th>
                            <th class="text-end" style="width: 15%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($grids) > 0): ?>
                            <?php foreach ($grids as $g): 
                                $config = json_decode($g['config_json'], true) ?: [];
                                $total_marks = 0;
                                foreach ($config as $t => $info) {
                                    $total_marks += ($info['count'] ?? 0) * ($info['marks'] ?? 0);
                                }
                            ?>
                            <tr>
                                <td><code>#<?= $g['id'] ?></code></td>
                                <td><strong><?= sanitize($g['name']) ?></strong></td>
                                <td><span class="badge bg-secondary"><?= sanitize($g['class_name'] ?: 'Unknown Class') ?></span></td>
                                <td><span class="badge bg-info text-dark"><?= sanitize($g['subject_name'] ?: 'Unknown Subject') ?></span></td>
                                <td><span class="badge bg-primary fs-6"><?= $total_marks ?> Marks</span></td>
                                <td class="text-end">
                                    <a href="<?= APP_URL ?>/modules/learning/paper_generator.php?class_id=<?= $g['class_id'] ?>&subject_id=<?= $g['subject_id'] ?>&generation_mode=auto" class="btn btn-sm btn-outline-success" title="Generate Paper using this Grid">
                                        <i class="fas fa-magic"></i> Use Grid
                                    </a>
                                    <button onclick='editGrid(<?= json_encode($g) ?>)' class="btn btn-sm btn-outline-warning" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete=<?= $g['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this specification grid layout?');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No customized specification grids found. Click "Create New Grid Layout" to get started!</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Grid Full-Page Form -->
<div id="gridsFormSection" class="card border-0 shadow-sm p-4 mt-2" style="display:none; min-height: 80vh; background: #ffffff; border-radius: 16px;">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
        <h3 class="text-primary mb-0 fw-bold" id="formTitle">Create Specification Grid</h3>
        <button type="button" class="btn btn-outline-secondary" onclick="hideForm()"><i class="fas fa-times"></i> Cancel & Return</button>
    </div>
    
    <form method="POST" action="" class="needs-validation" novalidate>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="grid_id" id="modalGridId">
        
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Grid Layout Name *</label>
                <input type="text" name="name" id="modalGridName" class="form-control" placeholder="e.g. Science First Term Blueprint" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Target Class *</label>
                <select name="class_id" id="modalClass" class="form-select" required>
                    <option value="">Choose Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Subject *</label>
                <select name="subject_id" id="modalSubject" class="form-select" required>
                    <option value="">Choose Subject</option>
                    <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= sanitize($s['name_en']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-12 mt-4">
                <h5 class="text-primary border-bottom pb-2 fw-bold"><i class="fas fa-th"></i> Grid Configuration Matrix</h5>
                <p class="text-muted small">Specify the default questions counts and marks weights for this blueprint layout.</p>
            </div>
            
            <div class="col-12">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-dark">
                            <tr>
                                <th>Question Classification</th>
                                <th style="width: 30%;">Number of Questions</th>
                                <th style="width: 30%;">Marks Per Question</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $types_list = [
                                'MCQ' => 'Multiple Choice (MCQ)',
                                'Very_Short' => 'Very Short Answer',
                                'Short' => 'Short Answer',
                                'Long' => 'Long Answer',
                                'Reasoning' => 'Reasoning Question',
                                'Difference_Between' => 'Difference Between'
                            ];
                            foreach ($types_list as $key => $label): 
                            ?>
                            <tr>
                                <td class="text-start fw-semibold"><?= $label ?></td>
                                <td>
                                    <input type="number" name="count_<?= $key ?>" id="count_<?= $key ?>" class="form-control text-center count-input" value="0" min="0">
                                </td>
                                <td>
                                    <input type="number" name="marks_<?= $key ?>" id="marks_<?= $key ?>" class="form-control text-center marks-input" value="1" min="1">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="col-12 text-end mt-4">
                <button type="submit" class="btn btn-success btn-lg px-5 shadow-sm"><i class="fas fa-save"></i> Save Grid Blueprint</button>
            </div>
        </div>
    </form>
</div>

<script>
function showAddForm() {
    document.getElementById('gridsListSection').style.display = 'none';
    document.getElementById('gridsFormSection').style.display = 'block';
    
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalGridId').value = '';
    document.getElementById('formTitle').innerText = 'Create Specification Grid';
    document.getElementById('modalGridName').value = '';
    document.getElementById('modalClass').value = '';
    document.getElementById('modalSubject').value = '';
    
    // Reset inputs
    document.querySelectorAll('.count-input').forEach(i => i.value = 0);
    document.querySelectorAll('.marks-input').forEach(i => i.value = 1);
}

function editGrid(g) {
    document.getElementById('gridsListSection').style.display = 'none';
    document.getElementById('gridsFormSection').style.display = 'block';
    
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalGridId').value = g.id;
    document.getElementById('formTitle').innerText = 'Edit Specification Grid Blueprint';
    document.getElementById('modalGridName').value = g.name;
    document.getElementById('modalClass').value = g.class_id;
    document.getElementById('modalSubject').value = g.subject_id;
    
    // Parse config JSON
    let config = {};
    try {
        config = JSON.parse(g.config_json);
    } catch(e) {}
    
    const types = {
        'MCQ': 'MCQ',
        'Very Short': 'Very_Short',
        'Short': 'Short',
        'Long': 'Long',
        'Reasoning': 'Reasoning',
        'Difference Between': 'Difference_Between'
    };
    
    for (let type in types) {
        let key = types[type];
        let val = config[type] || { count: 0, marks: 1 };
        document.getElementById('count_' + key).value = val.count || 0;
        document.getElementById('marks_' + key).value = val.marks || 1;
    }
}

function hideForm() {
    document.getElementById('gridsListSection').style.display = 'block';
    document.getElementById('gridsFormSection').style.display = 'none';
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
