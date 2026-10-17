// Mechanically extracted from modules/exams/view.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function printAdmitCards() {
    const classId = document.getElementById('print_class_id').value;
    if (!classId) {
        Swal.fire('Error', 'Please select a class first.', 'error');
        return;
    }
    window.open(''+(config.values["exams-view-value-0"]??'')+'?exam_id='+(config.values["exams-view-value-1"]??'')+'&class_id=' + classId, '_blank');
}

// Dynamic class-subject dropdown filtering
const classSubjectsMap = JSON.parse(config.values["exams-view-value-2"]||'null');
const editMapping = JSON.parse(config.values["exams-view-value-3"]||'null');

document.addEventListener('DOMContentLoaded', function() {
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    const creditHoursInput = document.getElementById('credit_hours');
    const intCreditInput = document.getElementById('internal_credit_hours');
    const extCreditInput = document.getElementById('external_credit_hours');
    const thFullInput = document.getElementById('form_theory_full');
    const thPassInput = document.getElementById('form_theory_pass');
    const inFullInput = document.getElementById('form_practical_full');
    const inPassInput = document.getElementById('form_practical_pass');

    function updateSubjects() {
        const classId = classSelect.value;
        const selectedSubjectId = editMapping ? editMapping.subject_id : '';

        if (classId) {
            subjectSelect.innerHTML = '<option value="">Choose Subject</option>';
            if (classSubjectsMap[classId]) {
                classSubjectsMap[classId].forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name;
                    opt.dataset.thCredit = sub.th_credit ?? '';
                    opt.dataset.inCredit = sub.in_credit ?? '';
                    opt.dataset.thFull = sub.th_full ?? '';
                    opt.dataset.thPass = sub.th_pass ?? '';
                    opt.dataset.inFull = sub.in_full ?? '';
                    opt.dataset.inPass = sub.in_pass ?? '';
                    if (sub.id == selectedSubjectId) {
                        opt.selected = true;
                    }
                    subjectSelect.appendChild(opt);
                });
            } else {
                subjectSelect.innerHTML = '<option value="">No Subjects Mapped to Class</option>';
            }
        } else {
            subjectSelect.innerHTML = '<option value="">Choose Class First</option>';
        }
    }

    function onSubjectChange() {
        if (editMapping) return; // Do not overwrite when explicitly editing an existing mapping
        const selectedOpt = subjectSelect.options[subjectSelect.selectedIndex];
        if (selectedOpt && selectedOpt.value) {
            const thCr = parseFloat(selectedOpt.dataset.thCredit) || 0;
            const inCr = parseFloat(selectedOpt.dataset.inCredit) || 0;
            if (extCreditInput) extCreditInput.value = selectedOpt.dataset.thCredit || '';
            if (intCreditInput) intCreditInput.value = selectedOpt.dataset.inCredit || '';
            if (creditHoursInput) {
                creditHoursInput.value = (thCr + inCr > 0) ? (thCr + inCr) : '';
            }
            if (thFullInput && selectedOpt.dataset.thFull) thFullInput.value = selectedOpt.dataset.thFull;
            if (thPassInput && selectedOpt.dataset.thPass) thPassInput.value = selectedOpt.dataset.thPass;
            if (inFullInput && selectedOpt.dataset.inFull) inFullInput.value = selectedOpt.dataset.inFull;
            if (inPassInput && selectedOpt.dataset.inPass) inPassInput.value = selectedOpt.dataset.inPass;
        }
    }

    if (classSelect) {
        classSelect.addEventListener('change', updateSubjects);
        // Pre-fill / trigger on page load (essential for edit states)
        updateSubjects();
    }

    if (subjectSelect) {
        subjectSelect.addEventListener('change', onSubjectChange);
    }
});


scope.bindActions({"exams-view-action-0":function(event,args){printAdmitCards()}});
}
