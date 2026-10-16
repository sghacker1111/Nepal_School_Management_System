<?php
/**
 * Exam Management - Add Page
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/report_issue.php';
require_once __DIR__.'/../../includes/exam_template_service.php';

Auth::requireRole(['super_admin', 'school_admin', 'exam_officer']);

$db = getDB();
$school_id = Auth::getSchoolId();
$error = '';
$templates=examTemplates($db);
$mode=$_POST['creation_mode']??$_GET['creation_mode']??'manual';
if(!in_array($mode,['manual','automatic'],true))denySchoolRequest('Choose automatic or manual exam creation.',422);
try{$templateId=examTemplateId($_POST['template_id']??$_GET['template_id']??($templates[0]['id']??0));$template=examTemplate($db,$templateId,true);}
catch(InvalidArgumentException $e){if($_SERVER['REQUEST_METHOD']!=='POST')denySchoolRequest($e->getMessage(),422);$error=$e->getMessage();$template=$templates[0]??['name'=>'','type'=>'terminal','has_internal'=>1,'result_system'=>'gpa','date_calendar'=>'BS','date_format'=>'ymd'];$templateId=(int)($template['id']??0);}

// Choose any visible school year, with the active year selected by default.
$stmt = $db->prepare("SELECT * FROM academic_years WHERE school_id = ? AND COALESCE(is_hidden,0)=0 ORDER BY is_active DESC,id DESC");
$stmt->execute([$school_id]);
$academicYears = $stmt->fetchAll();
$academicYearId=is_scalar($_POST['academic_year_id']??null)?(int)$_POST['academic_year_id']:(int)($academicYears[0]['id']??0);

if (!$academicYears) {
    redirect(schoolRouteUrl('modules/exams/index.php'), 'Add an academic year in school settings before creating an exam.', 'error');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'&&$error==='') {
        try {
            $new_id=createExamFromTemplate($db,(int)$school_id,(int)Auth::getUserId(),$_POST);
            
            Auth::logActivity(Auth::getUserId(), 'Create Exam', 'exams', $new_id);
            redirect(schoolRouteUrl('modules/exams/view.php') . '?id=' . $new_id, $mode==='automatic'?'Exam created successfully with locked default settings. You can now assign symbols and enter marks.':'Exam created successfully. Now review subjects and weightage.');
        } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
        catch (PDOException $e) {
            $error = schoolPublicError($e);
        }
}
try{$issue=reportIssueDate([],array_replace(['display_calendar'=>$template['date_calendar'],'date_format'=>$template['date_format'],'issue_calendar'=>'BS'],$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[]));}catch(InvalidArgumentException $e){$issue=reportIssueDate([]);}
$form=$template;if($mode==='manual'&&$_SERVER['REQUEST_METHOD']==='POST')foreach(['name','type','result_system','has_internal'] as $k)if(is_scalar($_POST[$k]??null))$form[$k]=$_POST[$k];

$pageTitle = 'Create New Exam';
$activePage = 'exams';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-plus-circle"></i> Create New Exam</h2>
    <a href="<?= schoolRouteUrl('modules/exams/index.php') ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to List
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?>
</div>
<?php endif; ?>

<div class="card max-width-600 mx-auto">
    <div class="card-header bg-primary text-white py-3">
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Exam Definition Details</h5>
    </div>
    <div class="card-body p-4">
        <form method="GET" class="row g-3 mb-4"><div class="col-md-6"><label for="creationMode" class="form-label">Creation mode</label><select id="creationMode" name="creation_mode" class="form-select" onchange="this.form.submit()"><option value="manual" <?= $mode==='manual'?'selected':'' ?>>Manual — customize defaults</option><option value="automatic" <?= $mode==='automatic'?'selected':'' ?>>Automatic — locked default settings</option></select></div><div class="col-md-6"><label for="examTemplate" class="form-label">Super-admin default exam</label><select id="examTemplate" name="template_id" class="form-select" onchange="this.form.submit()"><?php foreach($templates as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $templateId===(int)$t['id']?'selected':'' ?>><?= sanitize($t['name']) ?></option><?php endforeach; ?></select></div></form>
        <div class="alert alert-info"><?= $mode==='automatic'?'Automatic exam: default settings are read-only. Choose your school dates below. Marks, schedules, symbols and lock/reopen remain available.':'Manual exam: defaults are loaded from the selected template. Edit them to suit your school; existing exams are not changed.' ?></div>
        <form method="POST" action="">
            <input type="hidden" name="creation_mode" value="<?= sanitize($mode) ?>"><input type="hidden" name="template_id" value="<?= $templateId ?>"><input type="hidden" name="template_revision" value="<?= (int)($template['revision']??0) ?>">
            <div class="mb-3">
                <label for="academic_year_id" class="form-label">Academic Year *</label>
                <select id="academic_year_id" name="academic_year_id" class="form-select" required><?php foreach($academicYears as $year): ?><option value="<?= (int)$year['id'] ?>" <?= $academicYearId===(int)$year['id']?'selected':'' ?>><?= sanitize(getAcademicYearLabel($year['year_bs'],$year['year_ad']).($year['is_active']?' (Active)':'')) ?></option><?php endforeach; ?></select>
            </div>
            
            <div class="mb-3">
                <label for="name" class="form-label">Exam Name *</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= sanitize($form['name']) ?>" maxlength="150" required <?= $mode==='automatic'?'readonly':'' ?>>
            </div>
            
            <div class="mb-3">
                <label for="type" class="form-label">Exam Type</label>
                <select class="form-select" id="type" name="type">
                    <?php foreach(['terminal'=>'Terminal Exam (Term Exam)','final'=>'Final Exam','unit'=>'Unit Test / Class Test','practical'=>'Practical / Assessment Only'] as $v=>$label): ?><option value="<?= $v ?>" <?= $form['type']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="result_system" class="form-label">Result System (for Unit Tests or General)</label>
                <select class="form-select" id="result_system" name="result_system">
                    <option value="gpa" <?= $form['result_system']==='gpa'?'selected':'' ?>>Grading System (GPA / Grades)</option>
                    <option value="percentage" <?= $form['result_system']==='percentage'?'selected':'' ?>>Marksheet System (Marks / Percentage)</option>
                </select>
            </div>
            
            <div class="mb-3">
                <div class="form-check form-switch">
                    <?php if($mode==='manual'): ?><input type="hidden" name="has_internal" value="0"><?php endif; ?>
                    <input class="form-check-input" type="checkbox" id="has_internal" name="has_internal" value="1" <?= $form['has_internal']?'checked':'' ?>>
                    <label class="form-check-label fw-bold text-primary" for="has_internal">
                        Include Internal Exam / Practical Assessment Component
                    </label>
                </div>
                <div class="form-text text-muted">If disabled, the exam will only have theory (external) marks. The practical marks input and calculations will be skipped.</div>
            </div>
            
            <?php include __DIR__.'/../../includes/exam_date_controls.php'; ?>
            <div class="row g-3 mb-4"><?php include __DIR__.'/../../includes/report_issue_controls.php'; ?></div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="fas fa-save"></i> <?= $mode==='automatic'?'Save Exam and Continue':'Save and Configure Subjects' ?>
            </button>
        </form>
    </div>
</div>

<script>
<?php if($mode==='automatic'): ?>['type','result_system','has_internal','display_calendar','date_format'].forEach(id=>document.getElementById(id).disabled=true);<?php endif; ?>
document.getElementById('type').addEventListener('change',function(){
    const internal=document.getElementById('has_internal');internal.disabled=this.value==='unit';
    if(internal.disabled)internal.checked=false;
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
