<?php // $issue is validated by reportIssueDate(). ?>
<div class="col-md-4 text-start">
 <label for="issue_date_mode" class="form-label">Date of Issue</label>
 <select class="form-select" id="issue_date_mode" name="issue_date_mode" data-erp-change="shared-report-issue-controls-action-0" data-erp-change-args="<?= schoolPageActionArgs("shared-report-issue-controls",[]) ?>">
  <option value="auto" <?= $issue['mode']==='auto'?'selected':'' ?>>Today automatically (Nepal time)</option>
  <option value="manual" <?= $issue['mode']==='manual'?'selected':'' ?>>Manual date</option>
 </select>
 <div class="form-text">Today: <?= sanitize(schoolDateLabel($issue['today'],'both','ymd')) ?></div>
</div>
<div class="col-md-4 text-start" id="manual_issue_date_group" <?= $issue['mode']==='auto'?'hidden':'' ?>>
 <label for="issue_calendar" class="form-label">Enter issue date using</label>
 <select id="issue_calendar" name="issue_calendar" class="form-select" data-erp-change="shared-report-issue-controls-action-1" data-erp-change-args="<?= schoolPageActionArgs("shared-report-issue-controls",[]) ?>">
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
<?php schoolPageModule("shared-report-issue-controls");schoolPageValue("shared-report-issue-controls-value-0",(($_GET['print']??'')==='1')?'true':'false');
if(($_GET['print']??'')==='1'):
endif; ?>
<?php if(in_array(schoolControllerPath(),['/modules/exams/admit_cards.php','/modules/results/report_card.php'],true)):
require_once __DIR__.'/frontend.php';$printControlConfig=['values'=>$GLOBALS['erpPageValues']??[]]; ?>
<div hidden data-erp-print-config="<?= htmlspecialchars(json_encode($printControlConfig,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR),ENT_QUOTES,'UTF-8') ?>"></div>
<script defer src="<?= schoolAsset('print-controls.js') ?>"></script>
<?php endif; ?>
