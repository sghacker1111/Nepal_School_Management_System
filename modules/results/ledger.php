<?php
/**
 * Result Management - Class Result Ledger
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__.'/../../includes/result_scope.php';

Auth::requireRole(['super_admin', 'school_admin', 'principal', 'teacher', 'exam_officer']);

$db = getDB();

// Filters
$exam_id = (int)($_GET['exam'] ?? 0);
$class_id = (int)($_GET['class'] ?? 0);
$section_id = (int)($_GET['section'] ?? 0);
$subject_id = (int)($_GET['subject'] ?? 0);
$ledgerMode=$_GET['mode']??'grade';
if(!in_array($ledgerMode,['grade','marks'],true))denySchoolRequest('Choose Grade Only or Marks Only.',422);
$ledgerSystem=$ledgerMode==='grade'?'gpa':'percentage';

$school_id = Auth::getSchoolId();
$role=Auth::getRole();
$teacherScope=$role==='teacher'?teacherClassResultScope($db,(int)$school_id,(int)Auth::getUserId(),$class_id):['class_teacher'=>false,'subjects'=>[]];
$canFullLedger=true;
$canOpenMarks=$role!=='teacher'||$teacherScope['class_teacher']||array_reduce(array_keys($teacherScope['subjects']),static fn($allowed,$id)=>$allowed||((!$subject_id||$subject_id===$id)&&(!$section_id||teacherResultCellAllowed($teacherScope,(int)$id,$section_id))),false);
$schoolQuery=$db->prepare('SELECT * FROM schools WHERE id=?');$schoolQuery->execute([$school_id]);$school=$schoolQuery->fetch()?:[];

// Fetch active exams
$stmt = $db->prepare("
    SELECT e.*, y.year_bs 
    FROM exams e 
    JOIN academic_years y ON e.academic_year_id = y.id 
    WHERE e.school_id = ?
    ORDER BY e.id DESC
");
$stmt->execute([$school_id]);
$exams = $stmt->fetchAll();

// Fetch classes
$classes=resultClasses($db,(int)$school_id,$role,(int)Auth::getUserId(),true);

$selected_class = null;
if ($class_id) {
    foreach ($classes as $c) {
        if ($c['id'] == $class_id) {
            $selected_class = $c;
            break;
        }
    }
}

// Fetch sections for selected class
$sections = [];
if ($class_id) {
    $stmt = $db->prepare("SELECT * FROM sections WHERE school_id = ? AND class_id = ? ORDER BY name");
    $stmt->execute([$school_id, $class_id]);
    $sections = $stmt->fetchAll();
}

$subjects = [];
$all_subjects=[];
$students = [];
$ledger_data = [];

if ($exam_id && $class_id) {
    // Verify exam and class ownership
    $chk_exam = $db->prepare("SELECT * FROM exams WHERE id = ? AND school_id = ?");
    $chk_exam->execute([$exam_id, $school_id]);
    $exam = $chk_exam->fetch();
    if (!$exam) {
        die("Unauthorized access to exam data.");
    }
    $chk_class = $db->prepare("SELECT id FROM classes WHERE id = ? AND school_id = ?");
    $chk_class->execute([$class_id, $school_id]);
    if (!$chk_class->fetch()) {
        die("Unauthorized access to class data.");
    }

    // 1. Fetch subjects configured for this exam-class
    $stmt = $db->prepare("
        SELECT es.*, s.name_en as subject_name, s.code as subject_code, COALESCE(es.is_optional,CASE WHEN s.type='optional' THEN 1 ELSE 0 END) as is_optional,
               es.credit_hours as exam_credit_hours, cs.credit_hours as class_credit_hours, 
               s.credit_hours as default_credit_hours
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        LEFT JOIN class_subjects cs ON cs.id=(SELECT MIN(cm.id) FROM class_subjects cm WHERE cm.class_id=es.class_id AND cm.subject_id=es.subject_id)
        WHERE es.exam_id = ? AND es.class_id = ?
        ORDER BY s.type DESC, s.name_en ASC
    ");
    $stmt->execute([$exam_id, $class_id]);
    $subjects = erpSortSubjects($stmt->fetchAll());
    $all_subjects=$subjects;
    if($subject_id){
        $subjects=array_values(array_filter($subjects,static fn($subject)=>(int)$subject['subject_id']===$subject_id));
        if(!$subjects)denySchoolRequest('Choose a subject configured for this exam and class that you are allowed to view.',403);
    }

    // If exam has no internal, override practical full & pass to 0
    if (!$exam['has_internal']) {
        foreach ($subjects as &$sub) {
            $sub['practical_full'] = 0;
            $sub['practical_pass'] = 0;
        }
        unset($sub);
    }

    // 2. Fetch active students in class
    $query = "SELECT st.id, st.student_id, st.first_name_en, st.last_name_en, st.roll_number, st.section_id, st.optional_subject_i, st.optional_subject_ii, st.optional_subject_iii, sy.symbol_no FROM students st LEFT JOIN exam_symbols sy ON sy.student_id=st.id AND sy.exam_id=? AND sy.school_id=? WHERE st.class_id = ? AND (st.status = 'active' OR EXISTS(SELECT 1 FROM marks historical WHERE historical.student_id=st.id AND historical.exam_id=?)) AND st.school_id=? AND st.academic_year_id=?";
    $params = [$exam_id,$school_id,$class_id,$exam_id,$school_id,$exam['academic_year_id']];
    if ($section_id) {
        $query .= " AND st.section_id = ?";
        $params[] = $section_id;
    }
    $query .= " ORDER BY st.roll_number ASC, st.first_name_en ASC,st.id";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    // 3. Fetch marks matrix
    $stmt = $db->prepare("
        SELECT m.* FROM marks m JOIN students st ON st.id=m.student_id
        WHERE m.exam_id=? AND st.class_id=? AND st.school_id=? AND st.academic_year_id=?
    ");
    $stmt->execute([$exam_id, $class_id,$school_id,$exam['academic_year_id']]);
    $marks_raw = $stmt->fetchAll();
    
    $marks = [];
    foreach ($marks_raw as $m) {
        $marks[$m['student_id']][$m['subject_id']] = $m;
    }

    // 4. Compute Ledger Matrix
    foreach ($students as $student) {
        $row_subs = [];
        $studentMarks=[];
        $hasObtained=false;
        foreach($subjects as $sub) {
            $sub_id=$sub['subject_id'];
            if(!empty($sub['is_optional']) && $sub_id!=$student['optional_subject_i'] && $sub_id!=$student['optional_subject_ii'] && $sub_id!=($student['optional_subject_iii']??null)) {$row_subs[$sub_id]='N/A';continue;}
            $m=$marks[$student['id']][$sub_id]??[];
            if(($m['theory_obtained']??null)!==null||((float)$sub['practical_full']>0&&($m['practical_obtained']??null)!==null))$hasObtained=true;
            $result=erpCalculateSubjectResult($sub,$m,$exam);
            $studentMarks[]=array_merge($sub,$m);
            $row_subs[$sub_id]=array_merge($result,['theory'=>$result['theory_obtained'],'practical'=>$result['practical_obtained'],'total'=>$result['incomplete']?null:(float)$result['theory_obtained']+(float)$result['practical_obtained'],'gp'=>$result['grade_point']]);
        }
        $summary=erpReportSummary($studentMarks,$exam,$ledgerSystem);
        $total_obtained=$hasObtained&&$summary['remarks_summary']!=='Incomplete'?$summary['grand_obtained']:null;
        $gpa=$summary['average_gpa'];
        $status=$summary['remarks_summary']==='Incomplete'?'Incomplete':($summary['has_failed_subject']?'Fail/NG':'Pass');
        if(!$studentMarks){$status='N/A';$gpa=null;}

        $ledger_data[] = [
            'id' => $student['id'],
            'student_id' => $student['student_id'],
            'name' => $student['first_name_en'] . ' ' . $student['last_name_en'],
            'roll' => $student['roll_number'],
            'symbol' => $student['symbol_no'],
            'subjects' => $row_subs,
            'total_marks' => $total_obtained,
            'gpa' => $gpa,
            'status' => $status,
            'division' => $summary['final_grade']
        ];
    }
}

$isSubjectLedger=$subject_id>0;
$subjectColumns=$ledgerMode==='grade'?4:3;
function ledgerMark($value):string {return $value===null?'—':rtrim(rtrim(number_format((float)$value,2,'.',','),'0'),'.');}

$pageTitle = $isSubjectLedger?'Subject Marks Ledger':'Class Result Ledger';
$activePage = 'results';
$pageStyles=['/assets/css/result-ledger.css'];

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h2><i class="fas fa-poll-h"></i> <?= $isSubjectLedger?'Subject Marks Ledger':'Full-Class Result Ledger' ?></h2>
    <div>
        <a href="<?= schoolRouteUrl('modules/results/index.php') ?>" class="btn btn-secondary me-2">
            <i class="fas fa-arrow-left"></i> Back to Results
        </a>
        <?php if ($exam_id && $class_id && $subjects): ?>
        <?php if($canOpenMarks): ?><a class="btn btn-outline-primary me-2" href="<?= schoolRouteUrl('modules/results/marks_entry.php') ?>?<?= htmlspecialchars(http_build_query(['exam'=>$exam_id,'class'=>$class_id,'section'=>$section_id,'subject'=>$subject_id?:''])) ?>"><?= $role==='teacher'&&!$teacherScope['class_teacher']?'Open assigned marks entry':'View / correct marks' ?></a><?php endif; ?>
        <button data-erp-click="results-ledger-action-0" data-erp-click-args="<?= schoolPageActionArgs("results-ledger",[]) ?>" class="btn btn-primary">
            <i class="fas fa-print"></i> Print Ledger
        </button>
        <?php endif; ?>
    </div>
</div>
<?php if($role==='teacher'&&$class_id): ?><div class="alert alert-info no-print">Ledger viewing: all classes and subjects in your school are available to view and print. This does not grant marks-editing rights; only your assigned subjects or supervised class can be corrected, and exam locks still apply.</div><?php endif; ?>
<p class="small text-muted no-print">Print format: A4 landscape. If your printer overrides page settings, select Landscape in its print dialog.</p>

<!-- Filters Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="ledgerExam">Select Exam</label>
                <select name="exam" id="ledgerExam" class="form-select" required data-erp-change="results-ledger-action-1" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>">
                    <option value="">Choose Exam</option>
                    <?php foreach ($exams as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $exam_id == $e['id'] ? 'selected' : '' ?>>
                        <?= sanitize($e['name']) ?> (<?= $e['year_bs'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="ledgerClass">Select Class</label>
                <select name="class" id="ledgerClass" class="form-select" required data-erp-change="results-ledger-action-2" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>">
                    <option value="">Choose Class</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                        <?= sanitize($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="ledgerSection">Select Section (Optional)</label>
                <select name="section" id="ledgerSection" class="form-select" data-erp-change="results-ledger-action-3" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>">
                    <option value="">All <?= $canFullLedger?'':'Assigned ' ?>Sections</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $section_id == $s['id'] ? 'selected' : '' ?>>
                        Section <?= sanitize($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="ledgerSubject">Subject</label>
                <select name="subject" id="ledgerSubject" class="form-select" data-erp-change="results-ledger-action-4" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>" <?= !$all_subjects?'disabled':'' ?>>
                    <?php if($canFullLedger): ?><option value="">All Subjects (full-class ledger)</option><?php endif; ?>
                    <?php if(!$all_subjects): ?><option value="">Select an exam and class first</option><?php endif; ?>
                    <?php foreach($all_subjects as $sub): ?><option value="<?= (int)$sub['subject_id'] ?>" <?= $subject_id===(int)$sub['subject_id']?'selected':'' ?>><?= sanitize($sub['subject_name'].($sub['subject_code']?' ('.$sub['subject_code'].')':'')) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="w-100"><label class="form-label" for="ledgerMode">Print mode</label><select name="mode" id="ledgerMode" class="form-select" data-erp-change="results-ledger-action-5" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>"><option value="grade" <?= $ledgerMode==='grade'?'selected':'' ?>>Grade Only</option><option value="marks" <?= $ledgerMode==='marks'?'selected':'' ?>>Marks Only</option></select></div>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-info w-100">
                    <i class="fas fa-sync"></i> Refresh Grid
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($exam_id && $class_id && count($subjects) > 0): ?>
<!-- Sorting & Filtering controls -->
<div class="card mb-4 no-print bg-light">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-sort"></i> Sort By</span>
                    <select id="sortSelector" class="form-select form-select-sm" data-erp-change="results-ledger-action-6" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>">
                        <option value="roll">Roll Number (Ascending)</option>
                        <option value="name">Student Name (A-Z)</option>
                        <option value="gpa-desc">Grade / percentage (High to Low)</option>
                        <option value="gpa-asc">Grade / percentage (Low to High)</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-filter"></i> Filter Status</span>
                    <select id="filterSelector" class="form-select form-select-sm" data-erp-change="results-ledger-action-7" data-erp-change-args="<?= schoolPageActionArgs("results-ledger",[]) ?>">
                        <option value="all">Show All Candidates</option>
                        <option value="pass">Show <?= ($selected_class && $selected_class['grading_type'] === 'gpa') ? 'Graded' : 'Pass' ?> Only</option>
                        <option value="fail">Show <?= ($selected_class && $selected_class['grading_type'] === 'gpa') ? 'Not Graded' : 'Fail / NG' ?> Only</option>
                        <option value="incomplete">Show Incomplete Only</option>
                        <option value="na">Show N/A Only</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Grid -->
<div class="card ledger-print-card">
    <div class="card-body p-0">
        <!-- Print Header -->
        <div class="d-none d-print-block text-center mb-4">
            <h4><?= sanitize($school['name_en']??APP_NAME) ?></h4>
            <?php if(!empty($school['address'])): ?><p class="mb-1"><?= sanitize($school['address']) ?></p><?php endif; ?>
            <h6><?= $isSubjectLedger?'Subject Marks Ledger':'Full-Class Result Ledger Sheet' ?></h6>
            <p class="mb-0">
                Exam: <strong><?= sanitize($exams[array_search($exam_id, array_column($exams, 'id'))]['name'] ?? '') ?></strong> | 
                Class: <strong><?= sanitize($selected_class['name']??'') ?></strong> |
                Academic year: <strong><?= sanitize($exam['year_bs']??$exams[array_search($exam_id,array_column($exams,'id'))]['year_bs']??'') ?></strong> |
                Section: <strong><?= $section_id?sanitize(schoolRecord($db,'sections',$section_id,(int)$school_id)['name']):($canFullLedger?'All':'All assigned') ?></strong>
                <?php if($isSubjectLedger): ?><br>Subject: <strong><?= sanitize($subjects[0]['subject_name']) ?></strong><?php endif; ?>
            </p>
            <hr>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle text-center mb-0" id="ledgerTable" style="font-size: 0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="align-middle">Roll No.</th>
                        <th rowspan="2" class="align-middle">Symbol No.</th>
                        <th rowspan="2" class="align-middle text-start" style="min-width: 150px;">Student Name</th>
                        <?php foreach ($subjects as $sub): ?>
                        <th colspan="<?= $subjectColumns ?>" class="small align-middle">
                            <?= sanitize($sub['subject_name']) ?>
                            <?php $headerResult=erpCalculateSubjectResult($sub,[],$exam); ?>
                            <?php if ($ledgerMode === 'grade'): ?>
                                <br><small class="text-muted">CH: <?= ledgerMark($headerResult['credit_hours']) ?></small>
                            <?php else: ?>
                                <br><small class="text-muted">FM: <?= ledgerMark($headerResult['theory_full']+$headerResult['practical_full']) ?></small>
                            <?php endif; ?>
                        </th>
                        <?php endforeach; ?>
                        <?php if($ledgerMode==='marks'): ?><th rowspan="2" class="align-middle"><?= $isSubjectLedger?'Subject Total':'Total Obtained' ?></th><?php endif; ?>
                        <th rowspan="2" class="align-middle"><?= $ledgerMode==='marks' ? ($isSubjectLedger?'Subject Percentage':'Percentage') : ($isSubjectLedger?'Subject GPA':'GPA') ?></th>
                        <th rowspan="2" class="align-middle"><?= $ledgerMode==='marks'?'Division':'Result' ?></th>
                    </tr>
                    <tr>
                        <?php foreach ($subjects as $sub): ?>
                        <?php if($ledgerMode==='grade'): ?>
                        <th style="font-size: 0.7rem;">GRADE (TH)</th>
                        <th style="font-size: 0.7rem;">GRADE (IN)</th>
                        <th style="font-size: 0.7rem;">FINAL GRADE</th>
                        <th style="font-size: 0.7rem;">GRADE POINT</th>
                        <?php else: ?>
                        <th style="font-size: 0.7rem;">TH</th>
                        <th style="font-size: 0.7rem;">PR</th>
                        <th style="font-size: 0.7rem;">TOTAL</th>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                    <?php foreach ($ledger_data as $row): ?>
                    <tr data-roll="<?= sanitize($row['roll']??999999) ?>" data-name="<?= sanitize($row['name']) ?>" data-gpa="<?= $row['gpa']??-1 ?>" data-status="<?= ['Pass'=>'pass','Incomplete'=>'incomplete','N/A'=>'na'][$row['status']]??'fail' ?>">
                        <td><strong><?= sanitize($row['roll']??'-') ?></strong></td>
                        <td><?= sanitize($row['symbol']??'-') ?></td>
                        <td class="text-start"><strong><?= sanitize($row['name']) ?></strong></td>
                        
                        <?php foreach ($subjects as $sub): ?>
                            <?php 
                            $sm = $row['subjects'][$sub['subject_id']] ?? null; 
                            if ($sm === 'N/A'):
                            ?>
                            <td colspan="<?= $subjectColumns ?>" class="bg-secondary-subtle text-muted">N/A</td>
                            <?php elseif ($sm): ?>
                                <?php if ($ledgerMode === 'grade'): ?>
                                    <td><?= ($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS'||$sm['theory']===null?'—':sanitize($sm['external_grade']) ?></td>
                                    <td><?= $sm['practical_full']<=0||$sm['practical']===null?'—':sanitize($sm['internal_grade']) ?></td>
                                    <td class="fw-bold <?= $sm['grade']==='NG'?'text-danger':'text-primary' ?>"><?= $sm['incomplete']?'—':sanitize($sm['grade']) ?></td>
                                    <td><?= $sm['incomplete']?'—':number_format($sm['gp'],2) ?></td>
                                <?php else: ?>
                                    <td><?= ($sub['assessment_method']??'')==='THEMATIC_CONTINUOUS'||$sm['theory_full']<=0?'—':ledgerMark($sm['theory']) ?></td>
                                    <td><?= $sm['practical_full'] > 0 ? ledgerMark($sm['practical']) : '—' ?></td>
                                    <td><?= ledgerMark($sm['total']) ?></td>
                                <?php endif; ?>
                            <?php else: ?>
                            <td colspan="<?= $subjectColumns ?>" class="text-muted small">Incomplete</td>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <?php if($ledgerMode==='marks'): ?><td><?= ledgerMark($row['total_marks']) ?></td><?php endif; ?>
                        <td class="fw-bold text-success font-monospace">
                            <?php if(in_array($row['status'],['Incomplete','N/A'],true)): ?>—
                            <?php elseif ($ledgerMode === 'marks'): ?>
                                <?= number_format($row['gpa'], 2) ?>%
                            <?php else: ?>
                                <?= number_format($row['gpa'], 2) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $row['status'] === 'Pass' ? 'success' : 'danger' ?>">
                                <?= in_array($row['status'],['Incomplete','N/A'],true)?$row['status']:($ledgerMode==='grade'?($row['status']==='Pass'?'Graded':'Not Graded'):sanitize($row['division'])) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small text-muted p-3 mb-0">Students: <?= count($ledger_data) ?>. — means an incomplete mark; N/A means the student does not take the optional subject. <?= $isSubjectLedger?'This is a selected-subject ledger, not the overall class result.':'' ?></p>
        <?= schoolPrintSignatures($db,(int)$school_id,$school,(int)$class_id) ?>
    </div>
</div>

<?php schoolPageModule("results-ledger"); ?>

<?php else: ?>
<div class="card text-center py-5 no-print">
    <div class="card-body">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h5>Please select an Exam and Class to display the Result Ledger Grid.</h5>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/header.php';
?>
