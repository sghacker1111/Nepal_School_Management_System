<?php
/** Shared by individual and bulk percentage reports. Totals come from the grading service. */
$percentageIncomplete=(bool)array_filter($marks_elaborated,static fn($m)=>!empty($m['incomplete']));
?>
<table class="table table-report table-bordered align-middle mb-4 percentage-components">
    <thead><tr><th>S.N.</th><th>Subject</th><th>Full Marks</th><th>Obtained Marks</th><th>Total Obtained</th><th>Remarks</th></tr></thead>
    <tbody>
    <?php foreach ($marks_elaborated as $index => $m):
        $thematic=($m['assessment_method']??'')==='THEMATIC_CONTINUOUS';
        $components=$thematic
            ? [['Overall assessment',$m['theory_full'],$m['theory_obtained']]]
            : [['TH',$m['theory_full'],$m['theory_full']>0?$m['theory_obtained']:null],['PR',$m['practical_full'],$m['practical_full']>0?$m['practical_obtained']:null]];
        $span=count($components);
        $complete=!$m['incomplete'];
        $remark=$complete?($m['failed']?'Fail':'Pass'):'Incomplete';
        foreach ($components as $row => [$label,$full,$obtained]): ?>
        <tr>
            <?php if ($row===0): ?><td rowspan="<?= $span ?>" class="text-center"><?= $index+1 ?></td><?php endif; ?>
            <td><strong><?= sanitize($m['subject_name']) ?><?= $thematic?' — Overall assessment':' ('.$label.')' ?></strong></td>
            <td class="text-center"><?= $full>0?$full:'—' ?></td><td class="text-center"><?= $obtained===null?'—':sanitize((string)$obtained) ?></td>
            <?php if ($row===0): ?>
            <td rowspan="<?= $span ?>" class="text-center fw-bold"><?= $complete?(float)$m['theory_obtained']+(float)$m['practical_obtained']:'—' ?></td>
            <td rowspan="<?= $span ?>" class="text-center <?= $complete&&$m['failed']?'text-danger':'' ?>"><?= $remark ?></td>
            <?php endif; ?>
        </tr>
        <?php endforeach; endforeach; ?>
        <tr class="table-light fw-bold"><td colspan="2" class="text-end">Total Summary:</td><td class="text-center"><?= $grand_full ?></td><td></td><td class="text-center"><?= $percentageIncomplete?'—':$grand_obtained ?></td><td><?= sanitize($final_grade) ?></td></tr>
    </tbody>
</table>
