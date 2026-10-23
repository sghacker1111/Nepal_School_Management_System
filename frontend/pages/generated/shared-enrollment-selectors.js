// Mechanically extracted from includes/enrollment_selectors.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

const enrollmentSections=JSON.parse(config.values["shared-enrollment-selectors-value-0"]||'null');
const enrollmentSubjects=JSON.parse(config.values["shared-enrollment-selectors-value-1"]||'null');
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

scope.expose({loadSections});
scope.bindActions({});
}
