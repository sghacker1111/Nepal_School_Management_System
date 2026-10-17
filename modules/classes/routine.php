<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/routine_service.php';
require_once __DIR__.'/../../includes/routine_profiles.php';
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
try{$profile_id=routineIdentifier($_GET['profile_id']??0);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}
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
            $activeLayout=routineActiveLayout($db,$school_id,$class_id);
            if($activeLayout){
                $week=routineActiveGrid($db,$school_id,$class_id);$week[$day]=[];
                foreach($_POST['grid'] as $p=>$cell)$week[$day][$p]=['subject_id'=>$cell['subject']??null,'teacher_id'=>$cell['teacher']??null];
                routinePublishWeek($db,$school_id,$class_id,$actor,$activeLayout,$week);
            }else saveRoutineDay($db,$school_id,$actor,$class_id,$day,$periods,$_POST['grid']);
            $success='Daily routine modified successfully; the previous version is retained in history.';
        }elseif($action==='save_profile'){
            if(!$class)throw new InvalidArgumentException('Choose an active class.');
            $input=$_POST;
            if(!empty($_POST['save_as_new']))$input['profile_id']=0;
            $profile_id=routineSaveProfile($db,$school_id,$class_id,$actor,$input);
            $saved=routineProfile($db,$school_id,$class_id,$profile_id);
            $intent=$_POST['intent']??'save';
            if(!in_array($intent,['save','activate'],true))throw new InvalidArgumentException('Choose save or activate.');
            if($intent==='activate')routinePublishWeek($db,$school_id,$class_id,$actor,$saved['payload']['layout'],$saved['payload']['grid'],$profile_id);
            $success=$intent==='activate'?'Routine profile saved and activated successfully. Previous routine retained in history.':'Routine profile saved successfully. The active timetable is unchanged.';
        }elseif($action==='activate_profile'){
            $profile_id=routineIdentifier($_POST['profile_id']??0);$saved=routineProfile($db,$school_id,$class_id,$profile_id);
            if(routineIdentifier($_POST['revision']??0)!==(int)$saved['revision'])throw new InvalidArgumentException('The profile changed. Reload it before activation.');
            routinePublishWeek($db,$school_id,$class_id,$actor,$saved['payload']['layout'],$saved['payload']['grid'],$profile_id);
            $success='Routine profile activated successfully. Previous routine retained in history.';
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
                $activeLayout=routineActiveLayout($db,$school_id,(int)$target['id']);
                $built=buildClassRoutine($db,$school_id,$target,$subjects,[1,2,3,4,5],$activeLayout?max(array_column($activeLayout,'count')):$periods,$activeLayout);
                if($built['unplaced'])throw new InvalidArgumentException($target['name'].': '.$built['unplaced'].' periods could not fit without teacher conflicts. Adjust assignments or use manual scheduling. No schedules were replaced.');
                if($activeLayout)routinePublishWeek($db,$school_id,(int)$target['id'],$actor,$activeLayout,$built['grid']);
                else foreach($built['grid'] as $d=>$slots)foreach($slots as $p=>$slot)$insert->execute([$target['id'],$d,$p,$slot['subject_id'],$slot['teacher_id'],$school_id]);
            }
            $success='Weekly routine generated successfully for '.count($ready).' classes; every first period uses the class teacher.';
        }else throw new InvalidArgumentException('Choose a valid routine action.');
        Auth::logActivity($actor,'Update weekly routine','classes',$class_id?:null);$db->commit();
        redirect(schoolRouteUrl('modules/classes/routine.php') . '?'.http_build_query(['class_id'=>$class_id,'day'=>$day,'profile_id'=>$profile_id]),$success,'success');
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof InvalidArgumentException)$error=$e->getMessage();else{error_log('Routine: '.$e->getMessage());$error='The routine could not be saved. Please try again; existing schedules are unchanged.';}}
}
$subjects=$class?routineSubjects($db,$school_id,$class_id):[];$unique=[];$subjectTeachers=[];
foreach($subjects as $s){$unique[$s['subject_id']]=$s;$ids=!empty($class['is_grading_teaching'])?[(int)$class['class_teacher_id']]:array_map('intval',explode(',',(string)$s['teacher_id']));$subjectTeachers[$s['subject_id']]=array_values(array_unique(array_merge($subjectTeachers[$s['subject_id']]??[],$ids)));}
$q=$db->prepare('SELECT id,full_name FROM users WHERE school_id=?');$q->execute([$school_id]);$teacherNames=array_column($q->fetchAll(),'full_name','id');
$q=$db->prepare('SELECT id,name_en FROM subjects WHERE school_id=?');$q->execute([$school_id]);$subjectNames=array_column($q->fetchAll(),'name_en','id');
$grid=routineActiveGrid($db,$school_id,$class_id);$activeLayout=routineActiveLayout($db,$school_id,$class_id);$hasLayout=(bool)$activeLayout;
$layout=$activeLayout??routineDefaultLayout($periods);$selectedProfile=null;
if($error)$profile_id=(int)($_GET['profile_id']??0);
if($profile_id){try{$selectedProfile=routineProfile($db,$school_id,$class_id,$profile_id);$layout=$selectedProfile['payload']['layout'];$grid=$selectedProfile['payload']['grid'];$hasLayout=true;}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),404);}}
$editorName=$selectedProfile['name']??'';$editorMode=$selectedProfile['mode']??'manual';
$editorLayout=$layout;$editorGrid=$grid;
if($error&&($_POST['action']??'')==='save_profile'){
    if(is_string($_POST['name']??null))$editorName=$_POST['name'];
    if(in_array($_POST['mode']??'', ['manual','automatic'],true))$editorMode=$_POST['mode'];
    $recovered=routineRecoverEditorInput($_POST,$layout,$grid,array_keys($unique),array_column($teachers,'id'));
    $editorLayout=$recovered['layout'];$editorGrid=$recovered['grid'];
}
$q=$db->prepare('SELECT id,name,revision FROM routine_profiles WHERE school_id=? AND class_id=? ORDER BY name,id');$q->execute([$school_id,$class_id]);$profiles=$q->fetchAll();
$usedTeachers=[];foreach($grid as $slots)foreach($slots as $cell)if(!empty($cell['teacher_id']))$usedTeachers[(int)$cell['teacher_id']]=(int)$cell['teacher_id'];
$displayPeriods=max(array_column($layout,'count'));$days=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday'];
$pageTitle='Weekly Class Routine';$activePage='routine';ob_start();?>
<div class="d-flex justify-content-between no-print"><h2>Weekly Class Routine</h2><button class="btn btn-primary" data-erp-click="classes-routine-action-0" data-erp-click-args="<?= schoolPageActionArgs("classes-routine",[]) ?>">Print routine</button></div>
<p class="text-muted">Monday–Friday full school days. Saturday and Sunday are holidays. Period 1 belongs to the class teacher every day.</p>
<div class="d-none d-print-block text-center mb-3"><h3><?= sanitize($school['name_en']??APP_NAME) ?></h3><p><?= sanitize($school['address']??'') ?></p></div>
<?php if($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<form method="GET" class="row g-3 mb-4 no-print"><div class="col-md-4"><label for="routineClass" class="form-label">Class</label><select id="routineClass" name="class_id" class="form-select" data-erp-change="classes-routine-action-1" data-erp-change-args="<?= schoolPageActionArgs("classes-routine",[]) ?>"><?php foreach($classes as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $class_id===(int)$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-6"><label for="routineProfile" class="form-label">Load weekly routine profile</label><select id="routineProfile" name="profile_id" class="form-select" data-erp-change="classes-routine-action-2" data-erp-change-args="<?= schoolPageActionArgs("classes-routine",[]) ?>"><option value="0">Current active timetable<?= $canEdit?' / new profile':'' ?></option><?php foreach($profiles as $profile): ?><option value="<?= (int)$profile['id'] ?>" <?= $profile_id===(int)$profile['id']?'selected':'' ?>><?= sanitize($profile['name']) ?> (v<?= (int)$profile['revision'] ?>)</option><?php endforeach; ?></select></div><div class="col-md-2 align-self-end"><button class="btn btn-outline-primary">Load</button></div></form>
<?php if($class):include __DIR__.'/../../includes/routine_timetable.php';endif; ?>
<?php if($canEdit&&$class): ?>
<?php if($selectedProfile): ?><form method="POST" class="no-print d-flex gap-2 align-items-end mb-3" data-erp-submit="classes-routine-action-3" data-erp-submit-args="<?= schoolPageActionArgs("classes-routine",[]) ?>"><input type="hidden" name="action" value="activate_profile"><input type="hidden" name="class_id" value="<?= $class_id ?>"><input type="hidden" name="profile_id" value="<?= $profile_id ?>"><input type="hidden" name="revision" value="<?= (int)$selectedProfile['revision'] ?>"><label>Current administrator password<input name="password" type="password" class="form-control" autocomplete="current-password" required></label><button class="btn btn-success">Activate saved profile</button><a class="btn btn-outline-secondary" href="?class_id=<?= $class_id ?>">New profile from active routine</a></form><?php endif; ?>
<?php include __DIR__.'/../../includes/routine_profile_editor.php'; ?>
<details class="card card-body mb-3 no-print" open><summary class="fw-bold">Subject teachers and total periods per week</summary><p class="small text-muted mt-2">Loaded from class subject assignments. Save missing or changed values here. Blank weekly values initially use rounded credits as a suggestion; saving periods does not alter TH/IN grading credits. For section-specific mappings, the class timetable uses the largest weekly requirement per subject.</p>
<form method="POST"><input type="hidden" name="action" value="save_requirements"><input type="hidden" name="class_id" value="<?= $class_id ?>"><div class="table-responsive"><table class="table"><thead><tr><th>Subject / section</th><th>Total periods per week</th><th>Assigned teachers (multiple selection supported)</th></tr></thead><tbody><?php foreach($subjects as $s): ?><tr><td><?= sanitize($s['name_en']) ?><?= $s['is_optional']?' (Optional)':'' ?><br><small><?= sanitize($s['section_name']??'All sections') ?></small></td><td><input class="form-control" aria-label="<?= sanitize($s['name_en']) ?> weekly periods" name="requirements[<?= $s['id'] ?>][weekly]" type="number" min="0" max="50" value="<?= routineWeeklyPeriods($s) ?>" required></td><td><select multiple class="form-select" aria-label="<?= sanitize($s['name_en']) ?> teachers" name="requirements[<?= $s['id'] ?>][teachers][]"><?php foreach($teachers as $t): ?><option value="<?= $t['id'] ?>" <?= teacherAssignmentContains($s['teacher_id'],(int)$t['id'])?'selected':'' ?>><?= sanitize($t['full_name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div><label>Current administrator password<input class="form-control mb-2" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-primary">Save weekly requirements</button></form></details>
<details class="card card-body mb-3 no-print"><summary class="fw-bold">Automatically create weekly routine</summary><form method="POST" class="mt-3" data-erp-submit="classes-routine-action-4" data-erp-submit-args="<?= schoolPageActionArgs("classes-routine",[]) ?>"><input type="hidden" name="action" value="generate"><input type="hidden" name="class_id" value="<?= $class_id ?>"><label class="d-block mb-2"><input type="checkbox" name="all_classes" value="1"> Generate all active classes (otherwise only <?= sanitize($class['name']) ?>)</label><label>Current administrator password<input class="form-control mb-2" type="password" name="password" autocomplete="current-password" required></label><button class="btn btn-success">Generate Monday–Friday routine</button></form></details>
<?php endif; ?>
<style>.routine-timetable td{min-width:105px}.routine-timetable abbr{text-decoration:none}@media print{@page{size:A4 landscape;margin:10mm}details,.no-print{display:none!important}table{font-size:9pt}tr{break-inside:avoid}.routine-timetable td{min-width:0}}</style>
<div class="d-none d-print-block text-end"><?= printSignature($school['principal_name']??'','Head Teacher',$school['principal_signature']??null) ?></div>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
