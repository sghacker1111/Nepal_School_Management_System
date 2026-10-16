<?php
require_once __DIR__.'/exam_paper_service.php';
function examPageSelectors(PDO $db,array $actor):array {
    $classes=[];$byClass=[];foreach(examReadClasses($db,$actor) as $c){$subjects=examReadSubjects($db,$actor,(int)$c['id']);if(!$subjects)continue;$classes[]=$c;$byClass[$c['id']]=$subjects;}return [$classes,$byClass];
}
function examPageCatalog(PDO $db,array $actor,int $class,int $subject):array {
    if(!$subject)return [[],[],[],[]];examPaperScope($db,$actor,$class,$subject);$chapters=learningCatalogChapters($db,$actor,$class,$subject);return [$chapters,[],examGridList($db,$actor,$class,$subject),examPaperReferences($db,$actor,$class,$subject)];
}
function examSelectorForm(array $classes,array $subjectsByClass,int $class,int $subject,array $extra=[]):void { ?>
<form method="get" class="card card-body mb-3"><?php foreach($extra as $key=>$value): ?><input type="hidden" name="<?= sanitize($key) ?>" value="<?= sanitize($value) ?>"><?php endforeach; ?><div class="row g-2"><div class="col-md-5"><label class="form-label">Class</label><select class="form-select" name="class_id" onchange="this.form.subject_id.value='';this.form.submit()"><option value="">Choose class</option><?php foreach($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $class===(int)$c['id']?'selected':'' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label">Subject</label><select class="form-select" name="subject_id" <?= !$class?'disabled':'' ?>><option value="">Choose subject</option><?php foreach($subjectsByClass[$class]??[] as $s): ?><option value="<?= $s['id'] ?>" <?= $subject===(int)$s['id']?'selected':'' ?>><?= sanitize($s['display_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2 align-self-end"><button class="btn btn-primary">Load class subject</button></div></div></form>
<?php }
