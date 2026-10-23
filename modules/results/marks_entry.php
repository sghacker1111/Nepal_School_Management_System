<?php
/**
 * Result Management - Marks Entry Page (CDC Compliant Wide Grid)
 */
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/previous_exam_service.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();
$message = '';
$message_type = 'success';


// Filters
$exam_id = $_GET['exam'] ?? '';
$class_id = $_GET['class'] ?? '';
$section_id = $_GET['section'] ?? '';
$prev_exam_id = $_GET['prev_exam'] ?? '';
$filter_subject_id = $_GET['subject'] ?? '';
$student_search=trim((string)($_GET['student_search']??''));
$marks_page=max(1,(int)($_GET['page']??1));

// Save through one transactional, school-scoped validation service.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/marks_service.php';
    try {
        if(($_POST['marks_complete']??'')!=='1')throw new InvalidArgumentException('The marks form was incomplete or exceeded the server limit. Nothing was saved. Reload this page or select one subject and try again.');
        saveExamMarks($db, (int)Auth::getSchoolId(), (int)Auth::getUserId(), Auth::getRole(), $_POST);
        Auth::logActivity(Auth::getUserId(), 'Save exam marks', 'exams', (int)$_POST['exam_id']);
        redirect(schoolRouteUrl('modules/results/marks_entry.php') . '?'.http_build_query(['exam'=>(int)$_POST['exam_id'],'class'=>(int)$_POST['class_id'],'section'=>(int)($_POST['section_id']??0),'subject'=>$filter_subject_id,'page'=>$marks_page,'student_search'=>$student_search]), 'Marks and attendance saved successfully.');
    } catch (InvalidArgumentException $e) { $message=$e->getMessage();$message_type='error'; }
    catch (PDOException $e) { error_log($e->getMessage());$message='Marks could not be saved. Please try again.';$message_type='error'; }
}
// Fetch active exams
$school_id = Auth::getSchoolId();
$selected_exam = null;
if ($exam_id) {
    $stmt_sel = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
    $stmt_sel->execute([$exam_id, $school_id]);
    $selected_exam = $stmt_sel->fetch(PDO::FETCH_ASSOC);
}
$marksEditable=$selected_exam&&!$selected_exam['is_locked']&&!$selected_exam['is_published']&&Auth::hasRole(['super_admin','school_admin','teacher','exam_officer'])&&!Auth::isReadOnly();
require_once __DIR__.'/../../includes/result_scope.php';
$teacherCanAttendance=true;$teacherIsClassTeacher=false;$teacherResultScope=['class_teacher'=>false,'subjects'=>[]];
if(Auth::getRole()==='teacher'){
    $teacherResultScope=teacherClassResultScope($db,(int)$school_id,(int)Auth::getUserId(),(int)$class_id);
    $teacherIsClassTeacher=$teacherResultScope['class_teacher'];$teacherCanAttendance=$teacherIsClassTeacher;
}
$stmt = $db->prepare("
    SELECT e.*, y.year_bs 
    FROM exams e 
    JOIN academic_years y ON e.academic_year_id = y.id 
    WHERE y.is_active = 1 AND e.school_id = ?
    ORDER BY e.id DESC
");
$stmt->execute([$school_id]);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes
$classes=array_values(array_filter(resultClasses($db,(int)$school_id,Auth::getRole(),(int)Auth::getUserId()),static fn($class)=>$class['is_active']));

// Fetch sections for selected class
$sections = [];
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM sections WHERE class_id = ? ORDER BY name");
    $stmt->execute([$class_id]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(Auth::getRole()==='teacher'&&!$teacherIsClassTeacher)$sections=array_values(array_filter($sections,static fn($section)=>array_reduce(array_keys($teacherResultScope['subjects']),static fn($permitted,$subject)=>$permitted||teacherResultCellAllowed($teacherResultScope,(int)$subject,(int)$section['id']),false)));
}

// Global class level resolver
$class_name = '';
$classType = '4-8';
if ($class_id) {
    $stmt_class = $db->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_class->execute([$class_id]);
    $class_name = $stmt_class->fetchColumn();
    $classType = getClassType($class_name);
}

// Fetch configured subjects for class
$subjects = [];
$all_subjects = [];
if ($exam_id && $class_id) {
    $stmt = $db->prepare("
        SELECT es.*, sub.name_en as subject_name, sub.code as subject_code, COALESCE(es.is_optional,CASE WHEN sub.type='optional' THEN 1 ELSE 0 END) as is_optional
        FROM exam_subjects es
        JOIN subjects sub ON es.subject_id = sub.id
        WHERE es.exam_id = ? AND es.class_id = ?
    ");
    $stmt->execute([$exam_id, $class_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (Auth::getRole()==='teacher') {
        $subjects=teacherResultSubjects($subjects,$teacherResultScope,(int)$section_id);
    }
    
    $subjects=erpSortSubjects($subjects);
    
    $all_subjects = $subjects;
    
    if ($filter_subject_id !== '') {
        $subjects = array_filter($subjects, function($s) use ($filter_subject_id) {
            return $s['subject_id'] == $filter_subject_id;
        });
    }
}

// Fetch students
$students = [];
if ($exam_id && $class_id) {
    $query = "
        SELECT s.id, s.student_id, s.first_name_en, s.last_name_en, s.roll_number, sy.symbol_no,
               s.optional_subject_i, s.optional_subject_ii, s.optional_subject_iii, s.section_id
        FROM students s
        LEFT JOIN exam_symbols sy ON sy.student_id=s.id AND sy.exam_id=? AND sy.school_id=?
        WHERE s.class_id = ? AND s.status = 'active' AND s.school_id = ? AND s.academic_year_id = ?
    ";
    $params = [$exam_id,$school_id,$class_id, $school_id, $selected_exam['academic_year_id'] ?? 0];
    if ($section_id) {
        $query .= " AND s.section_id = ?";
        $params[] = $section_id;
    }
    $query .= " ORDER BY s.roll_number ASC, s.first_name_en ASC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if(Auth::getRole()==='teacher'&&!$teacherIsClassTeacher)$students=array_values(array_filter($students,static function($student)use($subjects,$teacherResultScope){foreach($subjects as $subject)if(teacherResultCellAllowed($teacherResultScope,(int)$subject['subject_id'],(int)$student['section_id']))return true;return false;}));
}
if($student_search!=='')$students=array_values(array_filter($students,static function($s)use($student_search){
    foreach([$s['first_name_en'].' '.$s['last_name_en'],$s['student_id'],$s['roll_number'],$s['symbol_no']??''] as $value)if(mb_stripos((string)$value,$student_search)!==false)return true;
    return false;
}));
// Bound successful controls below PHP's input limit, even with custom IN components.
$fieldsPerStudent=1;
foreach($subjects as $subject)$fieldsPerStudent+=2+count(cdc2083InternalComponents($subject,$selected_exam));
$inputLimit=(int)ini_get('max_input_vars');if($inputLimit<=0)$inputLimit=1000;
$pageSize=max(1,min(10,(int)floor(max(1,$inputLimit-40)/$fieldsPerStudent)));
$studentCount=count($students);$pageCount=max(1,(int)ceil($studentCount/$pageSize));$marks_page=min($marks_page,$pageCount);
$students=array_slice($students,($marks_page-1)*$pageSize,$pageSize);

// Fetch saved marks
$marks_matrix = [];
if ($exam_id && $class_id && !empty($students)) {
    $stmt = $db->prepare('SELECT * FROM marks WHERE exam_id=? AND student_id IN ('.implode(',',array_fill(0,count($students),'?')).')');
    $stmt->execute([$exam_id,...array_column($students,'id')]);
    $marks_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($marks_raw as $m) {
        $marks_matrix[$m['student_id']][$m['subject_id']] = $m;
    }
}

// Fetch saved attendance
$attendance_data = [];
if ($exam_id) {
    $stmt = $db->prepare("SELECT student_id, present_days FROM exam_student_attendance WHERE exam_id = ?");
    $stmt->execute([$exam_id]);
    $att_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($att_raw as $ar) {
        $attendance_data[$ar['student_id']] = $ar['present_days'];
    }
}

// Only explicit loading fills a read-only preview, never a database write.
$previous_exams=$selected_exam?previousAssessmentExams($db,(int)$school_id,$selected_exam):[];
$previous_ids=array_values((array)($_GET['prev_exams']??($prev_exam_id!==''?[$prev_exam_id]:[])));
$previous_preview=[];
if(isset($_GET['load_previous'])&&$selected_exam&&$class_id&&$marksEditable){
 try{
  $preview=previousContributionPreview($db,(int)$school_id,$selected_exam,(int)$class_id,$subjects,$students,$previous_ids);
  $previous_preview=$preview['values'];$message="Loaded {$preview['loaded']} converted contributions for preview; {$preview['skipped']} missing or incomplete contributions were left unchanged. Review and click Save Marks to save.";$message_type='info';
 }catch(InvalidArgumentException $e){$message=$e->getMessage();$message_type='error';}
}

$pageTitle = 'Class-Wide Marks Entry';
$activePage = 'results';

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-edit text-primary"></i> Class-Wide Marks Entry Sheet</h2>
    <?php if($exam_id&&$class_id&&$subjects): ?><div class="d-flex gap-2">
        <?php if(Auth::getRole()!=='teacher'||$teacherIsClassTeacher): ?><a class="btn btn-outline-info" href="<?= schoolRouteUrl('modules/results/ledger.php') ?>?<?= htmlspecialchars(http_build_query(['exam'=>$exam_id,'class'=>$class_id,'section'=>$section_id])) ?>"><i class="fas fa-print"></i> Full-class ledger</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= schoolRouteUrl('modules/results/ledger.php') ?>?<?= htmlspecialchars(http_build_query(['exam'=>$exam_id,'class'=>$class_id,'section'=>$section_id,'subject'=>$filter_subject_id?:reset($subjects)['subject_id']])) ?>"><i class="fas fa-print"></i> Subject ledger</a>
    </div><?php endif; ?>
</div>
<?php if(Auth::getRole()==='teacher'&&$class_id): ?><div class="alert alert-info no-print"><?= $teacherIsClassTeacher?'Class teacher access: view and correct all configured subjects in this class. Locked or published exams remain view-only.':'Subject teacher access: view and enter marks for your assigned subjects and sections only.' ?></div><?php endif; ?>

<?php if ($message): ?>
<div data-notify class="alert alert-<?= $message_type == 'error' ? 'danger' : ($message_type==='info'?'info':'success') ?> mb-4">
    <i class="fas <?= $message_type == 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i> <?= sanitize($message) ?>
</div>
<?php endif; ?>

<!-- Filters Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3"><label class="form-label" for="studentSearch">Student name, ID, roll or symbol</label><input id="studentSearch" name="student_search" class="form-control" value="<?= sanitize($student_search) ?>" placeholder="Search this class"></div>
            <div class="col-md-2">
                <label class="form-label fw-bold">Exam</label>
                <select name="exam" class="form-select" required data-erp-change="results-marks-entry-action-0" data-erp-change-args="<?= schoolPageActionArgs("results-marks-entry",[]) ?>">
                    <option value="">Select Exam</option>
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $exam_id == $e['id'] ? 'selected' : '' ?>>
                        <?= sanitize($e['name']) ?> (<?= $e['year_bs'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label fw-bold">Class</label>
                <select name="class" class="form-select" required data-erp-change="results-marks-entry-action-1" data-erp-change-args="<?= schoolPageActionArgs("results-marks-entry",[]) ?>">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label fw-bold">Section</label>
                <select name="section" class="form-select" data-erp-change="results-marks-entry-action-2" data-erp-change-args="<?= schoolPageActionArgs("results-marks-entry",[]) ?>">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $section_id == $s['id'] ? 'selected' : '' ?>>
                        Section <?= sanitize($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Subject (Optional)</label>
                <select name="subject" class="form-select" data-erp-change="results-marks-entry-action-3" data-erp-change-args="<?= schoolPageActionArgs("results-marks-entry",[]) ?>">
                    <option value="">All Subjects</option>
                    <?php foreach ($all_subjects as $sub): ?>
                    <option value="<?= $sub['subject_id'] ?>" <?= $filter_subject_id == $sub['subject_id'] ? 'selected' : '' ?>>
                        <?= sanitize($sub['subject_name']) ?> (<?= sanitize($sub['subject_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-3">
                <label class="form-label fw-bold" for="prev_exams">Load Earlier Examination Marks (IN)</label>
                <select name="prev_exams[]" id="prev_exams" class="form-select" multiple size="3">
                    <?php foreach($previous_exams as $e): ?><option value="<?= $e['id'] ?>" <?= in_array((string)$e['id'],array_map('strval',$previous_ids),true)?'selected':'' ?>><?= sanitize($e['name']) ?> (<?= sanitize(ucfirst($e['type'])) ?>)</option><?php endforeach; ?>
                </select>
                <button class="btn btn-outline-primary btn-sm mt-2" type="submit" name="load_previous" value="1" <?= !$marksEditable?'disabled':'' ?>>Load Converted Marks</button>
                <div class="form-text">Only earlier unit/terminal exams in this academic year are offered. Select one for Term 1 or 2, or multiple for Term 3 (Ctrl/Cmd-click). Contribution = sum of obtained marks ÷ sum of full marks × this field’s maximum. Missing marks are not zero. TH and other IN fields stay unchanged.</div>
            </div>
        </form>
    </div>
</div>

<?php if ($exam_id && $class_id && !empty($subjects)): ?>
<?php if(!$marksEditable): ?><div class="alert alert-warning" role="status">Marks are view-only. <?= ($selected_exam['is_locked']||$selected_exam['is_published'])?'This exam is locked or published. A school administrator must reopen marks before any entry or correction can be saved.':'Your role cannot change marks.' ?></div><?php endif; ?>
<!-- Entry Grid Form -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 text-primary fw-bold"><i class="fas fa-list-ol"></i> Marks Grid: <?= sanitize($class_name) ?></h5>
        <small class="text-muted">Horizontal scrolling enabled. Gray cells are optional subjects not selected by the student. Internal components must sum to no more than the configured internal full marks. Enter zero for a component that does not apply.</small>
    </div>
    
    <div class="card-body p-0">
        <form method="POST" action="">
            <fieldset <?= !$marksEditable?'disabled':'' ?> class="border-0 p-0 m-0" id="marksFields">
            <?php if($marksEditable): ?><input type="hidden" name="save_marks" value="1"><?php endif; ?>
            <input type="hidden" name="exam_id" value="<?= sanitize($exam_id) ?>">
            <input type="hidden" name="class_id" value="<?= sanitize($class_id) ?>">
            <input type="hidden" name="section_id" value="<?= sanitize($section_id) ?>">
            <input type="hidden" name="subject_id_hidden" value="<?= sanitize($filter_subject_id) ?>">
            
            <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                <table class="table table-bordered table-sm align-middle text-center mb-0" style="font-size: 0.85rem; border: 2px solid #ddd; min-width: 1200px;">
                    <thead class="table-dark" style="position: sticky; top: 0; z-index: 100;">
                        <tr>
                            <th rowspan="2" class="align-middle px-2" style="width: 50px;">S.N.</th>
                            <th rowspan="2" class="align-middle text-start px-2" style="min-width: 180px; position: sticky; left: 0; background-color: #212529; z-index: 110;">Student Name</th>
                            <th rowspan="2" class="align-middle px-2" style="min-width: 100px;">Symbol No.</th>
                            <th rowspan="2" class="align-middle px-2" style="min-width: 90px; background-color: #34495e;">Attendance</th>
                            
                            <?php foreach ($subjects as $sub): ?>
                                <?php $components = (($selected_exam['type']??'')==='unit'||empty($selected_exam['has_internal'])||($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS')?[]:cdc2083InternalComponents($sub,$selected_exam); $colspan=1+count($components); ?>
                                <th colspan="<?= $colspan ?>" class="px-2 small text-uppercase">
                                    <?= sanitize($sub['subject_name']) ?> 
                                    <span class="d-block text-warning small font-monospace"><?= sanitize($sub['subject_code']) ?></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                        <tr>
                            <?php foreach ($subjects as $sub): ?>
                                <?php $components=(($selected_exam['type']??'')==='unit'||empty($selected_exam['has_internal'])||($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS')?[]:cdc2083InternalComponents($sub,$selected_exam); ?>
                                <?php foreach($components as $component): ?>
                                    <th style="min-width: 100px; font-size: 0.7rem;" title="Enter scaled contribution marks, not raw test scores."><?= sanitize($component['label']) ?><br>IN (<?= $component['max'] ?>)</th>
                                <?php endforeach; ?>
                                <th style="min-width: 100px; font-size: 0.75rem;"><?= ($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS'?'Achievement (%)':'Theory ('.$sub['theory_full'].')' ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($students) > 0): ?>
                            <?php 
                            $sn = ($marks_page-1)*$pageSize;
                            foreach ($students as $student): 
                                $sn++;
                                $student_att = $attendance_data[$student['id']] ?? '';
                            ?>
                            <tr>
                                <td><strong><?= $sn ?></strong></td>
                                <td class="text-start fw-bold" style="position: sticky; left: 0; background-color: #fff; box-shadow: 2px 0 5px rgba(0,0,0,0.05); z-index: 90;">
                                    <?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?>
                                </td>
                                <td><code><?= sanitize($student['symbol_no'] ?? '-') ?></code></td>
                                <td style="background-color: #f8f9fa;">
                                    <input <?= !$teacherCanAttendance?'disabled':'' ?> type="number" min="0" class="form-control form-control-sm text-center font-monospace" name="attendance[<?= $student['id'] ?>]" value="<?= $student_att ?>" placeholder="Days">
                                </td>
                                
                                <?php foreach ($subjects as $sub): ?>
                                    <?php 
                                    // Check optional mapping
                                    $canViewCell=Auth::getRole()!=='teacher'||teacherResultCellAllowed($teacherResultScope,(int)$sub['subject_id'],(int)$student['section_id']);
                                    $canEditCell=$marksEditable&&$canViewCell;
                                    if(!$canViewCell){$cellParts=(($selected_exam['type']??'')==='unit'||empty($selected_exam['has_internal'])||($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS')?[]:cdc2083InternalComponents($sub,$selected_exam);echo '<td colspan="'.(count($cellParts)+1).'" class="text-muted small">Not assigned</td>';continue;}
                                    $is_optional = (!empty($sub['is_optional']));
                                    $is_selected = true;
                                    if ($is_optional) {
                                        $is_selected = ($sub['subject_id'] == $student['optional_subject_i'] || $sub['subject_id'] == $student['optional_subject_ii'] || $sub['subject_id'] == ($student['optional_subject_iii']??null));
                                    }
                                    
                                    if (!$is_selected): 
                                        $components = (($selected_exam['type']??'')==='unit'||empty($selected_exam['has_internal'])||($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS')?[]:cdc2083InternalComponents($sub,$selected_exam); $colspan=1+count($components);
                                        $savedElective=$marks_matrix[$student['id']][$sub['subject_id']]??null;
                                        $savedParts=json_decode($savedElective['internal_breakdown']??'null',true);$savedParts=is_array($savedParts)?$savedParts:[];
                                    ?>
                                        <?php foreach($components as $key=>$component): ?><td><input disabled data-elective="<?= (int)$student['id'].'-'.(int)$sub['subject_id'] ?>" type="number" step="0.01" min="0" max="<?= $component['max'] ?>" class="form-control form-control-sm" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][<?= $key ?>]" value="<?= sanitize($savedParts[$key]??'') ?>" aria-label="<?= sanitize($student['first_name_en'].' '.$sub['subject_name'].' '.$component['label']) ?>"></td><?php endforeach; ?>
                                        <td><label class="small"><input <?= !$canEditCell?'disabled':'' ?> type="checkbox" class="elective-select" data-elective="<?= (int)$student['id'].'-'.(int)$sub['subject_id'] ?>" name="optional_enrollment[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>]" value="1"> Take this subject</label><input disabled data-elective="<?= (int)$student['id'].'-'.(int)$sub['subject_id'] ?>" type="number" step="0.01" min="0" max="<?= $sub['theory_full'] ?>" class="form-control form-control-sm" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][theory]" value="<?= sanitize($savedElective['theory_obtained']??'') ?>" aria-label="<?= sanitize($student['first_name_en'].' '.$sub['subject_name'].' theory marks') ?>"><?php if($savedElective): ?><span class="d-block small text-warning">Saved marks retained. Confirm the subject selection to edit.</span><?php endif; ?></td>
                                    <?php else: ?>
                                        <?php 
                                        $m = $marks_matrix[$student['id']][$sub['subject_id']] ?? null;
                                        $theory_obtained = $m !== null && $m['theory_obtained'] !== null ? $m['theory_obtained'] : '';
                                        $practical_obtained = $m !== null && $m['practical_obtained'] !== null ? $m['practical_obtained'] : '';
                                        
                                        $breakdown = $m !== null && $m['internal_breakdown'] !== null ? json_decode($m['internal_breakdown'], true) : [];
                                        
                                        $prt_val = $breakdown['participation'] ?? '';
                                        $prj_val = $breakdown['project_work'] ?? '';
                                        $prv_val = $breakdown['previous_exam'] ?? '';
                                        
                                        $breakdown=array_replace(is_array($breakdown)?$breakdown:[],$previous_preview[$student['id']][$sub['subject_id']]??[]);
                                        ?>
                                        <?php $components=(($selected_exam['type']??'')==='unit'||empty($selected_exam['has_internal'])||($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS')?[]:cdc2083InternalComponents($sub,$selected_exam); ?>
                                        <?php foreach($components as $key=>$component): ?>
                                            <td><input <?= !$canEditCell?'disabled':'' ?> type="number" step="0.01" min="0" max="<?= $component['max'] ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][<?= $key ?>]" value="<?= sanitize($breakdown[$key]??'') ?>" aria-label="<?= sanitize($student['first_name_en'].' '.$sub['subject_name'].' '.$component['label']) ?>"></td>
                                        <?php endforeach; ?>
                                        <td style="background-color: #e8f8f5;"><input <?= !$canEditCell?'disabled':'' ?> type="number" step="0.01" min="0" max="<?= $sub['theory_full'] ?>" class="form-control form-control-sm text-center font-monospace" name="marks[<?= $student['id'] ?>][<?= $sub['subject_id'] ?>][theory]" value="<?= $theory_obtained ?>" aria-label="<?= sanitize($student['first_name_en'].' '.$sub['subject_name'].' '.(($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS'?'achievement percentage':'theory marks')) ?>"></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?= 4+array_sum(array_map(static fn($s)=>1+(($selected_exam['type']??'')==='unit'||empty($selected_exam['has_internal'])?0:count(cdc2083InternalComponents($s,$selected_exam))),$subjects)) ?>" class="text-center text-muted py-5">
                                    <i class="fas fa-user-slash fa-2x mb-2"></i>
                                    <p class="mb-0">No active students found in this class/section.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (count($students) > 0 && $marksEditable): ?>
            <div class="text-end p-4 bg-light border-top no-print">
                <label class="d-block small mb-3"><input type="checkbox" name="confirm_optional_enrollment" value="1"> I confirm newly checked optional subjects are taken by these students. Saving updates their subject selection and report cards.</label>
                <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm">
                    <i class="fas fa-save me-2"></i> Save & Compute Results
                </button>
            </div>
            <?php endif; ?>
            <input type="hidden" name="marks_complete" value="1">
            </fieldset>
        </form>
        <?php if($studentCount): ?><nav class="p-3 no-print" aria-label="Marks pages">
            <span>Page <?= $marks_page ?> of <?= $pageCount ?> · <?= $studentCount ?> students. Save this page before changing pages.</span>
            <?php foreach(['Previous'=>$marks_page-1,'Next'=>$marks_page+1] as $label=>$number): if($number<1||$number>$pageCount)continue; ?>
                <a class="btn btn-outline-primary btn-sm ms-2" href="?<?= sanitize(http_build_query(array_replace($_GET,['page'=>$number]))) ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav><?php endif; ?>
        <?php schoolPageModule("results-marks-entry"); ?>
    </div>
</div>
<?php else: ?>
<div class="card text-center py-5 no-print">
    <div class="card-body">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h5>Please select an Exam and Class above to load the marks entry grid.</h5>
    </div>
</div>
<?php endif; ?>

<style>
    /* Styling for the mark entry boxes */
    .table input[type="number"] {
        width: 80px !important;
        min-width: 80px !important;
        margin: 0 auto;
        padding-left: 2px !important;
        padding-right: 2px !important;
    }
</style>

<?php schoolPageModule("results-marks-entry"); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
