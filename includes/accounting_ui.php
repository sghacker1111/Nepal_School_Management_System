<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/database.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/accounting_service.php';
Auth::requireRole(['super_admin','school_admin','accountant','cashier','auditor']);
Auth::requireAccess('accounting');
try{$ac=new SchoolAccounting(getDB(),(int)Auth::getSchoolId(),(int)Auth::getUserId(),Auth::isReadOnly());}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),403);}
$db=$ac->db;$school=$ac->school;$error='';$activePage='accounting';
function acUrl(string $page,array $args=[]):string{return schoolRouteUrl('modules/accounting/'.$page.'.php',$args);}
function acInput(string $name,string $label,$value='',string $type='text',bool $required=true):void {
    $id='ac_'.preg_replace('/[^A-Za-z0-9]/','_',$name);echo '<label class="form-label d-block" for="'.$id.'">'.sanitize($label).'<input id="'.$id.'" class="form-control" name="'.sanitize($name).'" type="'.$type.'" value="'.sanitize((string)$value).'" '.($required?'required':'').' '.($type==='number'?'step="0.01" min="0"':'').'></label>';
}
function acSelect(string $name,string $label,array $rows,$selected='',string $valueKey='id',string $labelKey='name',bool $required=true):void {
    $id='ac_'.preg_replace('/[^A-Za-z0-9]/','_',$name);echo '<label class="form-label d-block" for="'.$id.'">'.sanitize($label).'<select class="form-select" id="'.$id.'" name="'.sanitize($name).'" '.($required?'required':'').'><option value="">Choose…</option>';foreach($rows as $r)echo '<option value="'.sanitize((string)$r[$valueKey]).'" '.((string)$r[$valueKey]===(string)$selected?'selected':'').'>'.sanitize((string)$r[$labelKey]).'</option>';echo '</select></label>';
}
function acChoices(array $options):array{$rows=[];foreach($options as $key=>$name)$rows[]=['id'=>$key,'name'=>$name];return $rows;}
function acForm(string $action,string $label=''):void{echo '<form method="post" class="card card-body mb-4"><input type="hidden" name="action" value="'.sanitize($action).'"><input type="hidden" name="idempotency_key" value="'.bin2hex(random_bytes(20)).'">';if($label)echo '<h4>'.sanitize($label).'</h4>';}
function acSubmit(string $label='Save'):void{echo '<button class="btn btn-primary align-self-start mt-3" type="submit">'.sanitize($label).'</button></form>';}
function acStudentPicker(int $selected=0,bool $required=false):void {
    global $ac;$name='';if($selected){$s=schoolRecord($ac->db,'students',$selected,$ac->school);$name=$s['first_name_en'].' '.$s['last_name_en'].' · '.$s['student_id'];}
    echo '<div class="mb-3" data-student-picker><label class="form-label" for="studentSearch">Find student by name, ID, class or guardian phone</label><input id="studentSearch" type="search" class="form-control" autocomplete="off" value="'.sanitize($name).'" placeholder="Type at least 2 characters"><input type="hidden" name="student_id" value="'.($selected?:'').'" '.($required?'required':'').'><div class="list-group mt-1" data-student-results></div><small class="text-muted">Select a result to link a bill/payment to the student. Clear the search for an external customer.</small></div>';
}
function acRender(string $title,string $content):void {
    global $error,$activePage,$ac,$pageMutationOutcome;$pageTitle=$title;
    $pageStyles=['/assets/css/accounting.css'];schoolPageModule('external-accounting');
    $links=['index'=>'Overview','fees'=>'Fees & products','create_bill'=>'Create bill','invoices'=>'Invoices','generate'=>'Generate bills','payments'=>'Collect payment','student'=>'Student accounts','subscriptions'=>'Subscriptions','expenses'=>'Expenses','journals'=>'Accounts & journals','reports'=>'Reports','settings'=>'Settings & audit'];
    $nav='<div class="d-flex flex-wrap gap-2 mb-4 no-print">';foreach($links as $p=>$label){if(in_array($p,['create_bill','generate','subscriptions'],true)&&!$ac->can($p==='subscriptions'?'subscription':'invoice'))continue;if($p==='payments'&&!$ac->can('payment'))continue;$nav.='<a class="btn btn-sm btn-outline-secondary" href="'.acUrl($p).'">'.$label.'</a>';}$nav.='</div>';
    $content='<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3"><h2>'.sanitize($title).'</h2><span class="text-muted">Accounting & Billing · NPR</span></div>'.$nav.($error?'<div class="alert alert-danger">'.sanitize($error).'</div>':'').$content;include __DIR__.'/header.php';
}
function acHandle(callable $callback,string $page,array $args=[]):void {
    global $error;if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    try{$result=$callback();if(is_int($result)&&!isset($args['id']))$args['id']=$result;redirect(acUrl($page,$args),'Saved successfully.');}
    catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(PDOException $e){error_log($e->getMessage());$error='This transaction could not be saved. No partial changes were committed. Please check the data and retry.';}
}
