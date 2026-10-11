<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/routine_service.php';
Auth::requireRole(['super_admin','school_admin','principal','teacher','exam_officer']);
$db=getDB();$school_id=(int)Auth::getSchoolId();$actor=(int)Auth::getUserId();
$canEdit=Auth::hasRole(['super_admin','school_admin'])&&!Auth::isReadOnly()&&$school_id>0;
$q=$db->prepare('SELECT * FROM schools WHERE id=?');$q->execute([$school_id]);$school=$q->fetch()?:[];
$periods=max(1,min(10,(int)($school['routine_periods_per_day']??7)));
$q=$db->prepare('SELECT * FROM classes WHERE school_id=? AND is_active=1 ORDER BY id');$q->execute([$school_id]);$classes=$q->fetchAll();
$class_id=(int)($_GET['class_id']??$_POST['class_id']??($classes[0]['id']??0));
$class=null;foreach($classes as $candidate)if((int)$candidate['id']===$class_id)$class=$candidate;
if($class_id&&!$class)denySchoolRequest('Choose an active class in this school.',404);
$day=(int)($_GET['day']??$_POST['day']??1);if(!in_array($day,[1,2,3,4,5],true))denySchoolRequest('School days are Monday to Friday.',422);
$q=$db->prepare("SELECT id,full_name FROM users WHERE school_id=? AND role='teacher' AND status=1 ORDER BY full_name");$q->execute([$school_id]);$teachers=$q->fetchAll();
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$canEdit)denySchoolRequest('Only administrators may change the weekly routine.',403);
    $q=$db->prepare('SELECT password FROM users WHERE id=? AND school_id=? AND status=1');$q->execute([$actor,$school_id]);
    if(!is_string($_POST['password']??null)||!password_verify($_POST['password'],$q->fetchColumn()?:''))$error='Enter your current administrator password to confirm routine changes.';
    else try{
        $db->beginTransaction();
        // Serialize timetable changes per school (including MySQL).
        $db->prepare('UPDATE schools SET routine_periods_per_day=routine_periods_per_day WHERE id=?')->execute([$school_id]);
        $action=$_POST['action']??'';
        if($action==='save_settings'){
            $n=$_POST['periods']??'';if(!is_scalar($n)||!preg_match('/^(?:[1-9]|10)$/',(string)$n))throw new InvalidArgumentException('Choose 1–10 periods per day.');
            $q=$db->prepare('SELECT MAX(period_number) FROM class_routines WHERE school_id=? AND subject_id IS NOT NULL');$q->execute([$school_id]);
            if((int)$q->fetchColumn()>(int)$n)throw new InvalidArgumentException('Move occupied later periods before reducing periods per day.');
            $db->prepare('UPDATE schools SET routine_periods_per_day=?,routine_include_sunday=0 WHERE id=?')->execute([(int)$n,$school_id]);$success='Routine settings saved successfully.';
        }elseif($action==='save_requirements'){
            if(!$class||!is_array($_POST['requirements']??null))throw new InvalidArgumentException('Choose a class and enter weekly requirements.');
            saveRoutineRequirements($db,$school_id,$class_id,$_POST['requirements']);$success='Subject teachers and weekly period requirements saved successfully.';
        }elseif($action==='customize'){
            if(!$class||!is_array($_POST['grid']??null))throw new InvalidArgumentException('Choose a class and valid period entries.');
            saveRoutineDay($db,$school_id,$actor,$class_id,$day,$periods,$_POST['grid']);$success='Daily routine modified successfully; the previous version is retained in history.';
        }elseif($action==='generate'){
            $targets=isset($_POST['all_classes'])?$classes:($class?[$class]:[]);$ready=[];
            foreach($targets as $target){$subjects=routineSubjects($db,$school_id,(int)$target['id']);if(!$subjects)continue;
                if(empty($target['class_teacher_id']))throw new InvalidArgumentException('Assign a class teacher to '.$target['name'].' before automatic generation.');
                $ready[]=[$target,$subjects];
            }
            if(!$ready)throw new InvalidArgumentException('Assign visible class subjects and teachers before generating.');
            foreach($ready as [$target,$subjects])routineArchive($db,$school_id,$actor,(int)$target['id']);
            $insert=$db->prepare('INSERT INTO class_routines(class_id,day_of_week,period_number,subject_id,teacher_id,school_id) VALUES(?,?,?,?,?,?)');
            foreach($ready as [$target,$subjects]){
                $built=buildClassRoutine($db,$school_id,$target,$subjects,[1,2,3,4,5],$periods);
                if($built['unplaced'])throw new InvalidArgumentException($target['name'].': '.$built['unplaced'].' periods could not fit without teacher conflicts. Adjust assignments or use manual scheduling. No schedules were replaced.');
                foreach($built['grid'] as $d=>$slots)foreach($slots as $p=>$slot)$insert->execute([$target['id'],$d,$p,$slot['subject_id'],$slot['teacher_id'],$school_id]);
            }
            $success='Weekly routine generated successfully for '.count($ready).' classes; every first period uses the class teacher.';
        }else throw new InvalidArgumentException('Choose a valid routine action.');
        Auth::logActivity($actor,'Update weekly routine','classes',$class_id?:null);$db->commit();
        redirect(APP_URL.'/modules/classes/routine.php?'.http_build_query(['class_id'=>$class_id,'day'=>$day]),$success,'success');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof InvalidArgumentException)$error=$e->getMessage();else{error_log('Routine: '.$e->getMessage());$error='The routine could not be saved. Please try again; existing schedules are unchanged.';}}
}
$subjects=$class?routineSubjects($db,$school_id,$class_id):[];$unique=[];foreach($subjects as $s)$unique[$s['subject_id']]=$s;
$q=$db->prepare('SELECT * FROM class_routines WHERE school_id=? AND class_id=? AND day_of_week BETWEEN 1 AND 5 ORDER BY day_of_week,period_number');$q->execute([$school_id,$class_id]);$grid=[];foreach($q as $slot)$grid[$slot['day_of_week']][$slot['period_number']]=$slot;
$teacherNames=array_column($teachers,'full_name','id');$days=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
$pageTitle='Weekly Class Routine';$activePage='routine';ob_start();?>
<div class="d-flex justify-content-between no-print"><h2>Weekly Class Routine</h2><button class="btn btn-primary" onclick="window.print()">Print routine</button></div>
<p class="text-muted">Monday–Friday full school days. Saturday and Sunday are holidays. Period 1 belongs to the class teacher every day.</p>
<div class="d-none d-print-block text-center mb-3"><h3><?= sanitize($school['name_en']??APP_NAME) ?></h3><p><?= sanitize($school['address']??'') ?></p></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<form method="GET" class="row g-3 mb-4 no-print"><div class="col-md-6"><label for="routineClass" class="form-label">Class</label><select id="routineClass" name="class_id" class="form-select" onchange="this.form.submit()"><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $class_id===(int)$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="routineDay">Day to modify</label><select id="routineDay" name="day" class="form-select" onchange="this.form.submit()"><?php foreach($days as $d=>$name): ?><option value="<?= $d ?>" <?= $day===$d?'selected':'' ?>><?= $name ?></option><?php endforeach; ?></select></div></form>
<?php if($class): ?>
<div class="card mb-4"><div class="card-header"><h4><?= sanitize($class['name']) ?> — weekly timetable</h4><p class="mb-0">Class teacher: <?= sanitize($teacherNames[(int)($class['class_teacher_id']??0)]??$class['class_teacher_name']??'Not assigned') ?></p></div><div class="table-responsive"><table class="table table-bordered mb-0"><thead><tr><th>Day</th><?php for($p=1;$p<=$periods;$p++): ?><th>Period <?= $p ?></th><?php endfor; ?></tr></thead><tbody><?php foreach($days as $d=>$name): ?><tr><th><?= $name ?></th><?php for($p=1;$p<=$periods;$p++): $cell=$grid[$d][$p]??[]; ?><td><?= sanitize($unique[$cell['subject_id']??0]['name_en']??'Free') ?><br><small><?= sanitize($teacherNames[$cell['teacher_id']??0]??'') ?></small></td><?php endfor; ?></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php if($canEdit&&$class): ?>
<details class="card card-body mb-3 no-print" open><summary class="fw-bold">Subject teachers and total periods per week</summary><p class="small text-muted mt-2">Loaded from class subject assignments. Save missing or changed values here. Blank weekly values initially use rounded credits as a suggestion; saving periods does not alter TH/IN grading credits. For section-specific mappings, the class timetable uses the largest weekly requirement per subject.</p>
<form method="POST"><input type="hidden" name="action" value="save_requirements"><input type="hidden" name="class_id" value="<?= $class_id ?>"><div class="table-responsive"><table class="table"><thead><tr><th>Subject / section</th><th>Total periods per week</th><th>Assigned teachers (multiple selection supported)</th></tr></thead><tbody><?php foreach($subjects as $s): ?><tr><td><?= sanitize($s['name_en']) ?><?= $s['is_optional']?' (Optional)':'' ?><br><small><?= sanitize($s['section_name']??'All sections') ?></small></td><td><input class="form-control" aria-label="<?= sanitize($s['name_en']) ?> weekly periods" name="requirements[<?= $s['id'] ?>][weekly]" type="number" min="0" max="50" value="<?= routineWeeklyPeriods($s) ?>" required></td><td><select multiple class="form-select" aria-label="<?= sanitize($s['name_en']) ?> teachers" name="requirements[<?= $s['id'] ?>][teachers][]"><?php foreach($teachers as $t): ?><option value="<?= $t['id'] ?>" <?= teacherAssignmentContains($s['teacher_id'],(int)$t['id'])?'selected':'' ?>><?= sanitize($t['full_name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><label>Current administrator password<input class="form-control mb-2" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-primary">Save weekly requirements</button></form></details>
<details class="card card-body mb-3 no-print"><summary class="fw-bold">Automatically create weekly routine</summary><form method="POST" class="mt-3" onsubmit="return confirm('Replace selected routines? Previous schedules will be retained in history. Teacher conflicts or insufficient periods cancel the whole operation.')"><input type="hidden" name="action" value="generate"><input type="hidden" name="class_id" value="<?= $class_id ?>"><label class="d-block mb-2"><input type="checkbox" name="all_classes" value="1"> Generate all active classes (otherwise only <?= sanitize($class['name']) ?>)</label><label>Current administrator password<input class="form-control mb-2" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-success">Generate Monday–Friday routine</button></form></details>
<details class="card card-body mb-3 no-print"><summary class="fw-bold">Manually modify <?= $days[$day] ?> periods</summary><form method="POST" class="mt-3"><input type="hidden" name="action" value="customize"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="day" value="<?= $day ?>"><div class="table-responsive"><table class="table"><thead><tr><th>Period</th><th>Subject</th><th>Teacher</th></tr></thead><tbody><?php for($p=1;$p<=$periods;$p++):$cell=$grid[$day][$p]??[]; ?><tr><th><?= $p ?><?= $p===1?' (Class teacher)':'' ?></th><td><select class="form-select" name="grid[<?= $p ?>][subject]"><option value="">Free period</option><?php foreach($unique as $s): ?><option value="<?= $s['subject_id'] ?>" <?= ($cell['subject_id']??0)==$s['subject_id']?'selected':'' ?>><?= sanitize($s['name_en']) ?></option><?php endforeach; ?></select></td><td><select class="form-select" name="grid[<?= $p ?>][teacher]"><option value="">No teacher</option><?php foreach($teachers as $t): ?><option value="<?= $t['id'] ?>" <?= ($cell['teacher_id']??0)==$t['id']?'selected':'' ?>><?= sanitize($t['full_name']) ?></option><?php endforeach; ?></select></td></tr><?php endfor; ?></tbody></table></div><p class="small">Only assigned subject teachers may be selected. First period requires the class teacher. Weekly totals and other-class conflicts are validated when saving.</p><label>Current administrator password<input class="form-control mb-2" type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary">Save <?= $days[$day] ?> routine</button></form></details>
<details class="card card-body no-print"><summary class="fw-bold">Routine settings</summary><form method="POST" class="mt-3"><input type="hidden" name="action" value="save_settings"><input type="hidden" name="class_id" value="<?= $class_id ?>"><label>Periods per day<input class="form-control mb-2" type="number" name="periods" min="1" max="10" value="<?= $periods ?>" required></label><p>Saturday and Sunday are holidays; Monday–Friday are school days.</p><label>Current administrator password<input class="form-control mb-2" type="password" name="password" required autocomplete="current-password"></label><button class="btn btn-primary">Save routine settings</button></form></details>
<?php endif; ?>
<style>@media print{@page{size:A4 landscape;margin:10mm}details,.no-print{display:none!important}table{font-size:9pt}tr{break-inside:avoid}}</style>
<div class="d-none d-print-block text-end"><?= printSignature($school['principal_name']??'','Head Teacher',$school['principal_signature']??null) ?></div>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
