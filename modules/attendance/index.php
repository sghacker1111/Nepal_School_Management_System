<?php
/**
 * Attendance System - Take Attendance Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher']);
Auth::requireAccess('attendance');

$db = getDB();
$school_id = Auth::getSchoolId();
$message = '';
$message_type = 'success';

// Filters
$date_ad = $_GET['date'] ?? date('Y-m-d');
$class_id = $_GET['class'] ?? '';
$section_id = $_GET['section'] ?? '';

// Authorize class selection for teachers
if ($class_id && Auth::getRole() === 'teacher') {
    $stmt_chk_class = $db->prepare("SELECT COUNT(*) FROM classes WHERE id = ? AND class_teacher_name = ? AND school_id = ?");
    $stmt_chk_class->execute([$class_id, $_SESSION['full_name'] ?? '', $school_id]);
    if ($stmt_chk_class->fetchColumn() == 0) {
        $class_id = '';
    }
}

// Handle POST - Save Attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $att_data = $_POST['attendance'] ?? [];
    $date_ad_post = $_POST['date_ad'] ?? $date_ad;
    $class_id_post = $_POST['class_id'] ?? $class_id;
    
    if (empty($class_id_post)) {
        $message = "Please select a class first.";
        $message_type = 'error';
    } else {
        // Authorize class selection for teachers in POST
        if (Auth::getRole() === 'teacher') {
            $stmt_chk_class = $db->prepare("SELECT COUNT(*) FROM classes WHERE id = ? AND class_teacher_name = ? AND school_id = ?");
            $stmt_chk_class->execute([$class_id_post, $_SESSION['full_name'] ?? '', $school_id]);
            if ($stmt_chk_class->fetchColumn() == 0) {
                redirect(APP_URL . "/modules/attendance/index.php", "Unauthorized access to class data.", "error");
            }
        }

        // Check if attendance records already exist
        $stmt_att_exist = $db->prepare("
            SELECT COUNT(*) FROM attendances a
            JOIN students s ON a.student_id = s.id
            WHERE s.class_id = ? AND a.date_ad = ?
        ");
        $stmt_att_exist->execute([$class_id_post, $date_ad_post]);
        $attendance_exists_post = ((int)$stmt_att_exist->fetchColumn() > 0);

        $password_verified = true;
        if ($attendance_exists_post) {
            $edit_password = $_POST['edit_password'] ?? '';
            $stmt_pwd = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt_pwd->execute([Auth::getUserId()]);
            $hashed_password = $stmt_pwd->fetchColumn();
            
            if (empty($edit_password) || !password_verify($edit_password, $hashed_password)) {
                $password_verified = false;
            }
        }

        if (!$password_verified) {
            $message = "Invalid verification password. Attendance records not modified.";
            $message_type = 'error';
        } else {
            try {
                $db->beginTransaction();
                
                // Simplified date conversion helper for SQLite/MySQL (can add real Nepali BS date library later)
                // For now, let's keep date_bs as the same or simple string
                $date_bs = $date_ad_post; 
                
                // Fetch all active student IDs in this class to verify they belong to this school
                $stmt_student_ids = $db->prepare("SELECT id FROM students WHERE class_id = ? AND school_id = ? AND status = 'active'");
                $stmt_student_ids->execute([$class_id_post, $school_id]);
                $valid_student_ids = $stmt_student_ids->fetchAll(PDO::FETCH_COLUMN);
                
                $stmt_check = $db->prepare("SELECT id FROM attendances WHERE student_id = ? AND date_ad = ?");
                $stmt_insert = $db->prepare("INSERT INTO attendances (student_id, date_ad, date_bs, status) VALUES (?, ?, ?, ?)");
                $stmt_update = $db->prepare("UPDATE attendances SET status = ? WHERE student_id = ? AND date_ad = ?");
                
                foreach ($att_data as $student_id => $status) {
                    if (!in_array($student_id, $valid_student_ids)) {
                        continue; // Skip student not belonging to this school/class
                    }
                    
                    $stmt_check->execute([$student_id, $date_ad_post]);
                    $record = $stmt_check->fetch();
                    
                    if ($record) {
                        // Update
                        $stmt_update->execute([$status, $student_id, $date_ad_post]);
                    } else {
                        // Insert
                        $stmt_insert->execute([$student_id, $date_ad_post, $date_bs, $status]);
                    }
                }
                
                $db->commit();
                Auth::logActivity(Auth::getUserId(), "Marked Attendance for Class ID $class_id_post on $date_ad_post", 'attendances');
                
                // Redirect to preserve filters
                redirect(APP_URL . "/modules/attendance/index.php?date=$date_ad_post&class=$class_id_post&section=$section_id", "Attendance saved successfully");
                
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $message = "Database error: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Fetch classes
$classes_stmt = $db->prepare("SELECT * FROM classes WHERE (school_id = ? OR school_id = 0) AND is_active = 1 ORDER BY id ASC");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

if (Auth::getRole() === 'teacher') {
    $teacher_name = $_SESSION['full_name'] ?? '';
    $classes = array_filter($classes, function($c) use ($teacher_name) {
        return $c['class_teacher_name'] === $teacher_name;
    });
}

// Fetch sections for selected class
$sections = [];
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM sections WHERE school_id = ? AND class_id = ? ORDER BY name");
    $stmt->execute([$school_id, $class_id]);
    $sections = $stmt->fetchAll();
}

// Fetch students for attendance sheet
$students = [];
if ($class_id) {
    $query = "
        SELECT s.id, s.student_id, s.first_name_en, s.last_name_en, s.roll_number, a.status as attendance_status
        FROM students s
        LEFT JOIN attendances a ON s.id = a.student_id AND a.date_ad = ?
        WHERE s.school_id = ? AND s.class_id = ? AND s.status = 'active'
    ";
    $params = [$date_ad, $school_id, $class_id];
    
    if ($section_id) {
        $query .= " AND s.section_id = ?";
        $params[] = $section_id;
    }
    
    $query .= " ORDER BY s.roll_number ASC, s.first_name_en ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

$pageTitle = 'Daily Attendance';
$activePage = 'attendance';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-calendar-check"></i> Daily Student Attendance</h2>
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
            <div class="col-md-3">
                <label class="form-label">Attendance Date</label>
                <input type="date" name="date" class="form-control" value="<?= sanitize($date_ad) ?>" max="<?= date('Y-m-d') ?>">
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
            
            <div class="col-md-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Load Sheet
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Attendance sheet -->
<?php if ($class_id): ?>
<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
        <h5 class="mb-0 text-primary">
            <i class="fas fa-list"></i> Attendance Sheet: Class <?= count($students) > 0 ? sanitize($students[0]['roll_number'] ?? '') : '' ?> 
            (Date: <?= formatDate($date_ad) ?>)
        </h5>
        <div class="no-print">
            <button class="btn btn-sm btn-outline-success me-2" onclick="markAll('P')">Mark All Present</button>
            <button class="btn btn-sm btn-outline-danger" onclick="markAll('A')">Mark All Absent</button>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="date_ad" value="<?= sanitize($date_ad) ?>">
            <input type="hidden" name="class_id" value="<?= sanitize($class_id) ?>">
            
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle">
                    <thead class="table-light">
                        <tr>
                            <th width="80">Roll No.</th>
                            <th>Student Name</th>
                            <th>Student ID</th>
                            <th width="300" class="text-center">Attendance Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($students) > 0): ?>
                            <?php foreach ($students as $student): ?>
                            <?php 
                            // Default to Present (P) if no attendance is logged yet
                            $status = $student['attendance_status'] ?? 'P'; 
                            ?>
                            <tr>
                                <td><strong><?= sanitize($student['roll_number'] ?? '-') ?></strong></td>
                                <td><strong><?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?></strong></td>
                                <td><code><?= sanitize($student['student_id']) ?></code></td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <input type="radio" class="btn-check" name="attendance[<?= $student['id'] ?>]" 
                                               id="pres_<?= $student['id'] ?>" value="P" <?= $status == 'P' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-success px-3" for="pres_<?= $student['id'] ?>">P</label>
                                        
                                        <input type="radio" class="btn-check" name="attendance[<?= $student['id'] ?>]" 
                                               id="abs_<?= $student['id'] ?>" value="A" <?= $status == 'A' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-danger px-3" for="abs_<?= $student['id'] ?>">A</label>
                                        
                                        <input type="radio" class="btn-check" name="attendance[<?= $student['id'] ?>]" 
                                               id="late_<?= $student['id'] ?>" value="L" <?= $status == 'L' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-warning px-3" for="late_<?= $student['id'] ?>">L</label>
                                        
                                        <input type="radio" class="btn-check" name="attendance[<?= $student['id'] ?>]" 
                                               id="holiday_<?= $student['id'] ?>" value="H" <?= $status == 'H' ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-secondary px-3" for="holiday_<?= $student['id'] ?>">H</label>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No active students found in this class.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php 
            // Check if attendance records exist to prompt password
            $attendance_exists = false;
            if ($class_id) {
                $stmt_att_exist = $db->prepare("
                    SELECT COUNT(*) FROM attendances a
                    JOIN students s ON a.student_id = s.id
                    WHERE s.class_id = ? AND a.date_ad = ?
                ");
                $stmt_att_exist->execute([$class_id, $date_ad]);
                $attendance_exists = ((int)$stmt_att_exist->fetchColumn() > 0);
            }
            ?>
            <?php if (count($students) > 0 && Auth::hasRole(['super_admin', 'school_admin', 'teacher'])): ?>
            <div class="d-flex flex-column align-items-end mt-4 no-print">
                <?php if ($attendance_exists): ?>
                <div class="mb-3 border p-3 rounded bg-light text-start border-warning" style="max-width: 400px;">
                    <label for="edit_password" class="form-label text-danger fw-bold"><i class="fas fa-lock"></i> Verification Password Required</label>
                    <input type="password" name="edit_password" id="edit_password" class="form-control form-control-sm" placeholder="Enter password to authorize edit" required>
                    <div class="form-text small">Attendance has already been logged for this class and date. Your password is required to update these records.</div>
                </div>
                <?php endif; ?>
                <button type="submit" name="save_attendance" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-save"></i> Save Attendance Sheet
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
        <h5>Please select a Class and Section above to take attendance.</h5>
    </div>
</div>
<?php endif; ?>

<script>
function markAll(status) {
    const radioButtons = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radioButtons.forEach(radio => {
        radio.checked = true;
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
