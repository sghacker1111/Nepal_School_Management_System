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

// Handle POST - Save Marks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    $exam_id_post = (int)$_POST['exam_id'];
    $class_id_post = (int)$_POST['class_id'];
    $section_id_post = $_POST['section_id'] ?? '';
    
    // Fetch exam details to verify lock status
    $school_id = Auth::getSchoolId();
    $stmt = $db->prepare("SELECT is_locked, type, result_system, has_internal FROM exams WHERE id = ? AND school_id = ?");
    $stmt->execute([$exam_id_post, $school_id]);
    $exam = $stmt->fetch();
    
    if (!$exam || $exam['is_locked']) {
        $message = "Cannot enter marks. This exam is locked.";
        $message_type = 'error';
    } else {
        try {
            $db->beginTransaction();
            
            // 1. Save Attendance
            if (isset($_POST['attendance'])) {
                $stmt_att_check = $db->prepare("SELECT id FROM exam_student_attendance WHERE exam_id = ? AND student_id = ?");
                $stmt_att_insert = $db->prepare("INSERT INTO exam_student_attendance (exam_id, student_id, present_days) VALUES (?, ?, ?)");
                $stmt_att_update = $db->prepare("UPDATE exam_student_attendance SET present_days = ? WHERE exam_id = ? AND student_id = ?");
                
                foreach ($_POST['attendance'] as $student_id => $days) {
                    $val_days = $days !== '' ? (int)$days : 0;
                    $stmt_att_check->execute([$exam_id_post, $student_id]);
                    if ($stmt_att_check->fetch()) {
                        $stmt_att_update->execute([$val_days, $exam_id_post, $student_id]);
                    } else {
                        $stmt_att_insert->execute([$exam_id_post, $student_id, $val_days]);
                    }
                }
            }
            
            // 2. Fetch class name
            $stmt_c = $db->prepare("SELECT name FROM classes WHERE id = ?");
            $stmt_c->execute([$class_id_post]);
            $class_name = $stmt_c->fetchColumn();
            $classType = getClassType($class_name);
            
            // 3. Fetch subjects configured for this exam-class
            $stmt_es = $db->prepare("
                SELECT es.*, sub.name_en as subject_name, sub.type as subject_type
                FROM exam_subjects es
                JOIN subjects sub ON es.subject_id = sub.id
                WHERE es.exam_id = ? AND es.class_id = ? AND (sub.is_hidden = 0 OR sub.is_hidden IS NULL)
            ");
            $stmt_es->execute([$exam_id_post, $class_id_post]);
            $exam_subjects = $stmt_es->fetchAll(PDO::FETCH_ASSOC);
            $exam_subjects_mapped = [];
            foreach ($exam_subjects as $es) {
                $exam_subjects_mapped[$es['subject_id']] = $es;
            }
            
            // 4. Fetch students mapping
            $stmt_s = $db->prepare("SELECT id, optional_subject_i, optional_subject_ii FROM students WHERE class_id = ?");
            $stmt_s->execute([$class_id_post]);
            $students_raw = $stmt_s->fetchAll(PDO::FETCH_ASSOC);
            $students_mapped = [];
            foreach ($students_raw as $s) {
                $students_mapped[$s['id']] = $s;
            }
            
            // 5. Save marks
            if (isset($_POST['marks'])) {
                $stmt_mark_check = $db->prepare("SELECT id FROM marks WHERE exam_id = ? AND student_id = ? AND subject_id = ?");
                $stmt_mark_insert = $db->prepare("
                    INSERT INTO marks (exam_id, student_id, subject_id, theory_obtained, practical_obtained, internal_breakdown, grade, grade_point, remarks, entered_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_mark_update = $db->prepare("
                    UPDATE marks 
                    SET theory_obtained = ?, practical_obtained = ?, internal_breakdown = ?, grade = ?, grade_point = ?, remarks = ?, entered_by = ?
                    WHERE exam_id = ? AND student_id = ? AND subject_id = ?
                ");
                
                foreach ($_POST['marks'] as $student_id => $subjects_data) {
                    $student = $students_mapped[$student_id] ?? null;
                    if (!$student) continue;
                    
                    foreach ($subjects_data as $sub_id => $fields) {
                        $es_row = $exam_subjects_mapped[$sub_id] ?? null;
                        if (!$es_row) continue;
                        
                        // Check optional subjects mapping
                        if ($es_row['subject_type'] === 'optional') {
                            if ($sub_id != $student['optional_subject_i'] && $sub_id != $student['optional_subject_ii']) {
                                continue;
                            }
                        }
                        
                        // Check if empty
                        $is_empty = false;
                        if ($classType === '1-3' || $exam['type'] === 'unit') {
                            $theory_val = $fields['theory'] ?? '';
                            if ($theory_val === '') $is_empty = true;
                        } else {
                            $prt = $fields['participation'] ?? '';
                            $prj = $fields['project_work'] ?? '';
                            $prv = $fields['previous_exam'] ?? '';
                            $trm = $fields['theory'] ?? '';
                            if ($prt === '' && $prj === '' && $prv === '' && $trm === '') $is_empty = true;
                        }
                        
                        if ($is_empty) {
                            $theory_obtained = null;
                            $practical_obtained = null;
                            $internal_breakdown = null;
                            $grade = null;
                            $gp = null;
                            $remarks = null;
                        } else {
                            if ($exam['type'] === 'unit') {
                                $theory_obtained = (float)$theory_val;
                                $practical_obtained = 0.0;
                                $internal_breakdown = null;
                                
                                $theory_full = (float)$es_row['theory_full'];
                                $theory_pass = (float)$es_row['theory_pass'];
                                $pct = $theory_full > 0 ? ($theory_obtained / $theory_full) * 100 : 0.0;
                                
                                $is_fail = ($theory_obtained < $theory_pass);
                                if (($exam['result_system'] ?? 'gpa') === 'percentage') {
                                    $gp = 0.0;
                                    $grade = '';
                                    $remarks = $is_fail ? 'Fail' : 'Pass';
                                } else {
                                    if ($is_fail) {
                                        $gp = 0.0;
                                        $grade = 'NG';
                                        $remarks = 'NG';
                                    } else {
                                        $gp = calculateGPA($pct);
                                        $grade = calculateGrade($pct);
                                        $remarks = 'Pass';
                                    }
                                }
                            } elseif ($classType === '1-3') {
                                $theory_obtained = (float)$theory_val;
                                $practical_obtained = 0.0;
                                $internal_breakdown = null;
                                
                                $grade = calculateGrade($theory_obtained);
                                $gp = calculateGPA($theory_obtained);
                                $remarks = 'Pass';
                            } else {
                                $val_prt = $prt !== '' ? (float)$prt : 0.0;
                                $val_prj = $prj !== '' ? (float)$prj : 0.0;
                                $val_prv = $prv !== '' ? (float)$prv : 0.0;
                                $val_trm = $trm !== '' ? (float)$trm : 0.0;
                                
                                $theory_obtained = $val_trm;
                                $practical_obtained = $val_prt + $val_prj + $val_prv;
                                $internal_breakdown = json_encode([
                                    'participation' => $val_prt,
                                    'project_work' => $val_prj,
                                    'previous_exam' => $val_prv
                                ]);
                                
                                $theory_full = (float)$es_row['theory_full'];
                                $theory_pass = (float)$es_row['theory_pass'];
                                $practical_full = (float)$es_row['practical_full'];
                                $practical_pass = (float)$es_row['practical_pass'];
                                
                                $int_ch = $es_row['internal_credit_hours'] !== null ? (float)$es_row['internal_credit_hours'] : ($classType === '4-8' ? 2.5 : 1.25);
                                $ext_ch = $es_row['external_credit_hours'] !== null ? (float)$es_row['external_credit_hours'] : ($classType === '4-8' ? 2.5 : 3.75);
                                $total_ch = $es_row['credit_hours'] !== null ? (float)$es_row['credit_hours'] : ($int_ch + $ext_ch);
                                
                                $int_pct = $practical_full > 0 ? ($practical_obtained / $practical_full) * 100 : 0;
                                $int_gp = calculateGPA($int_pct);
                                
                                $ext_pct = $theory_full > 0 ? ($theory_obtained / $theory_full) * 100 : 0;
                                $ext_gp = calculateGPA($ext_pct);
                                
                                $int_wgp = $int_ch * $int_gp;
                                $ext_wgp = $ext_ch * $ext_gp;
                                
                                $final_gp = $total_ch > 0 ? ($int_wgp + $ext_wgp) / $total_ch : 0.0;
                                
                                // Theory below 35% or Practical/Internal below 40% = NG
                                $is_fail = ($theory_obtained < $theory_pass) || ($practical_obtained < $practical_pass);
                                
                                if ($is_fail) {
                                    $gp = 0.0;
                                    $grade = 'NG';
                                    $remarks = 'NG';
                                } else {
                                    $gp = round($final_gp, 2);
                                    $grade = calculateSubjectFinalGrade($gp);
                                    $remarks = 'Pass';
                                }
                            }
                        }
                        
                        $stmt_mark_check->execute([$exam_id_post, $student_id, $sub_id]);
                        $record = $stmt_mark_check->fetch();
                        
                        if ($record) {
                            $stmt_mark_update->execute([
                                $theory_obtained, $practical_obtained, $internal_breakdown, $grade, $gp, $remarks, Auth::getUserId(),
                                $exam_id_post, $student_id, $sub_id
                            ]);
                        } else {
                            $stmt_mark_insert->execute([
                                $exam_id_post, $student_id, $sub_id,
                                $theory_obtained, $practical_obtained, $internal_breakdown, $grade, $gp, $remarks, Auth::getUserId()
                            ]);
                        }
                    }
                }
            }
            
            $db->commit();
            $message = "Marks and attendance saved successfully.";
            $message_type = 'success';
            
            $subject_id_post = $_POST['subject_id_hidden'] ?? '';
            $prev_exam_param = $prev_exam_id ? "&prev_exam=" . $prev_exam_id : "";
            $subject_param = $subject_id_post !== '' ? "&subject=" . $subject_id_post : "";
            redirect(APP_URL . "/modules/results/marks_entry.php?exam=$exam_id_post&class=$class_id_post&section=$section_id_post" . $prev_exam_param . $subject_param, $message);
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $message = "Error: " . $e->getMessage();
            $message_type = 'error';
        }
    }
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
$stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ? OR school_id = 0) AND is_active = 1 ORDER BY id ASC");
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
        SELECT es.*, sub.name_en as subject_name, sub.code as subject_code, sub.type as subject_type
        FROM exam_subjects es
        JOIN subjects sub ON es.subject_id = sub.id
        WHERE es.exam_id = ? AND es.class_id = ? AND (sub.is_hidden = 0 OR sub.is_hidden IS NULL)
    ");
    $stmt->execute([$exam_id, $class_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
               s.optional_subject_i, s.optional_subject_ii
        FROM students s
        WHERE s.class_id = ? AND s.status = 'active'
    ";
    $params = [$class_id];
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
        <small class="text-muted">Horizontal scrolling enabled. Gray cells are optional subjects not selected by the student.</small>
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
                                <?php $colspan = (($selected_exam && $selected_exam['type'] === 'unit') || $classType === '1-3') ? 1 : 4; ?>
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
                                <?php elseif ($classType === '1-3'): ?>
                                    <th style="min-width: 100px; font-size: 0.75rem;">Ach % (100)</th>
                                <?php else: ?>
                                    <?php $max_prt = ($classType === '4-8') ? 4 : 3; ?>
                                    <?php $max_prj = ($classType === '4-8') ? 36 : 16; ?>
                                    <?php $max_prv = ($classType === '4-8') ? 10 : 6; ?>
                                    <?php $max_trm = ($classType === '4-8') ? 50 : 75; ?>
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
                                    $is_optional = ($sub['subject_type'] === 'optional');
                                    $is_selected = true;
                                    if ($is_optional) {
                                        $is_selected = ($sub['id'] == $student['optional_subject_i'] || $sub['id'] == $student['optional_subject_ii']);
                                    }
                                    
                                    if (!$is_selected): 
                                        $colspan = (($selected_exam && $selected_exam['type'] === 'unit') || $classType === '1-3') ? 1 : 4;
                                    ?>
                                        <td colspan="<?= $colspan ?>" style="background-color: #eaeded; color: #a6acaf; font-size: 0.75rem;" class="text-center font-monospace">N/A</td>
                                    <?php else: ?>
                                        <?php 
                                        $m = $marks_matrix[$student['id']][$sub['id']] ?? null;
                                        $theory_obtained = $m !== null && $m['theory_obtained'] !== null ? $m['theory_obtained'] : '';
                                        $practical_obtained = $m !== null && $m['practical_obtained'] !== null ? $m['practical_obtained'] : '';
                                        
                                        $breakdown = $m !== null && $m['internal_breakdown'] !== null ? json_decode($m['internal_breakdown'], true) : [];
                                        
                                        $prt_val = $breakdown['participation'] ?? '';
                                        $prj_val = $breakdown['project_work'] ?? '';
                                        $prv_val = $breakdown['previous_exam'] ?? '';
                                        
                                        // Auto scaling prefill logic if prv_val is empty and previous exam marks are loaded
                                        if ($prv_val === '' && isset($prev_marks[$student['id']][$sub['id']])) {
                                            $pm = $prev_marks[$student['id']][$sub['id']];
                                            $pm_obt = $pm['theory'] + $pm['practical'];
                                            $pm_full = $pm['theory_full'] + $pm['practical_full'];
                                            if ($pm_full > 0) {
                                                if ($classType === '4-8') {
                                                    $prv_val = round(($pm_obt / $pm_full) * 10, 2);
                                                } elseif ($classType === '9-12') {
                                                    $prv_val = round(($pm_obt / $pm_full) * 6, 2);
                                                }
                                            }
                                        }
                                        ?>
                                        <?php if ($selected_exam && $selected_exam['type'] === 'unit'): ?>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $sub['theory_full'] ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['id'] ?>][theory]" value="<?= $theory_obtained ?>">
                                            </td>
                                        <?php elseif ($classType === '1-3'): ?>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['id'] ?>][theory]" value="<?= $theory_obtained ?>">
                                            </td>
                                        <?php else: ?>
                                            <?php $max_prt = ($classType === '4-8') ? 4 : 3; ?>
                                            <?php $max_prj = ($classType === '4-8') ? 36 : 16; ?>
                                            <?php $max_prv = ($classType === '4-8') ? 10 : 6; ?>
                                            <?php $max_trm = ($classType === '4-8') ? 50 : 75; ?>
                                            
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $max_prt ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['id'] ?>][participation]" value="<?= $prt_val ?>">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $max_prj ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['id'] ?>][project_work]" value="<?= $prj_val ?>">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" max="<?= $max_prv ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['id'] ?>][previous_exam]" value="<?= $prv_val ?>">
                                            </td>
                                            <td style="background-color: #e8f8f5;">
                                                <input type="number" step="0.01" min="0" max="<?= $max_trm ?>" class="form-control form-control-sm text-center font-monospace fw-bold" name="marks[<?= $student['id'] ?>][<?= $sub['id'] ?>][theory]" value="<?= $theory_obtained ?>">
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
            
            <?php if (count($students) > 0 && Auth::hasRole(['super_admin', 'school_admin', 'teacher'])): ?>
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
