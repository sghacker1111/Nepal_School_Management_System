<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/database.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/functions.php';
require_once __DIR__.'/../../includes/symbol_service.php';
require_once __DIR__.'/../../includes/report_issue.php';
Auth::requireRole(['super_admin','school_admin','principal','exam_officer','teacher']);
$db=getDB();$school_id=(int)Auth::getSchoolId();
$exam_id=(int)($_GET['exam_id']??0);$class_id=(int)($_GET['class_id']??0);
$exam=schoolRecord($db,'exams',$exam_id,$school_id);
$class=$class_id?schoolRecord($db,'classes',$class_id,$school_id):null;
$q=$db->prepare('SELECT * FROM schools WHERE id=?');$q->execute([$school_id]);$school=$q->fetch();
$q=$db->prepare('SELECT id,name FROM classes WHERE school_id=? AND is_active=1 ORDER BY id');$q->execute([$school_id]);$classes=$q->fetchAll();
try{$issue=reportIssueDate($exam,$_GET);}catch(InvalidArgumentException $e){denySchoolRequest($e->getMessage(),422);}
$mode=$_GET['student_mode']??'all';if(!in_array($mode,['all','choose'],true))denySchoolRequest('Choose all students or selected students.',422);
$selectedIds=[];
foreach((array)($_GET['selected']??[]) as $id){if(!ctype_digit((string)$id))denySchoolRequest('Choose valid student IDs.',422);$selectedIds[(int)$id]=true;}
$selectedStudents=$class?examSymbolStudents($db,$school_id,$exam,$class_id,['ids'=>array_keys($selectedIds)]):[];
if(count($selectedStudents)!==count($selectedIds))denySchoolRequest('A selected student does not belong to this class and examination year.',422);
$selected=$selectedIds;
$search=trim((string)($_GET['search']??''));$page=max(1,(int)($_GET['page']??1));
$filteredCount=$class&&$mode==='choose'?examSymbolStudentCount($db,$school_id,$exam,$class_id,$search):0;
$pages=max(1,(int)ceil($filteredCount/10));$page=min($pages,$page);
$list=$class&&$mode==='choose'?examSymbolStudents($db,$school_id,$exam,$class_id,['search'=>$search,'limit'=>10,'offset'=>($page-1)*10]):[];
$render=($_GET['render']??'')==='1'&&$class;$error='';
$students=$render?($mode==='all'?examSymbolStudents($db,$school_id,$exam,$class_id):$selectedStudents):[];
if($render&&!$students){$error='Choose at least one student to print, or select a class with students.';$render=false;}
$student_chunks=array_chunk($students,4);$pageTitle='Print Admit Cards'.($class?': '.$class['name']:'');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?></title>
    <?php require_once __DIR__.'/../../includes/frontend.php'; ?>
    <link href="<?= schoolAsset('app.css') ?>" rel="stylesheet">
    <link href="<?= schoolAsset('icons.css') ?>" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
            color: #333;
        }

        .no-print-bar {
            background-color: #fff;
            padding: 15px 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-family: inherit;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: bold;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: background-color 0.2s;
        }

        .btn-primary {
            background-color: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background-color: #2980b9;
        }

        .btn-secondary {
            background-color: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #7f8c8d;
        }

        .print-layout {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        /* Standard A4 layout styling */
        .admit-card-page {
            width: 210mm;
            height: 297mm;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            padding: 8mm;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 8mm;
            page-break-after: always;
            break-after: page;
        }

        .admit-card-page:last-child {page-break-after: auto;break-after: auto;}
        .admit-card {
            border: 2px solid #000;
            border-radius: 6px;
            padding: 10px;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background-color: #fff;
            overflow: hidden;
            height: 100%;
        }

        .admit-card-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
            margin-bottom: 8px;
        }

        .school-info {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: flex-start;
            text-align: left;
            gap: 8px;
            width: 100%;
        }

        .school-logo {
            height: 48px;
            width: 48px;
            flex: 0 0 48px;
            object-fit: contain;
        }

        .school-name {
            font-size: 13px;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
            color: #000;
        }

        .school-subtext {
            font-size: 9px;
            margin: 2px 0 0 0;
            color: #444;
            line-height: 1.2;
        }

        .card-title-badge {
            background-color: #000;
            color: #fff;
            font-size: 11px;
            font-weight: bold;
            padding: 2px 10px;
            border-radius: 3px;
            display: inline-block;
            margin-top: 5px;
            letter-spacing: 1px;
        }

        .academic-year {
            font-size: 9px;
            margin: 3px 0 0 0;
            font-weight: bold;
        }

        .admit-card-body {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
            align-items: flex-start;
        }

        .student-photo-container {
            width: 75px;
            height: 90px;
            border: 1px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #fafafa;
            flex-shrink: 0;
            border-radius: 4px;
            overflow: hidden;
        }

        .student-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-info-grid {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-item {
            display: flex;
            font-size: 11px;
            line-height: 1.3;
        }

        .info-label {
            font-weight: bold;
            width: 90px;
            flex-shrink: 0;
        }

        .info-val {
            flex-grow: 1;
        }

        .exam-info-box {
            border: 1px dashed #000;
            background-color: #fcfcfc;
            padding: 6px;
            border-radius: 4px;
            margin-bottom: 8px;
        }

        .exam-info-title {
            font-size: 11px;
            font-weight: bold;
            text-align: center;
            margin: 0 0 4px 0;
            text-decoration: underline;
        }

        .instructions-list {
            margin: 0;
            padding-left: 12px;
            font-size: 8.5px;
            color: #333;
            line-height: 1.3;
        }

        .admit-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 8px;
            padding-top: 5px;
            border-top: 1px solid #ccc;
        }

        .sig-box {
            text-align: center;
            width: 31%;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: center;
            height: 48px;
        }

        .sig-image-placeholder {
            height: 32px;
            width: 100%;
        }

        .sig-image {
            max-height: 32px;
            max-width: 100%;
            object-fit: contain;
        }

        .sig-line {
            border-top: 1px solid #000;
            width: 100%;
            font-size: 8px;
            font-weight: bold;
            padding-top: 2px;
            margin-top: 2px;
            text-align: center;
            white-space: nowrap;
        }

        @media screen and (max-width: 850px) {
            .print-layout {align-items: stretch; padding: 12px;}
            .admit-card-page {width: 100%; height: auto; grid-template-columns: 1fr; grid-template-rows: none; padding: 12px; gap: 16px;}
            .admit-card {height: auto; min-height: 460px;}
            .school-name {font-size: 16px;}
            .info-item, .info-val, .info-label {font-size: 12px;}
            .instructions-list {font-size: 11px;}
        }
        @media print {
            body {
                background-color: #fff;
            }

            .no-print-bar, .no-print {
                display: none !important;
            }

            .admit-card-page {
                box-shadow: none;
                margin: 0;
                padding: 8mm;
            }

            @page {
                size: A4 portrait;
                margin: 0;
            }
        }
    </style>
