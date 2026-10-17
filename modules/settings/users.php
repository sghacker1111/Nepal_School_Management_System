<?php
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/user_management.php';
Auth::requireRole(['super_admin','school_admin']);
$db=getDB(); $system=Auth::getRole()==='super_admin'; $error='';
if(isset($_GET['student_lookup'])){
    header('Content-Type: application/json');
    $school=$system?(int)($_GET['lookup_school']??0):(int)Auth::getSchoolId();
    $search=$_GET['search']??'';$selected=(int)($_GET['selected_record']??0);
    if(!is_string($search)||mb_strlen($search)>100)denySchoolRequest('Search students using up to 100 characters.',422);
    $params=[$school];$where='s.school_id=?';
    if(trim($search)!==''){$where.=" AND (s.first_name_en LIKE ? ESCAPE '!' OR s.last_name_en LIKE ? ESCAPE '!' OR s.student_id LIKE ? ESCAPE '!' OR s.roll_number LIKE ? ESCAPE '!')";$needle='%'.strtr(trim($search),['!'=>'!!','%'=>'!%','_'=>'!_']).'%';array_push($params,$needle,$needle,$needle,$needle);}
    $q=$db->prepare('SELECT s.id,s.first_name_en,s.last_name_en,s.student_id,s.roll_number AS roll_no,s.status,c.name class_name FROM students s JOIN classes c ON c.id=s.class_id AND c.school_id=s.school_id WHERE '.$where.' ORDER BY s.first_name_en,s.last_name_en,s.id LIMIT 50');$q->execute($params);$rows=$q->fetchAll();
    if($selected&&!in_array($selected,array_map('intval',array_column($rows,'id')),true)){
        $q=$db->prepare('SELECT s.id,s.first_name_en,s.last_name_en,s.student_id,s.roll_number AS roll_no,s.status,c.name class_name FROM students s JOIN classes c ON c.id=s.class_id AND c.school_id=s.school_id WHERE s.school_id=? AND s.id=?');$q->execute([$school,$selected]);if($row=$q->fetch())array_unshift($rows,$row);
    }
    echo json_encode(['success'=>true,'students'=>$rows]);exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $saved=saveSchoolUser($db,(int)Auth::getUserId(),Auth::getRole(),(int)Auth::getSchoolId(),$_POST);
        Auth::logActivity(Auth::getUserId(),'Save user account','users',$saved);
        redirect(APP_URL.($system?'/modules/super_admin/users.php':'/modules/settings/users.php'),'Account saved successfully.');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (PDOException $e) { error_log($e->getMessage()); $error='The account could not be saved. Check the username and try again.'; }
}
$sql="SELECT u.id,u.school_id,u.username,u.full_name,u.role,u.email,u.phone,u.status,u.student_record_id,s.name_en school_name FROM users u JOIN schools s ON s.id=u.school_id WHERE u.role<>'super_admin'";
$params=[]; if(!$system){$sql.=' AND u.school_id=?';$params[]=(int)Auth::getSchoolId();}
$stmt=$db->prepare($sql.' ORDER BY s.name_en,u.full_name');$stmt->execute($params);$accounts=$stmt->fetchAll();
$schools=$system?$db->query('SELECT id,name_en FROM schools ORDER BY name_en')->fetchAll():[];
$roles=['school_admin'=>'School administrator','principal'=>'Principal','teacher'=>'Teacher','exam_officer'=>'Exam officer','accountant'=>'Accountant','cashier'=>'Cashier','auditor'=>'Auditor (read only)','student'=>'Student (learning only)'];
$pageTitle='User management';$activePage=$system?'super_admin_users':'users';ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h2>User management</h2><p class="text-muted mb-0">Manage staff and student logins. Student accounts access learning only.</p></div><button class="btn btn-primary" data-erp-click="settings-users-action-0" data-erp-click-args="<?= schoolPageActionArgs("settings-users",[]) ?>">Add account</button></div>
<?php if($error):?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif;?>
<div class="card"><div class="card-body"><label class="form-label" for="accountSearch">Find an account</label><input id="accountSearch" type="search" class="form-control mb-3" placeholder="Search name, username, role or school"><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Name / username</th><?php if($system):?><th>School</th><?php endif;?><th>Role</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead><tbody id="accountRows">
<?php foreach($accounts as $account):?><tr><td><strong><?= sanitize($account['full_name']) ?></strong><br><small><?= sanitize($account['username']) ?></small></td><?php if($system):?><td><?= sanitize($account['school_name']) ?></td><?php endif;?><td><?= $roles[$account['role']]??sanitize($account['role']) ?></td><td><?= sanitize($account['email']??'') ?><br><small><?= sanitize($account['phone']??'') ?></small></td><td><span class="badge <?= $account['status']?'bg-success':'bg-secondary' ?>"><?= $account['status']?'Active':'Inactive' ?></span></td><td><button class="btn btn-sm btn-outline-primary" data-account="<?= htmlspecialchars(json_encode($account),ENT_QUOTES,'UTF-8') ?>" data-erp-click="settings-users-action-1" data-erp-click-args="<?= schoolPageActionArgs("settings-users",[]) ?>">Edit access</button></td></tr><?php endforeach;?>
<?php if(!$accounts):?><tr><td colspan="<?= $system?6:5 ?>" class="text-center text-muted py-4">No accounts yet.</td></tr><?php endif;?></tbody></table></div></div></div>
<div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountTitle" aria-hidden="true"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5 class="modal-title" id="accountTitle">Staff account</h5><button type="button" class="btn-close" data-ui-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><input type="hidden" name="user_id" id="accountId">
<?php if($system):?><label for="accountSchool" class="form-label">School</label><select class="form-select mb-3" name="school_id" id="accountSchool" required><option value="">Select school</option><?php foreach($schools as $s):?><option value="<?= $s['id'] ?>"><?= sanitize($s['name_en']) ?></option><?php endforeach;?></select><small class="text-muted d-block mb-3">Existing accounts remain in their assigned school.</small><?php endif;?>
<?php foreach(['full_name'=>'Full name','username'=>'Username','email'=>'Email','phone'=>'Phone'] as $key=>$label):?><label class="form-label" for="account_<?= $key ?>"><?= $label ?></label><input class="form-control mb-3" id="account_<?= $key ?>" name="<?= $key ?>" type="<?= $key==='email'?'email':'text' ?>" <?= in_array($key,['full_name','username'])?'required':'' ?>><?php endforeach;?>
<label class="form-label" for="accountRole">Role</label><select class="form-select mb-3" name="role" id="accountRole"><?php foreach($roles as $value=>$label):?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach;?></select>
<div id="studentAccountFields" hidden class="mb-3"><label class="form-label" for="accountStudentSearch">Find student</label><input class="form-control mb-2" type="search" id="accountStudentSearch" placeholder="First or last name, student ID or roll number" maxlength="100"><label class="form-label" for="accountStudent">Linked student record</label><select class="form-select" id="accountStudent" name="student_record_id"><option value="">Choose student</option></select><small class="text-muted">Up to 50 matches. One login per student; class access follows their enrollment.</small><div id="studentLookupStatus" class="small text-danger" role="status"></div></div>
<label class="form-label" for="accountPassword">Password</label><input type="password" class="form-control" id="accountPassword" name="password" minlength="8" autocomplete="new-password"><small class="text-muted">At least 8 characters. Leave blank when editing to keep the current password.</small><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="status" value="1" id="accountStatus" checked><label class="form-check-label" for="accountStatus">Account is active</label></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-ui-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save account</button></div></form></div></div>
<?php schoolPageModule("settings-users");schoolPageValue("settings-users-value-0",(string)((int)Auth::getSchoolId()));
schoolPageValue("settings-users-value-1",(string)(json_encode(schoolRouteUrl('modules/settings/users.php')))); ?>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
