<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/symbol_service.php';
Auth::requireRole(['super_admin','school_admin','principal','exam_officer','teacher']);
$db=getDB();$school=(int)Auth::getSchoolId();$examId=(int)($_GET['exam_id']??0);$class=(int)($_GET['class_id']??0);$error='';
$exam=schoolRecord($db,'exams',$examId,$school);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{$count=saveExamSymbols($db,$school,$examId,$class,$_POST,(int)Auth::getUserId());Auth::logActivity(Auth::getUserId(),"Saved $count exam symbol numbers",'exams',$examId);redirect(schoolRouteUrl('modules/exams/symbols.php') . "?exam_id=$examId&class_id=$class","Saved $count symbol numbers successfully. Student registration roll numbers are unchanged.");}
 catch(InvalidArgumentException $e){$error=$e->getMessage();}
 catch(PDOException $e){error_log($e->getMessage());$error='Symbol numbers could not be saved. Please try again.';}
}
$q=$db->prepare('SELECT id,name FROM classes WHERE school_id=? AND is_active=1 ORDER BY id');$q->execute([$school]);$classes=$q->fetchAll();
$students=$class?examSymbolStudents($db,$school,$exam,$class):[];
$q=$db->prepare('SELECT * FROM exams WHERE school_id=? AND id<>? ORDER BY id DESC');$q->execute([$school,$examId]);
$previous=array_values(array_filter($q->fetchAll(),fn($e)=>examIsEarlier($e,$exam)));
$canWrite=Auth::hasRole(['super_admin','school_admin','exam_officer'])&&!Auth::isReadOnly()&&!$exam['is_locked']&&!$exam['is_published'];
$pageTitle='Exam Symbol Numbers';$activePage='exams';ob_start();?>
<div class="d-flex justify-content-between mb-3"><h2>Symbol Numbers — <?= sanitize($exam['name']) ?></h2><a class="btn btn-secondary" href="<?= schoolRouteUrl('modules/exams/view.php') ?>?id=<?= $examId ?>">Back to Exam</a></div>
<?php if($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<div class="alert alert-info">Registration Roll No. and exam Symbol No. are separate. Symbols are unique within this exam and may differ in another exam.</div>
<form method="GET" class="card card-body mb-3"><input type="hidden" name="exam_id" value="<?= $examId ?>"><label for="class_id">Select Class</label><select class="form-select" id="class_id" name="class_id" onchange="this.form.submit()"><option value="">Choose Class</option><?php foreach($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $class==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></form>
<?php if($class): ?>
<?php if(!$canWrite): ?><div class="alert alert-warning">These symbols are read-only. An authorised exam administrator can change symbols in an open exam.</div><?php endif; ?>
<form method="POST" class="card card-body">
 <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRFToken() ?>">
 <fieldset <?= $canWrite?'':'disabled' ?>>
 <div class="row g-3 mb-3">
  <div class="col-md-4"><label for="symbol_mode">Assign symbols using</label><select class="form-select" name="symbol_mode" id="symbol_mode" onchange="toggleSymbolMode()"><option value="manual">Manual entries</option><option value="alphabetical">Alphabetical, serial numbers</option><option value="previous">Load previous exam symbols</option><?php if(array_filter($students,fn($s)=>trim((string)($s['exam_roll_number']??''))!=='')): ?><option value="legacy">Copy legacy student symbols to this exam</option><?php endif; ?></select></div>
  <div class="col-md-4" id="first_symbol_group" hidden><label for="first_symbol">First student's symbol</label><input class="form-control" id="first_symbol" name="first_symbol" inputmode="numeric" placeholder="3001"><small>Sorted by first name, last name, then student ID.</small></div>
  <div class="col-md-4" id="previous_symbol_group" hidden><label for="previous_exam_id">Previous Exam</label><select class="form-select" id="previous_exam_id" name="previous_exam_id"><option value="">Choose Exam</option><?php foreach($previous as $e): ?><option value="<?= $e['id'] ?>"><?= sanitize($e['name']) ?></option><?php endforeach; ?></select><small>Only matched students are copied; unmatched symbols remain unchanged.</small></div>
 </div>
 <label class="form-check mb-3"><input type="checkbox" class="form-check-input" name="confirm_overwrite" value="1"> Confirm replacement of any existing symbols in this class.</label>
 <label for="symbol_search" class="form-label">Find student, roll or symbol</label><input type="search" id="symbol_search" class="form-control mb-3" placeholder="Filter the list">
 <div class="table-responsive"><table class="table table-striped"><thead><tr><th>Name (alphabetical)</th><th>Registration Roll No.</th><th>Symbol No.</th></tr></thead><tbody><?php foreach($students as $s): ?><tr class="symbol-row"><td><?= sanitize($s['first_name_en'].' '.$s['last_name_en']) ?></td><td><?= sanitize($s['roll_number']) ?></td><td><input class="form-control symbol-input" name="symbols[<?= $s['id'] ?>]" maxlength="100" value="<?= sanitize($_POST['symbols'][$s['id']]??$s['symbol_no']??'') ?>" aria-label="Symbol for <?= sanitize($s['first_name_en']) ?>"></td></tr><?php endforeach; ?></tbody></table></div>
 <div class="form-text mb-3">Blank manual entries leave existing symbols unchanged. Generating or copying does not change marks. Legacy values are copied only to this exam when explicitly selected; they are never used automatically on cards.</div><button class="btn btn-primary" type="submit">Save Symbol Numbers</button>
 </fieldset>
</form>
<a class="btn btn-outline-primary mt-3" href="<?= schoolRouteUrl('modules/exams/admit_cards.php') ?>?exam_id=<?= $examId ?>&class_id=<?= $class ?>">Select & Print Admit Cards</a>
<script>function toggleSymbolMode(){const mode=document.getElementById('symbol_mode').value;document.getElementById('first_symbol_group').hidden=mode!=='alphabetical';document.getElementById('previous_symbol_group').hidden=mode!=='previous';document.querySelectorAll('.symbol-input').forEach(i=>i.readOnly=mode!=='manual');}document.getElementById('symbol_search').addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('.symbol-row').forEach(r=>r.hidden=!(r.textContent+' '+r.querySelector('input').value).toLowerCase().includes(q));});</script>
<?php endif; ?><?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
