<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/school_validation.php';
Auth::requireRole(['super_admin','school_admin','principal','exam_officer','teacher']);
$db=getDB();$school=(int)Auth::getSchoolId();$student=schoolRecord($db,'students',(int)($_GET['id']??0),$school);
$q=$db->prepare('SELECT DISTINCT e.* FROM exams e JOIN exam_subjects es ON es.exam_id=e.id WHERE e.school_id=? AND e.academic_year_id=? AND es.class_id=? ORDER BY e.id DESC');$q->execute([$school,$student['academic_year_id'],$student['class_id']]);$exams=$q->fetchAll();
if(!empty($_GET['exam_id'])){
 $chosen=null;foreach($exams as $exam)if($exam['id']==$_GET['exam_id'])$chosen=$exam;
 if(!$chosen)denySchoolRequest('Choose an exam configured for this student’s class and year.',422);
 $query=['exam_id'=>$chosen['id'],'class_id'=>$student['class_id'],'student_mode'=>'choose','selected'=>[$student['id']],'render'=>'1'];
 foreach(['issue_date_mode','issue_date','issue_calendar','display_calendar','date_format'] as $key)if(isset($_GET[$key]))$query[$key]=$_GET[$key];
 redirect(APP_URL.'/modules/exams/admit_cards.php?'.http_build_query($query));
}
$pageTitle='Student Admit Card';$activePage='students';ob_start();?>
<h2>Admit Card — <?= sanitize($student['first_name_en'].' '.$student['last_name_en']) ?></h2>
<p>Choose an examination. Its own Symbol No. will be used, separately from the registration Roll No.</p>
<form method="GET" class="card card-body"><input type="hidden" name="id" value="<?= $student['id'] ?>"><label for="exam_id">Examination</label><select class="form-select mb-3" name="exam_id" id="exam_id" required><option value="">Choose Exam</option><?php foreach($exams as $e): ?><option value="<?= $e['id'] ?>"><?= sanitize($e['name']) ?></option><?php endforeach; ?></select><button class="btn btn-primary">Generate Admit Card</button></form>
<?php $content=ob_get_clean();include __DIR__.'/../../includes/header.php';
