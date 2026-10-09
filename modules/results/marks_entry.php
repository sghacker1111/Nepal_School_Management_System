<?php
/**
 * Result Management - Marks Entry Page (CDC Compliant Wide Grid)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$message = '';
$message_type = 'success';


// Filters
$exam_id = $_GET['exam'] ?? '';
$class_id = $_GET['class'] ?? '';
$section_id = $_GET['section'] ?? '';
$prev_exam_id = $_GET['prev_exam'] ?? '';
$filter_subject_id = $_GET['subject'] ?? '';

// Save through one transactional, school-scoped validation service.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    require_once __DIR__ . '/../../includes/marks_service.php';
    try {
        saveExamMarks($db, (int)Auth::getSchoolId(), (int)Auth::getUserId(), Auth::getRole(), $_POST);
        Auth::logActivity(Auth::getUserId(), 'Save exam marks', 'exams', (int)$_POST['exam_id']);
        redirect(APP_URL.'/modules/results/marks_entry.php?'.http_build_query(['exam'=>(int)$_POST['exam_id'],'class'=>(int)$_POST['class_id'],'section'=>(int)($_POST['section_id']??0),'subject'=>$filter_subject_id]), 'Marks and attendance saved successfully.');
    } catch (InvalidArgumentException $e) { $message=$e->getMessage();$message_type='error'; }
    catch (PDOException $e) { error_log($e->getMessage());$message='Marks could not be saved. Please try again.';$message_type='error'; }
}
// Fetch active exams
$school_id = Auth::getSchoolId();
$selected_exam = null;
if ($exam_id) {
    $stmt_sel = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
    $stmt_sel->execute([$exam_id, $school_id]);
    $selected_exam = $stmt_sel->fetch(PDO::FETCH_ASSOC);
}
$stmt = $db->prepare("
    SELECT e.*, y.year_bs 
    FROM exams e 
    JOIN academic_years y ON e.academic_year_id = y.id 
    WHERE y.is_active = 1 AND e.school_id = ?
    ORDER BY e.id DESC
");
$stmt->execute([$school_id]);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch sections for selected class
$sections = [];
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM sections WHERE class_id = ? ORDER BY name");
    $stmt->execute([$class_id]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Global class level resolver
$class_name = '';
$classType = '4-8';
if ($class_id) {
    $stmt_class = $db->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_class->execute([$class_id]);
    $class_name = $stmt_class->fetchColumn();
    $classType = getClassType($class_name);
}

// Subject sorting weight helper
if (!function_exists('getSubjectSortWeight')) {
    function getSubjectSortWeight($sub_name) {
        $name = strtolower(trim($sub_name));
        if (strpos($name, 'english') !== false) return 10;
        if (strpos($name, 'nepali') !== false) return 9;
        if (strpos($name, 'math') !== false) return 8;
        if (strpos($name, 'science') !== false) return 7;
        if (strpos($name, 'social') !== false) return 6;
        if (strpos($name, 'optional i') !== false || strpos($name, 'opt i') !== false) return 5;
        if (strpos($name, 'optional ii') !== false || strpos($name, 'opt ii') !== false) return 4;
        return 1;
    }
}

// Fetch configured subjects for class
$subjects = [];
$all_subjects = [];
if ($exam_id && $class_id) {
    $stmt = $db->prepare("
        SELECT es.*, sub.name_en as subject_name, sub.code as subject_code, COALESCE(es.is_optional,CASE WHEN sub.type='optional' THEN 1 ELSE 0 END) as is_optional
        FROM exam_subjects es
        JOIN subjects sub ON es.subject_id = sub.id
        WHERE es.exam_id = ? AND es.class_id = ?
    ");
    $stmt->execute([$exam_id, $class_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (Auth::getRole()==='teacher') {
        $assigned=$db->prepare('SELECT subject_id,teacher_id FROM class_subjects WHERE school_id=? AND class_id=?');$assigned->execute([$school_id,$class_id]);$allowed=[];
        foreach($assigned as $assignment)if(in_array((string)Auth::getUserId(),explode(',',(string)$assignment['teacher_id']),true))$allowed[]=$assignment['subject_id'];
        $subjects=array_values(array_filter($subjects,static fn($s)=>in_array($s['subject_id'],$allowed)));
    }
    
    // Sort subjects serially (English, Nepali, Math, Science, Social, Opt I, Opt II)
    usort($subjects, function($a, $b) {
        $weightA = getSubjectSortWeight($a['subject_name']);
        $weightB = getSubjectSortWeight($b['subject_name']);
        if ($weightA !== $weightB) {
            return $weightB <=> $weightA;
        }
        return strcasecmp($a['subject_name'], $b['subject_name']);
    });
    
    $all_subjects = $subjects;
    
    if ($filter_subject_id !== '') {
        $subjects = array_filter($subjects, function($s) use ($filter_subject_id) {
            return $s['subject_id'] == $filter_subject_id;
        });
    }
}

// Fetch students
$students = [];
if ($exam_id && $class_id) {
    $query = "
        SELECT s.id, s.student_id, s.first_name_en, s.last_name_en, s.roll_number, s.exam_roll_number,
               s.optional_subject_i, s.optional_subject_ii, s.optional_subject_iii
        FROM students s
        WHERE s.class_id = ? AND s.status = 'active' AND s.school_id = ? AND s.academic_year_id = ?
    ";
    $params = [$class_id, $school_id, $selected_exam['academic_year_id'] ?? 0];
    if ($section_id) {
        $query .= " AND s.section_id = ?";
        $params[] = $section_id;
    }
    $query .= " ORDER BY s.roll_number ASC, s.first_name_en ASC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch saved marks
$marks_matrix = [];
if ($exam_id && $class_id && !empty($students)) {
    $stmt = $db->prepare("
        SELECT * FROM marks 
        WHERE exam_id = ? AND student_id IN (
            SELECT id FROM students WHERE class_id = ? AND status = 'active'
        )
    ");
    $stmt->execute([$exam_id, $class_id]);
    $marks_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($marks_raw as $m) {
        $marks_matrix[$m['student_id']][$m['subject_id']] = $m;
    }
}

// Fetch saved attendance
$attendance_data = [];
if ($exam_id) {
    $stmt = $db->prepare("SELECT student_id, present_days FROM exam_student_attendance WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    $att_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($att_raw as $ar) {
        $attendance_data[$ar['student_id']] = $ar['present_days'];
    }
}

// Fetch previous terminal exam marks for scaling
$prev_marks = [];
if ($prev_exam_id && $class_id) {
    $stmt = $db->prepare("
        SELECT m.student_id, m.subject_id, m.theory_obtained, m.practical_obtained,
               es.theory_full, es.practical_full
        FROM marks m
        JOIN students s ON m.student_id = s.id
        JOIN exam_subjects es ON es.exam_id = m.exam_id AND es.subject_id = m.subject_id AND es.class_id = s.class_id
        WHERE m.exam_id = ? AND s.class_id = ?
    ");
    $stmt->execute([$prev_exam_id, $class_id]);
    $prev_marks_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prev_marks_raw as $pm) {
        $prev_marks[$pm['student_id']][$pm['subject_id']] = [
            'theory' => (float)$pm['theory_obtained'],
            'practical' => (float)$pm['practical_obtained'],
            'theory_full' => (float)$pm['theory_full'],
            'practical_full' => (float)$pm['practical_full']
        ];
    }
}

$pageTitle = 'Class-Wide Marks Entry';
$activePage = 'results';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit text-primary"></i> Class-Wide Marks Entry Sheet</h2>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $message_type == 'error' ? 'danger' : 'success' ?> mb-4">
    <i class="fas <?= $message_type == 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i> <?= $message ?>
</div>
<?php endif; ?>

<!-- Filters Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-2">
                <label class="form-label fw-bold">Exam</label>
                <select name="exam" class="form-select" required onchange="this.form.submit()">
                    <option value="">Select Exam</option>
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $exam_id == $e['id'] ? 'selected' : '' ?>>
                        <?= sanitize($e['name']) ?> (<?= $e['year_bs'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label fw-bold">Class</label>
                <select name="class" class="form-select" required onchange="this.form.submit()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label fw-bold">Section</label>
                <select name="section" class="form-select" onchange="this.form.submit()">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $section_id == $s['id'] ? 'selected' : '' ?>>
                        Section <?= sanitize($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Subject (Optional)</label>
                <select name="subject" class="form-select" onchange="this.form.submit()">
                    <option value="">All Subjects</option>
                    <?php foreach ($all_subjects as $sub): ?>
                    <option value="<?= $sub['subject_id'] ?>" <?= $filter_subject_id == $sub['subject_id'] ? 'selected' : '' ?>>
                        <?= sanitize($sub['subject_name']) ?> (<?= sanitize($sub['subject_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label fw-bold">Load Previous Terminal Exam (Optional)</label>
                <select name="prev_exam" class="form-select" onchange="this.form.submit()">
                    <option value="">No Previous Exam</option>
                    <?php foreach ($exams as $e): ?>
                    <?php if ($e['id'] != $exam_id): ?>
                    <option value="<?= $e['id'] ?>" <?= $prev_exam_id == $e['id'] ? 'selected' : '' ?>>
                        <?= sanitize($e['name']) ?> (<?= $e['year_bs'] ?>)
                    </option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($exam_id && $class_id && !empty($subjects)): ?>
<!-- Entry Grid Form -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-list-ol"></i> Marks Grid: <?= sanitize($class_name) ?></h5>
        <small class="text-muted">Horizontal scrolling enabled. Gray cells are optional subjects not selected by the student. Internal components must sum to no more than the configured internal full marks. Enter zero for a component that does not apply.</small>
    </div>
    
    <div class="card-body p-0">
        <form method="POST" action="">
            <input type="hidden" name="exam_id" value="<?= sanitize($exam_id) ?>">
            <input type="hidden" name="class_id" value="<?= sanitize($class_id) ?>">
            <input type="hidden" name="section_id" value="<?= sanitize($section_id) ?>">
            <input type="hidden" name="subject_id_hidden" value="<?= sanitize($filter_subject_id) ?>">
            
            <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                <table class="table table-bordered table-sm align-middle text-center mb-0" style="font-size: 0.85rem; border: 2px solid #ddd; min-width: 1200px;">
                    <thead class="table-dark" style="position: sticky; top: 0; z-index: 100;">
                        <tr>
                            <th rowspan="2" class="align-middle px-2" style="width: 50px;">S.N.</th>
                            <th rowspan="2" class="align-middle text-start px-2" style="min-width: 180px; position: sticky; left: 0; background-color: #212529; z-index: 110;">Student Name</th>
                            <th rowspan="2" class="align-middle px-2" style="min-width: 100px;">Symbol No.</th>
                            <th rowspan="2" class="align-middle px-2" style="min-width: 90px; background-color: #34495e;">Attendance</th>
                            
                            <?php foreach ($subjects as $sub): ?>
                                <?php $colspan = (($selected_exam && $selected_exam['type'] === 'unit') || empty($selected_exam['has_internal'])) ? 1 : 4; ?>
                                <th colspan="<?= $colspan ?>" class="px-2 small text-uppercase">
                                    <?= sanitize($sub['subject_name']) ?> 
                                    <span class="d-block text-warning small font-monospace"><?= sanitize($sub['subject_code']) ?></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach ($subjects as $sub): ?>
                                <?php if ($selected_exam && $selected_exam['type'] === 'unit'): ?>
                                    <th style="min-width: 100px; font-size: 0.75rem;">Marks (<?= $sub['theory_full'] ?>)</th>
                                <?php elseif (empty($selected_exam['has_internal'])): ?>
                                    <th style="min-width: 100px; font-size: 0.75rem;">Theory (<?= $sub['theory_full'] ?>)</th>
                                <?php else: ?>
                                    <?php $max_prt = $selected_exam['has_internal'] ? (float)$sub['practical_full'] : 0; ?>
                                    <?php $max_prj = $max_prt; ?>
                                    <?php $max_prv = $max_prt; ?>
                                    <?php $max_trm = (float)$sub['theory_full']; ?>
                                    <th style="min-width: 60px; font-size: 0.7rem; background-color: #2c3e50;">PRT (<?= $max_prt ?>)</th>
                                    <th style="min-width: 60px; font-size: 0.7rem; background-color: #2c3e50;">PRJ (<?= $max_prj ?>)</th>
                                    <th style="min-width: 60px; font-size: 0.7rem; background-color: #2c3e50;">PRV (<?= $max_prv ?>)</th>
                                    <th style="min-width: 60px; font-size: 0.7rem; background-color: #16a085;">TRM (<?= $max_trm ?>)</th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($students) > 0): ?>
                            <?php 
                            $sn = 0; 
                            foreach ($students as $student): 
                                $sn++;
                                $student_att = $attendance_data[$student['id']] ?? '';
                            ?>
                            <tr>
                                <td><strong><?= $sn ?></strong></td>
                                <td class="text-start fw-bold" style="position: sticky; left: 0; background-color: #fff; box-shadow: 2px 0 5px rgba(0,0,0,0.05); z-index: 90;">
                                    <?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?>
                                </td>
                                <td><code><?= sanitize($student['exam_roll_number'] ?? $student['roll_number'] ?? '-') ?></code></td>
                                <td style="background-color: #f8f9fa;">
                                    <input type="number" min="0" class="form-control form-control-sm text-center font-monospace" name="attendance[<?= $student['id'] ?>]" value="<?= $student_att ?>" placeholder="Days">
                                </td>
                                
                                <?php foreach ($subjects as $sub): ?>
                                    <?php 
                                    // Check optional mapping
                                    $is_optional = (!empty($sub['is_optional']));
                                    $is_selected = true;
                                    if ($is_optional) {
                                        $is_selected = ($sub['subject_id'] == $student['optional_subject_i'] || $sub['subject_id'] == $student['optional_subject_ii'] || $sub['subject_id'] == ($student['optional_subject_iii']??null));
                                    }
                                    
                                    if (!$is_selected): 
                                        $colspan = (($selected_exam && $selected_exam['type'] === 'unit') || empty($selected_exam['has_internal'])) ? 1 : 4;
                                    ?>
                                        <td colspan="<?= $colspan ?>" style="background-color: #eaeded; color: #a6acaf; font-size: 0.75rem;" class="text-center font-monospace">N/A</td>
                                    <?php else: ?>
                                        <?php 
                                        $m = $marks_matrix[$student['id']][$sub['subject_id']] ?? null;
                                        $theory_obtained = $m !== null && $m['theory_obtained'] !== null ? $m['theory_obtained'] : '';
                                        $practical_obtained = $m !== null && $m['practical_obtained'] !== null ? $m['practical_obtained'] : '';
                                        
                                        $breakdown = $m !== null && $m['internal_breakdown'] !== null ? json_decode($m['internal_breakdown'], true) : [];
                                        
                                        $prt_val = $breakdown['participation'] ?? '';
                                        $prj_val = $breakdown['project_work'] ?? '';
                                        $prv_val = $breakdown['previous_exam'] ?? '';
                                        
                                        // Previous-exam allocation depends on the approved school scheme.
                                        // Do not silently scale or overwrite an incomplete component.
                                        ?>
                                        <?php if ($selected_exam && $selected_exam['type'] === 'unit'): ?>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $sub['theory_full'] ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][theory]" value="<?= $theory_obtained ?>">
                                            </td>
                                        <?php elseif (empty($selected_exam['has_internal'])): ?>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $sub['theory_full'] ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][theory]" value="<?= $theory_obtained ?>">
                                            </td>
                                        <?php else: ?>
                                            <?php $max_prt = !empty($selected_exam['has_internal']) ? (float)$sub['practical_full'] : 0; ?>
                                            <?php $max_prj = $max_prt; ?>
                                            <?php $max_prv = $max_prt; ?>
                                            <?php $max_trm = (float)$sub['theory_full']; ?>
                                            
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $max_prt ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][participation]" value="<?= $prt_val ?>">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $max_prj ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][project_work]" value="<?= $prj_val ?>">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $max_prv ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][previous_exam]" value="<?= $prv_val ?>">
                                            </td>
                                            <td style="background-color: #e8f8f5;">
                                                <input type="number" step="0.01" min="0" max="<?= $max_trm ?>" class="form-control form-control-sm text-center font-monospace fw-bold" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][theory]" value="<?= $theory_obtained ?>">
                                            </td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= 4 + count($subjects) * (($classType === '1-3') ? 1 : 4) ?>" class="text-center text-muted py-5">
                                    <i class="fas fa-user-slash fa-2x mb-2"></i>
                                    <p class="mb-0">No active students found in this class/section.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (count($students) > 0 && Auth::hasRole(['super_admin', 'school_admin', 'teacher', 'exam_officer'])): ?>
            <div class="text-end p-4 bg-light border-top no-print">
                <button type="submit" name="save_marks" class="btn btn-primary btn-lg px-5 shadow-sm">
                    <i class="fas fa-save me-2"></i> Save & Compute Results
                </button>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card text-center py-5 no-print">
    <div class="card-body">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h5>Please select an Exam and Class above to load the marks entry grid.</h5>
    </div>
</div>
<?php endif; ?>

<style>
    /* Styling for the mark entry boxes */
    .table input[type="number"] {
        width: 80px !important;
        min-width: 80px !important;
        margin: 0 auto;
        padding-left: 2px !important;
        padding-right: 2px !important;
    }
