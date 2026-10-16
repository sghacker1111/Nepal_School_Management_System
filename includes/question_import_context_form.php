<?php
$importContextRequireTopic=$importContextRequireTopic??true;
$importContextFields=$importContextRequireTopic?['class'=>'Class','subject'=>'Subject','chapter'=>'Chapter','topic'=>'Topic']:['class'=>'Class','subject'=>'Subject','chapter'=>'Chapter'];
$importSelected=[];
foreach(['class','subject','chapter','topic'] as $importField){
    $importRaw=$importContextValues['import_'.$importField.'_id']??'';
    $importSelected[$importField]=is_scalar($importRaw)?(string)$importRaw:'';
}
$importChapterChoices=[];$importTopicChoices=[];
try{
    if($importSelected['subject'])$importChapterChoices=learningCatalogChapters($db,$importContextActor,(int)$importSelected['class'],(int)$importSelected['subject']);
    if($importContextRequireTopic&&$importSelected['chapter'])$importTopicChoices=learningCatalogTopics($db,$importContextActor,(int)$importSelected['class'],(int)$importSelected['subject'],(int)$importSelected['chapter']);
}catch(InvalidArgumentException $ignore){}
?>
<fieldset class="col-12 mb-2" data-question-import-context data-catalog-url="<?= sanitize(schoolRouteUrl('modules/learning/'.($importContextPage??($importContextKey==='bank'?'questions.php':'exam_questions.php')),['action'=>'import_catalog','bank_ui'=>1])) ?>">
<legend class="fs-6 fw-bold">Select existing catalog entries</legend>
<p class="small text-muted">Every row must contain matching <?= $importContextRequireTopic?'Class, Subject, Chapter and Topic':'Class, Subject and Chapter' ?> values. A mismatch rejects the whole file. Imports never create catalog entries.<?= $importContextRequireTopic?'':' Exam questions cover the chapter; a Topic column is not needed.' ?></p>
<script type="application/json" data-import-subjects><?= json_encode($importContextSubjects,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<div class="row g-2">
<?php foreach($importContextFields as $importField=>$importLabelText): ?>
<div class="col-md-6"><label for="import-<?= $importContextKey.'-'.$importField ?>" class="form-label"><?= $importLabelText ?> *</label>
<select id="import-<?= $importContextKey.'-'.$importField ?>" name="import_<?= $importField ?>_id" data-import-field="<?= $importField ?>" class="form-select" required>
<option value="">Choose <?= strtolower($importLabelText) ?></option>
<?php
$importOptions=match($importField){'class'=>array_values(array_filter($importContextClasses,static fn($c)=>!empty($importContextSubjects[(int)$c['id']]))),'subject'=>$importContextSubjects[(int)$importSelected['class']]??[],'chapter'=>$importChapterChoices,'topic'=>$importTopicChoices};
foreach($importOptions as $importOption): ?>
<option value="<?= (int)$importOption['id'] ?>" <?= $importSelected[$importField]===(string)$importOption['id']?'selected':'' ?>><?= sanitize($importOption['display_name']??$importOption['name']??$importOption['name_en']) ?></option>
<?php endforeach; ?></select></div>
<?php endforeach; ?>
</div><div data-import-context-status class="small text-muted mt-2" role="status" aria-live="polite">Choose all <?= $importContextRequireTopic?'four':'three' ?> entries before downloading a sample or uploading a file. If a catalog entry is missing, ask super admin to add it in Curriculum &amp; Topics Manager first.</div>
</fieldset>
