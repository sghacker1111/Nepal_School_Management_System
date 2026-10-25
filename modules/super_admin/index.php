<?php
/**
 * Super Admin Global Dashboard
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

Auth::requireRole('super_admin');
require_once __DIR__.'/../../includes/runtime_security.php';
$runtimeSecurity = schoolRuntimeSecurity();

$db = getDB();

// Fetch statistics
$stats = [];
$stats['schools'] = $db->query("SELECT COUNT(*) FROM schools")->fetchColumn();
$stats['school_admins'] = $db->query("SELECT COUNT(*) FROM users WHERE role = 'school_admin'")->fetchColumn();
$stats['teachers'] = $db->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
$stats['students'] = $db->query("SELECT COUNT(*) FROM students")->fetchColumn();

// Fetch recent schools
$recent_schools = $db->query("SELECT * FROM schools ORDER BY id DESC LIMIT 5")->fetchAll();

// Fetch recent activity logs
$recent_logs = $db->query("
    SELECT l.*, u.username, u.full_name 
    FROM activity_logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    ORDER BY l.id DESC LIMIT 5
")->fetchAll();

$pageTitle = 'Super Admin Control Center';
$activePage = 'super_admin_dashboard';

ob_start();
?>

<?php if(!schoolBackupCodes($db,(int)Auth::getUserId())): ?><div class="alert alert-warning" role="status"><strong>Set up administrator recovery.</strong> Generate and save backup codes before you need to recover a locked account. <a href="<?= schoolRouteUrl('modules/auth/security.php') ?>">Open Account security</a></div><?php endif; ?>
<div class="alert <?= $runtimeSecurity['patched'] ? 'alert-info' : 'alert-danger' ?>" role="status">
    <strong>PHP security:</strong> this web server reports PHP <?= sanitize($runtimeSecurity['version']) ?>.
    <?php if ($runtimeSecurity['patched']): ?>
        Its version meets the October 21, 2026 upstream security baseline.
    <?php else: ?>
        The October 21, 2026 critical PHP fixes are not confirmed by this version.
        Ask your hosting provider to install PHP 8.2.35, 8.3.36, 8.4.27 or 8.5.12 or newer in the same supported branch,
        or provide confirmation of both CVE-2026-109618 and CVE-2026-109619 backports, and restart all PHP workers.
        Updating this application does not patch the PHP engine.
    <?php endif; ?>
    <a href="<?= sanitize($runtimeSecurity['advisory']) ?>" target="_blank" rel="noopener noreferrer">Official advisory</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-user-shield text-danger"></i> Control Center <small class="fs-6 text-muted">Global Administration</small></h2>
    <span class="badge bg-danger p-2 fs-6">Global Administrator Mode</span>
</div>

<!-- Stats cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card primary h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Registered Schools</h6>
                        <h3 class="mb-0"><?= number_format($stats['schools']) ?></h3>
                    </div>
                    <div class="text-primary">
                        <i class="fas fa-university fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card success h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">School Administrators</h6>
                        <h3 class="mb-0"><?= number_format($stats['school_admins']) ?></h3>
                    </div>
                    <div class="text-success">
                        <i class="fas fa-users-cog fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card warning h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Total Teachers</h6>
                        <h3 class="mb-0"><?= number_format($stats['teachers']) ?></h3>
                    </div>
                    <div class="text-warning">
                        <i class="fas fa-chalkboard-teacher fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="card stat-card danger h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-muted mb-2">Global Students</h6>
                        <h3 class="mb-0"><?= number_format($stats['students']) ?></h3>
                    </div>
                    <div class="text-danger">
                        <i class="fas fa-user-graduate fa-3x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Manage Menu -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0"><i class="fas fa-cogs"></i> Administrative Actions</h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-3">
                    <a href="<?= schoolRouteUrl('modules/super_admin/schools.php') ?>" class="btn btn-outline-primary py-3 fs-5">
                        <i class="fas fa-university"></i> Manage Schools
                    </a>
                    <a href="<?= schoolRouteUrl('modules/super_admin/users.php') ?>" class="btn btn-outline-success py-3 fs-5">
                        <i class="fas fa-users-cog"></i> School Administrators
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Schools -->
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-school text-primary"></i> Registered Schools Overview</h5>
                <a href="<?= schoolRouteUrl('modules/super_admin/schools.php') ?>" class="btn btn-sm btn-primary">View All</a>
            </div>
            <div class="card-body">
                <?php if (count($recent_schools) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>EMIS Code</th>
                                <th>Phone</th>
                                <th>Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_schools as $school): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($school['name_en']) ?></strong><br>
                                    <small class="text-muted"><?= sanitize($school['name_np']) ?></small>
                                </td>
                                <td><code><?= sanitize($school['emis_code'] ?: 'N/A') ?></code></td>
                                <td><?= sanitize($school['phone'] ?: 'N/A') ?></td>
                                <td><?= sanitize($school['address'] ?: 'N/A') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="text-muted text-center py-4">No schools registered yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- System Audit Logs -->
<div class="card mt-4">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0"><i class="fas fa-history text-danger"></i> System Audit Activity Log</h5>
    </div>
    <div class="card-body">
        <?php if (count($recent_logs) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>IP Address</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                    <tr>
                        <td>
                            <strong><?= sanitize($log['full_name'] ?: 'System') ?></strong><br>
                            <small class="text-muted">@<?= sanitize($log['username'] ?: 'system') ?></small>
                        </td>
                        <td><span class="badge bg-secondary"><?= sanitize($log['action']) ?></span></td>
                        <td><code><?= sanitize($log['table_name'] ?: '-') ?></code></td>
                        <td><?= $log['record_id'] ?: '-' ?></td>
                        <td><code><?= sanitize($log['ip_address'] ?: '-') ?></code></td>
                        <td><?= formatDate($log['created_at'], 'Y-m-d H:i:s') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No activity logged yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
