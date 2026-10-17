<?php
// Share one relationship-aware selector implementation between add and edit screens.
$selectorQuery=getDB()->prepare('SELECT cs.class_id,s.id,s.name_en FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND cs.is_optional=1 AND cs.is_active=1 AND s.is_hidden=0');
$selectorQuery->execute([Auth::getSchoolId()]);$selectorSubjects=$selectorQuery->fetchAll();
?>
<?php schoolPageModule("shared-enrollment-selectors");schoolPageValue("shared-enrollment-selectors-value-0",(string)(json_encode($sections,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)));
schoolPageValue("shared-enrollment-selectors-value-1",(string)(json_encode($selectorSubjects,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))); ?>
