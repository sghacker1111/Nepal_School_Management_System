<?php
/**
 * Admit Card Batch Printing Panel
 * Prints 4 different student admit cards per A4 sheet in a 2x2 grid.
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'exam_officer', 'teacher']);

$db = getDB();
$school_id = Auth::getSchoolId();

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!$exam_id || !$class_id) {
    die("Invalid request. Exam ID and Class ID are required.");
}

// Fetch exam details
$stmt = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
$stmt->execute([$exam_id, $school_id]);
$exam = $stmt->fetch();

if (!$exam) {
    die("Exam not found.");
}

// Fetch class details
$stmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND school_id = ? AND is_active = 1");
$stmt->execute([$class_id, $school_id]);
$class = $stmt->fetch();

if (!$class) {
    die("Class not found.");
}

// Fetch school details
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();
if (!$school) {
    $school = [
        'name_en' => 'Model Secondary School',
        'name_np' => 'नमूना माध्यमिक विद्यालय',
        'address' => 'Kathmandu, Nepal',
        'phone' => '',
        'logo' => '',
        'class_teacher_signature' => '',
        'exam_coordinator_signature' => '',
        'principal_signature' => ''
    ];
}

// Fetch active students in class
$stmt = $db->prepare("
    SELECT s.*, sc.name as section_name, ay.year_bs, ay.year_ad 
    FROM students s
    LEFT JOIN sections sc ON s.section_id = sc.id
    LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
    WHERE s.class_id = ? AND s.school_id = ? AND s.status = 'active'
    ORDER BY s.roll_number ASC, s.first_name_en ASC
");
$stmt->execute([$class_id, $school_id]);
$students = $stmt->fetchAll();

if (empty($students)) {
    die("<div style='text-align:center; padding: 50px; font-family: sans-serif;'><h3 class='text-danger'>No active students found in this class.</h3><p><a href='javascript:window.close()'>Close Window</a></p></div>");
}

// Chunk students in groups of 4 for A4 pages
$student_chunks = array_chunk($students, 4);

$pageTitle = 'Print Admit Cards: ' . $class['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?></title>
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .no-print-bar {
            background-color: #fff;
            padding: 15px 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-family: inherit;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: bold;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
        }

        .btn-secondary {
            background-color: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #7f8c8d;
        }

        .print-layout {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        /* Standard A4 layout styling */
        .admit-card-page {
            width: 210mm;
            height: 297mm;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 8mm;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 8mm;
            page-break-after: always;
            break-after: page;
        }

        .admit-card {
            border: 2px solid #000;
            border-radius: 6px;
            padding: 10px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background-color: #fff;
            overflow: hidden;
            height: 100%;
        }

        .admit-card-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }

        .school-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 4px;
            width: 100%;
        }

        .school-logo {
            max-height: 42px;
            max-width: 42px;
            object-fit: contain;
        }

        .school-name {
            font-size: 13px;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
            color: #000;
        }

        .school-subtext {
            font-size: 9px;
            margin: 2px 0 0 0;
            color: #444;
            line-height: 1.2;
        }

        .card-title-badge {
            background-color: #000;
            color: #fff;
            font-size: 11px;
            font-weight: bold;
            padding: 2px 10px;
            border-radius: 3px;
            display: inline-block;
            margin-top: 5px;
            letter-spacing: 1px;
        }

        .academic-year {
            font-size: 9px;
            margin: 3px 0 0 0;
            font-weight: bold;
        }

        .admit-card-body {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
            align-items: flex-start;
        }

        .student-photo-container {
            width: 75px;
            height: 90px;
            border: 1px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #fafafa;
            flex-shrink: 0;
            border-radius: 4px;
            overflow: hidden;
        }

        .student-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-info-grid {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-item {
            display: flex;
            font-size: 11px;
            line-height: 1.3;
        }

        .info-label {
            font-weight: bold;
            width: 90px;
            flex-shrink: 0;
        }

        .info-val {
            flex-grow: 1;
        }

        .exam-info-box {
            border: 1px dashed #000;
            background-color: #fcfcfc;
            padding: 6px;
            border-radius: 4px;
            margin-bottom: 8px;
        }

        .exam-info-title {
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            margin: 0 0 4px 0;
            text-decoration: underline;
        }

        .instructions-list {
            margin: 0;
            padding-left: 12px;
            font-size: 8.5px;
            color: #333;
            line-height: 1.3;
        }

        .admit-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px solid #ccc;
        }

        .sig-box {
            text-align: center;
            width: 31%;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            height: 48px;
        }

        .sig-image-placeholder {
            height: 32px;
            width: 100%;
        }

        .sig-image {
            max-height: 32px;
            max-width: 100%;
            object-fit: contain;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 100%;
            font-size: 8px;
            font-weight: bold;
            padding-top: 2px;
            margin-top: 2px;
            text-align: center;
            white-space: nowrap;
        }

        @media print {
            body {
                background-color: #fff;
            }

            .no-print-bar {
                display: none !important;
            }

            .admit-card-page {
                box-shadow: none;
                margin: 0;
                padding: 8mm;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<div class="no-print-bar">
    <div style="display: flex; align-items: center; gap: 15px;">
        <h3 style="margin: 0;"><i class="fas fa-print text-primary"></i> Batch Printing Admit Cards: <?= sanitize($class['name']) ?></h3>
        <span style="background-color: #e8f4fd; color: #2b82c9; font-size: 12px; padding: 4px 10px; border-radius: 20px; font-weight: bold;">
            Total: <?= count($students) ?> Students
        </span>
    </div>
    <div style="display: flex; gap: 10px;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print" style="margin-right: 8px;"></i> Print Admit Cards
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="fas fa-times" style="margin-right: 8px;"></i> Close Window
        </button>
    </div>
</div>

<div class="print-layout">
    <?php foreach ($student_chunks as $chunk_index => $chunk): ?>
    <div class="admit-card-page">
        <?php foreach ($chunk as $student): ?>
        <div class="admit-card">
            <!-- Header -->
            <div class="admit-card-header">
                <div class="school-info d-flex flex-column align-items-center justify-content-center">
                    <?php if (!empty($school['logo'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $school['logo'] ?>" alt="Logo" class="school-logo mb-1">
                    <?php endif; ?>
                    <div style="text-align: center;">
                        <h4 class="school-name"><?= sanitize($school['name_en']) ?></h4>
                        <p class="school-subtext">
                            <?= sanitize($school['address'] ?? '') ?>
                            <?php if (!empty($school['phone'])): ?> | Phone: <?= sanitize($school['phone']) ?><?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="card-title-badge">EXAM ADMIT CARD</div>
                <div class="academic-year">Academic Year: <?= getAcademicYearLabel($student['year_bs'], $student['year_ad']) ?></div>
            </div>

            <!-- Body -->
            <div class="admit-card-body">
                <div class="student-photo-container">
                    <?php if ($student['photo']): ?>
                    <img src="<?= APP_URL ?>/uploads/students/<?= $student['photo'] ?>" alt="Student Photo" class="student-photo">
                    <?php else: ?>
                    <i class="fas fa-user fa-2x text-muted" style="opacity: 0.5;"></i>
                    <?php endif; ?>
                </div>
                <div class="student-info-grid">
                    <div class="info-item">
                        <span class="info-label">Name:</span>
                        <span class="info-val" style="font-weight: bold; font-size: 11px; text-transform: uppercase;">
                            <?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?>
                        </span>
                    </div>
                    <?php if ($student['first_name_np']): ?>
                    <div class="info-item">
                        <span class="info-label">नाम (नेपाली):</span>
                        <span class="info-val" style="font-size: 10px;">
                            <?= sanitize($student['first_name_np'] . ' ' . $student['last_name_np']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Class:</span>
                        <span class="info-val"><?= sanitize($class['name']) ?> <?php if ($student['section_name']): ?>- <?= sanitize($student['section_name']) ?><?php endif; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Roll Number:</span>
                        <span class="info-val"><?= sanitize($student['roll_number'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Exam Roll No:</span>
                        <span class="info-val" style="font-weight: bold; text-decoration: underline; font-size: 11px;">
                            <?= sanitize($student['exam_roll_number'] ?? $student['roll_number'] ?? 'N/A') ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Gender:</span>
                        <span class="info-val"><?= sanitize($student['gender']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date of Birth:</span>
                        <span class="info-val"><?= sanitize($student['dob_bs'] ?: formatDate($student['dob_ad'])) ?></span>
                    </div>
                </div>
            </div>

            <!-- Exam Information -->
            <div class="exam-info-box">
                <div class="exam-info-title">EXAMINATION INFORMATION</div>
                <div class="info-item" style="margin-bottom: 2px;">
                    <span class="info-label">Exam Name:</span>
                    <span class="info-val" style="font-weight: bold;"><?= sanitize($exam['name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Date Range:</span>
                    <span class="info-val"><?= formatDate($exam['start_date']) ?> to <?= formatDate($exam['end_date']) ?></span>
                </div>
            </div>

            <!-- Instructions -->
            <div style="margin-bottom: 5px;">
                <div style="font-size: 9px; font-weight: bold; margin-bottom: 2px;">Instructions to Candidates:</div>
                <ol class="instructions-list">
                    <li>Candidates must bring this admit card to the examination hall.</li>
                    <li>Arrive at the examination hall at least 15 minutes before the start.</li>
                    <li>No textbooks, calculators, or mobile devices are allowed in the exam hall.</li>
                </ol>
            </div>

            <!-- Footer Signatures -->
            <div class="admit-card-footer">
                <div class="sig-box">
                    <div class="sig-image-placeholder"></div>
                    <div class="sig-line">Student's Signature</div>
                </div>
                <div class="sig-box">
                    <?php 
                    $ct_sig = !empty($class['class_teacher_signature']) ? $class['class_teacher_signature'] : ($school['class_teacher_signature'] ?? '');
                    if (!empty($ct_sig)): 
                    ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $ct_sig ?>" alt="Class Teacher Sig" class="sig-image">
                    <?php else: ?>
                    <div class="sig-image-placeholder"></div>
                    <?php endif; ?>
                    <div class="sig-line">Class Teacher</div>
                </div>
                <div class="sig-box">
                    <?php if (!empty($school['exam_coordinator_signature'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $school['exam_coordinator_signature'] ?>" alt="Exam Coordinator Sig" class="sig-image">
                    <?php else: ?>
                    <div class="sig-image-placeholder"></div>
                    <?php endif; ?>
                    <div class="sig-line">Exam Coordinator</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

</body>
</html>