</style>

<script>
$(document).ready(function() {
    // Disable scrolling changing value
    $('table').on('wheel', 'input[type="number"]', function(e) {
        $(this).blur();
    });

    // Keyboard navigation
    $('table').on('keydown', 'input[type="number"]', function(e) {
        var key = e.which || e.keyCode;
        
        // 38 = Arrow Up, 40 = Arrow Down, 13 = Enter
        if (key === 38 || key === 40 || key === 13) {
            e.preventDefault();
            
            var $rows = $('table tbody tr');
            var $currentRow = $(this).closest('tr');
            var rowIndex = $rows.index($currentRow);
            
            var $rowInputs = $currentRow.find('input[type="number"]');
            var colIndex = $rowInputs.index(this);
            
            var targetRowIndex = rowIndex;
            if (key === 38) {
                targetRowIndex = rowIndex - 1; // Up
            } else {
                targetRowIndex = rowIndex + 1; // Down (Arrow Down or Enter)
            }
            
            if (targetRowIndex >= 0 && targetRowIndex < $rows.length) {
                var $targetRowInputs = $rows.eq(targetRowIndex).find('input[type="number"]');
                if ($targetRowInputs.length > colIndex) {
                    $targetRowInputs.eq(colIndex).focus().select();
                }
            }
        }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
