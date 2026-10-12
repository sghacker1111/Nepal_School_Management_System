<?php // Read-only preview shared by all permitted school roles. ?>
<div class="card mb-4"><div class="card-header"><h4><?= sanitize($class['name']) ?> — <?= $selectedProfile?sanitize($selectedProfile['name']).' (saved profile)':'active weekly timetable' ?></h4><p class="mb-0">Class teacher: <?= sanitize($teacherNames[(int)($class['class_teacher_id']??0)]??$class['class_teacher_name']??'Not assigned') ?></p></div>
<div class="table-responsive"><table class="table table-bordered mb-0 routine-timetable"><thead><tr><th>Day</th><?php for($p=1;$p<=$displayPeriods;$p++): ?><th>Period <?= $p ?></th><?php endfor; ?></tr></thead><tbody>
<?php foreach($days as $d=>$name): ?><tr><th><?= $name ?><?php if($hasLayout):foreach($layout[$d]['breaks'] as $kind=>$break):if($break['start']): ?><small class="d-block fw-normal mt-1"><?= $kind==='short'?'Short break':'Tiffin / long break' ?><br><?= sanitize($break['start'].'–'.$break['end']) ?></small><?php endif;endforeach;endif; ?></th>
<?php for($p=1;$p<=$displayPeriods;$p++):$cell=$grid[$d][$p]??[];$enabled=$p<=$layout[$d]['count'];$teacher=$teacherNames[$cell['teacher_id']??0]??''; ?><td class="<?= !$enabled?'table-secondary':'' ?>">
<?php if($enabled): ?><?php if($hasLayout): ?><small class="d-block text-muted"><?= sanitize($layout[$d]['times'][$p]['start'].'–'.$layout[$d]['times'][$p]['end']) ?></small><?php endif; ?>
<?= sanitize($subjectNames[$cell['subject_id']??0]??(empty($cell['subject_id'])?'Free':'Unavailable subject')) ?>
<?php if($teacher): ?><br><abbr title="<?= sanitize($teacher) ?>">(<?= sanitize(routineTeacherInitials($teacher)) ?>)</abbr><?php endif; ?>
<?php else: ?>—<?php endif; ?></td><?php endfor; ?></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if(!$hasLayout): ?><p class="text-muted small">Period times and breaks have not been configured for this legacy routine. An administrator can save and activate a timed profile below.</p><?php endif; ?>
<?php if($usedTeachers): ?><p class="small routine-legend"><?php foreach($usedTeachers as $id):$name=$teacherNames[$id]??''; ?> <span class="me-3"><?= sanitize(routineTeacherInitials($name)) ?> = <?= sanitize($name) ?></span><?php endforeach; ?></p><?php endif; ?>
