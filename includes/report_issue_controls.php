<?php // $issue is validated by reportIssueDate(). ?>
<div class="col-md-4 text-start">
 <label for="issue_date_mode" class="form-label">Date of Issue</label>
 <select class="form-select" id="issue_date_mode" name="issue_date_mode" onchange="toggleReportIssueDate()">
  <option value="auto" <?= $issue['mode']==='auto'?'selected':'' ?>>Today automatically (Nepal time)</option>
  <option value="manual" <?= $issue['mode']==='manual'?'selected':'' ?>>Manual date</option>
 </select>
 <div class="form-text">Today: <?= sanitize(schoolDateLabel($issue['today'],'both','ymd')) ?></div>
</div>
<div class="col-md-4 text-start" id="manual_issue_date_group" <?= $issue['mode']==='auto'?'hidden':'' ?>>
 <label for="issue_calendar" class="form-label">Enter issue date using</label>
 <select id="issue_calendar" name="issue_calendar" class="form-select" onchange="changeIssueCalendar(this)">
  <?php foreach(['AD','BS'] as $cal): ?><option <?= $issue['calendar']===$cal?'selected':'' ?>><?= $cal ?></option><?php endforeach; ?>
 </select>
 <label for="issue_date" class="form-label mt-2">Manual issue date (YYYY-MM-DD)</label>
 <input type="<?= $issue['calendar']==='AD'?'date':'text' ?>" class="form-control" id="issue_date" name="issue_date" value="<?= sanitize($issue['input_date']) ?>" data-calendar="<?= $issue['calendar'] ?>" <?= $issue['mode']==='manual'?'required':'disabled' ?>>
</div>
<div class="col-md-2 text-start">
 <label for="display_calendar" class="form-label">Print calendar</label>
 <select name="display_calendar" id="display_calendar" class="form-select">
  <?php foreach(['both'=>'BS and AD','BS'=>'BS only','AD'=>'AD only'] as $cal=>$label): ?><option value="<?= $cal ?>" <?= $issue['display']===$cal?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
 </select>
</div>
<div class="col-md-2 text-start">
 <label for="date_format" class="form-label">Date format</label>
 <select name="date_format" id="date_format" class="form-select">
  <?php foreach(['long'=>'Day Month Year','ymd'=>'YYYY-MM-DD','dmy'=>'DD-MM-YYYY'] as $format=>$label): ?><option value="<?= $format ?>" <?= $issue['format']===$format?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
 </select>
</div>
<script>
function toggleReportIssueDate(){const manual=document.getElementById('issue_date_mode').value==='manual';document.getElementById('manual_issue_date_group').hidden=!manual;const date=document.getElementById('issue_date');date.disabled=!manual;date.required=manual;}
function changeIssueCalendar(select){const date=document.getElementById('issue_date');try{date.value=window.schoolConvertDate(date.value,date.dataset.calendar,select.value);date.dataset.calendar=select.value;date.type=select.value==='AD'?'date':'text';}catch(e){select.value=date.dataset.calendar;window.alert(e.message);}}
<?php if(($_GET['print']??'')==='1'): ?>window.addEventListener('load',()=>window.print());<?php endif; ?>
</script>
