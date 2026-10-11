<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/attendance_service.php';
Auth::requireRole(['super_admin','school_admin','principal','teacher']);
Auth::requireAccess('attendance');
$db=getDB();$school=(int)Auth::getSchoolId();$actor=(int)Auth::getUserId();$role=Auth::getRole();
$classId=(int)($_GET['class']??0);$sectionId=(int)($_GET['section']??0);
$today=attendanceToday();$message='';$messageType='success';
try{
    $search=studentSearchTerm($_GET['search']??'');
    $period=attendancePeriod($_GET);
    $selectedClass=$classId?attendanceClass($db,$school,$actor,$role,$classId):null;
    $roster=$classId?attendanceStudents($db,$school,$classId,$sectionId,$search,$period):[];
}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
$window=attendanceWindow($period,(int)($_GET['day_page']??1));
$studentPages=max(1,(int)ceil(count($roster)/10));$studentPage=max(1,min($studentPages,(int)($_GET['student_page']??1)));
$students=array_slice($roster,($studentPage-1)*10,10);
$filterQuery=['view'=>$period['view'],'calendar'=>$period['calendar'],'class'=>$classId,'section'=>$sectionId,'search'=>$search,'day_page'=>$window['page'],'student_page'=>$studentPage];
if($period['view']==='daily')$filterQuery['date_input']=$period['dateInput'];
elseif($period['view']==='range'){$filterQuery['range_start']=$period['fromInput'];$filterQuery['range_end']=$period['toInput'];}
else{$filterQuery['year']=$period['year'];if($period['view']==='monthly')$filterQuery['month']=$period['month'];}
$canEdit=!Auth::isReadOnly()&&in_array($role,['super_admin','school_admin','teacher'],true);
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['save_attendance'])){
    if(!$canEdit)denySchoolRequest('Your role can view attendance but cannot save changes.',403);
    try{
        if((int)($_POST['class_id']??0)!==$classId||(int)($_POST['section_id']??0)!==$sectionId)throw new InvalidArgumentException('The submitted attendance class or section does not match this page.');
        $saved=saveStudentAttendance($db,$school,$actor,$role,$_POST,$period,$students,$window['dates']);
        Auth::logActivity($actor,'Save '.$saved.' attendance changes','attendances');
        redirect(attendanceUrl($filterQuery),$saved?'Attendance saved successfully ('.$saved.' records).':'No attendance changes were needed.');
    }catch(InvalidArgumentException $e){$message=$e->getMessage();$messageType='danger';}
    catch(PDOException $e){error_log($e->getMessage());$message='Attendance could not be saved. Please retry; no partial changes were saved.';$messageType='danger';}
}
$q=$db->prepare('SELECT id,name,class_teacher_id FROM classes WHERE school_id=? AND is_active=1 ORDER BY id');$q->execute([$school]);$classes=$q->fetchAll();
if($role==='teacher')$classes=array_values(array_filter($classes,static fn($c)=>(int)$c['class_teacher_id']===$actor));
$sections=[];if($classId){$q=$db->prepare('SELECT id,name FROM sections WHERE school_id=? AND class_id=? ORDER BY name');$q->execute([$school,$classId]);$sections=$q->fetchAll();}
$marks=[];$summary=[];$hasExisting=false;$expectedCells=0;
if($students){
    $ids=array_column($students,'id');$placeholders=implode(',',array_fill(0,count($ids),'?'));
    $q=$db->prepare('SELECT student_id,date_ad,status FROM attendances WHERE student_id IN ('.$placeholders.') AND date_ad BETWEEN ? AND ?');
    $q->execute([...$ids,$period['start'],$period['end']]);
    foreach($q as $row){$summary[$row['student_id']][$row['status']]=($summary[$row['student_id']][$row['status']]??0)+1;$marks[$row['student_id']][$row['date_ad']]=$row['status'];}
    foreach($students as $student)foreach($window['dates'] as $date){
        if($canEdit&&$student['status']==='active'&&$date<=$today)$expectedCells++;
        if(isset($marks[$student['id']][$date]))$hasExisting=true;
    }
}
$pageTitle='Student Attendance';$activePage='attendance';
$pageStyles=['/assets/css/attendance.css'];
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print"><h2><i class="fas fa-calendar-check"></i> Student Attendance</h2><button class="btn btn-outline-secondary" onclick="window.print()">Print saved sheet</button></div>
<?php if($message): ?><div data-notify class="alert alert-<?= $messageType ?>"><?= sanitize($message) ?></div><?php endif; ?>
<div class="card mb-4 no-print"><div class="card-body">
<form method="GET" class="row g-3" id="attendanceFilters">
    <div class="col-md-2"><label for="attendanceView" class="form-label">View</label><select name="view" id="attendanceView" class="form-select" onchange="this.form.submit()">
    <?php foreach(['daily'=>'Daily','monthly'=>'Monthly','yearly'=>'Yearly','range'=>'Date range'] as $value=>$label): ?><option value="<?= $value ?>" <?= $period['view']===$value?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label for="attendanceCalendar" class="form-label">Calendar</label><select name="calendar" id="attendanceCalendar" class="form-select" data-previous="<?= $period['calendar'] ?>" onchange="changeAttendanceCalendar(this)"><option value="AD" <?= $period['calendar']==='AD'?'selected':'' ?>>AD (English)</option><option value="BS" <?= $period['calendar']==='BS'?'selected':'' ?>>BS (नेपाली)</option></select></div>
    <?php if($period['view']==='daily'): ?><div class="col-md-3"><label for="attendanceDate" class="form-label">Date (<?= $period['calendar'] ?>)</label><input id="attendanceDate" name="date_input" data-calendar="<?= $period['calendar'] ?>" class="form-control" type="<?= $period['calendar']==='AD'?'date':'text' ?>" value="<?= sanitize($period['dateInput']) ?>" placeholder="YYYY-MM-DD" required></div><?php endif; ?>
    <?php if(in_array($period['view'],['monthly','yearly'],true)): ?>
    <div class="col-md-2"><label for="attendanceYear" class="form-label">Year (<?= $period['calendar'] ?>)</label><input name="year" id="attendanceYear" type="number" class="form-control" min="<?= $period['calendar']==='BS'?2000:1900 ?>" max="<?= $period['calendar']==='BS'?2090:2100 ?>" value="<?= $period['year'] ?>" required></div>
    <?php if($period['view']==='monthly'): ?><div class="col-md-3"><label for="attendanceMonth" class="form-label">Month (<?= $period['calendar'] ?>)</label><select name="month" id="attendanceMonth" class="form-select"><?php foreach(attendanceMonths($period['calendar']) as $i=>$month): ?><option value="<?= $i+1 ?>" <?= $period['month']===$i+1?'selected':'' ?>><?= $month ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <?php endif; ?>
    <?php if($period['view']==='range'): ?>
    <?php foreach(['range_start'=>'From','range_end'=>'To'] as $field=>$label): ?><div class="col-md-3"><label for="<?= $field ?>" class="form-label"><?= $label ?> (<?= $period['calendar'] ?>)</label><input id="<?= $field ?>" name="<?= $field ?>" data-calendar="<?= $period['calendar'] ?>" type="<?= $period['calendar']==='AD'?'date':'text' ?>" class="form-control" placeholder="YYYY-MM-DD" value="<?= sanitize($field==='range_start'?$period['fromInput']:$period['toInput']) ?>" required></div><?php endforeach; ?>
    <?php endif; ?>
    <div class="col-md-3"><label for="attendanceClass" class="form-label">Class</label><select name="class" id="attendanceClass" class="form-select" onchange="this.form.elements.section.value='';this.form.submit()"><option value="">Select Class</option><?php foreach($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $classId===(int)$class['id']?'selected':'' ?>><?= sanitize($class['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label for="attendanceSection" class="form-label">Section</label><select name="section" id="attendanceSection" class="form-select" onchange="this.form.submit()"><option value="">All Sections</option><?php foreach($sections as $section): ?><option value="<?= (int)$section['id'] ?>" <?= $sectionId===(int)$section['id']?'selected':'' ?>><?= sanitize($section['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label for="attendanceSearch" class="form-label">Student name, roll or student ID</label><input type="search" name="search" id="attendanceSearch" class="form-control" maxlength="100" value="<?= sanitize($search) ?>" placeholder="Name, roll, student ID or EMIS"></div>
    <div class="col-md-3 d-flex align-items-end gap-2"><button class="btn btn-primary">Load attendance</button><a href="<?= APP_URL ?>/modules/attendance/index.php" class="btn btn-outline-secondary">Reset</a></div>
</form></div></div>
<?php if($classId): ?>
<div class="card mb-3"><div class="card-body">
<h5><?= sanitize($selectedClass['name']) ?> — <?= sanitize(ucfirst($period['view'])) ?> attendance</h5>
<p class="mb-1">Period: <?= sanitize(schoolDateLabel($period['start'],'both','ymd')) ?><?php if($period['start']!==$period['end']): ?> to <?= sanitize(schoolDateLabel($period['end'],'both','ymd')) ?><?php endif; ?></p>
<p class="small text-muted mb-0">Totals cover the full selected period. Editing shows up to 10 students and 32 dates per page. P = Present, A = Absent, L = Late, H = Holiday. Blank/— = not recorded, not absent. Archived students and future dates are view-only.</p>
</div></div>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3 no-print">
<div>Students <?= count($students)?(($studentPage-1)*10+1):0 ?>–<?= min($studentPage*10,count($roster)) ?> of <?= count($roster) ?>
<?php if($studentPage>1): ?><a class="btn btn-sm btn-outline-primary" href="<?= sanitize(attendanceUrl($filterQuery,['student_page'=>$studentPage-1])) ?>">Previous students</a><?php endif; ?>
<?php if($studentPage<$studentPages): ?><a class="btn btn-sm btn-outline-primary" href="<?= sanitize(attendanceUrl($filterQuery,['student_page'=>$studentPage+1])) ?>">Next students</a><?php endif; ?></div>
<div>Dates: <?= sanitize(schoolDateLabel($window['dates'][0],$period['calendar'],'ymd')) ?> – <?= sanitize(schoolDateLabel(end($window['dates']),$period['calendar'],'ymd')) ?>
<?php if($window['page']>1): ?><a class="btn btn-sm btn-outline-primary" href="<?= sanitize(attendanceUrl($filterQuery,['day_page'=>$window['page']-1])) ?>">Previous dates</a><?php endif; ?>
<?php if($window['page']<$window['pages']): ?><a class="btn btn-sm btn-outline-primary" href="<?= sanitize(attendanceUrl($filterQuery,['day_page'=>$window['page']+1])) ?>">Next dates</a><?php endif; ?></div></div>
<?php if($students): ?>
<form method="POST" id="attendanceSheet">
<input type="hidden" name="class_id" value="<?= $classId ?>"><input type="hidden" name="section_id" value="<?= $sectionId ?>"><input type="hidden" name="expected_cells" value="<?= $expectedCells ?>">
<div class="card"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered table-sm align-middle text-center mb-0" id="attendanceTable"><thead><tr><th>Roll</th><th class="text-start">Student name / ID</th><th>P</th><th>A</th><th>L</th><th>H</th>
<?php foreach($window['dates'] as $date): ?><th style="min-width:82px" title="<?= sanitize(schoolDateLabel($date,'both','ymd')) ?>"><?= sanitize(schoolDateLabel($date,$period['calendar'],'dmy')) ?><br><small><?= (new DateTimeImmutable($date))->format('D') ?></small></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($students as $student): ?><tr><td><?= sanitize($student['roll_number']??'—') ?></td><td class="text-start" style="min-width:160px"><strong><?= sanitize($student['first_name_en'].' '.$student['last_name_en']) ?></strong><br><small><?= sanitize($student['student_id']) ?><?= $student['status']!=='active'?' (archived)':'' ?></small></td>
<?php foreach(['P','A','L','H'] as $status): ?><td><?= $summary[$student['id']][$status]??0 ?></td><?php endforeach; ?>
<?php foreach($window['dates'] as $date): $value=$marks[$student['id']][$date]??''; ?><td>
<?php if($canEdit&&$student['status']==='active'&&$date<=$today): ?>
<select class="form-select form-select-sm attendance-cell no-print" name="attendance_grid[<?= (int)$student['id'] ?>][<?= $date ?>]" aria-label="<?= sanitize($student['first_name_en'].' '.$student['last_name_en'].' — '.schoolDateLabel($date,$period['calendar'],'ymd')) ?>"><option value="">—</option><?php foreach(['P','A','L','H'] as $status): ?><option value="<?= $status ?>" <?= $value===$status?'selected':'' ?>><?= $status ?></option><?php endforeach; ?></select><span class="d-none d-print-inline attendance-print-value"><?= sanitize($value?:'—') ?></span>
<?php else: ?><?= sanitize($value?:'—') ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<?php if($canEdit&&$expectedCells): ?><div class="card mt-3 no-print"><div class="card-body"><div class="d-flex flex-wrap align-items-end gap-3">
<div><label for="attendanceBulk" class="form-label">Set visible cells</label><select id="attendanceBulk" class="form-select"><option value="P">Present</option><option value="A">Absent</option><option value="L">Late</option><option value="H">Holiday</option></select></div><button type="button" class="btn btn-outline-primary" onclick="markAttendancePage()">Apply to visible page</button>
<?php if($hasExisting): ?><div><label for="edit_password" class="form-label">Password to correct saved attendance</label><input type="password" id="edit_password" name="edit_password" class="form-control" autocomplete="current-password"><small class="text-muted">Required only when changing an already saved status.</small></div><?php endif; ?>
<button type="submit" name="save_attendance" value="1" class="btn btn-primary">Save visible attendance</button></div><p class="small text-muted mt-2 mb-0">Only this page is saved. A blank selection keeps any existing attendance unchanged.</p></div></div><?php endif; ?>
</form>
<?php else: ?><div class="alert alert-info">No students match this class, section and search.</div><?php endif; ?>
<?php else: ?><div class="alert alert-info">Select a class to view or mark attendance. Class teachers see only their supervised classes.</div><?php endif; ?>
<script>
function markAttendancePage(){const status=document.getElementById('attendanceBulk').value;document.querySelectorAll('.attendance-cell').forEach(input=>{input.value=status;});}
</script>
<script src="<?= APP_URL ?>/assets/js/attendance-controls.js"></script>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php'; ?>
