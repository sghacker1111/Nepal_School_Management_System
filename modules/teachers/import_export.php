<?php
/**
 * Teacher Management - EMIS CSV Import & Export
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

// 1. Handle Template Download
if (isset($_GET['action']) && $_GET['action'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=teacher_import_template.csv');
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    writeCsvRow($output, [
        'Full Name',
        'Username',
        'Email',
        'Phone',
        'Join Date',
        'DOB',
        'Bank Name',
        'Bank Account',
        'Status'
    ]);
    fclose($output);
    exit;
}

// 2. Handle Export
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=teachers_export_' . date('Ymd_His') . '.csv');
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    writeCsvRow($output, [
        'Full Name',
        'Username',
        'Email',
        'Phone',
        'Join Date',
        'DOB',
        'Bank Name',
        'Bank Account',
        'Status'
    ]);
    
    $stmt = $db->prepare("
        SELECT u.full_name, u.username, u.email, u.phone, u.status, td.join_date, td.dob, td.bank_name, td.bank_account
        FROM users u
        LEFT JOIN teacher_details td ON u.id = td.user_id
        WHERE u.school_id = ? AND u.role = 'teacher'
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$school_id]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        writeCsvRow($output, [
            $row['full_name'],
            $row['username'],
            $row['email'],
            $row['phone'],
            $row['join_date'],
            $row['dob'],
            $row['bank_name'],
            $row['bank_account'],
            $row['status'] == 1 ? 'active' : 'inactive'
        ]);
    }
    fclose($output);
    exit;
}

// 3. Handle Import POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload failed with error code ' . $_FILES['csv_file']['error'];
    } else {
        $file_path = $_FILES['csv_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            $error = 'Please upload a valid CSV file.';
        } else {
            // Map table column names to possible CSV headings (lowercase aliases)
            $headers_map = [
                'full_name' => ['full name', 'fullname', 'full_name', 'name', 'teacher name', 'teacher_name', 'teacher full name', 'staff name', 'staff_name'],
                'username' => ['username', 'user_name', 'user', 'login username', 'login'],
                'email' => ['email', 'email address', 'email_address', 'mail'],
                'phone' => ['phone', 'phone number', 'phone_number', 'mobile', 'mobile number', 'contact', 'contact no', 'contact number'],
                'status' => ['status', 'active', 'state'],
                'join_date' => ['join date', 'join_date', 'date of joining', 'appointment date', 'joining date'],
                'dob' => ['dob', 'date of birth', 'birth date', 'dob ad', 'dob_ad'],
                'bank_name' => ['bank name', 'bank_name', 'bank'],
                'bank_account' => ['bank account', 'bank_account', 'account number', 'account no', 'account_no']
            ];
            
            if (($handle = fopen($file_path, 'r')) !== FALSE) {
                // Strip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== chr(0xEF) . chr(0xBB) . chr(0xBF)) {
                    rewind($handle);
                }
                
                // Read headings
                $headings = fgetcsv($handle, 0, ',', '"', '');
                if (!$headings) {
                    $error = 'CSV file is empty.';
                } else {
                    // Match CSV headings to our schema keys
                    $matched_indices = [];
                    foreach ($headings as $index => $heading) {
                        $heading_clean = strtolower(trim($heading));
                        foreach ($headers_map as $key => $aliases) {
                            if (in_array($heading_clean, $aliases)) {
                                $matched_indices[$key] = $index;
                                break;
                            }
                        }
                    }
                    
                    if (!isset($matched_indices['full_name'])) {
                        $error = 'Could not find a teacher name column in CSV file. Please align headers.';
                    } else {
                        $imported_count = 0;
                        $skipped_count = 0;
                        $line_num = 1;
                        
                        // Imported accounts stay disabled until an administrator sets a private password.
                        
                        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== FALSE) {
                            $line_num++;
                            
                            // Map values according to indices
                            $get_val = function($key) use ($row, $matched_indices) {
                                if (isset($matched_indices[$key]) && isset($row[$matched_indices[$key]])) {
                                    return trim($row[$matched_indices[$key]]);
                                }
                                return null;
                            };
                            
                            $full_name = $get_val('full_name');
                            if (empty($full_name)) {
                                $skipped_count++;
                                continue;
                            }
                            
                            // Username generation if not provided
                            $username = $get_val('username');
                            if (empty($username)) {
                                $username = strtolower(str_replace(' ', '', $full_name)) . rand(10, 99);
                            }
                            // Clean username
                            $username = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
                            
                            // Check if username already exists
                            $stmt_chk = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                            $stmt_chk->execute([$username]);
                            if ($stmt_chk->fetchColumn() > 0) {
                                // Try adding extra random digit
                                $username .= rand(0, 9);
                                $stmt_chk->execute([$username]);
                                if ($stmt_chk->fetchColumn() > 0) {
                                    $skipped_count++;
                                    continue; // Skip if still exists
                                }
                            }
                            
                            $email = $get_val('email') ?: '';
                            $phone = $get_val('phone') ?: '';
                            
                            $status = 0;
                            $default_password_hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
                            try {
                                $join_date=importDateValue($get_val('join_date'));
                                $dob=importDateValue($get_val('dob'));
                            } catch(InvalidArgumentException $e) { $skipped_count++; continue; }
                            if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) { $skipped_count++; continue; }
                            if(strlen($username)<3)$username='teacher_'.bin2hex(random_bytes(4));
                            
                            $bank_name = $get_val('bank_name') ?: '';
                            $bank_account = $get_val('bank_account') ?: '';
                            
                            $family_arr = [
                                'father_name' => '',
                                'mother_name' => '',
                                'spouse_name' => ''
                            ];
                            $family_details = json_encode($family_arr);
                            
                            try {
                                $db->beginTransaction();
                                
                                // Insert into users
                                $stmt_usr = $db->prepare("INSERT INTO users (username, password, role, full_name, email, phone, status, school_id, signature) VALUES (?, ?, 'teacher', ?, ?, ?, ?, ?, NULL)");
                                $stmt_usr->execute([$username, $default_password_hash, $full_name, $email, $phone, $status, $school_id]);
                                $new_user_id = $db->lastInsertId();
                                
                                // Insert into teacher_details
                                $stmt_det = $db->prepare("INSERT INTO teacher_details (user_id, join_date, perm_issue_date, temp_issue_date, cit_no, family_details, dob, bank_name, bank_account) VALUES (?, ?, NULL, NULL, '', ?, ?, ?, ?)");
                                $stmt_det->execute([$new_user_id, $join_date, $family_details, $dob, $bank_name, $bank_account]);
                                
                                $db->commit();
                                $imported_count++;
                            } catch (PDOException $e) {
                                if ($db->inTransaction()) {
                                    $db->rollBack();
                                }
                                $skipped_count++;
                            }
                        }
                        
                        $success = "CSV Import complete! Successfully registered {$imported_count} teachers. Accounts are disabled. Set a unique password and activate each account in Staff accounts. (Skipped/Failed: {$skipped_count})";
                        Auth::logActivity(Auth::getUserId(), 'EMIS Bulk Import Teachers', 'teachers');
                    }
                }
                fclose($handle);
            } else {
                $error = 'Failed to open uploaded CSV file.';
            }
        }
    }
}

$pageTitle = 'EMIS Teacher Import / Export';
$activePage = 'teachers';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-file-import text-primary"></i> EMIS Teacher Import & Export</h2>
    <a href="<?= schoolRouteUrl('modules/teachers/index.php') ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Teachers List
    </a>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show mb-4">
    <i class="fas fa-check-circle me-2"></i> <?= $success ?>
    <button type="button" class="btn-close" data-ui-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
    <button type="button" class="btn-close" data-ui-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <!-- CSV Import Card -->
    <div class="col-md-7 mb-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-primary text-white py-3">
                <h5 class="mb-0"><i class="fas fa-upload me-2"></i> Import Teachers from EMIS (CSV)</h5>
            </div>
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <p class="text-muted small">
                        Upload a CSV file containing your teacher / staff database. The system dynamically maps headings such as Full Name, Username, Email, Phone, Bank details and imports them.
                    </p>
                    
                    <div class="alert alert-info border-start border-info border-4">
                        <h6 class="fw-bold mb-1"><i class="fas fa-info-circle me-1"></i> Tip: Auto-generated login profiles</h6>
                        <p class="mb-0 small text-dark">
                            If the <em>Username</em> column is missing, the system will automatically generate a clean username from their name. Imported accounts are disabled for safety. Set a unique password and activate each account in <a href="<?= schoolRouteUrl('modules/settings/users.php') ?>">Staff accounts</a>.
                        </p>
                    </div>
                </div>
                
                <form method="POST" action="" enctype="multipart/form-data" class="mt-4">
                    <div class="mb-3">
                        <label for="csv_file" class="form-label fw-bold">Select CSV File</label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="?action=template" class="btn btn-outline-secondary">
                            <i class="fas fa-download me-1"></i> Download Template CSV
                        </a>
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-file-import me-1"></i> Start Import Process
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Data Export Card -->
    <div class="col-md-5 mb-4">
        <div class="card h-100 shadow-sm border-0 bg-light">
            <div class="card-header bg-dark text-white py-3">
                <h5 class="mb-0"><i class="fas fa-file-download me-2"></i> Backup & Export Data</h5>
            </div>
            <div class="card-body p-4 d-flex flex-column justify-content-between">
                <div>
                    <p class="text-muted">
                        Export your current school's teacher directory. The exported CSV file contains full profiles, active statuses, bank details, and registration dates.
                    </p>
                    
                    <div class="p-3 bg-white rounded border mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Role Filter:</span>
                            <span class="fw-bold text-dark">Teacher Accounts</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary">Compatible Formats:</span>
                            <span class="fw-bold text-success">UTF-8 CSV (Excel)</span>
                        </div>
                    </div>
                </div>
                
                <a href="?action=export" class="btn btn-dark btn-lg w-100 py-3 mt-4">
                    <i class="fas fa-file-csv me-2"></i> Export All Teachers (CSV)
                </a>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
