<?php
/**
 * Result Management - Printable Report Card
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireLogin();

$db = getDB();
$student_id = isset($_GET['student']) ? (int)$_GET['student'] : 0;
$exam_id = isset($_GET['exam']) ? (int)$_GET['exam'] : 0;

$school_id = Auth::getSchoolId();
$grading_system = $_GET['system'] ?? 'gpa';

if (!function_exists('getPercentageDivision')) {
    function getPercentageDivision($pct, $has_failed) {
        if ($has_failed || $pct < 32) {
            return 'Failed';
        }
        if ($pct >= 80) return 'Distinction';
        if ($pct >= 60) return 'First Division';
        if ($pct >= 45) return 'Second Division';
        return 'Third Division';
    }
}

// Fetch student details
$stmt = $db->prepare("
    SELECT s.*, c.name as class_name, c.level as class_level, c.class_teacher_name, c.class_teacher_signature, c.grading_type, sc.name as section_name, y.year_bs, y.year_ad
    FROM students s
    JOIN classes c ON s.class_id = c.id
    LEFT JOIN sections sc ON s.section_id = sc.id
    JOIN academic_years y ON s.academic_year_id = y.id
    WHERE s.id = ? AND s.school_id = ? AND c.is_active = 1
");
$stmt->execute([$student_id, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student not found.");
}

// Fetch sections count for class
$stmt_sec_count = $db->prepare("SELECT COUNT(*) FROM sections WHERE class_id = ?");
$stmt_sec_count->execute([$student['class_id']]);
$sectionsCount = (int)$stmt_sec_count->fetchColumn();

// Fetch exam details
$stmt = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
$stmt->execute([$exam_id, $school_id]);
$exam = $stmt->fetch();

if (!$exam) {
    die("Exam not found.");
}

if (!isset($_GET['system']) && !empty($exam['result_system'])) {
    $grading_system = $exam['result_system'];
}

// Fetch school details
$school_stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$school_stmt->execute([$school_id]);
$school = $school_stmt->fetch();

// Fetch student's marks for this exam with subject weightages
$stmt = $db->prepare("
    SELECT m.theory_obtained, m.practical_obtained, m.grade, m.grade_point, m.remarks,
           sub.name_en as subject_name, sub.name_np as subject_name_np, sub.code as subject_code, sub.type as subject_type, sub.id as subject_id,
           es.credit_hours as exam_credit_hours, sub.credit_hours as default_credit_hours,
           es.theory_full, es.theory_pass, es.practical_full, es.practical_pass,
           es.internal_credit_hours, es.external_credit_hours
    FROM exam_subjects es
    JOIN subjects sub ON es.subject_id = sub.id
    LEFT JOIN marks m ON m.exam_id = es.exam_id AND m.subject_id = es.subject_id AND m.student_id = ?
    WHERE es.exam_id = ? AND es.class_id = ?
");
$stmt->execute([$student_id, $exam_id, $student['class_id']]);
$marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($marks)) {
    die("No marks recorded for this student in this exam.");
}

// Filter unregistered optional subjects
$filtered_marks = [];
foreach ($marks as $mark) {
    if ($mark['subject_type'] === 'optional') {
        if ($mark['subject_id'] != $student['optional_subject_i'] && $mark['subject_id'] != $student['optional_subject_ii']) {
            continue;
        }
    }
    $filtered_marks[] = $mark;
}
$marks = $filtered_marks;

if (empty($marks)) {
    die("No marks recorded for the student's selected subjects in this exam.");
}

// Get class level type
$classType = getClassType($student['class_name']);

// Get attendance
$attendance_days = null;
$stmt_att = $db->prepare("SELECT present_days FROM exam_student_attendance WHERE exam_id = ? AND student_id = ?");
$stmt_att->execute([$exam_id, $student_id]);
$attendance_days = $stmt_att->fetchColumn();

// Calculate GPA metrics (Nepal CDC Standards)
$total_credit_hours = 0;
$weighted_gp_sum = 0;
$has_failed_subject = false;

// If exam has no internal, force practical components to 0
if (!$exam['has_internal']) {
    foreach ($marks as &$mark) {
        $mark['practical_full'] = 0;
        $mark['practical_pass'] = 0;
        $mark['practical_obtained'] = 0;
    }
    unset($mark);
}

$marks_elaborated = [];
foreach ($marks as $mark) {
    if ($exam['type'] === 'unit') {
        $ch = (float)($mark['exam_credit_hours'] ?? $mark['default_credit_hours'] ?? 4);
        $gp = (float)$mark['grade_point'];
        $grade = $mark['grade'] ?: '';
        $remarks = $mark['remarks'] ?: '';
        
        $is_fail = ($remarks === 'NG' || $remarks === 'Fail' || $grade === 'NG');
        if ($is_fail) {
            $has_failed_subject = true;
        }
        
        $total_credit_hours += $ch;
        $weighted_gp_sum += ($gp * $ch);
        
        $marks_elaborated[] = [
            'subject_code' => $mark['subject_code'],
            'subject_name' => $mark['subject_name'],
            'subject_name_np' => $mark['subject_name_np'],
            'credit_hours' => $ch,
            'theory_obtained' => $mark['theory_obtained'] !== null ? (float)$mark['theory_obtained'] : 0.0,
            'theory_full' => (float)$mark['theory_full'],
            'theory_pass' => (float)$mark['theory_pass'],
            'grade' => $grade,
            'grade_point' => $gp,
            'remarks' => $remarks
        ];
    } elseif ($classType === '1-3') {
        $ch = (float)($mark['exam_credit_hours'] ?? $mark['default_credit_hours'] ?? 4);
        $gp = (float)$mark['grade_point'];
        $grade = $mark['grade'] ?: 'NG';
        
        $is_fail = ($grade === 'NG' || $mark['remarks'] === 'NG');
        if ($is_fail) {
            $remarks = ($student['grading_type'] === 'percentage') ? 'Fail' : 'Not Graded';
            $has_failed_subject = true;
        } else {
            $remarks = $mark['remarks'] ?: (($student['grading_type'] === 'percentage') ? 'Pass' : 'Graded');
        }
        
        $total_credit_hours += $ch;
        $weighted_gp_sum += ($gp * $ch);
        
        $marks_elaborated[] = [
            'subject_code' => $mark['subject_code'],
            'subject_name' => $mark['subject_name'],
            'subject_name_np' => $mark['subject_name_np'],
            'credit_hours' => $ch,
            'grade' => $grade,
            'grade_point' => $gp,
            'remarks' => $remarks
        ];
    } else {
        // Class 4-8 or Class 9-12 (Secondary)
        $int_ch = $mark['internal_credit_hours'] !== null ? (float)$mark['internal_credit_hours'] : ($classType === '4-8' ? 2.5 : 1.25);
        $ext_ch = $mark['external_credit_hours'] !== null ? (float)$mark['external_credit_hours'] : ($classType === '4-8' ? 2.5 : 3.75);
        $total_ch = $mark['exam_credit_hours'] !== null ? (float)$mark['exam_credit_hours'] : ($int_ch + $ext_ch);
        
        $theory_obtained = $mark['theory_obtained'] !== null ? (float)$mark['theory_obtained'] : 0.0;
        $practical_obtained = $mark['practical_obtained'] !== null ? (float)$mark['practical_obtained'] : 0.0;
        
        $int_full = (float)$mark['practical_full'];
        $int_pass = (float)$mark['practical_pass'];
        $ext_full = (float)$mark['theory_full'];
        $ext_pass = (float)$mark['theory_pass'];
        
        // Component percentages
        $int_pct = $int_full > 0 ? ($practical_obtained / $int_full) * 100 : 0;
        $ext_pct = $ext_full > 0 ? ($theory_obtained / $ext_full) * 100 : 0;
        
        $int_gp = calculateGPA($int_pct);
        $int_grade = $practical_obtained < $int_pass ? 'NG' : calculateGrade($int_pct);
        
        $ext_gp = calculateGPA($ext_pct);
        $ext_grade = $theory_obtained < $ext_pass ? 'NG' : calculateGrade($ext_pct);
        
        $int_wgp = $int_ch * $int_gp;
        $ext_wgp = $ext_ch * $ext_gp;
        
        $final_gp = $total_ch > 0 ? ($int_wgp + $ext_wgp) / $total_ch : 0.0;
        $is_fail = ($theory_obtained < $ext_pass) || ($practical_obtained < $int_pass);
        
        if ($is_fail) {
            $gp = 0.0;
            $grade = 'NG';
            $remarks = ($student['grading_type'] === 'percentage') ? 'Fail' : 'Not Graded';
            $has_failed_subject = true;
        } else {
            $gp = round($final_gp, 2);
            $grade = calculateSubjectFinalGrade($gp);
            $remarks = ($student['grading_type'] === 'percentage') ? 'Pass' : 'Graded';
        }
        
        $total_credit_hours += $total_ch;
        $weighted_gp_sum += ($gp * $total_ch);
        
        $marks_elaborated[] = [
            'subject_code' => $mark['subject_code'],
            'subject_name' => $mark['subject_name'],
            'subject_name_np' => $mark['subject_name_np'],
            'credit_hours' => $total_ch,
            'internal_credit_hours' => $int_ch,
            'external_credit_hours' => $ext_ch,
            'theory_obtained' => $theory_obtained,
            'practical_obtained' => $practical_obtained,
            'theory_full' => $ext_full,
            'practical_full' => $int_full,
            'internal_gp' => $int_gp,
            'internal_grade' => $int_grade,
            'external_gp' => $ext_gp,
            'external_grade' => $ext_grade,
            'grade' => $grade,
            'grade_point' => $gp,
            'remarks' => $remarks
        ];
    }
}

$grand_full = 0;
$grand_obtained = 0;
if ($exam['type'] === 'unit') {
    foreach ($marks_elaborated as $m) {
        $grand_full += $m['theory_full'];
        $grand_obtained += $m['theory_obtained'];
    }
    $average_gpa = $grand_full > 0 ? round(($grand_obtained / $grand_full) * 100, 2) : 0.00;
} else {
    $average_gpa = $total_credit_hours > 0 ? round($weighted_gp_sum / $total_credit_hours, 2) : 0.00;
}

if ($has_failed_subject) {
    if ($grading_system === 'percentage') {
        $final_gpa_text = number_format($average_gpa, 2) . '%';
        $final_grade = 'F';
        $remarks_summary = 'Failed';
    } else {
        $final_gpa_text = 'NG';
        $final_grade = 'NG';
        $remarks_summary = 'Not Graded';
    }
} else {
    if ($grading_system === 'percentage') {
        $final_gpa_text = number_format($average_gpa, 2) . '%';
        $final_grade = getPercentageDivision($average_gpa, false);
        $remarks_summary = 'Passed';
    } else {
        $final_gpa_text = number_format($average_gpa, 2);
        $final_grade = calculateSubjectFinalGrade($average_gpa);
        $remarks_summary = 'Graded';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progress Report - <?= sanitize($student['first_name_en'] . '_' . $student['last_name_en']) ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }
        .report-container {
            background-color: #fff;
            max-width: 800px;
            margin: 15px auto;
            padding: 20px 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border: 1px solid #e1e4e8;
            position: relative;
        }
        .school-header {
            border-bottom: 3px double #0d6efd;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .school-title {
            color: #0b4b8f;
            font-weight: 800;
            text-transform: uppercase;
        }
        .table-report {
            border: 2px solid #333;
            font-size: 0.8rem;
        }
        .table-report th {
            background-color: #f1f3f5;
            border: 1px solid #333;
            font-weight: 700;
            text-align: center;
            padding: 4px 6px !important;
        }
        .table-report td {
            border: 1px solid #333;
            padding: 4px 6px !important;
        }
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            font-weight: bold;
            color: rgba(0,0,0,0.03);
            pointer-events: none;
            white-space: nowrap;
            z-index: 1;
        }
        .signature-section {
            margin-top: 15px;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 120px;
            margin: 0 auto;
            margin-top: 25px;
        }
        @media print {
            body {
                background-color: #fff;
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .report-container {
                box-shadow: none;
                margin: 0 auto;
                padding: 0px 0px;
                margin-top: 0 !important;
                padding-top: 0 !important;
                width: 100%;
                max-width: 100%;
                border: none;
            }
            .report-container table {
                margin-bottom: 5px !important;
            }
            .report-container table th, .report-container table td {
                padding: 2px 4px !important;
                font-size: 0.75rem !important;
            }
            .school-header {
                margin-top: 0 !important;
                padding-top: 0 !important;
                margin-bottom: 6px !important;
                padding-bottom: 4px !important;
            }
            .school-header h2 {
                font-size: 1.25rem !important;
            }
            .school-header h4 {
                font-size: 0.95rem !important;
            }
            .school-header p {
                font-size: 0.75rem !important;
                margin-bottom: 0 !important;
            }
            .school-header h5 {
                font-size: 0.85rem !important;
                margin-top: 2px !important;
                margin-bottom: 2px !important;
            }
            .school-header h6 {
                font-size: 0.75rem !important;
            }
            .signature-section {
                margin-top: 6px !important;
            }
            .signature-line {
                margin-top: 15px !important;
            }
            .no-print {
                display: none !important;
            }
            @page {
                size: A4 portrait;
                margin: 2mm 0.4in;
            }
        }
    </style>
</head>
<body>

<div class="container no-print text-center my-3">
    <div class="btn-group me-2" role="group">
        <a href="?student=<?= $student_id ?>&exam=<?= $exam_id ?>&system=gpa" class="btn btn-<?= $grading_system === 'gpa' ? 'primary' : 'outline-primary' ?>">GPA Format</a>
        <a href="?student=<?= $student_id ?>&exam=<?= $exam_id ?>&system=percentage" class="btn btn-<?= $grading_system === 'percentage' ? 'primary' : 'outline-primary' ?>">Percentage Format</a>
    </div>
    <button onclick="window.print()" class="btn btn-success px-4 py-2">
        <i class="fas fa-print"></i> Print Report Card (A4 Page)
    </button>
</div>

<div class="report-container">
    <div class="watermark"><?= sanitize($school['name_en'] ?? 'School ERP') ?></div>
    
    <!-- School Header -->
    <div class="school-header text-center">
        <div class="row align-items-center">
            <div class="col-2 text-start">
                <?php if (!empty($school['logo'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $school['logo'] ?>" width="110" alt="Logo">
                <?php else: ?>
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 110px; height: 110px; border: 1px solid #ddd;">
                        <span class="small text-muted">LOGO</span>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="col-8">
                <h2 class="school-title mb-0"><?= sanitize($school['name_en']) ?></h2>
                <h4 class="text-danger mb-1"><?= sanitize($school['name_np'] ?? '') ?></h4>
                <p class="mb-0 small"><?= sanitize($school['address'] ?? 'Nepal') ?> | Phone: <?= sanitize($school['phone'] ?? '-') ?> | Email: <?= sanitize($school['email'] ?? '-') ?></p>
                
                <?php if ($classType === '1-3'): ?>
                    <h5 class="mt-2 text-uppercase fw-bold text-dark text-decoration-underline mb-1">GRADE SHEET</h5>
                    <h6 class="mb-0 fw-bold small"><?= sanitize($exam['name']) ?> - <?= $student['year_bs'] ?> BS (<?= $student['year_ad'] ?> AD)</h6>
                <?php elseif ($classType === '4-8'): ?>
                    <h5 class="mt-2 text-uppercase fw-bold text-dark mb-0" style="font-size: 1rem;">BASIC LEVEL EXAMINATION</h5>
                    <h5 class="text-uppercase fw-bold text-dark text-decoration-underline mb-1">GRADE SHEET</h5>
                    <h6 class="mb-0 fw-bold small"><?= sanitize($exam['name']) ?> - <?= $student['year_bs'] ?> BS (<?= $student['year_ad'] ?> AD)</h6>
                <?php else: ?>
                    <h5 class="text-uppercase fw-bold text-dark text-decoration-underline mb-1" style="margin-top: 10px;">GRADE SHEET</h5>
                    <h6 class="mb-0 fw-bold small"><?= sanitize($exam['name']) ?> - <?= $student['year_bs'] ?> BS (<?= $student['year_ad'] ?> AD)</h6>
                <?php endif; ?>
            </div>
            <div class="col-2"></div>
        </div>
    </div>
    
    <!-- Student Details -->
    <div class="mb-2" style="border: 1px solid #333; padding: 4px 8px; border-radius: 4px;">
        <table class="table table-sm table-borderless mb-0" style="font-size: 0.8rem; width: 100%;">
            <tbody>
                <tr>
                    <td style="padding: 2px 0; width: 38%;">
                        <span class="text-muted">The Grade(s) Secured By:</span> <strong class="text-uppercase"><?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?></strong>
                    </td>
                    <td style="padding: 2px 0; width: 32%; text-align: center;">
                        <span class="text-muted">Class/Grade:</span> <strong><?= sanitize($student['class_name']) ?><?= ($sectionsCount > 1 && !empty($student['section_name'])) ? ' (Section: ' . sanitize($student['section_name']) . ')' : '' ?></strong>
                    </td>
                    <td style="padding: 2px 0; width: 30%; text-align: right;">
                        <span class="text-muted">Attendance (Present Days):</span> <strong><?= ($attendance_days !== false && $attendance_days !== null) ? $attendance_days . ' Days' : '-' ?></strong>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 2px 0;">
                        <span class="text-muted">Registration No:</span> <strong><?= sanitize($student['student_id']) ?></strong>
                    </td>
                    <td style="padding: 2px 0; text-align: center;">
                        <span class="text-muted">Symbol No / Roll No:</span> <strong><?= sanitize($student['exam_roll_number'] ?: $student['roll_number'] ?: '-') ?></strong>
                    </td>
                    <td style="padding: 2px 0; text-align: right;">
                        <span class="text-muted">Date of Birth:</span> <strong><?= sanitize($student['dob_bs'] ?: '-') ?> BS (<?= formatDate($student['dob_ad']) ?> AD)</strong>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Marks Table -->
    <?php if ($exam['type'] === 'unit'): ?>
        <?php if ($grading_system === 'percentage'): ?>
        <!-- Unit Test Marksheet Layout -->
        <table class="table table-report table-bordered align-middle">
            <thead>
                <tr>
                    <th width="60">S.N.</th>
                    <th>Subject</th>
                    <th width="120">Full Marks</th>
                    <th width="120">Pass Marks</th>
                    <th width="140">Obtained Marks</th>
                    <th width="120">Percentage</th>
                    <th width="140">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sn = 0;
                foreach ($marks_elaborated as $m): 
                    $sn++;
                    $sub_pct = $m['theory_full'] > 0 ? ($m['theory_obtained'] / $m['theory_full']) * 100 : 0;
                    $is_sub_fail = ($m['remarks'] === 'Fail' || $m['remarks'] === 'NG');
                ?>
                <tr>
                    <td class="text-center font-monospace"><?= $sn ?></td>
                    <td><strong><?= sanitize($m['subject_name']) ?></strong></td>
                    <td class="text-center font-monospace"><?= $m['theory_full'] ?></td>
                    <td class="text-center font-monospace"><?= $m['theory_pass'] ?></td>
                    <td class="text-center font-monospace fw-bold"><?= $m['theory_obtained'] ?></td>
                    <td class="text-center font-monospace"><?= round($sub_pct, 1) ?>%</td>
                    <td class="text-center fw-bold <?= $is_sub_fail ? 'text-danger' : 'text-success' ?>"><?= $is_sub_fail ? 'Fail' : 'Pass' ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="table-light fw-bold" style="border-top: 2px solid #333;">
                    <td colspan="2" class="text-end">Total Summary:</td>
                    <td class="text-center font-monospace"><?= $grand_full ?></td>
                    <td></td>
                    <td class="text-center font-monospace text-primary"><?= $grand_obtained ?></td>
                    <td class="text-center font-monospace"><?= number_format($average_gpa, 2) ?>%</td>
                    <td class="text-center text-uppercase"><?= $remarks_summary ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <!-- Unit Test Grading Layout -->
        <table class="table table-report table-bordered align-middle">
            <thead>
                <tr>
                    <th width="60">S.N.</th>
                    <th>Subject</th>
                    <th width="120">Full Marks</th>
                    <th width="120">Grade</th>
                    <th width="120">Grade Point</th>
                    <th width="150">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sn = 0;
                foreach ($marks_elaborated as $m): 
                    $sn++;
                ?>
                <tr>
                    <td class="text-center font-monospace"><?= $sn ?></td>
                    <td><strong><?= sanitize($m['subject_name']) ?></strong></td>
                    <td class="text-center font-monospace"><?= $m['theory_full'] ?></td>
                    <td class="text-center font-monospace fw-bold"><?= $m['grade'] ?: '-' ?></td>
                    <td class="text-center font-monospace fw-bold"><?= number_format($m['grade_point'], 2) ?></td>
                    <td class="text-center small"><?= $m['remarks'] ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="table-light fw-bold" style="border-top: 2px solid #333;">
                    <td colspan="2" class="text-end">Total Summary:</td>
                    <td class="text-center font-monospace"><?= $grand_full ?></td>
                    <td class="text-center font-monospace"><?= $final_grade ?></td>
                    <td class="text-center font-monospace text-primary"><?= $final_gpa_text ?></td>
                    <td class="text-center"><?= $remarks_summary ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
    <?php elseif ($grading_system === 'percentage'): ?>
    <table class="table table-report table-bordered align-middle">
        <thead>
            <tr>
                <th width="50">S.N.</th>
                <th>Subject</th>
                <th width="90">Full Marks</th>
                <th width="90">Pass Marks</th>
                <th width="90">Theory (TH)</th>
                <th width="90">Practical (PR)</th>
                <th width="100">Total Obtained</th>
                <th width="80">Percentage</th>
                <th width="100">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sn = 0;
            $grand_full = 0;
            $grand_obtained = 0;
            $has_failed_sub = false;
            foreach ($marks_elaborated as $m): 
                $sn++;
                $orig = null;
                foreach ($marks as $mark) {
                    if ($mark['subject_code'] == $m['subject_code']) {
                        $orig = $mark;
                        break;
                    }
                }
                if (!$orig) continue;
                
                $th_full = (float)($orig['theory_full'] ?? 0);
                $pr_full = (float)($orig['practical_full'] ?? 0);
                $th_pass = (float)($orig['theory_pass'] ?? 0);
                $pr_pass = (float)($orig['practical_pass'] ?? 0);
                
                $th_obtained = $orig['theory_obtained'] !== null ? (float)$orig['theory_obtained'] : 0;
                $pr_obtained = $orig['practical_obtained'] !== null ? (float)$orig['practical_obtained'] : 0;
                
                $sub_full = $th_full + $pr_full;
                $sub_obtained = $th_obtained + $pr_obtained;
                
                $sub_pct = $sub_full > 0 ? ($sub_obtained / $sub_full) * 100 : 0;
                
                $is_sub_fail = false;
                if ($th_full > 0 && $th_obtained < $th_pass) $is_sub_fail = true;
                if ($pr_full > 0 && $pr_obtained < $pr_pass) $is_sub_fail = true;
                
                if ($is_sub_fail) {
                    $has_failed_sub = true;
                    $sub_remarks = 'Fail';
                } else {
                    $sub_remarks = 'Pass';
                }
                
                $grand_full += $sub_full;
                $grand_obtained += $sub_obtained;
            ?>
            <tr>
                <td class="text-center font-monospace"><?= $sn ?></td>
                <td>
                    <strong><?= sanitize($m['subject_name']) ?></strong>
                </td>
                <td class="text-center font-monospace"><?= $sub_full ?></td>
                <td class="text-center font-monospace"><?= $th_pass + $pr_pass ?></td>
                <td class="text-center font-monospace"><?= $th_full > 0 ? $th_obtained : '-' ?></td>
                <td class="text-center font-monospace"><?= $pr_full > 0 ? $pr_obtained : '-' ?></td>
                <td class="text-center font-monospace fw-bold"><?= $sub_obtained ?></td>
                <td class="text-center font-monospace"><?= round($sub_pct, 1) ?>%</td>
                <td class="text-center fw-bold <?= $is_sub_fail ? 'text-danger' : 'text-success' ?>"><?= $sub_remarks ?></td>
            </tr>
            <?php endforeach; ?>
            
            <?php
            $grand_pct = $grand_full > 0 ? ($grand_obtained / $grand_full) * 100 : 0;
            $division = getPercentageDivision($grand_pct, $has_failed_sub);
            ?>
            <tr class="table-light fw-bold" style="border-top: 2px solid #333;">
                <td colspan="2" class="text-end">Total Summary:</td>
                <td class="text-center font-monospace"><?= $grand_full ?></td>
                <td></td>
                <td colspan="2"></td>
                <td class="text-center font-monospace text-primary"><?= $grand_obtained ?></td>
                <td class="text-center font-monospace"><?= round($grand_pct, 2) ?>%</td>
                <td class="text-center text-uppercase"><?= $division ?></td>
            </tr>
        </tbody>
    </table>
    
    <?php elseif ($classType === '1-3'): ?>
    <table class="table table-report table-bordered align-middle">
        <thead>
            <tr>
                <th width="60">S.N.</th>
                <th>Subjects</th>
                <th width="120">Credit Hour (CH)</th>
                <th width="100">Grade</th>
                <th width="120">Grade Point</th>
                <th width="150">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sn = 0;
            foreach ($marks_elaborated as $m): 
                $sn++;
            ?>
            <tr>
                <td class="text-center font-monospace"><?= $sn ?></td>
                <td>
                    <strong><?= sanitize($m['subject_name']) ?></strong>
                </td>
                <td class="text-center font-monospace"><?= $m['credit_hours'] ?></td>
                <td class="text-center font-monospace fw-bold"><?= $m['grade'] ?></td>
                <td class="text-center font-monospace fw-bold"><?= number_format($m['grade_point'], 2) ?></td>
                <td class="text-center small"><?= $m['remarks'] ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Summary Row -->
            <tr class="table-light fw-bold" style="border-top: 2px solid #333;">
                <td colspan="2" class="text-end">Total Summary:</td>
                <td class="text-center font-monospace"><?= $total_credit_hours ?> CH</td>
                <td class="text-center font-monospace"><?= $final_grade ?></td>
                <td class="text-center font-monospace text-primary"><?= $final_gpa_text ?></td>
                <td class="text-center"><?= $remarks_summary ?></td>
            </tr>
        </tbody>
    </table>
    
    <?php elseif ($classType === '4-8'): ?>
    <table class="table table-report table-bordered align-middle">
        <thead>
            <tr>
                <th>Subjects</th>
                <th width="150">Credit Hour (CH)</th>
                <th width="150">Grade Point (GP)</th>
                <th width="120">Grade</th>
                <th width="150">Final Grade</th>
                <th width="150">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($marks_elaborated as $m): ?>
            <!-- Theory Row -->
            <tr>
                <td>
                    <strong><?= sanitize($m['subject_name']) ?> (TH)</strong>
                </td>
                <td class="text-center font-monospace"><?= $m['external_credit_hours'] ?></td>
                <td class="text-center font-monospace"><?= number_format($m['external_gp'], 2) ?></td>
                <td class="text-center font-monospace"><?= $m['external_grade'] ?></td>
                <td rowspan="2" class="text-center font-monospace fw-bold align-middle bg-light"><?= $m['grade'] ?></td>
                <td rowspan="2" class="text-center small align-middle bg-light"><?= $m['remarks'] ?></td>
            </tr>
            <!-- Internal Row -->
            <tr>
                <td><strong><?= sanitize($m['subject_name']) ?> (IN)</strong></td>
                <td class="text-center font-monospace"><?= $m['internal_credit_hours'] ?></td>
                <td class="text-center font-monospace"><?= number_format($m['internal_gp'], 2) ?></td>
                <td class="text-center font-monospace"><?= $m['internal_grade'] ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Summary Row -->
            <tr class="table-light fw-bold" style="border-top: 2px solid #333;">
                <td class="text-end">Total Summary:</td>
                <td class="text-center font-monospace"><?= $total_credit_hours ?> CH</td>
                <td colspan="2"></td>
                <td class="text-center font-monospace text-primary bg-light"><?= $final_gpa_text === 'NG' ? 'NG' : 'GPA ' . $final_gpa_text ?></td>
                <td class="text-center bg-light"><?= $remarks_summary ?></td>
            </tr>
        </tbody>
    </table>
    
    <?php else: // Class 9-12 (Secondary) ?>
    <table class="table table-report table-bordered align-middle">
        <thead>
            <tr>
                <th style="white-space: nowrap; text-align: left; padding-left: 10px !important;">Subject</th>
                <th width="150">Credit Hour (CH)</th>
                <th width="100">Grade</th>
                <th width="150">Grade Point (GP)</th>
                <th width="150">Final Grade (FG)</th>
                <th width="150">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($marks_elaborated as $m): ?>
            <!-- Theory Row -->
            <tr>
                <td style="white-space: nowrap; padding-left: 10px !important; text-align: left;">
                    <strong><?= sanitize($m['subject_name']) ?> (TH)</strong>
                </td>
                <td class="text-center font-monospace"><?= $m['external_credit_hours'] ?></td>
                <td class="text-center font-monospace"><?= $m['external_grade'] ?></td>
                <td class="text-center font-monospace"><?= number_format($m['external_gp'], 2) ?></td>
                <td rowspan="2" class="text-center font-monospace fw-bold align-middle bg-light"><?= $m['grade'] ?></td>
                <td rowspan="2" class="text-center small align-middle bg-light"><?= $m['remarks'] ?></td>
            </tr>
            <!-- Internal Row -->
            <tr>
                <td style="white-space: nowrap; padding-left: 10px !important; text-align: left;"><strong><?= sanitize($m['subject_name']) ?> (IN)</strong></td>
                <td class="text-center font-monospace"><?= $m['internal_credit_hours'] ?></td>
                <td class="text-center font-monospace"><?= $m['internal_grade'] ?></td>
                <td class="text-center font-monospace"><?= number_format($m['internal_gp'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Summary Row -->
            <tr class="table-light fw-bold" style="border-top: 2px solid #333;">
                <td class="text-end" style="padding-right: 15px !important;">Total Summary:</td>
                <td class="text-center font-monospace"><?= $total_credit_hours ?> CH</td>
                <td colspan="2"></td>
                <td class="text-center font-monospace text-primary bg-light"><?= $final_gpa_text === 'NG' ? 'NG' : 'GPA ' . $final_gpa_text ?></td>
                <td class="text-center bg-light"><?= $remarks_summary ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
    
    <!-- Grading Key & Result Box -->
    <div class="row mt-3 align-items-center">
        <div class="col-8 text-start">
            <span class="fw-bold d-block mb-1 small">Grading Key System:</span>
            <table class="table table-bordered table-sm text-center mb-0" style="font-size: 0.7rem; line-height: 1.2; border: 1px solid #ddd;">
                <thead class="table-light">
                    <tr>
                        <th class="py-1">GPA Range</th>
                        <th class="py-1">Grade</th>
                        <th class="py-1">Remarks</th>
                        <th class="py-1">GPA Range</th>
                        <th class="py-1">Grade</th>
                        <th class="py-1">Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="py-0">3.61 - 4.00</td>
                        <td class="py-0 fw-bold">A+</td>
                        <td class="py-0">Outstanding</td>
                        <td class="py-0">2.01 - 2.40</td>
                        <td class="py-0 fw-bold">C+</td>
                        <td class="py-0">Satisfactory</td>
                    </tr>
                    <tr>
                        <td class="py-0">3.21 - 3.60</td>
                        <td class="py-0 fw-bold">A</td>
                        <td class="py-0">Excellent</td>
                        <td class="py-0">1.61 - 2.00</td>
                        <td class="py-0 fw-bold">C</td>
                        <td class="py-0">Acceptable</td>
                    </tr>
                    <tr>
                        <td class="py-0">2.81 - 3.20</td>
                        <td class="py-0 fw-bold">B+</td>
                        <td class="py-0">Very Good</td>
                        <td class="py-0">1.60</td>
                        <td class="py-0 fw-bold">D</td>
                        <td class="py-0">Basic</td>
                    </tr>
                    <tr>
                        <td class="py-0">2.41 - 2.80</td>
                        <td class="py-0 fw-bold">B</td>
                        <td class="py-0">Good</td>
                        <td class="py-0">Below 1.60</td>
                        <td class="py-0 fw-bold text-danger">NG</td>
                        <td class="py-0 text-danger">Not Graded</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="col-4 text-end">
            <div class="border p-2 bg-light text-center shadow-sm rounded">
                <span class="d-block text-uppercase fw-bold text-muted small" style="font-size: 0.65rem;">Final Result</span>
                <h5 class="mb-0 text-primary fw-bold font-monospace" style="font-size: 1.05rem;">
                    <?php 
                    if ($grading_system === 'percentage') {
                        echo ($remarks_summary === 'Failed') ? 'Failed' : 'Passed (' . $final_gpa_text . ')';
                    } else {
                        echo ($final_gpa_text === 'NG') ? 'Not Graded' : 'Graded (GPA ' . $final_gpa_text . ')';
                    }
                    ?>
                </h5>
            </div>
        </div>
    </div>
    
    <!-- Signatures -->
    <div class="row signature-section text-center">
        <div class="col-4">
            <div class="d-flex flex-column align-items-center justify-content-end" style="height: 40px; margin-bottom: 5px;">
                <?php 
                $ct_sig = !empty($student['class_teacher_signature']) ? $student['class_teacher_signature'] : '';
                if (empty($ct_sig) && !empty($student['class_teacher_name'])) {
                    $stmt_t_sig = $db->prepare("SELECT signature FROM users WHERE full_name = ? AND role = 'teacher' AND school_id = ? LIMIT 1");
                    $stmt_t_sig->execute([$student['class_teacher_name'], $school_id]);
                    $ct_sig = $stmt_t_sig->fetchColumn() ?: '';
                }
                if (empty($ct_sig)) {
                    $ct_sig = $school['class_teacher_signature'] ?? '';
                }
                if (!empty($ct_sig)): 
                ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $ct_sig ?>" style="max-height: 30px; max-width: 100px; object-fit: contain;" alt="Signature">
                <?php endif; ?>
            </div>
            <div class="signature-line" style="margin-top: 0;"></div>
            <p class="mb-0 small fw-bold mt-2">Class Teacher</p>
            <?php if (!empty($student['class_teacher_name'])): ?>
                <p class="mb-0 small text-muted font-monospace">(<?= sanitize($student['class_teacher_name']) ?>)</p>
            <?php endif; ?>
        </div>
        
        <div class="col-4">
            <div class="d-flex flex-column align-items-center justify-content-end" style="height: 40px; margin-bottom: 5px;">
                <?php if (!empty($school['exam_coordinator_signature'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $school['exam_coordinator_signature'] ?>" style="max-height: 30px; max-width: 100px; object-fit: contain;" alt="Signature">
                <?php endif; ?>
            </div>
            <div class="signature-line" style="margin-top: 0;"></div>
            <p class="mb-0 small fw-bold mt-2">Exam Coordinator</p>
        </div>
        
        <div class="col-4">
            <div class="d-flex flex-column align-items-center justify-content-end" style="height: 40px; margin-bottom: 5px;">
                <?php if (!empty($school['principal_signature'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $school['principal_signature'] ?>" style="max-height: 30px; max-width: 100px; object-fit: contain;" alt="Signature">
                <?php endif; ?>
            </div>
            <div class="signature-line" style="margin-top: 0;"></div>
            <p class="mb-0 small fw-bold mt-2">Head Teacher</p>
        </div>
    </div>
    
    <div class="mt-3 pt-2 border-top small text-muted text-start" style="font-size: 0.65rem; line-height: 1.3;">
        <p class="mb-1"><strong>NOTE:</strong> ONE CREDIT HOUR EQUALS TO 32 WORKING HOURS.</p>
        <?php if ($classType !== '1-3'): ?>
        <p class="mb-1"><strong>INTERNAL (IN):</strong> THIS COVERS PARTICIPATION, PRACTICAL/PROJECT WORKS, COMMUNITY WORKS, INTERNSHIP, PRESENTATIONS, AND TERMINAL EXAMINATIONS.</p>
        <p class="mb-1"><strong>THEORY (TH):</strong> THIS COVERS WRITTEN EXTERNAL EXAMINATION.</p>
        <p class="mb-0"><strong>ABS:</strong> ABSENT | <strong>W:</strong> WITHHELD | <strong>NG:</strong> NOT GRADED</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
