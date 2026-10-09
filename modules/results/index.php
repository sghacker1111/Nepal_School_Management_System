<?php
/**
 * Result Management - Overview Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);
Auth::requireAccess('results');

$db = getDB();

// Filters
$class_id = $_GET['class'] ?? '';
$section_id = $_GET['section'] ?? '';
$exam_id = $_GET['exam'] ?? '';

// Fetch active exams
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("
    SELECT e.*, y.year_bs 
    FROM exams e 
    JOIN academic_years y ON e.academic_year_id = y.id 
    WHERE e.school_id = ?
    ORDER BY e.id DESC
");
$stmt->execute([$school_id]);
$exams = $stmt->fetchAll();

// Fetch classes
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll();

$selected_class = null;
if ($class_id) {
    foreach ($classes as $c) {
        if ($c['id'] == $class_id) {
            $selected_class = $c;
            break;
        }
    }
}

// Fetch sections for selected class
$sections = [];
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM sections WHERE class_id = ? ORDER BY name");
    $stmt->execute([$class_id]);
    $sections = $stmt->fetchAll();
}

// Find the currently selected exam
$selected_exam = null;
if ($exam_id) {
    foreach ($exams as $e) {
        if ($e['id'] == $exam_id) {
            $selected_exam = $e;
            break;
        }
    }
}

$is_gpa_format = ($selected_exam['result_system']??'gpa')==='gpa';
// Fetch students for results list
$students = [];
if ($class_id && $exam_id) {
    $query = "
        SELECT s.*
        FROM students s
        WHERE s.class_id = ? AND s.school_id=? AND s.academic_year_id=? AND (s.status='active' OR EXISTS(SELECT 1 FROM marks historical WHERE historical.student_id=s.id AND historical.exam_id=?))
    ";
    $params = [$class_id,$school_id,$selected_exam['academic_year_id']??0,$exam_id];
    
    if ($section_id) {
        $query .= " AND s.section_id = ?";
        $params[] = $section_id;
    }
    
    $query .= " ORDER BY s.roll_number ASC, s.first_name_en ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    $q=$db->prepare('SELECT * FROM exam_subjects WHERE exam_id=? AND class_id=?');$q->execute([$exam_id,$class_id]);$schemes=$q->fetchAll();
    $q=$db->prepare('SELECT * FROM marks WHERE exam_id=?');$q->execute([$exam_id]);$matrix=[];
    foreach($q as $mark)$matrix[$mark['student_id']][$mark['subject_id']]=$mark;
    foreach($students as &$student) {
        $rows=[];$failed=0;
        foreach($schemes as $scheme) {
            if(!empty($scheme['is_optional']) && !in_array($scheme['subject_id'],[$student['optional_subject_i'],$student['optional_subject_ii'],$student['optional_subject_iii']],true))continue;
            $row=array_merge($scheme,$matrix[$student['id']][$scheme['subject_id']]??[]);
            $rows[]=$row;
            if(erpCalculateSubjectResult($row,$row,$selected_exam)['failed'])$failed++;
        }
        $summary=erpReportSummary($rows,$selected_exam,$selected_exam['result_system']??'gpa');
        $student['total_obtained']=empty($matrix[$student['id']])?null:$summary['grand_obtained'];
        $student['failed_subjects_count']=$failed;
        $student['incomplete']=$summary['remarks_summary']==='Incomplete';
    }
    unset($student);
}

$pageTitle = 'Student Results';
$activePage = 'results';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-poll"></i> Student Exam Results</h2>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/modules/results/ledger.php" class="btn btn-outline-info">
            <i class="fas fa-poll-h"></i> Ledger Grid
        </a>
        <a href="<?= APP_URL ?>/modules/results/print_bulk.php" class="btn btn-outline-success">
            <i class="fas fa-print"></i> Bulk Print Cards
        </a>
        <a href="<?= APP_URL ?>/modules/results/marks_entry.php" class="btn btn-primary">
            <i class="fas fa-edit"></i> Marks Entry
        </a>
    </div>
</div>

<!-- Filters Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Select Exam</label>
                <select name="exam" class="form-select" required onchange="this.form.submit()">
                    <option value="">Select Exam</option>
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $exam_id == $e['id'] ? 'selected' : '' ?>>
                        <?= sanitize($e['name']) ?> (<?= $e['year_bs'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Class</label>
                <select name="class" class="form-select" required onchange="this.form.submit()">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Section</label>
                <select name="section" class="form-select" onchange="this.form.submit()">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $section_id == $s['id'] ? 'selected' : '' ?>>
                        Section <?= sanitize($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Load Results
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Results sheet -->
<?php if ($class_id && $exam_id): ?>
<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-success"><i class="fas fa-file-signature"></i> Student Performance Sheet</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" id="resultsTable">
                <thead class="table-light">
                    <tr>
                        <th width="80">Roll No.</th>
                        <th>Student Name</th>
                        <th>Student ID</th>
                        <th>Total Marks Obtained</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($students) > 0): ?>
                        <?php foreach ($students as $student): ?>
                        <tr>
                            <td><strong><?= sanitize($student['roll_number'] ?? '-') ?></strong></td>
                            <td><strong><?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?></strong></td>
                            <td><code><?= sanitize($student['student_id']) ?></code></td>
                            <td>
                                <strong><?= $student['total_obtained'] !== null ? $student['total_obtained'] : 'N/A' ?></strong>
                            </td>
                            <td class="text-center">
                                <?php if ($student['total_obtained'] === null): ?>
                                    <span class="badge bg-secondary">No Marks</span>
                                <?php elseif ($student['incomplete']): ?>
                                    <span class="badge bg-warning text-dark">Incomplete</span>
                                <?php elseif ($student['failed_subjects_count'] > 0): ?>
                                    <span class="badge bg-danger"><?= $is_gpa_format ? 'Not Graded' : 'Failed' ?> (<?= $student['failed_subjects_count'] ?> Sub)</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><?= $is_gpa_format ? 'Graded' : 'Passed' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($student['total_obtained'] !== null): ?>
                                    <a href="<?= APP_URL ?>/modules/results/report_card.php?student=<?= $student['id'] ?>&exam=<?= $exam_id ?>" 
                                       class="btn btn-sm btn-outline-success" target="_blank">
                                        <i class="fas fa-file-invoice"></i> Report Card
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                        <i class="fas fa-file-invoice"></i> Report Card
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No active students found in this class.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card text-center py-5 no-print">
    <div class="card-body">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h5>Please select an Exam and Class above to load student results.</h5>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    $('#resultsTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']]
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
