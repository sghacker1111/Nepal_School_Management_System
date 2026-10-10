<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/user_management.php';
Auth::requireRole(['super_admin','school_admin']);
$db=getDB(); $system=Auth::getRole()==='super_admin'; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        $saved=saveSchoolUser($db,(int)Auth::getUserId(),Auth::getRole(),(int)Auth::getSchoolId(),$_POST);
        Auth::logActivity(Auth::getUserId(),'Save staff account','users',$saved);
        redirect(APP_URL.($system?'/modules/super_admin/users.php':'/modules/settings/users.php'),'Account saved successfully.');
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    catch (PDOException $e) { error_log($e->getMessage()); $error='The account could not be saved. Check the username and try again.'; }
}
$sql="SELECT u.id,u.school_id,u.username,u.full_name,u.role,u.email,u.phone,u.status,s.name_en school_name FROM users u JOIN schools s ON s.id=u.school_id WHERE u.role<>'super_admin'";
$params=[]; if(!$system){$sql.=' AND u.school_id=?';$params[]=(int)Auth::getSchoolId();}
$stmt=$db->prepare($sql.' ORDER BY s.name_en,u.full_name');$stmt->execute($params);$accounts=$stmt->fetchAll();
$schools=$system?$db->query('SELECT id,name_en FROM schools ORDER BY name_en')->fetchAll():[];
$roles=['school_admin'=>'School administrator','principal'=>'Principal','teacher'=>'Teacher','exam_officer'=>'Exam officer','accountant'=>'Accountant','cashier'=>'Cashier','auditor'=>'Auditor (read only)'];
$pageTitle='User management';$activePage=$system?'super_admin_users':'users';ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h2>User management</h2><p class="text-muted mb-0">Manage staff access without losing their school records.</p></div><button class="btn btn-primary" onclick="openAccount()">Add staff account</button></div>
<?php if($error):?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif;?>
<div class="card"><div class="card-body"><label class="form-label" for="accountSearch">Find a staff member</label><input id="accountSearch" type="search" class="form-control mb-3" placeholder="Search name, username, role or school"><div class="table-responsive"><table class="table table-hover"><thead><tr><th>Name / username</th><?php if($system):?><th>School</th><?php endif;?><th>Role</th><th>Contact</th><th>Status</th><th>Action</th></tr></thead><tbody id="accountRows">
<?php foreach($accounts as $account):?><tr><td><strong><?= sanitize($account['full_name']) ?></strong><br><small><?= sanitize($account['username']) ?></small></td><?php if($system):?><td><?= sanitize($account['school_name']) ?></td><?php endif;?><td><?= $roles[$account['role']]??sanitize($account['role']) ?></td><td><?= sanitize($account['email']??'') ?><br><small><?= sanitize($account['phone']??'') ?></small></td><td><span class="badge <?= $account['status']?'bg-success':'bg-secondary' ?>"><?= $account['status']?'Active':'Inactive' ?></span></td><td><button class="btn btn-sm btn-outline-primary" data-account="<?= htmlspecialchars(json_encode($account),ENT_QUOTES,'UTF-8') ?>" onclick="openAccount(JSON.parse(this.dataset.account))">Edit access</button></td></tr><?php endforeach;?>
<?php if(!$accounts):?><tr><td colspan="6" class="text-center text-muted py-4">No staff accounts yet. Add your first staff member.</td></tr><?php endif;?></tbody></table></div></div></div>
<div class="modal fade" id="accountModal" tabindex="-1" aria-labelledby="accountTitle" aria-hidden="true"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5 class="modal-title" id="accountTitle">Staff account</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><input type="hidden" name="user_id" id="accountId">
<?php if($system):?><label for="accountSchool" class="form-label">School</label><select class="form-select mb-3" name="school_id" id="accountSchool" required><option value="">Select school</option><?php foreach($schools as $s):?><option value="<?= $s['id'] ?>"><?= sanitize($s['name_en']) ?></option><?php endforeach;?></select><small class="text-muted d-block mb-3">Existing accounts remain in their assigned school.</small><?php endif;?>
<?php foreach(['full_name'=>'Full name','username'=>'Username','email'=>'Email','phone'=>'Phone'] as $key=>$label):?><label class="form-label" for="account_<?= $key ?>"><?= $label ?></label><input class="form-control mb-3" id="account_<?= $key ?>" name="<?= $key ?>" type="<?= $key==='email'?'email':'text' ?>" <?= in_array($key,['full_name','username'])?'required':'' ?>><?php endforeach;?>
<label class="form-label" for="accountRole">Role</label><select class="form-select mb-3" name="role" id="accountRole"><?php foreach($roles as $value=>$label):?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach;?></select><label class="form-label" for="accountPassword">Password</label><input type="password" class="form-control" id="accountPassword" name="password" minlength="8" autocomplete="new-password"><small class="text-muted">At least 8 characters. Leave blank when editing to keep the current password.</small><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="status" value="1" id="accountStatus" checked><label class="form-check-label" for="accountStatus">Account is active</label></div></div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save account</button></div></form></div></div>
<script>
function openAccount(user={}){document.getElementById('accountId').value=user.id||'';['full_name','username','email','phone'].forEach(k=>document.getElementById('account_'+k).value=user[k]||'');document.getElementById('accountRole').value=user.role||'teacher';document.getElementById('accountStatus').checked=user.status===undefined||Number(user.status)===1;document.getElementById('accountPassword').value='';document.getElementById('accountPassword').required=!user.id;const school=document.getElementById('accountSchool');if(school){school.value=user.school_id||'';Array.from(school.options).forEach(o=>o.disabled=!!user.id&&o.value!==String(user.school_id));}document.getElementById('accountTitle').textContent=user.id?'Edit staff account':'Add staff account';bootstrap.Modal.getOrCreateInstance(document.getElementById('accountModal')).show();}
document.getElementById('accountSearch').addEventListener('input',function(){document.querySelectorAll('#accountRows tr').forEach(r=>r.hidden=!r.textContent.toLowerCase().includes(this.value.toLowerCase()));});
</script>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
