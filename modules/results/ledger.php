<?php
/**
 * Result Management - Class Result Ledger
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();

// Filters
$exam_id = $_GET['exam'] ?? '';
$class_id = $_GET['class'] ?? '';
$section_id = $_GET['section'] ?? '';

$school_id = Auth::getSchoolId();

// Fetch active exams
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
$classes_stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ?) ORDER BY id ASC");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

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
    $stmt = $db->prepare("SELECT * FROM sections WHERE school_id = ? AND class_id = ? ORDER BY name");
    $stmt->execute([$school_id, $class_id]);
    $sections = $stmt->fetchAll();
}

$subjects = [];
$students = [];
$ledger_data = [];

if ($exam_id && $class_id) {
    // Verify exam and class ownership
    $chk_exam = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
    $chk_exam->execute([$exam_id, $school_id]);
    $exam = $chk_exam->fetch();
    if (!$exam) {
        die("Unauthorized access to exam data.");
    }
    $chk_class = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
    $chk_class->execute([$class_id, $school_id]);
    if (!$chk_class->fetch()) {
        die("Unauthorized access to class data.");
    }

    // 1. Fetch subjects configured for this exam-class
    $stmt = $db->prepare("
        SELECT es.*, s.name_en as subject_name, s.code as subject_code, COALESCE(es.is_optional,CASE WHEN s.type='optional' THEN 1 ELSE 0 END) as is_optional,
               es.credit_hours as exam_credit_hours, cs.credit_hours as class_credit_hours, 
               s.credit_hours as default_credit_hours
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        LEFT JOIN class_subjects cs ON cs.id=(SELECT MIN(cm.id) FROM class_subjects cm WHERE cm.class_id=es.class_id AND cm.subject_id=es.subject_id)
        WHERE es.exam_id = ? AND es.class_id = ?
        ORDER BY s.type DESC, s.name_en ASC
    ");
    $stmt->execute([$exam_id, $class_id]);
    $subjects = $stmt->fetchAll();

    // If exam has no internal, override practical full & pass to 0
    if (!$exam['has_internal']) {
        foreach ($subjects as &$sub) {
            $sub['practical_full'] = 0;
            $sub['practical_pass'] = 0;
        }
        unset($sub);
    }

    // 2. Fetch active students in class
    $query = "SELECT id, student_id, first_name_en, last_name_en, roll_number, optional_subject_i, optional_subject_ii, optional_subject_iii FROM students WHERE class_id = ? AND (status = 'active' OR EXISTS(SELECT 1 FROM marks historical WHERE historical.student_id=students.id AND historical.exam_id=?)) AND school_id=? AND academic_year_id=?";
    $params = [$class_id,$exam_id,$school_id,$exam['academic_year_id']];
    if ($section_id) {
        $query .= " AND section_id = ?";
        $params[] = $section_id;
    }
    $query .= " ORDER BY roll_number ASC, first_name_en ASC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    // 3. Fetch marks matrix
    $stmt = $db->prepare("
        SELECT * FROM marks WHERE exam_id = ? AND student_id IN (
            SELECT id FROM students WHERE class_id = ?
        )
    ");
    $stmt->execute([$exam_id, $class_id]);
    $marks_raw = $stmt->fetchAll();
    
    $marks = [];
    foreach ($marks_raw as $m) {
        $marks[$m['student_id']][$m['subject_id']] = $m;
    }

    // Get class level type
    $stmt_class_lvl = $db->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_class_lvl->execute([$class_id]);
    $class_name = $stmt_class_lvl->fetchColumn();
    $classType = getClassType($class_name);

    // 4. Compute Ledger Matrix
    foreach ($students as $student) {
        $row_subs = [];
        $studentMarks=[];
        foreach($subjects as $sub) {
            $sub_id=$sub['subject_id'];
            if(!empty($sub['is_optional']) && $sub_id!=$student['optional_subject_i'] && $sub_id!=$student['optional_subject_ii'] && $sub_id!=($student['optional_subject_iii']??null)) {$row_subs[$sub_id]='N/A';continue;}
            $m=$marks[$student['id']][$sub_id]??[];
            $result=erpCalculateSubjectResult($sub,$m,$exam);
            $studentMarks[]=array_merge($sub,$m);
            $row_subs[$sub_id]=['theory'=>$result['theory_obtained'],'practical'=>$result['practical_obtained'],'total'=>(float)$result['theory_obtained']+(float)$result['practical_obtained'],'gp'=>$result['grade_point'],'grade'=>$result['grade'],'remarks'=>$result['remarks']];
        }
        $summary=erpReportSummary($studentMarks,$exam,$exam['result_system']??'gpa');
        $total_obtained=$summary['grand_obtained'];
        $gpa=$summary['average_gpa'];
        $status=$summary['remarks_summary']==='Incomplete'?'Incomplete':($summary['has_failed_subject']?'Fail/NG':'Pass');

        $ledger_data[] = [
            'id' => $student['id'],
            'student_id' => $student['student_id'],
            'name' => $student['first_name_en'] . ' ' . $student['last_name_en'],
            'roll' => $student['roll_number'] ?? 999,
            'subjects' => $row_subs,
            'total_marks' => $total_obtained,
            'gpa' => $gpa,
            'status' => $status
        ];
    }
}

$pageTitle = 'Class Result Ledger';
$activePage = 'results';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2><i class="fas fa-poll-h"></i> Class Result Ledger Grid</h2>
    <div>
        <a href="<?= APP_URL ?>/modules/results/index.php" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Results
        </a>
        <?php if ($exam_id && $class_id): ?>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print Ledger
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Select Exam</label>
                <select name="exam" class="form-select" required onchange="this.form.submit()">
                    <option value="">Choose Exam</option>
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $exam_id == $e['id'] ? 'selected' : '' ?>>
                        <?= sanitize($e['name']) ?> (<?= $e['year_bs'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Select Class</label>
                <select name="class" class="form-select" required onchange="this.form.submit()">
                    <option value="">Choose Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Select Section (Optional)</label>
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
                <button type="submit" class="btn btn-info w-100">
                    <i class="fas fa-sync"></i> Refresh Grid
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($exam_id && $class_id && count($subjects) > 0): ?>
<!-- Sorting & Filtering controls -->
<div class="card mb-4 no-print bg-light">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-sort"></i> Sort By</span>
                    <select id="sortSelector" class="form-select form-select-sm" onchange="applySorting()">
                        <option value="roll">Roll Number (Ascending)</option>
                        <option value="name">Student Name (A-Z)</option>
                        <option value="gpa-desc">GPA (High to Low)</option>
                        <option value="gpa-asc">GPA (Low to High)</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-filter"></i> Filter Status</span>
                    <select id="filterSelector" class="form-select form-select-sm" onchange="applyFiltering()">
                        <option value="all">Show All Candidates</option>
                        <option value="pass">Show <?= ($selected_class && $selected_class['grading_type'] === 'gpa') ? 'Graded' : 'Pass' ?> Only</option>
                        <option value="fail">Show <?= ($selected_class && $selected_class['grading_type'] === 'gpa') ? 'Not Graded' : 'Fail / NG' ?> Only</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Grid -->
<div class="card">
    <div class="card-body p-0">
        <!-- Print Header -->
        <div class="d-none d-print-block text-center mb-4">
            <h4>MODEL SECONDARY SCHOOL</h4>
            <h6>Class Result Ledger Sheet</h6>
            <p class="mb-0">
                Exam: <strong><?= sanitize($exams[array_search($exam_id, array_column($exams, 'id'))]['name'] ?? '') ?></strong> | 
                Class: <strong><?= sanitize($classes[array_search($class_id, array_column($classes, 'id'))]['name'] ?? '') ?></strong>
            </p>
            <hr>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle text-center mb-0" id="ledgerTable" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle">Roll No.</th>
                        <th rowspan="2" class="align-middle text-start" style="min-width: 150px;">Student Name</th>
                        <?php foreach ($subjects as $sub): ?>
                        <th colspan="<?= $exam['type'] === 'unit' ? 1 : 3 ?>" rowspan="<?= $exam['type'] === 'unit' ? 2 : 1 ?>" class="small align-middle">
                            <?= sanitize($sub['subject_name']) ?>
                            <?php if ($exam['type'] !== 'unit'): ?>
                                <?php $ch = $sub['exam_credit_hours'] ?? $sub['class_credit_hours'] ?? $sub['default_credit_hours'] ?? 4; ?>
                                <br><small class="text-muted">(<?= $ch ?> CH)</small>
                            <?php else: ?>
                                <br><small class="text-muted">(FM: <?= $sub['theory_full'] ?>)</small>
                            <?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                        <th rowspan="2" class="align-middle">Total Obtained</th>
                        <th rowspan="2" class="align-middle"><?= ($exam['result_system'] ?? 'gpa') === 'percentage' ? 'Percentage' : 'GPA' ?></th>
                        <th rowspan="2" class="align-middle">Result</th>
                    </tr>
                    <?php if ($exam['type'] !== 'unit'): ?>
                    <tr>
                        <?php foreach ($subjects as $sub): ?>
                        <th style="font-size: 0.7rem;">TH</th>
                        <th style="font-size: 0.7rem;">IN</th>
                        <th style="font-size: 0.7rem;">GD</th>
                        <?php endforeach; ?>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody id="ledgerBody">
                    <?php foreach ($ledger_data as $row): ?>
                    <tr data-roll="<?= $row['roll'] ?>" data-name="<?= sanitize($row['name']) ?>" data-gpa="<?= $row['gpa'] ?>" data-status="<?= $row['status'] === 'Pass' ? 'pass' : 'fail' ?>">
                        <td><strong><?= $row['roll'] !== 999 ? $row['roll'] : '-' ?></strong></td>
                        <td class="text-start"><strong><?= sanitize($row['name']) ?></strong></td>
                        
                        <?php foreach ($subjects as $sub): ?>
                            <?php 
                            $sm = $row['subjects'][$sub['subject_id']] ?? null; 
                            if ($sm === 'N/A'):
                            ?>
                            <td colspan="<?= $exam['type'] === 'unit' ? 1 : 3 ?>" class="bg-secondary-subtle text-muted">-</td>
                            <?php elseif ($sm): ?>
                                <?php if ($exam['type'] === 'unit'): ?>
                                    <?php if (($exam['result_system'] ?? 'gpa') === 'percentage'): ?>
                                        <td><?= $sm['theory'] ?></td>
                                    <?php else: ?>
                                        <td class="fw-bold <?= $sm['grade'] === 'NG' ? 'text-danger' : 'text-primary' ?>"><?= $sm['grade'] ?></td>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <td><?= $sm['theory'] ?></td>
                                    <td><?= $sub['practical_full'] > 0 ? $sm['practical'] : '-' ?></td>
                                    <td class="fw-bold <?= $sm['grade'] === 'NG' ? 'text-danger' : 'text-primary' ?>"><?= $sm['grade'] ?></td>
                                <?php endif; ?>
                            <?php else: ?>
                            <td colspan="<?= $exam['type'] === 'unit' ? 1 : 3 ?>" class="text-muted small">Absent</td>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <td><?= $row['total_marks'] ?></td>
                        <td class="fw-bold text-success font-monospace">
                            <?php if (($exam['result_system'] ?? 'gpa') === 'percentage'): ?>
                                <?= number_format($row['gpa'], 2) ?>%
                            <?php else: ?>
                                <?= number_format($row['gpa'], 2) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $row['status'] === 'Pass' ? 'success' : 'danger' ?>">
                                <?= (($exam['result_system'] ?? 'gpa') === 'gpa') ? ($row['status'] === 'Pass' ? 'Graded' : 'Not Graded') : ($row['status'] === 'Pass' ? 'Passed' : 'Failed') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function applySorting() {
    const selector = document.getElementById('sortSelector').value;
    const tbody = document.getElementById('ledgerBody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    rows.sort((a, b) => {
        if (selector === 'roll') {
            return parseInt(a.getAttribute('data-roll')) - parseInt(b.getAttribute('data-roll'));
        } else if (selector === 'name') {
            return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
        } else if (selector === 'gpa-desc') {
            return parseFloat(b.getAttribute('data-gpa')) - parseFloat(a.getAttribute('data-gpa'));
        } else if (selector === 'gpa-asc') {
            return parseFloat(a.getAttribute('data-gpa')) - parseFloat(b.getAttribute('data-gpa'));
        }
        return 0;
    });
    
    // Clear and append
    tbody.innerHTML = '';
    rows.forEach(r => tbody.appendChild(r));
}

function applyFiltering() {
    const filter = document.getElementById('filterSelector').value;
    const tbody = document.getElementById('ledgerBody');
    const rows = tbody.querySelectorAll('tr');
    
    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        if (filter === 'all') {
            row.style.display = '';
        } else if (filter === 'pass' && status === 'pass') {
            row.style.display = '';
        } else if (filter === 'fail' && status === 'fail') {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php else: ?>
<div class="card text-center py-5 no-print">
    <div class="card-body">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h5>Please select an Exam and Class to display the Result Ledger Grid.</h5>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
