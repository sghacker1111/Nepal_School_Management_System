<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/exam_template_service.php';
Auth::requireRole('super_admin');$db=getDB();$error='';
$templates=examTemplates($db,true);
try{$id=examTemplateId($_GET['template_id']??$_POST['template_id']??$templates[0]['id']);$selected=examTemplate($db,$id);}
catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}
$class=(int)($_GET['filter_class']??0);
if($class)try{schoolRecord($db,'classes',$class,0);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}
if($_SERVER['REQUEST_METHOD']==='POST')try{
    if(($_POST['action']??'')!=='save_template'||examTemplateId($_POST['template_id']??0)!==$id)throw new InvalidArgumentException('Choose a valid exam template action.');
    saveExamTemplate($db,(int)Auth::getUserId(),$id,$_POST);
    Auth::logActivity(Auth::getUserId(),'Update default exam template','exam_templates',$id);
    redirect(schoolRouteUrl('modules/super_admin/exam_templates.php') . '?'.http_build_query(['template_id'=>$id,'filter_class'=>$class]),'Default exam template saved successfully. Existing exams are unchanged.');
}catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable $e){error_log($e->getMessage());$error='The defaults could not be saved. Existing exams are unchanged.';}
$catalog=examTemplateCatalog($db);$schemes=examTemplateSchemes($db,$selected);
$classes=$db->query('SELECT id,name FROM classes WHERE school_id=0 AND is_active=1 ORDER BY id')->fetchAll();
$form=$selected;if($error)foreach(['name','type','term_no','result_system','date_calendar','date_format'] as $k)if(is_string($_POST[$k]??null))$form[$k]=$_POST[$k];
$pageTitle='Default Exam Templates';$activePage='super_admin_exams';ob_start(); ?>
<h2>Default Exam Templates</h2>
<p class="text-muted">Set First, Second and Third / Final term defaults. Automatic school exams use a read-only snapshot; manual exams start from these defaults and can be customized. Changing or deactivating a default never changes an existing exam.</p>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<form method="GET" class="row g-3 mb-4"><div class="col-md-6"><label for="templateChoice" class="form-label">Exam template</label><select id="templateChoice" name="template_id" class="form-select" data-erp-change="super-admin-exam-templates-action-0" data-erp-change-args="<?= schoolPageActionArgs("super-admin-exam-templates",[]) ?>"><?php foreach($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $id===(int)$t['id']?'selected':'' ?>><?= sanitize($t['name']) ?><?= $t['is_active']?'':' (Inactive)' ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label for="templateClass" class="form-label">Class subject settings</label><select id="templateClass" name="filter_class" class="form-select" data-erp-change="super-admin-exam-templates-action-1" data-erp-change-args="<?= schoolPageActionArgs("super-admin-exam-templates",[]) ?>"><option value="">Choose class to edit TH / IN defaults</option><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $class===(int)$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div></form>
<form method="POST" class="card card-body"><input type="hidden" name="action" value="save_template"><input type="hidden" name="template_id" value="<?= $id ?>"><input type="hidden" name="revision" value="<?= (int)$selected['revision'] ?>">
<div class="row g-3 mb-3"><div class="col-md-6"><label for="templateName" class="form-label">Default exam name</label><input id="templateName" name="name" class="form-control" maxlength="150" required value="<?= sanitize($form['name']) ?>"></div>
<div class="col-md-3"><label for="termNo" class="form-label">Term</label><select id="termNo" name="term_no" class="form-select"><?php foreach([1=>'First',2=>'Second',3=>'Third / Final'] as $v=>$label): ?><option value="<?= $v ?>" <?= (int)$form['term_no']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label for="templateType" class="form-label">Exam type</label><select id="templateType" name="type" class="form-select"><?php foreach(['terminal','final','unit','practical'] as $v): ?><option <?= $form['type']===$v?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label for="templateSystem" class="form-label">Result system</label><select id="templateSystem" name="result_system" class="form-select"><?php foreach(['gpa'=>'GPA / Grades','percentage'=>'Marks / Percentage'] as $v=>$label): ?><option value="<?= $v ?>" <?= $form['result_system']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label for="templateCalendar" class="form-label">Print calendar</label><select id="templateCalendar" name="date_calendar" class="form-select"><?php foreach(['BS'=>'BS only','AD'=>'AD only','both'=>'BS and AD'] as $v=>$label): ?><option value="<?= $v ?>" <?= $form['date_calendar']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label for="templateFormat" class="form-label">Date format</label><select id="templateFormat" name="date_format" class="form-select"><?php foreach(['ymd'=>'YYYY-MM-DD','dmy'=>'DD-MM-YYYY','long'=>'Day Month Year'] as $v=>$label): ?><option value="<?= $v ?>" <?= $form['date_format']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div></div>
<label class="mb-2"><input type="checkbox" name="has_internal" value="1" <?= $selected['has_internal']?'checked':'' ?>> Include Internal / Practical (unit tests remain theory-only)</label>
<label class="mb-3"><input type="checkbox" name="is_active" value="1" <?= $selected['is_active']?'checked':'' ?>> Available to schools</label>
<?php if($class): ?><h4>Class subject defaults</h4><p class="small text-muted">Loaded from the universal CDC catalog unless customized here. Section-specific school rows are not used to alter automatic exam settings. Credits and marks are copied into each new exam. Existing exams remain unchanged.</p><div class="table-responsive"><table class="table"><thead><tr><th>Subject</th><?php foreach(['theory_full'=>'TH Full','theory_pass'=>'TH Pass','practical_full'=>'IN Full','practical_pass'=>'IN Pass','credit_hours'=>'Total CH','external_credit_hours'=>'TH CH','internal_credit_hours'=>'IN CH'] as $label): ?><th><?= $label ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($catalog as $mapping=>$source):if((int)$source['class_id']!==$class)continue;$r=$schemes[$mapping]??$source; ?><tr><th><?= sanitize($source['name_en']) ?><small class="d-block"><?= sanitize($source['code']) ?></small></th><?php foreach(['theory_full','theory_pass','practical_full','practical_pass','credit_hours','external_credit_hours','internal_credit_hours'] as $k): ?><td><input aria-label="<?= sanitize($source['name_en'].' '.$k) ?>" type="number" min="0" max="1000" step="0.01" class="form-control" name="schemes[<?= (int)$mapping ?>][<?= $k ?>]" value="<?= sanitize($r[$k]??'') ?>" <?= in_array($k,['internal_credit_hours','external_credit_hours'],true)?'':'required' ?>></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="alert alert-warning">Save applies only to future exams. Automatic exams cannot be customized by schools. Use Manual mode for school-specific subjects or schemes without a system default.</div><button class="btn btn-primary">Save default settings</button></form>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
