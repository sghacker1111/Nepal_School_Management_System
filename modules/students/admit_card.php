<?php
/**
 * Exam Admit Card Generator
 * Prints 4 admit cards per A4 page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'exam_officer', 'teacher']);

$db = getDB();

// Get student ID from URL
$studentId = $_GET['id'] ?? null;

if (!$studentId) {
    redirect(APP_URL . '/modules/students/index.php', 'Student not specified');
}

// Get student details
$school_id = Auth::getSchoolId();
$stmt = $db->prepare("SELECT s.*, c.name as class_name, sc.name as section_name, 
                      ay.year_bs, ay.year_ad
                      FROM students s
                      LEFT JOIN classes c ON s.class_id = c.id
                      LEFT JOIN sections sc ON s.section_id = sc.id
                      LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
                      WHERE s.id = ? AND s.school_id = ? AND c.is_active = 1");
$stmt->execute([$studentId, $school_id]);
$student = $stmt->fetch();

if (!$student) {
    redirect(APP_URL . '/modules/students/index.php', 'Student not found');
}

// Get school details
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

// Get upcoming exam for this class
$stmt = $db->prepare("SELECT e.* FROM exams e 
                      INNER JOIN exam_subjects es ON e.id = es.exam_id 
                      WHERE es.class_id = ? AND e.is_published = 0 AND e.school_id = ?
                      ORDER BY e.start_date ASC LIMIT 1");
$stmt->execute([$student['class_id'], $school_id]);
$exam = $stmt->fetch();

$pageTitle = 'Exam Admit Card';
$activePage = 'students';

ob_start();
?>

<style>
@media print {
    @page {
        size: A4;
        margin: 1cm;
    }
    
    body {
        margin: 0;
        padding: 0;
    }
    
    .no-print {
        display: none !important;
    }
    
    .admit-card-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }
    
    .admit-card {
        border: 2px solid #000;
        padding: 15px;
        page-break-inside: avoid;
    }
}

.admit-card-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    max-width: 210mm;
    margin: 0 auto;
}

.admit-card {
    border: 2px solid #333;
    padding: 20px;
    background: #fff;
    position: relative;
}

.admit-card-header {
    text-align: center;
    border-bottom: 2px solid #333;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

.admit-card-header img {
    max-height: 60px;
    margin-bottom: 10px;
}

.admit-card-header h3 {
    margin: 5px 0;
    font-size: 16px;
    font-weight: bold;
}

.admit-card-body {
    margin-bottom: 15px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 12px;
}

.info-label {
    font-weight: bold;
    color: #333;
}

.student-photo {
    width: 100px;
    height: 120px;
    border: 1px solid #333;
    float: right;
    margin-left: 15px;
    object-fit: cover;
}

.admit-card-footer {
    border-top: 1px solid #333;
    padding-top: 15px;
    margin-top: 20px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}

.signature-box {
    text-align: center;
    width: 150px;
}

.signature-line {
    border-top: 1px solid #333;
    margin-top: 40px;
    padding-top: 5px;
    font-size: 11px;
    font-weight: bold;
}

.signature-img {
    max-height: 40px;
    margin-bottom: 5px;
}

.exam-details {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.exam-details h4 {
    font-size: 13px;
    margin-bottom: 8px;
    text-align: center;
}

.term-condition {
    font-size: 10px;
    color: #666;
    margin-top: 10px;
    border-top: 1px dashed #ccc;
    padding-top: 8px;
}
</style>

<div class="no-print mb-4">
    <div class="d-flex justify-content-between align-items-center">
        <h2><i class="fas fa-id-card"></i> Exam Admit Card</h2>
        <div>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Admit Card
            </button>
            <a href="<?= APP_URL ?>/modules/students/index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
    <div class="alert alert-info mt-3">
        <i class="fas fa-info-circle"></i> 
        This will generate 4 copies of the admit card on one A4 page. 
        Ensure your printer is set to A4 size and actual size (100%) scaling.
    </div>
</div>

<div class="admit-card-container">
    <?php for ($i = 1; $i <= 4; $i++): ?>
    <div class="admit-card">
        <div class="admit-card-header">
            <?php if ($school['logo']): ?>
            <img src="<?= APP_URL ?>/uploads/<?= $school['logo'] ?>" alt="School Logo">
            <?php endif; ?>
            <h3><?= sanitize($school['name_en']) ?></h3>
            <?php if ($school['name_np']): ?>
            <p style="margin: 3px 0; font-size: 14px;"><?= sanitize($school['name_np']) ?></p>
            <?php endif; ?>
            <p style="margin: 3px 0; font-size: 12px;"><?= sanitize($school['address'] ?? '') ?></p>
            <p style="margin: 3px 0; font-size: 12px;">Phone: <?= sanitize($school['phone'] ?? '') ?> | Email: <?= sanitize($school['email'] ?? '') ?></p>
            <h4 style="margin: 10px 0 5px 0; text-decoration: underline;">EXAM ADMIT CARD</h4>
            <p style="font-size: 11px; margin: 0;">Academic Year: <?= getAcademicYearLabel($student['year_bs'], $student['year_ad']) ?></p>
        </div>
        
        <div class="admit-card-body">
            <?php if ($student['photo']): ?>
            <img src="<?= APP_URL ?>/uploads/students/<?= $student['photo'] ?>" alt="Student Photo" class="student-photo">
            <?php else: ?>
            <div class="student-photo d-flex align-items-center justify-content-center bg-light">
                <i class="fas fa-user text-muted fa-2x"></i>
            </div>
            <?php endif; ?>
            
            <div class="info-row">
                <span class="info-label">Student Name:</span>
                <span><?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?></span>
            </div>
            <?php if ($student['first_name_np']): ?>
            <div class="info-row">
                <span class="info-label">विद्यार्थी नाम:</span>
                <span><?= sanitize($student['first_name_np'] . ' ' . $student['last_name_np']) ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label">Class:</span>
                <span><?= sanitize($student['class_name']) ?> <?php if ($student['section_name']): ?>- <?= sanitize($student['section_name']) ?><?php endif; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Roll Number:</span>
                <span><?= sanitize($student['roll_number'] ?? '-') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Exam Roll No:</span>
                <span style="font-weight: bold; font-size: 14px;"><?= sanitize($student['exam_roll_number'] ?? $student['roll_number'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Gender:</span>
                <span><?= sanitize($student['gender']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date of Birth:</span>
                <span><?= sanitize($student['dob_bs'] ?? formatDate($student['dob_ad'])) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Father's Name:</span>
                <span><?= sanitize($student['father_name'] ?? '-') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Mother's Name:</span>
                <span><?= sanitize($student['mother_name'] ?? '-') ?></span>
            </div>
        </div>
        
        <?php if ($exam): ?>
        <div class="exam-details">
            <h4>EXAMINATION INFORMATION</h4>
            <div class="info-row">
                <span class="info-label">Exam Name:</span>
                <span><?= sanitize($exam['name']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Start Date:</span>
                <span><?= formatDate($exam['start_date']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">End Date:</span>
                <span><?= formatDate($exam['end_date']) ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="term-condition">
            <strong>Instructions:</strong>
            <ol style="margin: 5px 0; padding-left: 20px;">
                <li>Students must bring this admit card to the exam hall.</li>
                <li>Report 30 minutes before the exam starts.</li>
                <li>No electronic devices allowed in exam hall.</li>
            </ol>
        </div>
        
        <div class="admit-card-footer">
            <div class="signature-box">
                <?php if ($student['photo']): ?>
                <!-- Student signature placeholder -->
                <div style="height: 30px;"></div>
                <?php endif; ?>
                <div class="signature-line">Student's Signature</div>
            </div>
            
            <div class="signature-box">
                <?php if ($school['exam_coordinator_signature']): ?>
                <img src="<?= APP_URL ?>/uploads/<?= $school['exam_coordinator_signature'] ?>" alt="Signature" class="signature-img">
                <?php endif; ?>
                <div class="signature-line">Exam Coordinator</div>
            </div>
            
            <div class="signature-box">
                <?php if ($school['principal_signature']): ?>
                <img src="<?= APP_URL ?>/uploads/<?= $school['principal_signature'] ?>" alt="Signature" class="signature-img">
                <?php endif; ?>
                <div class="signature-line">Principal</div>
            </div>
        </div>
    </div>
    <?php endfor; ?>
</div>

<script>
// Auto-print when page loads (optional)
// window.onload = function() { window.print(); }
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
