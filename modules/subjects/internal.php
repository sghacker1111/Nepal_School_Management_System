<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/assessment_service.php';
require_once __DIR__.'/../../includes/exam_mapping_service.php';
Auth::requireRole(['super_admin','school_admin','exam_officer']);
$db=getDB();$school=(int)Auth::getSchoolId();$mapping=(int)($_GET['mapping']??0);$examId=(int)($_GET['exam']??0);$error='';
if(!$examId&&!Auth::hasRole(['super_admin','school_admin']))denySchoolRequest('Only administrators can change future class defaults.');
if($examId){$exam=schoolRecord($db,'exams',$examId,$school);$q=$db->prepare('SELECT * FROM exam_subjects WHERE id=? AND exam_id=?');$q->execute([$mapping,$examId]);$row=$q->fetch();}
else{$exam=['type'=>'terminal'];$row=schoolRecord($db,'class_subjects',$mapping,$school);$row['practical_full']=$row['in_full_marks'];}
if(!$row)denySchoolRequest('Subject configuration not found.',404);
if($examId&&($exam['creation_mode']??'manual')==='automatic')denySchoolRequest('Automatic exam IN settings are read-only. Create a manual exam to customize defaults.');
if(($row['assessment_method']??'')==='THEMATIC_CONTINUOUS'||($examId&&(empty($exam['has_internal'])||$exam['type']==='unit')))denySchoolRequest('This assessment has no separate IN components. Enable IN in a non-unit examination first.',422);
$subject=schoolRecord($db,'subjects',(int)$row['subject_id'],$school);
$kinds=$examId?[in_array($exam['type'],['final','annual'],true)?'annual':'terminal']:['terminal','annual'];
$profiles=[];foreach($kinds as $kind)$profiles[$kind]=cdc2083InternalComponents($row,['type'=>$kind==='annual'?'final':'terminal']);
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')try{
    saveInternalAssessment($db,$school,(int)Auth::getUserId(),Auth::getRole(),$mapping,$examId?:null,$_POST);
    redirect(APP_URL.'/modules/subjects/internal.php?'.http_build_query(['mapping'=>$mapping,'exam'=>$examId]),'Internal assessment saved successfully. '.($examId?'Existing entered scores were preserved.':'Only future exam mappings use these defaults.'));
}catch(InvalidArgumentException $e){$error=$e->getMessage();}catch(PDOException $e){error_log($e->getMessage());$error='Internal assessment could not be saved. Try again.';}
$pageTitle='Internal assessment';$activePage=$examId?'exams':'subjects';ob_start();
?>
<h2>Internal Assessment (IN) · <?= sanitize($subject['name_en']) ?></h2>
<p class="text-muted"><?= $examId?'Exam-specific configuration':'Class subject defaults for future exams' ?>. Component keys identify saved scores; labels and maxima may be changed.</p>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<form method="post" class="card card-body"><input type="hidden" name="revision" value="<?= assessmentRevision($row,(bool)$examId) ?>">
<div class="row mb-3"><label class="col-md-6">IN full marks<input class="form-control" type="number" step="0.01" min="0.01" max="1000" required name="total" value="<?= $row['practical_full'] ?>"></label><label class="col-md-6">IN pass marks<input class="form-control" type="number" step="0.01" min="0" required name="pass" value="<?= $row['practical_pass']??$row['in_pass_marks']??0 ?>"></label></div>
<?php foreach($profiles as $kind=>$parts): ?><h4><?= ucfirst($kind) ?> components</h4><div class="table-responsive"><table class="table"><thead><tr><th>Stable key</th><th>Displayed field name</th><th>Full marks</th><th></th></tr></thead><tbody data-kind="<?= $kind ?>">
<?php $i=0;foreach($parts as $key=>$part): ?><tr><td><input class="form-control" name="components[<?= $kind ?>][<?= $i ?>][key]" value="<?= sanitize($key) ?>" required></td><td><input class="form-control" name="components[<?= $kind ?>][<?= $i ?>][label]" value="<?= sanitize($part['label']) ?>" required maxlength="120"></td><td><input class="form-control" type="number" step="0.01" min="0" name="components[<?= $kind ?>][<?= $i++ ?>][max]" value="<?= $part['max'] ?>" required></td><td><button class="btn btn-outline-danger" type="button" onclick="this.closest('tr').remove()">Remove</button></td></tr><?php endforeach; ?></tbody></table></div><button class="btn btn-outline-secondary mb-4" type="button" data-add-component="<?= $kind ?>">Add field</button><?php endforeach; ?>
<div class="alert alert-warning"><strong>Warning:</strong> These are school-customized allocations, not a change to CDC grading bands. Changing exam limits can affect results. Saved scores cannot exceed new limits, and used fields cannot be removed. Class defaults do not overwrite existing exams.</div>
<label class="mb-3"><input type="checkbox" name="confirm_assessment_change" value="1" required> I have reviewed the allocations and confirm this modification.</label><button class="btn btn-primary" type="submit">Confirm & save IN configuration</button></form>
<script>document.querySelectorAll('[data-add-component]').forEach(b=>b.onclick=()=>{const kind=b.dataset.addComponent,body=document.querySelector('tbody[data-kind="'+kind+'"]'),i=Date.now();const tr=document.createElement('tr');tr.innerHTML='<td><input class="form-control" required name="components['+kind+']['+i+'][key]" placeholder="custom_field"></td><td><input class="form-control" required name="components['+kind+']['+i+'][label]" maxlength="120"></td><td><input class="form-control" required type="number" min="0" step="0.01" name="components['+kind+']['+i+'][max]"></td><td><button type="button" class="btn btn-outline-danger">Remove</button></td>';tr.querySelector('button').onclick=()=>tr.remove();body.append(tr);});</script>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
