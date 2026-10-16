<?php
// Render only catalog IDs; submitted allocations are checked again by the service.
$chapterAllocations=is_array($_POST['chapters']??null)?$_POST['chapters']:[];
$chapterValue=static fn($value,$fallback='0')=>is_scalar($value)?(string)$value:$fallback;
?>
<section class="card card-body mb-3" id="examChapterEditor">
    <h3 class="h5">Generate by chapter</h3>
    <p class="text-muted">Enter a count and total marks for each chapter you want. Leave the count at <strong>0</strong> to skip a chapter. Exam questions cover the whole chapter, including combined topics.</p>
    <div class="row g-3 mb-3">
        <div class="col-md-6"><label for="chapterGenerationKind" class="form-label">Generate</label><select id="chapterGenerationKind" name="generation_kind" class="form-select"><option value="written" <?= $generationKind==='written'?'selected':'' ?>>Written questions (no MCQs)</option><option value="mcq" <?= $generationKind==='mcq'?'selected':'' ?>>Multiple Choice Questions only</option></select></div>
        <div class="col-md-6"><label for="chapterDuration" class="form-label">Paper duration (minutes)</label><input id="chapterDuration" name="duration_minutes" class="form-control" type="number" min="0.01" max="600" step="0.01" required value="<?= sanitize($chapterValue($_POST['duration_minutes']??180,'180')) ?>"></div>
    </div>
    <?php if(!$chapters): ?><p class="alert alert-info">No chapters are available for this class subject. Ask the super-admin to set up the curriculum first.</p><?php else: ?>
    <div class="table-responsive"><table class="table align-middle exam-chapter-table"><thead><tr><th scope="col">Chapter</th><th scope="col">No. of Questions</th><th scope="col">Marks <span class="small fw-normal">(chapter total)</span></th></tr></thead><tbody>
    <?php foreach($chapters as $chapter): $cid=(int)$chapter['id'];$allocation=is_array($chapterAllocations[$cid]??null)?$chapterAllocations[$cid]:[]; ?>
        <tr data-exam-chapter="<?= $cid ?>"><th scope="row"><span><?= sanitize($chapter['name']) ?></span>
            <div class="small text-muted fw-normal">All chapter questions</div>
        </th><td><input class="form-control" type="number" data-chapter-count name="chapters[<?= $cid ?>][count]" min="0" max="200" step="1" required value="<?= sanitize($chapterValue($allocation['count']??0)) ?>" aria-label="<?= sanitize($chapter['name']) ?>: number of questions"></td>
        <td><input class="form-control" type="number" data-chapter-marks name="chapters[<?= $cid ?>][marks]" min="0" max="1000" step="0.01" value="<?= sanitize($chapterValue($allocation['marks']??0)) ?>" aria-label="<?= sanitize($chapter['name']) ?>: total marks"></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <p id="examChapterTotals" class="fw-bold mb-1" role="status" aria-live="polite">Totals are calculated from chapters with a question count above 0.</p>
    <p class="small text-muted mb-0">Up to 200 questions, 50 selected chapters and 1,000 marks. Questions keep their saved marks; the generator must find an exact match. Use Advanced grid for mixed MCQ/written papers, difficulty or K/U/A/HA quotas.</p>
    <?php endif; ?>
</section>
<script src="<?= APP_URL ?>/assets/js/exam-chapter-editor.js?v=<?= (int)filemtime(__DIR__.'/../assets/js/exam-chapter-editor.js') ?>"></script>