</head>
<body>

<section class="no-print bg-white p-3 mb-3">
<h2>Generate & Print Admit Cards — <?= sanitize($exam['name']) ?></h2>
<a href="<?= schoolRouteUrl('modules/exams/view.php') ?>?id=<?= $exam_id ?>" class="btn btn-secondary">Back to Exam</a>
<?php if($class): ?><a href="<?= schoolRouteUrl('modules/exams/symbols.php') ?>?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>" class="btn btn-secondary">Manage Symbol Numbers</a><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger mt-3"><?= sanitize($error) ?></div><?php endif; ?>
<form method="GET" id="admit_selector" class="row g-3 mt-1">
<input type="hidden" name="exam_id" value="<?= $exam_id ?>"><div id="selected_ids"></div>
<div class="col-md-4"><label for="class_id" class="form-label">First select class</label><select class="form-select" id="class_id" name="class_id" required onchange="admitSelections.clear();document.getElementById('selected_ids').replaceChildren();this.form.submit()"><option value="">Choose Class</option><?php foreach($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $class_id==$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label for="student_mode" class="form-label">Students to print</label><select class="form-select" name="student_mode" id="student_mode" onchange="toggleAdmitStudents()"><option value="all" <?= $mode==='all'?'selected':'' ?>>All students in the class</option><option value="choose" <?= $mode==='choose'?'selected':'' ?>>Choose students</option></select></div>
<?php include __DIR__.'/../../includes/report_issue_controls.php'; ?>
<div class="col-12" id="choose_students" <?= $mode==='all'?'hidden':'' ?>>
 <label for="search" class="form-label">Search name, Symbol No. or Roll No.</label>
 <div class="d-flex gap-2"><input type="search" class="form-control" id="search" name="search" value="<?= sanitize($search) ?>" placeholder="Student name, symbol or registration roll"><button class="btn btn-secondary" name="page" value="1" type="submit">Search</button></div>
 <p class="mt-2"><span id="selection_count"><?= count($selected) ?></span> students selected. Selections are kept while searching and changing pages.</p>
 <?php if($mode==='choose'): ?>
 <table class="table table-striped"><thead><tr><th>Select</th><th>Name</th><th>Roll No.</th><th>Symbol No.</th></tr></thead><tbody>
 <?php foreach($list as $s): ?><tr><td><input type="checkbox" class="admit-check" value="<?= $s['id'] ?>" <?= isset($selected[$s['id']])?'checked':'' ?> aria-label="Select <?= sanitize($s['first_name_en'].' '.$s['last_name_en']) ?>"></td><td><?= sanitize($s['first_name_en'].' '.$s['last_name_en']) ?></td><td><?= sanitize($s['roll_number']) ?></td><td><?= sanitize($s['symbol_no']??'Not assigned') ?></td></tr><?php endforeach; ?>
 </tbody></table>
 <div class="d-flex gap-2 align-items-center"><button class="btn btn-secondary" type="submit" name="page" value="<?= max(1,$page-1) ?>" <?= $page===1?'disabled':'' ?>>Previous</button><span>Page <?= $page ?> / <?= $pages ?> — <?= $filteredCount ?> matches (10 per page)</span><button class="btn btn-secondary" type="submit" name="page" value="<?= min($pages,$page+1) ?>" <?= $page===$pages?'disabled':'' ?>>Next</button><button class="btn btn-secondary" type="button" onclick="clearAdmitSelection()">Clear selection</button></div>
 <?php else: ?><button class="btn btn-secondary mt-2" type="submit">Show student list</button><?php endif; ?>
</div>
<div class="col-12"><button class="btn btn-primary" type="submit" name="render" value="1">Generate Cards</button><?php if($render): ?> <button class="btn btn-primary" type="button" onclick="window.print()">Print <?= count($students) ?> Cards</button><?php endif; ?></div>
</form>
</section>
<script>
const admitSelections=new Set(<?= json_encode(array_map('strval',array_keys($selected))) ?>);
function syncAdmitSelection(){const target=document.getElementById('selected_ids');target.replaceChildren();admitSelections.forEach(id=>{const i=document.createElement('input');i.type='hidden';i.name='selected[]';i.value=id;target.append(i);});document.getElementById('selection_count').textContent=admitSelections.size;}
function toggleAdmitStudents(){const choose=document.getElementById('student_mode').value==='choose';document.getElementById('choose_students').hidden=!choose;syncAdmitSelection();if(choose)document.getElementById('admit_selector').submit();}
function clearAdmitSelection(){admitSelections.clear();document.querySelectorAll('.admit-check').forEach(i=>i.checked=false);syncAdmitSelection();}
document.querySelectorAll('.admit-check').forEach(i=>i.addEventListener('change',()=>{if(i.checked)admitSelections.add(i.value);else admitSelections.delete(i.value);syncAdmitSelection();}));
document.getElementById('admit_selector').addEventListener('submit',syncAdmitSelection);syncAdmitSelection();
</script>

<div class="print-layout">
    <?php foreach ($student_chunks as $chunk_index => $chunk): ?>
    <div class="admit-card-page">
        <?php foreach ($chunk as $student): ?>
        <div class="admit-card">
            <!-- Header -->
            <div class="admit-card-header">
                <div class="school-info">
                    <?php if (!empty($school['logo'])): ?>
                    <img src="<?= APP_URL ?>/uploads/<?= $school['logo'] ?>" alt="Logo" class="school-logo mb-1">
                    <?php endif; ?>
                    <div style="text-align: center;">
                        <h4 class="school-name"><?= sanitize($school['name_en']) ?></h4>
                        <p class="school-subtext">
                            <?= sanitize($school['address'] ?? '') ?>
                            <?php if (!empty($school['phone'])): ?> | Phone: <?= sanitize($school['phone']) ?><?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="card-title-badge">EXAM ADMIT CARD</div>
                <div class="academic-year">Academic Year: <?= getAcademicYearLabel($student['year_bs'], $student['year_ad']) ?></div>
            </div>

            <!-- Body -->
            <div class="admit-card-body">
                <div class="student-photo-container">
                    <?php if ($student['photo']): ?>
                    <img src="<?= APP_URL ?>/uploads/students/<?= $student['photo'] ?>" alt="Student Photo" class="student-photo">
                    <?php else: ?>
                    <i class="fas fa-user fa-2x text-muted" style="opacity: 0.5;"></i>
                    <?php endif; ?>
                </div>
                <div class="student-info-grid">
                    <div class="info-item">
                        <span class="info-label">Name:</span>
                        <span class="info-val" style="font-weight: bold; font-size: 11px; text-transform: uppercase;">
                            <?= sanitize($student['first_name_en'] . ' ' . $student['last_name_en']) ?>
                        </span>
                    </div>
                    <?php if ($student['first_name_np']): ?>
                    <div class="info-item">
                        <span class="info-label">नाम (नेपाली):</span>
                        <span class="info-val" style="font-size: 10px;">
                            <?= sanitize($student['first_name_np'] . ' ' . $student['last_name_np']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Class:</span>
                        <span class="info-val"><?= sanitize($class['name']) ?> <?php if ($student['section_name']): ?>- <?= sanitize($student['section_name']) ?><?php endif; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Roll Number:</span>
                        <span class="info-val"><?= sanitize($student['roll_number'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Symbol No.</span>
                        <span class="info-val" style="font-weight: bold; text-decoration: underline; font-size: 11px;">
                            <?= sanitize($student['symbol_no'] ?? 'Not assigned') ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Gender:</span>
                        <span class="info-val"><?= sanitize($student['gender']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date of Birth:</span>
                        <span class="info-val"><?= sanitize($student['dob_ad']?schoolDateLabel($student['dob_ad'],$issue['display'],$issue['format']):(($student['dob_bs']??'')?($student['dob_bs'].' BS'):'—')) ?></span>
                    </div>
                </div>
            </div>

            <!-- Exam Information -->
            <div class="exam-info-box">
                <div class="exam-info-title">EXAMINATION INFORMATION</div>
                <div class="info-item" style="margin-bottom: 2px;">
                    <span class="info-label">Exam Name:</span>
                    <span class="info-val" style="font-weight: bold;"><?= sanitize($exam['name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Date Range:</span>
                    <span class="info-val"><?= sanitize(schoolDateLabel($exam['start_date'],$issue['display'],$issue['format'])) ?> to <?= sanitize(schoolDateLabel($exam['end_date'],$issue['display'],$issue['format'])) ?></span>
                </div>
            </div>

            <div class="info-item"><span class="info-label">Date of Issue:</span><span class="info-val" data-issue-date="<?= sanitize($issue['date']) ?>"><?= sanitize($issue['label']) ?></span></div>
            <!-- Instructions -->
            <div style="margin-bottom: 5px;">
                <div style="font-size: 9px; font-weight: bold; margin-bottom: 2px;">Instructions to Candidates:</div>
                <ol class="instructions-list">
                    <li>Candidates must bring this admit card to the examination hall.</li>
                    <li>Arrive at the examination hall at least 15 minutes before the start.</li>
                    <li>No textbooks, calculators, or mobile devices are allowed in the exam hall.</li>
                </ol>
            </div>

            <div class="admit-card-footer">
                <?= printSignature(trim(($student['first_name_en']??'').' '.($student['last_name_en']??'')),'Student') ?>
                <?= schoolClassTeacherSignature($db,$school_id,$school,$class_id) ?>
                <?= printSignature($school['exam_coordinator_name']??'','Exam Coordinator',$school['exam_coordinator_signature']??null) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<script src="<?= APP_URL ?>/assets/js/nepali-date-converter.js"></script>
<script src="<?= APP_URL ?>/assets/js/school-dates.js"></script>
<script src="<?= APP_URL ?>/assets/js/notifications.js"></script>
</body>
</html>
