<?php
// Share one relationship-aware selector implementation between add and edit screens.
$selectorQuery=getDB()->prepare('SELECT cs.class_id,s.id,s.name_en FROM class_subjects cs JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND cs.is_optional=1 AND cs.is_active=1 AND s.is_hidden=0');
$selectorQuery->execute([Auth::getSchoolId()]);$selectorSubjects=$selectorQuery->fetchAll();
?>
<script>
const enrollmentSections=<?= json_encode($sections,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const enrollmentSubjects=<?= json_encode($selectorSubjects,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function loadSections(){
    const classSelect=document.querySelector('[name="class_id"]');
    if(!classSelect)return;
    const classId=classSelect.value;
    const third=document.querySelector('[name="optional_subject_iii"]');
    if(third){const available=/^Class (11|12)$/.test(classSelect.selectedOptions[0]?.textContent.trim()||'');third.closest('.optional-third').hidden=!available;if(!available)third.value='';}
    const section=document.querySelector('[name="section_id"]');
    const populate=(select,rows,label)=>{
        if(!select)return;const selected=select.value;select.replaceChildren(new Option(label,''));
        rows.forEach(row=>select.add(new Option(row.name||row.name_en,row.id)));
        if(Array.from(select.options).some(option=>option.value===selected))select.value=selected;
    };
    populate(section,enrollmentSections.filter(row=>String(row.class_id)===classId),'No section');
    document.querySelectorAll('select[name^="optional_subject_"]').forEach(select=>populate(select,enrollmentSubjects.filter(row=>String(row.class_id)===classId),'No optional subject'));
}
document.addEventListener('DOMContentLoaded',()=>{const select=document.querySelector('[name="class_id"]');if(select){select.addEventListener('change',loadSections);loadSections();}});
</script>
