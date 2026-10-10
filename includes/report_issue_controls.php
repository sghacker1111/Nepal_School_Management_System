<?php // Shared by single and bulk print forms; $issue is validated by reportIssueDate(). ?>
<div class="col-md-4 text-start">
    <label for="issue_date_mode" class="form-label">Date of Issue</label>
    <select class="form-select" id="issue_date_mode" name="issue_date_mode" onchange="toggleReportIssueDate()">
        <option value="auto" <?= $issue['mode']==='auto'?'selected':'' ?>>Today automatically (Nepal time)</option>
        <option value="manual" <?= $issue['mode']==='manual'?'selected':'' ?>>Manual date (AD)</option>
    </select>
    <div class="form-text">Automatic date: <?= sanitize($issue['today']) ?> AD. Applied to every printed card.</div>
</div>
<div class="col-md-4 text-start" id="manual_issue_date_group" <?= $issue['mode']==='auto'?'hidden':'' ?>>
    <label for="issue_date" class="form-label">Manual Issue Date (AD)</label>
    <input type="date" class="form-control" id="issue_date" name="issue_date" value="<?= sanitize($issue['manual']?:$issue['today']) ?>" <?= $issue['mode']==='manual'?'required':'disabled' ?>>
</div>
<script>
function toggleReportIssueDate(){
    const manual=document.getElementById('issue_date_mode').value==='manual';
    document.getElementById('manual_issue_date_group').hidden=!manual;
    const date=document.getElementById('issue_date');date.disabled=!manual;date.required=manual;
}
<?php if(($_GET['print']??'')==='1'): ?>
window.addEventListener('load',()=>window.print());
<?php endif; ?>
</script>
