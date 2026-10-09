<?php
/**
 * Classes Management - Weekly Routine Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireLogin();
require_once __DIR__ . '/../../includes/routine_service.php';

$db = getDB();
$school_id = Auth::getSchoolId();
$user_id = Auth::getUserId();
$error = '';
$success = '';

// Fetch active school details for configuration
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$school_id]);
$school = $stmt->fetch();

$periods_count = isset($school['routine_periods_per_day']) ? (int)$school['routine_periods_per_day'] : 7;
$include_sunday = isset($school['routine_include_sunday']) ? (int)$school['routine_include_sunday'] : 0;

// Active tab helper
$active_tab = $_POST['tab'] ?? $_GET['tab'] ?? 'view';

// Fetch classes
$stmt = $db->prepare("SELECT id, name, class_teacher_name, class_teacher_id, is_grading_teaching FROM classes WHERE (school_id = ?) AND is_active = 1 ORDER BY id ASC");
$stmt->execute([$school_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sort logic for classes
if (!function_exists('get_class_sort_weight')) {
    function get_class_sort_weight($class_name) {
        if (preg_match('/Class\s+(\d+)/i', $class_name, $matches)) {
            return (int)$matches[1];
        }
        if (strpos(strtolower($class_name), 'ukg') !== false) return 0.3;
        if (strpos(strtolower($class_name), 'lkg') !== false) return 0.2;
        if (strpos(strtolower($class_name), 'nursery') !== false) return 0.1;
        return 0;
    }
}

// Separate normal classes and 11 & 12
$normal_classes = [];
$eleven_twelve_classes = [];
foreach ($classes as $c) {
    $weight = get_class_sort_weight($c['name']);
    if ($weight >= 11) {
        $eleven_twelve_classes[] = $c;
    } else {
        $normal_classes[] = $c;
    }
}

// Sort normal classes descending (Class 10 down to ECD)
usort($normal_classes, function($a, $b) {
    return get_class_sort_weight($b['name']) <=> get_class_sort_weight($a['name']);
});

// Sort 11-12 descending
usort($eleven_twelve_classes, function($a, $b) {
    return get_class_sort_weight($b['name']) <=> get_class_sort_weight($a['name']);
});

// Merge classes in the sorted order for dropdowns etc.
$classes = array_merge($normal_classes, $eleven_twelve_classes);

// Fetch active teachers
$stmt_t = $db->prepare("SELECT id, full_name FROM users WHERE role = 'teacher' AND status = 1 AND school_id = ? ORDER BY full_name");
$stmt_t->execute([$school_id]);
$teachers = $stmt_t->fetchAll(PDO::FETCH_ASSOC);

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    // Verify password
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $hashed = $stmt->fetchColumn();
    
    if (!password_verify($password, $hashed)) {
        $error = 'Invalid administrator password. Access denied.';
    } else {
        // Password verified successfully!
        if ($_POST['action'] === 'save_settings') {
            $periods_input = (int)($_POST['periods_per_day'] ?? 7);
            $sunday_input = isset($_POST['include_sunday']) ? 1 : 0;
            
            if ($periods_input < 1 || $periods_input > 10) {
                $error = 'Number of periods per day must be between 1 and 10.';
            } else {
                try {
                    $stmt_up = $db->prepare("UPDATE schools SET routine_periods_per_day = ?, routine_include_sunday = ? WHERE id = ?");
                    $stmt_up->execute([$periods_input, $sunday_input, $school_id]);
                    $success = 'Routine settings saved successfully!';
                    
                    // Reload school details
                    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
                    $stmt->execute([$school_id]);
                    $school = $stmt->fetch();
                    $periods_count = (int)$school['routine_periods_per_day'];
                    $include_sunday = (int)$school['routine_include_sunday'];
                } catch (Exception $e) {
                    $error = 'Failed to save routine settings: ' . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'generate') {
            $target_class_id = (int)($_POST['target_class_id'] ?? 0);
            
            $classes_to_generate = [];
            if ($target_class_id === 0) {
                $classes_to_generate = $classes;
            } else {
                foreach ($classes as $c) {
                    if ($c['id'] == $target_class_id) {
                        $classes_to_generate[] = $c;
                        break;
                    }
                }
            }
            
            try {
                $db->beginTransaction();
                
                // Get next version id for history
                $stmt_ver = $db->prepare("SELECT COALESCE(MAX(version_id), 0) FROM class_routines_history WHERE school_id = ?");
                $stmt_ver->execute([$school_id]);
                $next_version = (int)$stmt_ver->fetchColumn() + 1;
                
                // Days list to generate
                $days = $include_sunday ? [7, 1, 2, 3, 4, 5] : [1, 2, 3, 4, 5];
                
                foreach ($classes_to_generate as $class_item) {
                    $c_id = $class_item['id'];
                    $ct_name = $class_item['class_teacher_name'];
                    
                    // Backup current routine to history before deleting
                    $stmt_bk = $db->prepare("
                        INSERT INTO class_routines_history (version_id, class_id, day_of_week, period_number, subject_id, teacher_id, school_id, changed_by_user_id)
                        SELECT ?, class_id, day_of_week, period_number, subject_id, teacher_id, school_id, ? 
                        FROM class_routines 
                        WHERE class_id = ? AND school_id = ?
                    ");
                    $stmt_bk->execute([$next_version, $user_id, $c_id, $school_id]);
                    
                    // Delete current routine
                    $stmt_del = $db->prepare("DELETE FROM class_routines WHERE class_id = ? AND school_id = ?");
                    $stmt_del->execute([$c_id, $school_id]);
                    
                    // Get class subjects mapping
                    $stmt_cs = $db->prepare("
                        SELECT cs.subject_id, cs.teacher_id, cs.credit_hours, s.name_en, s.name_np
                        FROM class_subjects cs
                        JOIN subjects s ON cs.subject_id = s.id
                        WHERE cs.class_id = ? AND (cs.school_id = ?) AND cs.is_active = 1 AND (s.is_hidden = 0 OR s.is_hidden IS NULL)
                        ORDER BY s.id ASC
                    ");
                    $stmt_cs->execute([$c_id, $school_id]);
                    $mapped_subjects = $stmt_cs->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (empty($mapped_subjects)) {
                        continue; // skip if no subjects mapped
                    }
                    
                    $built=buildClassRoutine($db,(int)$school_id,$class_item,$mapped_subjects,$days,$periods_count);
                    $grid=$built['grid'];
                    $unplaced_total=($unplaced_total??0)+$built['unplaced'];

                    // Save grid to database
                    $stmt_ins = $db->prepare("
                        INSERT INTO class_routines (class_id, day_of_week, period_number, subject_id, teacher_id, school_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($days as $day) {
                        for ($period = 1; $period <= $periods_count; $period++) {
                            $sub_id = $grid[$day][$period]['subject_id'] ?? null;
                            $t_id = $grid[$day][$period]['teacher_id'] ?? null;
                            $stmt_ins->execute([$c_id, $day, $period, $sub_id, $t_id, $school_id]);
                        }
                    }
                }
                
                $db->commit();
                $success = 'Routines generated without teacher conflicts. '.($unplaced_total??0).' weekly periods could not be placed; review available teachers and slots.';
                $active_tab = 'view';
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to generate routines: ' . $e->getMessage();
            }
            
        } elseif ($_POST['action'] === 'customize') {
            $c_id = (int)$_POST['class_id'];
            $day = (int)$_POST['day_of_week'];
            
            try {
                $db->beginTransaction();
                
                // Get next version id for history
                $stmt_ver = $db->prepare("SELECT COALESCE(MAX(version_id), 0) FROM class_routines_history WHERE school_id = ?");
                $stmt_ver->execute([$school_id]);
                $next_version = (int)$stmt_ver->fetchColumn() + 1;
                
                // Backup current routine of the target class & day
                $stmt_bk = $db->prepare("
                    INSERT INTO class_routines_history (version_id, class_id, day_of_week, period_number, subject_id, teacher_id, school_id, changed_by_user_id)
                    SELECT ?, class_id, day_of_week, period_number, subject_id, teacher_id, school_id, ? 
                    FROM class_routines 
                    WHERE class_id = ? AND day_of_week = ? AND school_id = ?
                ");
                $stmt_bk->execute([$next_version, $user_id, $c_id, $day, $school_id]);
                
                // Delete current records for this class & day
                $stmt_del = $db->prepare("DELETE FROM class_routines WHERE class_id = ? AND day_of_week = ? AND school_id = ?");
                $stmt_del->execute([$c_id, $day, $school_id]);
                
                // Insert new customized records
                $stmt_ins = $db->prepare("
                    INSERT INTO class_routines (class_id, day_of_week, period_number, subject_id, teacher_id, school_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                for ($period = 1; $period <= $periods_count; $period++) {
                    $sub_id = $_POST["p_{$period}_sub"] !== '' ? (int)$_POST["p_{$period}_sub"] : null;
                    $teach_id = $_POST["p_{$period}_teach"] !== '' ? (int)$_POST["p_{$period}_teach"] : null;
                    
                    validateRoutineSlot($db,(int)$school_id,$c_id,$day,$period,$sub_id,$teach_id);
                    $stmt_ins->execute([$c_id, $day, $period, $sub_id, $teach_id, $school_id]);
                }
                
                $db->commit();
                $success = 'Routine updated and saved successfully for the selected class and day!';
                $active_tab = 'view';
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to save routine: ' . $e->getMessage();
            }
        }
    }
}

// Fetch period times array
$period_times = [
    1 => ['name' => '1st (पहिलो)', 'time' => '10:15-11:00'],
    2 => ['name' => '2nd (दोस्रो)', 'time' => '11:00-11:45'],
    3 => ['name' => '3rd (तेस्रो)', 'time' => '11:45-12:20'],
    4 => ['name' => '4th (चौथो)', 'time' => '12:30-01:15'],
    5 => ['name' => '5th (पांचौ)', 'time' => '01:15-02:00'],
    6 => ['name' => '6th (छैठौं)', 'time' => '02:35-03:20'],
    7 => ['name' => '7th (सातौं)', 'time' => '03:20-04:00'],
    8 => ['name' => '8th (आठौं)', 'time' => '04:00-04:40'],
    9 => ['name' => '9th (नवौं)', 'time' => '04:40-05:20'],
    10 => ['name' => '10th (दशौं)', 'time' => '05:20-06:00']
];

// Helper to aggregate cells
function getPeriodCellContent($db, $class_id, $period_number, $include_sunday = 0) {
    static $cache=null;
    if($cache===null){
        $stmt=$db->prepare('SELECT r.class_id,r.period_number,r.day_of_week,s.name_en sub_name,s.name_np sub_name_np,u.full_name teacher_name FROM class_routines r LEFT JOIN subjects s ON s.id=r.subject_id LEFT JOIN users u ON u.id=r.teacher_id WHERE r.school_id=? ORDER BY r.day_of_week');
        $stmt->execute([Auth::getSchoolId()]);$cache=[];
        foreach($stmt as $row)$cache[$row['class_id']][$row['period_number']][]=$row;
    }
    $days=$cache[$class_id][$period_number]??[];

    if (empty($days)) {
        return '<span class="text-muted font-monospace fw-bold">X</span>';
    }
    
    // Group days by subject & teacher key
    $groups = [];
    foreach ($days as $d) {
        if (!$d['sub_name']) continue;
        
        $initials = '';
        if ($d['teacher_name']) {
            $parts = explode(' ', trim($d['teacher_name']));
            if (count($parts) >= 2) {
                $initials = strtoupper(substr($parts[0], 0, 1)) . '.' . strtoupper(substr(end($parts), 0, 1));
            } else {
                $initials = strtoupper(substr($d['teacher_name'], 0, 2));
            }
        }
        
        $displayName = $d['sub_name_np'] ?: $d['sub_name'];
        $key = $displayName . '|' . $initials;
        $groups[$key][] = $d['day_of_week'];
    }
    
    if (empty($groups)) {
        return '<span class="text-muted font-monospace fw-bold">X</span>';
    }
    
    $total_school_days = $include_sunday ? 6 : 5;
    
    $html = [];
    foreach ($groups as $key => $day_list) {
        list($sub, $init) = explode('|', $key);
        if (count($day_list) === $total_school_days) {
            $html[] = '<div class="routine-cell-item"><div class="fw-bold text-dark text-nowrap" style="font-size: 0.85rem;">' . sanitize($sub) . '</div><div class="small text-secondary font-monospace fw-bold mt-1" style="font-size: 0.75rem;">' . sanitize($init) . '</div></div>';
        } else {
            sort($day_list);
            $day_names = [];
            foreach ($day_list as $day_num) {
                if ($day_num == 7) $day_names[] = 'Sun';
                else $day_names[] = $day_num;
            }
            $day_str = implode(',', $day_names);
            $html[] = '<div class="routine-cell-item"><div class="fw-bold text-dark text-nowrap" style="font-size: 0.85rem;">' . sanitize($sub) . ' <span class="badge bg-secondary" style="font-size:0.55rem; padding: 2px 4px;">(' . $day_str . ')</span></div><div class="small text-secondary font-monospace fw-bold mt-1" style="font-size: 0.75rem;">' . sanitize($init) . '</div></div>';
        }
    }
    
    return implode('<div class="my-1 border-top" style="border-top-style: dashed !important; border-top-color: #ddd !important;"></div>', $html);
}

$pageTitle = 'Weekly Class Routine';
$activePage = 'routine';

ob_start();
?>

<!-- Tabs & Actions Bar (no print) -->
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2><i class="fas fa-calendar-alt text-primary"></i> Weekly Class Routine Settings</h2>
    <div>
        <button onclick="window.print()" class="btn btn-success me-2">
            <i class="fas fa-print"></i> Print Routine (A4 Landscape)
        </button>
        <a href="<?= APP_URL ?>/modules/classes/index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Classes
        </a>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success mb-4 no-print">
    <i class="fas fa-check-circle"></i> <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger mb-4 no-print">
    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
</div>
<?php endif; ?>

<!-- Tabs Navigation (no print) -->
<ul class="nav nav-tabs mb-4 no-print" id="routineTabs" role="tablist">
    <li class="nav-item">
        <button class="nav-link <?= $active_tab === 'view' ? 'active' : '' ?>" id="view-tab" data-bs-toggle="tab" data-bs-target="#view-pane" type="button">
            <i class="fas fa-grid-horizontal"></i> View Full Routine Grid
        </button>
    </li>
    <?php if (in_array(Auth::getRole(), ['super_admin', 'school_admin', 'principal'])): ?>
    <li class="nav-item">
        <button class="nav-link <?= $active_tab === 'generate' ? 'active' : '' ?>" id="generate-tab" data-bs-toggle="tab" data-bs-target="#generate-pane" type="button">
            <i class="fas fa-sync-alt"></i> Auto-Generate Routine
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $active_tab === 'customize' ? 'active' : '' ?>" id="customize-tab" data-bs-toggle="tab" data-bs-target="#customize-pane" type="button">
            <i class="fas fa-sliders-h"></i> Customize Cells
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?= $active_tab === 'settings' ? 'active' : '' ?>" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings-pane" type="button">
            <i class="fas fa-cog"></i> Routine Settings
        </button>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content" id="routineTabContent">
    <!-- View Full Routine Pane -->
    <div class="tab-pane fade <?= $active_tab === 'view' ? 'show active' : '' ?>" id="view-pane">
        <?php
        $pages = [];
        if (!empty($normal_classes)) {
            $half = ceil(count($normal_classes) / 2);
            $pages[] = array_slice($normal_classes, 0, $half);
            $pages[] = array_slice($normal_classes, $half);
        }
        if (!empty($eleven_twelve_classes)) {
            $pages[] = $eleven_twelve_classes;
        }
        
        foreach ($pages as $pageNum => $pageClasses):
        ?>
        <div class="routine-print-container p-4 bg-white border shadow-sm mb-4">
            <!-- School Header -->
            <div class="text-center mb-4">
                <h3 class="fw-bold text-uppercase school-title mb-1" style="color: #0b4b8f; font-family: 'Times New Roman', serif;">
                    <?= sanitize($school['name_en'] ?? 'Shree Maikot Secondary School') ?>
                </h3>
                <h5 class="text-danger fw-bold mb-1">
                    <?= sanitize($school['name_np'] ?? 'श्री माईकोट माध्यमिक विद्यालय') ?>
                </h5>
                <p class="mb-0 fw-bold text-secondary" style="font-size: 0.9rem;">
                    <?= sanitize($school['address'] ?? 'Barpak Sulikot-6, Gorkha') ?> | Phone: <?= sanitize($school['phone'] ?? '') ?>
                </p>
                <h5 class="text-uppercase fw-bold text-decoration-underline text-dark mt-2 mb-0" style="letter-spacing: 1px; font-size: 1.15rem;">
                    Class Daily & Weekly Schedule (दैनिक/साप्ताहिक कार्यतालिका)
                </h5>
            </div>
            
            <!-- Routine Table -->
            <div class="table-responsive">
                <table class="table table-bordered text-center align-middle routine-table border-dark mb-0">
                    <thead class="table-dark-custom">
                        <tr>
                            <th rowspan="2" class="align-middle fw-bold py-3 text-uppercase border-dark" style="width: 70px; background-color: #2c3e50; color: #fff; font-size: 0.85rem;">Class</th>
                            <?php for ($p = 1; $p <= $periods_count; $p++): ?>
                                <th class="border-dark py-2" style="background-color: #34495e; color: #fff; font-size: 0.85rem;"><?= $period_times[$p]['name'] ?> Period</th>
                                <?php if ($p == 3 && $periods_count >= 4): ?>
                                    <th rowspan="2" class="align-middle border-dark pee-bar" style="width: 25px;">Pee Time</th>
                                <?php endif; ?>
                                <?php if ($p == 5 && $periods_count >= 6): ?>
                                    <th rowspan="2" class="align-middle border-dark break-bar" style="width: 35px;">Half Time</th>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </tr>
                        <tr class="time-header">
                            <?php for ($p = 1; $p <= $periods_count; $p++): ?>
                                <th class="border-dark py-1 text-light small" style="background-color: #2c3e50; font-size: 0.75rem;"><?= $period_times[$p]['time'] ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pageClasses as $c): ?>
                        <tr>
                            <td class="fw-bold border-dark class-name-cell" style="background-color: #f1f3f5; font-size: 0.95rem;"><?= sanitize($c['name']) ?></td>
                            <?php for ($p = 1; $p <= $periods_count; $p++): ?>
                                <td class="border-dark py-2"><?= getPeriodCellContent($db, $c['id'], $p, $include_sunday) ?></td>
                                <?php if ($p == 3 && $periods_count >= 4): ?>
                                    <td class="border-dark font-monospace text-center pee-bar-cell text-uppercase fw-bold" style="font-size: 0.7rem; writing-mode: vertical-rl; transform: rotate(180deg); padding: 4px !important;">12:20 - 12:30</td>
                                <?php endif; ?>
                                <?php if ($p == 5 && $periods_count >= 6): ?>
                                    <td class="border-dark font-monospace text-center break-bar-cell text-uppercase fw-bold" style="font-size: 0.7rem; writing-mode: vertical-rl; transform: rotate(180deg); padding: 4px !important;">Lunch Break (02:00 - 02:35)</td>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center printing-footer">
                <span class="small text-muted font-monospace">
                    NOTE: (1,2,3,4,5) INDICATE DAYS OF THE WEEK (MONDAY TO FRIDAY)<?= $include_sunday ? ', (7) INDICATES SUNDAY' : '' ?>.
                </span>
                <span class="small text-dark text-center" style="min-width: 200px;">
                    <?php if (!empty($school['principal_signature'])): ?>
                        <img src="<?= APP_URL ?>/uploads/<?= $school['principal_signature'] ?>" alt="Principal Signature" style="max-height: 45px; display: block; margin: 0 auto 5px auto;"><br>
                    <?php else: ?>
                        <br><br>
                    <?php endif; ?>
                    <span class="border-top border-dark pt-1 d-block fw-bold">
                        <?= sanitize($school['principal_name'] ?? 'Headmaster / Principal') ?><br>
                        <span class="text-secondary small fw-normal">Headmaster / Principal</span>
                    </span>
                </span>
            </div>
        </div>
        <?php if ($pageNum < count($pages) - 1): ?>
        <div class="page-break"></div>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <!-- Auto-Generate Tab Pane -->
    <?php if (in_array(Auth::getRole(), ['super_admin', 'school_admin', 'principal'])): ?>
    <div class="tab-pane fade <?= $active_tab === 'generate' ? 'show active' : '' ?>" id="generate-pane">
        <div class="card max-width-600 mx-auto">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><i class="fas fa-cogs"></i> Automatic Schedule Generation</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="tab" value="generate">
                    <input type="hidden" name="action" value="generate">
                    
                    <div class="mb-3">
                        <label for="target_class_id" class="form-label">Select Class to Auto-Schedule</label>
                        <select class="form-select" id="target_class_id" name="target_class_id" required>
                            <option value="0">All Classes (Generate Entire Routine)</option>
                            <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= sanitize($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="text-muted small d-block mt-2">
                            <i class="fas fa-info-circle"></i> Auto-generation algorithm assigns the **Class Teacher's** mapped subject to **Period 1**, and then sequentially distributes the remaining mapped subjects to **Periods 2-<?= $periods_count ?>**.
                        </span>
                    </div>
                    
                    <hr class="my-4">
                    <div class="mb-3">
                        <label for="admin_password" class="form-label fw-bold text-danger">Administrator Password Verification</label>
                        <input type="password" class="form-control" id="admin_password" name="password" required placeholder="Enter password to verify changes">
                        <span class="text-muted small d-block mt-1">Changes are archived in history log under this administrator account.</span>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100 py-2 mt-2">
                        <i class="fas fa-play"></i> Auto-Generate Weekly Schedule
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Customize Cells Pane -->
    <?php if (in_array(Auth::getRole(), ['super_admin', 'school_admin', 'principal'])): ?>
    <div class="tab-pane fade <?= $active_tab === 'customize' ? 'show active' : '' ?>" id="customize-pane">
        <?php
        $selected_class_id = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? ($classes[0]['id'] ?? 0));
        $selected_day = (int)($_GET['day_of_week'] ?? $_POST['day_of_week'] ?? 1);
        
        // Fetch mapped subjects for selected class
        $stmt_cs = $db->prepare("
            SELECT s.id, s.name_en, s.name_np
            FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.id
            WHERE cs.class_id = ? AND (cs.school_id = ?) AND cs.is_active = 1 AND (s.is_hidden = 0 OR s.is_hidden IS NULL)
            ORDER BY s.name_en ASC
        ");
        $stmt_cs->execute([$selected_class_id, $school_id]);
        $class_subjects = $stmt_cs->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch current routine records for this day & class
        $stmt_r = $db->prepare("SELECT period_number, subject_id, teacher_id FROM class_routines WHERE class_id = ? AND day_of_week = ?");
        $stmt_r->execute([$selected_class_id, $selected_day]);
        $current_routines = $stmt_r->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        ?>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header bg-light py-3">
                        <h5 class="mb-0 text-dark"><i class="fas fa-filter"></i> Parameters Selection</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="GET" action="" id="customize-selector-form">
                            <input type="hidden" name="tab" value="customize">
                            
                            <div class="mb-3">
                                <label for="class_id_sel" class="form-label">Select Class</label>
                                <select class="form-select" id="class_id_sel" name="class_id" onchange="this.form.submit()">
                                    <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $selected_class_id == $c['id'] ? 'selected' : '' ?>>
                                        <?= sanitize($c['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="day_sel" class="form-label">Select Day of Week</label>
                                <select class="form-select" id="day_sel" name="day_of_week" onchange="this.form.submit()">
                                    <?php if ($include_sunday): ?>
                                    <option value="7" <?= $selected_day == 7 ? 'selected' : '' ?>>Day 7 (Sunday)</option>
                                    <?php endif; ?>
                                    <option value="1" <?= $selected_day == 1 ? 'selected' : '' ?>>Day 1 (Monday)</option>
                                    <option value="2" <?= $selected_day == 2 ? 'selected' : '' ?>>Day 2 (Tuesday)</option>
                                    <option value="3" <?= $selected_day == 3 ? 'selected' : '' ?>>Day 3 (Wednesday)</option>
                                    <option value="4" <?= $selected_day == 4 ? 'selected' : '' ?>>Day 4 (Thursday)</option>
                                    <option value="5" <?= $selected_day == 5 ? 'selected' : '' ?>>Day 5 (Friday)</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                                <i class="fas fa-sync"></i> Refresh Options
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-info text-white py-3">
                        <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Periods: Class <?= sanitize(array_column($classes, 'name', 'id')[$selected_class_id] ?? '') ?> (Day <?= $selected_day ?>)</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" action="">
                            <input type="hidden" name="tab" value="customize">
                            <input type="hidden" name="action" value="customize">
                            <input type="hidden" name="class_id" value="<?= $selected_class_id ?>">
                            <input type="hidden" name="day_of_week" value="<?= $selected_day ?>">
                            
                            <?php for ($period = 1; $period <= $periods_count; $period++): 
                                $curr_sub = $current_routines[$period]['subject_id'] ?? '';
                                $curr_teach = $current_routines[$period]['teacher_id'] ?? '';
                            ?>
                            <div class="row g-3 align-items-center mb-3 pb-3 border-bottom">
                                <div class="col-md-2">
                                    <span class="fw-bold"><?= $period_times[$period]['name'] ?></span>
                                    <span class="text-muted d-block small"><?= $period_times[$period]['time'] ?></span>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small text-muted mb-1">Subject</label>
                                    <select class="form-select form-select-sm" name="p_<?= $period ?>_sub">
                                        <option value="">Free Period / Break (X)</option>
                                        <?php foreach ($class_subjects as $sub): ?>
                                        <option value="<?= $sub['id'] ?>" <?= $curr_sub == $sub['id'] ? 'selected' : '' ?>>
                                            <?= sanitize($sub['name_en']) ?><?= !empty($sub['name_np']) ? ' (' . sanitize($sub['name_np']) . ')' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small text-muted mb-1">Teacher</label>
                                    <select class="form-select form-select-sm" name="p_<?= $period ?>_teach">
                                        <option value="">No Teacher Assigned</option>
                                        <?php foreach ($teachers as $t): ?>
                                        <option value="<?= $t['id'] ?>" <?= $curr_teach == $t['id'] ? 'selected' : '' ?>>
                                            <?= sanitize($t['full_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php endfor; ?>
                            
                            <div class="mb-4">
                                <label for="admin_password_custom" class="form-label fw-bold text-danger">Administrator Password Verification</label>
                                <input type="password" class="form-control" id="admin_password_custom" name="password" required placeholder="Enter password to verify changes">
                                <span class="text-muted small d-block mt-1">Changes are archived in history log under this administrator account.</span>
                            </div>
                            
                            <button type="submit" class="btn btn-info w-100 text-white py-2">
                                <i class="fas fa-save"></i> Save Customized Period Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Tab Pane -->
    <div class="tab-pane fade <?= $active_tab === 'settings' ? 'show active' : '' ?>" id="settings-pane">
        <div class="card max-width-600 mx-auto">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0"><i class="fas fa-cog"></i> Weekly Routine Configuration</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="tab" value="settings">
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div class="mb-3">
                        <label for="periods_per_day" class="form-label fw-bold">Number of Periods per Day</label>
                        <input type="number" class="form-control" id="periods_per_day" name="periods_per_day" min="1" max="10" value="<?= $periods_count ?>" required>
                        <span class="text-muted small">Choose between 1 and 10 periods per day. Default is 7.</span>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="include_sunday" name="include_sunday" value="1" <?= $include_sunday ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="include_sunday">Include Sunday as a School Day</label>
                        </div>
                        <span class="text-muted small">Enable Sunday to add it to the routine generation and customization grids.</span>
                    </div>
                    
                    <hr class="my-4">
                    <div class="mb-3">
                        <label for="admin_password_settings" class="form-label fw-bold text-danger">Administrator Password Verification</label>
                        <input type="password" class="form-control" id="admin_password_settings" name="password" required placeholder="Enter password to verify changes">
                    </div>
                    
                    <button type="submit" class="btn btn-dark w-100 py-2 mt-2">
                        <i class="fas fa-save"></i> Save Routine Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.routine-print-container {
    max-width: 1100px;
    margin: 0 auto;
}
.routine-table {
    border-collapse: collapse;
    width: 100%;
}
.routine-table th, .routine-table td {
    border: 1px solid #333 !important;
    padding: 6px 4px !important;
}
.table-dark-custom th {
    background-color: #2c3e50 !important;
    color: #fff !important;
}
.time-header th {
    background-color: #34495e !important;
    color: #fff !important;
}
.pee-bar {
    background-color: #f1c40f !important; /* Yellow theme */
    color: #2c3e50 !important;
    font-size: 0.75rem;
    font-weight: bold;
    text-transform: uppercase;
    padding: 10px 4px !important;
}
.pee-bar-cell {
    background-color: #fce4d6 !important; /* Soft orange/yellow */
    color: #a04000 !important;
}
.break-bar {
    background-color: #f1c40f !important;
    color: #2c3e50 !important;
    font-size: 0.75rem;
    font-weight: bold;
    text-transform: uppercase;
    padding: 10px 4px !important;
}
.break-bar-cell {
    background-color: #fff2cc !important; /* Soft yellow */
    color: #7f6000 !important;
}
.class-name-cell {
    background-color: #eaecee !important;
}
.routine-cell-item {
    min-height: 42px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.page-break {
    display: none;
}

@media print {
    .page-break {
        display: block !important;
        page-break-after: always;
        break-after: page;
    }
    .sidebar, .top-navbar, .no-print, nav, header {
        display: none !important;
    }
    main, .content-wrapper, .col-md-10 {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        float: none !important;
        flex: none !important;
    }
    body {
        background-color: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    .routine-print-container {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    @page {
        size: A4 landscape;
        margin: 5mm;
    }
    .routine-table th, .routine-table td {
        font-size: 0.75rem !important;
        padding: 4px 2px !important;
    }
    .routine-cell-item .fw-bold {
        font-size: 0.75rem !important;
    }
    .routine-cell-item .small {
        font-size: 0.65rem !important;
    }
    .pee-bar-cell, .break-bar-cell {
        font-size: 0.65rem !important;
    }
}
</style>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
