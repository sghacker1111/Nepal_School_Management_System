<?php
/**
 * Settings - Academic Years Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole(['super_admin', 'school_admin']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';
$success = '';

// Handle Add Year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $year_bs = sanitize($_POST['year_bs'] ?? '');
    $year_ad = sanitize($_POST['year_ad'] ?? '');
    
    if (empty($year_bs) || empty($year_ad)) {
        $error = "Both Year (BS) and Year (AD) are required";
    } else {
        try {
            // Check if exists
            $stmt = $db->prepare("SELECT COUNT(*) FROM academic_years WHERE (year_bs = ? OR year_ad = ?) AND school_id = ?");
            $stmt->execute([$year_bs, $year_ad, $school_id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "This academic year already exists.";
            } else {
                $stmt = $db->prepare("INSERT INTO academic_years (year_bs, year_ad, is_active, school_id) VALUES (?, ?, 0, ?)");
                $stmt->execute([$year_bs, $year_ad, $school_id]);
                $new_id = $db->lastInsertId();
                Auth::logActivity(Auth::getUserId(), 'Add Academic Year', 'academic_years', $new_id);
                $success = "Academic Year added successfully.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Handle Toggle Hidden Year
if (isset($_GET['toggle_hidden'])) {
    $id = (int)$_GET['toggle_hidden'];
    try {
        // Check if active
        $stmt = $db->prepare("SELECT is_active, is_hidden, school_id FROM academic_years WHERE id = ?");
        $stmt->execute([$id]);
        $yr_record = $stmt->fetch();
        
        if (!$yr_record || $yr_record['school_id'] != $school_id) {
            $error = "Unauthorized access or academic year not found.";
        } else if ($yr_record['is_active'] == 1 && $yr_record['is_hidden'] == 0) {
            $error = "Cannot hide the active academic year.";
        } else {
            $new_hidden = $yr_record['is_hidden'] == 1 ? 0 : 1;
            $stmt = $db->prepare("UPDATE academic_years SET is_hidden = ? WHERE id = ? AND school_id = ?");
            $stmt->execute([$new_hidden, $id, $school_id]);
            Auth::logActivity(Auth::getUserId(), 'Toggle Hidden Academic Year', 'academic_years', $id);
            $success = $new_hidden ? 'Academic Year hidden successfully.' : 'Academic Year shown successfully.';
            redirect(APP_URL . '/modules/settings/years.php', $success, 'success');
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Handle Set Active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_active') {
    $id = (int)$_POST['id'];
    $password = $_POST['password'] ?? '';
    
    // Verify target year belongs to the school
    $stmt = $db->prepare("SELECT school_id FROM academic_years WHERE id = ?");
    $stmt->execute([$id]);
    $yr_school_id = $stmt->fetchColumn();
    
    // Server-side verification
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([Auth::getUserId()]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password'])) {
        $error = "Incorrect password. Academic year change cancelled.";
    } else if ($yr_school_id != $school_id) {
        $error = "Unauthorized academic year target.";
    } else {
        try {
            $db->beginTransaction();
            
            // Deactivate all years for this school
            $stmt_deact = $db->prepare("UPDATE academic_years SET is_active = 0 WHERE school_id = ?");
            $stmt_deact->execute([$school_id]);
            
            // Activate target year
            $stmt_act = $db->prepare("UPDATE academic_years SET is_active = 1 WHERE id = ? AND school_id = ?");
            $stmt_act->execute([$id, $school_id]);
            
            // Fetch year details to update school table
            $stmt_get = $db->prepare("SELECT * FROM academic_years WHERE id = ? AND school_id = ?");
            $stmt_get->execute([$id, $school_id]);
            $yr = $stmt_get->fetch();
            
            if ($yr) {
                $label = $yr['year_bs'] . '/' . $yr['year_ad'];
                $db->prepare("UPDATE schools SET academic_year_current = ? WHERE id = ?")->execute([$label, $school_id]);
            }
            
            $db->commit();
            Auth::logActivity(Auth::getUserId(), 'Change Active Academic Year', 'academic_years', $id);
            redirect(APP_URL . '/modules/settings/years.php', 'Active academic year switched successfully!');
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch all years
$stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? ORDER BY year_bs DESC");
$stmt->execute([$school_id]);
$years = $stmt->fetchAll();

$pageTitle = 'Academic Year Settings';
$activePage = 'settings';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-cog"></i> System Settings & Academic Years</h2>
</div>

<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> <?= sanitize($success) ?>
</div>
<?php endif; ?>

<div class="row">
    <!-- Academic Years List -->
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-primary"><i class="fas fa-calendar-alt"></i> Configure Academic Years</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-striped align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nepali Year (BS)</th>
                                <th>English Year (AD)</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($years as $yr): ?>
                            <tr>
                                <td><strong><?= sanitize($yr['year_bs']) ?> BS</strong></td>
                                <td><?= sanitize($yr['year_ad']) ?> AD</td>
                                <td>
                                    <?php if ($yr['is_active'] == 1): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Current Active</span>
                                    <?php elseif ($yr['is_hidden'] == 1): ?>
                                        <span class="badge bg-danger"><i class="fas fa-eye-slash"></i> Hidden</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($yr['is_active'] == 0): ?>
                                        <button onclick="switchYear(<?= $yr['id'] ?>, '<?= sanitize($yr['year_bs']) ?> / <?= sanitize($yr['year_ad']) ?>')" 
                                                class="btn btn-sm btn-success" title="Set Active">
                                            <i class="fas fa-toggle-on"></i> Set Active
                                        </button>
                                        <?php if ($yr['is_hidden'] == 1): ?>
                                            <button onclick="toggleHiddenYear(<?= $yr['id'] ?>, false)" 
                                                    class="btn btn-sm btn-outline-success" title="Show Academic Year">
                                                <i class="fas fa-eye"></i> Show
                                            </button>
                                        <?php else: ?>
                                            <button onclick="toggleHiddenYear(<?= $yr['id'] ?>, true)" 
                                                    class="btn btn-sm btn-outline-warning" title="Hide Academic Year">
                                                <i class="fas fa-eye-slash"></i> Hide
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-lock"></i> Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Academic Year Form -->
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 text-success"><i class="fas fa-plus-circle"></i> Add Academic Year</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Nepali Year (BS) *</label>
                        <input type="text" name="year_bs" class="form-control" placeholder="e.g. 2083" required pattern="^[0-9]{4}$">
                        <div class="invalid-feedback">Please enter a valid 4-digit year.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">English Year (AD) *</label>
                        <input type="text" name="year_ad" class="form-control" placeholder="e.g. 2026" required pattern="^[0-9]{4}$">
                        <div class="invalid-feedback">Please enter a valid 4-digit year.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-save"></i> Save Academic Year
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for setting active year -->
<form id="setActiveYearForm" method="POST" action="">
    <input type="hidden" name="action" value="set_active">
    <input type="hidden" name="id" id="activeYearId">
    <input type="hidden" name="password" id="activeYearPassword">
</form>

<script>
function switchYear(id, label) {
    Swal.fire({
        title: 'Confirm Year Switch',
        text: 'Are you sure you want to switch the active academic year to ' + label + '? All modules will display data for this year.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, proceed'
    }).then((result) => {
        if (result.isConfirmed) {
            // Prompt for password verification
            verifyPasswordAndProceed(function(password) {
                document.getElementById('activeYearId').value = id;
                document.getElementById('activeYearPassword').value = password;
                document.getElementById('setActiveYearForm').submit();
            }, 'Verify Administrator Password', 'Please enter your password to authorize this academic year switch:');
        }
    });
}

function toggleHiddenYear(id, hide) {
    const actionText = hide ? 'hide' : 'show';
    const confirmBtnText = hide ? 'Yes, hide it' : 'Yes, show it';
    Swal.fire({
        title: (hide ? 'Hide' : 'Show') + ' Academic Year',
        text: 'Are you sure you want to ' + actionText + ' this academic year?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: hide ? '#e0a800' : '#28a745',
        cancelButtonColor: '#3085d6',
        confirmButtonText: confirmBtnText
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '<?= APP_URL ?>/modules/settings/years.php?toggle_hidden=' + id;
        }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
