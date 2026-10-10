<?php $dateExam=$exam??[];$inputCalendar=$_POST['exam_date_calendar']??'AD'; ?>
<div class="mb-3"><label for="exam_date_calendar" class="form-label">Enter Exam Dates Using</label><select id="exam_date_calendar" name="exam_date_calendar" class="form-select" onchange="toggleExamCalendar()"><option value="AD" <?= $inputCalendar==='AD'?'selected':'' ?>>AD (English date)</option><option value="BS" <?= $inputCalendar==='BS'?'selected':'' ?>>BS (नेपाली मिति)</option></select></div>
<div class="row g-3 mb-4">
<?php foreach(['start_date'=>'Start Date','end_date'=>'End Date'] as $field=>$label): $ad=$_POST[$field]??$dateExam[$field]??'';try{$bs=schoolAdToBs($dateExam[$field]??null)??'';}catch(InvalidArgumentException $e){$bs='';}$bs=$_POST[$field.'_bs']??$bs; ?>
<div class="col-md-6"><label for="<?= $field ?>" class="form-label"><?= $label ?> (AD)</label><input type="date" class="form-control" id="<?= $field ?>" name="<?= $field ?>" value="<?= sanitize($ad) ?>" data-date-ad="<?= $field ?>" <?= $inputCalendar==='BS'?'disabled':'' ?>></div>
<div class="col-md-6"><label for="<?= $field ?>_bs" class="form-label"><?= $label ?> (BS)</label><input type="text" class="form-control" id="<?= $field ?>_bs" name="<?= $field ?>_bs" value="<?= sanitize($bs) ?>" placeholder="2083-06-24" data-date-bs="<?= $field ?>" <?= $inputCalendar==='AD'?'disabled':'' ?>></div>
<?php endforeach; ?>
</div>
<script>function toggleExamCalendar(){const bs=document.getElementById('exam_date_calendar').value==='BS';document.querySelectorAll('[data-date-ad]').forEach(x=>x.disabled=bs);document.querySelectorAll('[data-date-bs]').forEach(x=>x.disabled=!bs);}</script>
