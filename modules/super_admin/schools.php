<?php
/**
 * Super Admin School Management
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/packages.php';

Auth::requireRole('super_admin');

$db = getDB();
$error = '';
$success = '';

// Package checkboxes are the single source of access; keep legacy flags/data intact.
if (isset($_GET['perm_action']) || isset($_GET['view_perm_action']) || isset($_GET['hub_perm_action'])) {
    redirect(schoolRouteUrl('modules/super_admin/schools.php'), 'Use Edit School to activate or remove packages and set their expiry.');
}

// Handle Add/Edit School Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $name_en = sanitize($_POST['name_en'] ?? '');
    $name_np = sanitize($_POST['name_np'] ?? '');
    $emis_code = sanitize($_POST['emis_code'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $academic_year_current = sanitize($_POST['academic_year_current'] ?? '2081/2025');
    $principal_name = sanitize($_POST['principal_name'] ?? '');
    $principal_mobile = sanitize($_POST['principal_mobile'] ?? '');
    $technical_person = sanitize($_POST['technical_person'] ?? '');
    try{$subscriptionConfig=schoolSubscriptionInput($_POST);$subscription_type=$subscriptionConfig['subscription_type'];}catch(InvalidArgumentException $e){$error=$e->getMessage();}
    
    $admin_full_name = sanitize($_POST['admin_full_name'] ?? '');
    $admin_username = sanitize($_POST['admin_username'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';
    
    if($error) {
        // Keep the subscription validation message.
    } elseif (empty($name_en) || empty($name_np) || empty($emis_code) || empty($address) || empty($phone) || empty($email) || empty($principal_name) || empty($principal_mobile) || empty($technical_person) || empty($admin_full_name) || empty($admin_username)) {
        $error = "All fields are required.";
    } elseif (($action === 'add' || $admin_password !== '') && strlen($admin_password) < 8) {
        $error = 'Administrator passwords must contain at least 8 characters.';
    } elseif (!preg_match('/^(\d{4})\/(\d{4})$/', $academic_year_current, $year_parts)) {
        $error = 'Enter the academic year as BS/AD, for example 2083/2026.';
    } else {
        try {
            if ($action === 'add') {
                if (empty($admin_password)) {
                    $error = "Administrator password is required for new schools.";
                } else {
                    // Check username uniqueness
                    $stmt_u = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt_u->execute([$admin_username]);
                    if ($stmt_u->fetchColumn() > 0) {
                        $error = "Administrator username already exists. Please choose a unique one.";
                    } else {
                        $db->beginTransaction();
                        
                        $stmt = $db->prepare("
                            INSERT INTO schools (name_en, name_np, emis_code, address, phone, email, academic_year_current, principal_name, principal_mobile, technical_person, subscription_type) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$name_en, $name_np, $emis_code, $address, $phone, $email, $academic_year_current, $principal_name, $principal_mobile, $technical_person, $subscription_type]);
                        $new_school_id = $db->lastInsertId();
                        saveSchoolSubscription($db,(int)$new_school_id,$subscriptionConfig);
                        
                        // Add default academic years for this new school automatically!
                        $stmt = $db->prepare("INSERT INTO academic_years (year_bs, year_ad, is_active, school_id) VALUES (?, ?, 1, ?)");
                        $stmt->execute([$year_parts[1], $year_parts[2], $new_school_id]);
                        provisionSchool($db, (int)$new_school_id);
                        
                        // Create admin user
                        $pass_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            INSERT INTO users (username, password, role, full_name, email, phone, school_id, status) 
                            VALUES (?, ?, 'school_admin', ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$admin_username, $pass_hash, $admin_full_name, $email, $phone, $new_school_id]);
                        
                        $db->commit();
                        Auth::logActivity(Auth::getUserId(), 'Create School & Admin', 'schools', $new_school_id);
                        $success = "School registered and administrator account created successfully.";
                    }
                }
            } elseif ($action === 'edit') {
                $school_id = (int)$_POST['school_id'];
                
                // Find existing admin user ID for this school
                $stmt_admin = $db->prepare("SELECT id FROM users WHERE school_id = ? AND role = 'school_admin' ORDER BY id LIMIT 1");
                $stmt_admin->execute([$school_id]);
                $admin_record = $stmt_admin->fetch();
                $admin_user_id = $admin_record ? $admin_record['id'] : null;
                
                $username_taken = false;
                if ($admin_user_id) {
                    $stmt_u = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
                    $stmt_u->execute([$admin_username, $admin_user_id]);
                    $username_taken = ($stmt_u->fetchColumn() > 0);
                } else {
                    $stmt_u = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt_u->execute([$admin_username]);
                    $username_taken = ($stmt_u->fetchColumn() > 0);
                }
                
                if ($username_taken) {
                    $error = "Administrator username already exists. Please choose a unique one.";
                } else {
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("
                        UPDATE schools 
                        SET name_en = ?, name_np = ?, emis_code = ?, address = ?, phone = ?, email = ?, academic_year_current = ?, principal_name = ?, principal_mobile = ?, technical_person = ?, subscription_type = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name_en, $name_np, $emis_code, $address, $phone, $email, $academic_year_current, $principal_name, $principal_mobile, $technical_person, $subscription_type, $school_id]);
                    saveSchoolSubscription($db,$school_id,$subscriptionConfig);
                    
                    if ($admin_user_id) {
                        if (!empty($admin_password)) {
                            $pass_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET username = ?, password = ?, full_name = ?, email = ?, phone = ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$admin_username, $pass_hash, $admin_full_name, $email, $phone, $admin_user_id]);
                        } else {
                            $stmt = $db->prepare("
                                UPDATE users 
                                SET username = ?, full_name = ?, email = ?, phone = ? 
                                WHERE id = ?
                            ");
                            $stmt->execute([$admin_username, $admin_full_name, $email, $phone, $admin_user_id]);
                        }
                    } else {
                        // Create one since it was missing
                        if (strlen($admin_password)<8) throw new InvalidArgumentException('Set a password of at least 8 characters for the missing school administrator.');
                        $pass_hash = password_hash($admin_password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("
                            INSERT INTO users (username, password, role, full_name, email, phone, school_id, status) 
                            VALUES (?, ?, 'school_admin', ?, ?, ?, ?, 1)
                        ");
                        $stmt->execute([$admin_username, $pass_hash, $admin_full_name, $email, $phone, $school_id]);
                    }
                    
                    $db->commit();
                    Auth::logActivity(Auth::getUserId(), 'Edit School Details & Admin', 'schools', $school_id);
                    $success = "School details and administrator account updated successfully.";
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = schoolPublicError($e);
        }
    }
}

// Fetch all schools with their main administrator
$schools = $db->query("
    SELECT s.*, u.username as admin_username, u.full_name as admin_full_name 
    FROM schools s
    LEFT JOIN users u ON u.id=(SELECT MIN(a.id) FROM users a WHERE a.school_id=s.id AND a.role='school_admin')
    ORDER BY s.id DESC
")->fetchAll();

// Restore a failed form without ever returning its administrator password.
$formRecovery=null;
if($error&&in_array($_POST['action']??'', ['add','edit'],true)){
    $formRecovery=['action'=>$_POST['action'],'school_id'=>(int)($_POST['school_id']??0),'packages'=>array_values(array_intersect(array_filter((array)($_POST['packages']??[]),'is_string'),array_keys(schoolPackages()))),'subscription_enabled'=>isset($_POST['subscription_enabled'])];
    foreach(['name_en','name_np','emis_code','address','phone','email','academic_year_current','principal_name','principal_mobile','technical_person','admin_full_name','admin_username','subscription_mode','subscription_start','subscription_end'] as $key)$formRecovery[$key]=is_scalar($_POST[$key]??null)?(string)$_POST[$key]:'';
}

$pageTitle = 'School Management';
$activePage = 'super_admin_schools';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-university text-primary"></i> School Management</h2>
    <div>
        <a href="<?= schoolRouteUrl('modules/super_admin/index.php') ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Control Center
        </a>
        <button class="btn btn-primary" onclick="showAddModal()">
            <i class="fas fa-plus"></i> Register New School
        </button>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary">Registered Schools List (<?= count($schools) ?>)</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>School Name (English & Nepali)</th>
                        <th>EMIS Code</th>
                        <th>Contact info</th>
                        <th>Address</th>
                        <th>Package</th>
                        <th>Enabled Features</th>
                        <th>Current Year</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($schools) > 0): ?>
                        <?php foreach ($schools as $school): ?>
                        <tr>
                            <td><code>#<?= $school['id'] ?></code></td>
                            <td>
                                <strong><?= sanitize($school['name_en']) ?></strong><br>
                                <span class="text-muted small"><?= sanitize($school['name_np'] ?: '-') ?></span>
                            </td>
                            <td><code><?= sanitize($school['emis_code'] ?: '-') ?></code></td>
                            <td>
                                <i class="fas fa-phone fa-xs text-muted me-1"></i> <?= sanitize($school['phone'] ?: '-') ?><br>
                                <i class="fas fa-envelope fa-xs text-muted me-1"></i> <?= sanitize($school['email'] ?: '-') ?>
                            </td>
                            <td><?= sanitize($school['address'] ?: '-') ?></td>
                            <td>
                                <?php
                                foreach(schoolSelectedPackages($school) as $pkg)echo '<span class="badge bg-secondary me-1 mb-1">'.sanitize(schoolPackages()[$pkg]).'</span>';
                                $state=schoolSubscriptionState($school);echo '<div><span class="badge '.($state==='active'?'bg-success':'bg-danger').'">'.sanitize(ucwords(str_replace('_',' ',$state))).'</span></div>';
                                if($school['subscription_end'])echo '<small>Expires '.sanitize(schoolDateLabel($school['subscription_end'],'both','ymd')).'</small>';
                                else echo '<small>No expiry (legacy or administrator choice)</small>';
                                ?>
                            </td>
                            <td>
                                <?php
                                foreach(['results'=>'Results','learning'=>'Learning','paper_generator'=>'Paper Generator','questions'=>'Question View','accounting'=>'Accounting'] as $feature=>$label) {
                                    if(schoolSubscriptionAllows($school,$feature))echo '<div class="small text-success"><i class="fas fa-check"></i> '.sanitize($label).'</div>';
                                }
                                if(schoolSubscriptionCanAddQuestions($school))echo '<div class="small text-success"><i class="fas fa-check"></i> Question Add</div>';
                                if($state!=='active')echo '<span class="text-muted small">Access inactive</span>';
                                ?>
                            </td>
                            <td><span class="badge bg-info"><?= sanitize($school['academic_year_current'] ?: '2081/2025') ?></span></td>
                            <td class="text-end">
                                <button onclick="editSchool(<?= htmlspecialchars(json_encode($school)) ?>)" class="btn btn-sm btn-outline-warning" title="Edit">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No schools registered in the database.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit School Modal -->
<div class="modal fade" id="schoolModal" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form id="schoolForm" method="POST" action="" class="needs-validation" novalidate>
            <input type="hidden" name="action" id="modalAction" value="add">
            <input type="hidden" name="school_id" id="modalSchoolId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Register New School</h5>
                    <button type="button" class="btn-close" data-ui-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    <?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?> Your form is retained; re-enter a new administrator password only if needed.</div><?php endif; ?>
                    <div class="row g-3">
                        <!-- School Profile Details -->
                        <div class="col-12">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-university"></i> School Profile Details</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Name (English) *</label>
                            <input type="text" name="name_en" id="modalNameEn" class="form-control" required placeholder="e.g. Kathmandu Public School">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Name (Nepali) *</label>
                            <input type="text" name="name_np" id="modalNameNp" class="form-control" required placeholder="e.g. काठमाडौं पब्लिक स्कुल">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">EMIS Code *</label>
                            <input type="text" name="emis_code" id="modalEmisCode" class="form-control" required placeholder="e.g. 23812739">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Academic Year *</label>
                            <input type="text" name="academic_year_current" id="modalAcademicYear" class="form-control" value="2081/2025" placeholder="e.g. 2081/2025" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">School Address *</label>
                            <textarea name="address" id="modalAddress" class="form-control" rows="2" placeholder="e.g. Putalisadak, Kathmandu" required></textarea>
                        </div>

                        <!-- Contact & Leadership -->
                        <div class="col-12 mt-4">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-id-card"></i> Contact & Leadership</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Phone Number *</label>
                            <input type="text" name="phone" id="modalPhone" class="form-control" placeholder="e.g. 01-445566" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">School Email Address *</label>
                            <input type="email" name="email" id="modalEmail" class="form-control" placeholder="e.g. info@school.edu.np" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Principal Name *</label>
                            <input type="text" name="principal_name" id="modalPrincipalName" class="form-control" placeholder="e.g. Dr. Ramesh Gupta" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Principal Mobile Number *</label>
                            <input type="text" name="principal_mobile" id="modalPrincipalMobile" class="form-control" placeholder="e.g. 9851000000" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Technical Contact Person *</label>
                            <input type="text" name="technical_person" id="modalTechnicalPerson" class="form-control" placeholder="e.g. Sharad Acharya" required>
                        </div>

                        <!-- Subscription Package -->
                        <div class="col-12 mt-4">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-box-open"></i> Subscription & Access Details</h6>
                        </div>
                        <div class="col-md-6">
                            <fieldset><legend class="fs-6">Activate packages (tick all purchased)</legend>
                                <?php foreach(schoolPackages() as $package=>$label): ?><div class="form-check"><input type="checkbox" class="form-check-input school-package" name="packages[]" id="package_<?= $package ?>" value="<?= $package ?>"><label class="form-check-label" for="package_<?= $package ?>"><?= sanitize($label) ?></label></div><?php endforeach; ?>
                            </fieldset>
                            <div class="form-text">Full ERP includes every module. Unticking a package removes access without deleting records.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="modalSubscriptionMode" class="form-label">Subscription duration</label>
                            <select name="subscription_mode" id="modalSubscriptionMode" class="form-select" onchange="updateSubscriptionDates()"><option value="yearly">Yearly (one year from start date)</option><option value="custom">Custom start and expiry dates</option><option value="no_expiry">No expiry</option></select>
                            <div class="form-check mt-3"><input type="checkbox" name="subscription_enabled" value="1" id="modalSubscriptionEnabled" class="form-check-input" checked><label for="modalSubscriptionEnabled" class="form-check-label">Activate school account</label></div>
                            <div class="form-text">Expired schools cannot use modules until the system administrator renews them. Saved records are retained.</div>
                        </div>
                        <div class="col-md-6" id="subscriptionStartControl">
                            <label for="modalSubscriptionStart" class="form-label">Subscription start</label><input type="date" name="subscription_start" id="modalSubscriptionStart" class="form-control" onchange="updateSubscriptionDates()">
                        </div>
                        <div class="col-md-6" id="subscriptionEndControl">
                            <label for="modalSubscriptionEnd" class="form-label">Expiry date (inclusive)</label><input type="date" name="subscription_end" id="modalSubscriptionEnd" class="form-control">
                            <div class="form-text">Yearly expiry is calculated by the server. Custom dates may also be entered in BS using the date calendar control.</div>
                            <button type="button" class="btn btn-outline-success btn-sm mt-2" onclick="renewSubscription()">Renew for one year</button>
                        </div>

                        <!-- Administrator Credentials -->
                        <div class="col-12 mt-4">
                            <h6 class="text-primary border-bottom pb-2 mb-1"><i class="fas fa-user-shield"></i> School Administrator Credentials</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administrator Full Name *</label>
                            <input type="text" name="admin_full_name" id="modalAdminFullName" class="form-control" placeholder="e.g. School Administrator" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Administrator Username *</label>
                            <input type="text" name="admin_username" id="modalAdminUsername" class="form-control" placeholder="e.g. suryodaya_admin" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" id="adminPasswordLabel">Administrator Password *</label>
                            <input type="password" name="admin_password" id="modalAdminPassword" class="form-control" placeholder="Password">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-ui-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save School</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
let schoolModalObj;
const schoolToday=<?= json_encode((new DateTimeImmutable('now',new DateTimeZone('Asia/Kathmandu')))->format('Y-m-d')) ?>;
function subscriptionDateAd(field){const selector=field.parentElement.querySelector('[name^="calendar_fields"]');return window.schoolConvertDate?schoolConvertDate(field.value,selector?.value||'AD','AD'):field.value;}
function subscriptionYearEnd(start){const [y,m,d]=start.split('-').map(Number);const last=new Date(Date.UTC(y+1,m,0)).getUTCDate();const expiry=new Date(Date.UTC(y+1,m-1,Math.min(d,last)));expiry.setUTCDate(expiry.getUTCDate()-1);return expiry.toISOString().slice(0,10);}
function updateSubscriptionDates() {
    const mode=document.getElementById('modalSubscriptionMode').value;
    const start=document.getElementById('modalSubscriptionStart');const end=document.getElementById('modalSubscriptionEnd');
    document.getElementById('subscriptionStartControl').hidden=mode==='no_expiry';document.getElementById('subscriptionEndControl').hidden=mode==='no_expiry';
    start.required=mode!=='no_expiry';end.required=mode==='custom';end.readOnly=mode==='yearly';
    if(mode==='yearly'&&start.value){try{const ad=subscriptionYearEnd(subscriptionDateAd(start));const selector=end.parentElement.querySelector('[name^="calendar_fields"]');end.value=window.schoolConvertDate?schoolConvertDate(ad,'AD',selector?.value||'AD'):ad;}catch(e){end.value='';}}
}
function resetSubscriptionCalendars(){['modalSubscriptionStart','modalSubscriptionEnd'].forEach(id=>{const field=document.getElementById(id);const select=field.parentElement.querySelector('[name^="calendar_fields"]');if(select){select.value='AD';select.dispatchEvent(new Event('change'));}});}
function renewSubscription(){
    resetSubscriptionCalendars();const start=document.getElementById('modalSubscriptionStart');const end=document.getElementById('modalSubscriptionEnd');
    if(end.value&&end.value>=schoolToday){const next=new Date(end.value+'T00:00:00Z');next.setUTCDate(next.getUTCDate()+1);document.getElementById('modalSubscriptionMode').value='custom';end.value=subscriptionYearEnd(next.toISOString().slice(0,10));if(!start.value)start.value=schoolToday;}
    else{document.getElementById('modalSubscriptionMode').value='yearly';start.value=schoolToday;}
    document.getElementById('modalSubscriptionEnabled').checked=true;updateSubscriptionDates();
}

$(document).ready(function() {
    schoolModalObj = new ErpUI.Modal(document.getElementById('schoolModal'), {
        focus: false
    });
    const recovery=<?= json_encode($formRecovery,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    if(recovery){
        showAddModal();
        const form=document.getElementById('schoolForm');
        Object.entries(recovery).forEach(([key,value])=>{const field=form.elements.namedItem(key);if(field&&typeof value==='string')field.value=value;});
        document.getElementById('modalSchoolId').value=recovery.school_id;
        document.querySelectorAll('.school-package').forEach(box=>box.checked=recovery.packages.includes(box.value));
        document.getElementById('modalSubscriptionEnabled').checked=recovery.subscription_enabled;
        if(recovery.action==='edit'){document.getElementById('modalTitle').innerText='Edit School Details';document.getElementById('modalAdminPassword').required=false;document.getElementById('adminPasswordLabel').innerText='Administrator Password (leave empty to keep current)';}
        updateSubscriptionDates();
    }
    
    $('#schoolForm').on('submit', function(e) {
        const form = this;
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
            form.classList.add('was-validated');
            return;
        }
        
        const action = document.getElementById('modalAction').value;
        if (action === 'edit') {
            e.preventDefault();
            e.stopPropagation();
            
            verifyPasswordAndProceed(function() {
                form.submit();
            }, 'Confirm School Edit', 'Please enter your administrator password to save the school details:');
        }
    });
});

function showAddModal() {
    document.getElementById('modalAction').value = 'add';
    document.getElementById('modalSchoolId').value = '';
    document.getElementById('modalTitle').innerText = 'Register New School';
    document.getElementById('modalNameEn').value = '';
    document.getElementById('modalNameNp').value = '';
    document.getElementById('modalEmisCode').value = '';
    document.getElementById('modalAcademicYear').value = '2081/2025';
    document.getElementById('modalPhone').value = '';
    document.getElementById('modalEmail').value = '';
    document.getElementById('modalAddress').value = '';
    
    document.getElementById('modalPrincipalName').value = '';
    document.getElementById('modalPrincipalMobile').value = '';
    document.getElementById('modalTechnicalPerson').value = '';
    
    resetSubscriptionCalendars();document.querySelectorAll('.school-package').forEach(box=>box.checked=box.value==='full');
    document.getElementById('modalSubscriptionMode').value='yearly';document.getElementById('modalSubscriptionStart').value=schoolToday;document.getElementById('modalSubscriptionEnabled').checked=true;updateSubscriptionDates();
    document.getElementById('modalAdminFullName').value = '';
    document.getElementById('modalAdminUsername').value = '';
    document.getElementById('modalAdminPassword').value = '';
    document.getElementById('modalAdminPassword').setAttribute('required', 'required');
    document.getElementById('adminPasswordLabel').innerText = 'Administrator Password *';
    schoolModalObj.show();
}

function editSchool(s) {
    document.getElementById('modalAction').value = 'edit';
    document.getElementById('modalSchoolId').value = s.id;
    document.getElementById('modalTitle').innerText = 'Edit School Details';
    document.getElementById('modalNameEn').value = s.name_en;
    document.getElementById('modalNameNp').value = s.name_np || '';
    document.getElementById('modalEmisCode').value = s.emis_code || '';
    document.getElementById('modalAcademicYear').value = s.academic_year_current || '2081/2025';
    document.getElementById('modalPhone').value = s.phone || '';
    document.getElementById('modalEmail').value = s.email || '';
    document.getElementById('modalAddress').value = s.address || '';
    
    document.getElementById('modalPrincipalName').value = s.principal_name || '';
    document.getElementById('modalPrincipalMobile').value = s.principal_mobile || '';
    document.getElementById('modalTechnicalPerson').value = s.technical_person || '';
    
    resetSubscriptionCalendars();let packages=[];try{packages=s.packages_json===null?[s.subscription_type]:JSON.parse(s.packages_json);if(!Array.isArray(packages))packages=[];}catch(e){}document.querySelectorAll('.school-package').forEach(box=>box.checked=packages.includes(box.value));
    document.getElementById('modalSubscriptionMode').value=s.subscription_mode||'no_expiry';document.getElementById('modalSubscriptionStart').value=s.subscription_start||schoolToday;document.getElementById('modalSubscriptionEnd').value=s.subscription_end||'';document.getElementById('modalSubscriptionEnabled').checked=Number(s.subscription_enabled)!==0;updateSubscriptionDates();
    document.getElementById('modalAdminFullName').value = s.admin_full_name || '';
    document.getElementById('modalAdminUsername').value = s.admin_username || '';
    document.getElementById('modalAdminPassword').value = '';
    document.getElementById('modalAdminPassword').removeAttribute('required');
    document.getElementById('adminPasswordLabel').innerText = 'Administrator Password (leave empty to keep current)';
    schoolModalObj.show();
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
