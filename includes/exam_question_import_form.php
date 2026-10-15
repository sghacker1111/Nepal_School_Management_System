<?php if(!isset($actor,$canCreate)||!$canCreate)return; ?>
<details class="card card-body mb-3" id="examImportPanel" open>
<summary class="fw-bold text-primary">Import <?= ($examSection??'written')==='mcq'?'MCQs':'Exam Questions' ?> — Excel / CSV</summary>
<div class="mt-3">
<p>Choose the matching import below. Both support <strong>Normal Text</strong> and <strong>HTML Source</strong>, including LaTeX maths, in Excel (.xlsx) or UTF-8 CSV.</p>
<div class="row g-3 mb-3">
<?php foreach($examImportKinds??['mcq'=>'MCQs','other'=>'Other Questions'] as $importKind=>$importLabel):
    $isImportPost=($_POST['action']??'')==='import'&&($_POST['import_kind']??'')===$importKind;
    $importMode=$isImportPost?($_POST['import_content_mode']??'normal'):'normal';
    $importVisibility=$isImportPost?($_POST['import_visibility']??'private'):($actor['school_id']===0?'public':'private');
?>
<div class="col-12"><section class="border rounded p-3 h-100 <?= $isImportPost&&$error?'border-danger':'' ?>" data-exam-import-panel="<?= $importKind ?>" aria-labelledby="examImportTitle-<?= $importKind ?>">
<h3 class="h5" id="examImportTitle-<?= $importKind ?>">Import <?= $importLabel ?></h3>
<p class="small text-muted"><?= $importKind==='mcq'?'Only MCQs. Each question needs all four options A–D in English or Nepali, and Correct Option A, B, C or D.':'Very Short, Short, Long, Difference Between and Reasoning. Questions and model answers only — no options or correct-option column.' ?></p>
<form method="post" enctype="multipart/form-data" id="examImportForm-<?= $importKind ?>" data-exam-import-form="<?= $importKind ?>" class="row g-3">
<input type="hidden" name="action" value="import"><input type="hidden" name="import_kind" value="<?= $importKind ?>">
<?php $importContextKey=$importKind;$importContextActor=$actor;$importContextClasses=$classes;$importContextSubjects=$writerSubjects;$importContextPage=$route??'exam_questions.php';$importContextValues=$isImportPost?$_POST:['import_class_id'=>$class,'import_subject_id'=>$subject];include __DIR__.'/question_import_context_form.php'; ?>
<?php foreach(['normal'=>'Normal Text','html'=>'HTML Source'] as $sampleMode=>$sampleLabel): ?>
<div class="mb-3"><h4 class="h6"><?= $sampleLabel ?> samples</h4><div class="d-flex flex-wrap gap-2">
<?php foreach(['xlsx'=>'Excel sample','csv'=>'CSV sample'] as $format=>$download): ?>
<a class="btn btn-sm btn-outline-primary" data-exam-import-kind="<?= $importKind ?>" data-exam-import-mode="<?= $sampleMode ?>" aria-label="<?= $importLabel.' '.$sampleLabel.' '.$download ?>" href="?<?= sanitize(http_build_query(['action'=>'download_template','import_kind'=>$importKind,'format'=>$format,'content_mode'=>$sampleMode,'class_id'=>$class,'subject_id'=>$subject])) ?>"><?= $download ?></a>
<?php endforeach; ?></div></div>
<?php endforeach; ?>
<div class="col-12"><label for="examImportMode-<?= $importKind ?>" class="form-label"><?= $importLabel ?> import method</label><select id="examImportMode-<?= $importKind ?>" name="import_content_mode" class="form-select"><option value="normal" <?= $importMode==='normal'?'selected':'' ?>>Normal Text</option><option value="html" <?= $importMode==='html'?'selected':'' ?>>HTML Source</option></select></div>
<div class="col-12"><label for="examImportFile-<?= $importKind ?>" class="form-label"><?= $importLabel ?> Excel / CSV file</label><input id="examImportFile-<?= $importKind ?>" type="file" name="excel_file" accept=".xlsx,.csv" class="form-control" required></div>
<div class="col-12"><label for="examImportVisibility-<?= $importKind ?>" class="form-label"><?= $importLabel ?> staff visibility</label><select id="examImportVisibility-<?= $importKind ?>" name="import_visibility" class="form-select"><option value="private" <?= $importVisibility==='private'?'selected':'' ?>>Private — creator, assigned teachers and admins</option><option value="public" <?= $importVisibility==='public'?'selected':'' ?>><?= $actor['school_id']===0?'Shared catalog staff':'Staff within this school' ?></option></select></div>
<div class="col-12"><button class="btn btn-success w-100" type="submit">Upload &amp; Import <?= $importLabel ?></button></div>
</form></section></div>
<?php endforeach; ?>
</div>
<p class="small text-muted">Samples use exactly the four catalog entries selected in that import form. Replace the question examples and retain the headings and matching catalog values. Class, Subject, Chapter and Topic must be filled in every row. A catalog mismatch rejects the whole upload. Other invalid question rows are skipped with a reason. Bilingual model answers are optional. Maximum 5 MB and 2,000 rows per upload; use the first worksheet. Zero saved rows is an error.</p>
<div class="alert alert-info py-2 mb-0">Source is assigned by your account: <?= $actor['school_id']===0?'Shared':'School' ?>. Leave Source blank or use Auto / <?= $actor['school_id']===0?'Shared':'School' ?>. No account, including super admin, can create chapters or topics by importing. Add missing catalog entries in Curriculum &amp; Topics Manager first. Imported questions are <strong>staff-only</strong> and never enter student practice. Release to students later through an individual question edit.</div>
</div></details>
<script src="<?= APP_URL ?>/assets/js/question-import-context.js?v=1" defer></script>
