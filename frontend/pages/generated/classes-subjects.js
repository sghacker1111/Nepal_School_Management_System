// Mechanically extracted from modules/classes/subjects.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function populateSubjectDetails(selectElem) {
    const selectedOption = selectElem.options[selectElem.selectedIndex];
    if (!selectedOption || !selectedOption.value) return;

    const isOpt = selectedOption.getAttribute('data-optional');
    const typeSelect = document.getElementById('is_optional');
    if (typeSelect) {
        typeSelect.value = isOpt == '1' ? '1' : '0';
    }

    const thCh = parseFloat(selectedOption.getAttribute('data-th-ch')) || 0;
    const inCh = parseFloat(selectedOption.getAttribute('data-in-ch')) || 0;
    const thFm = parseFloat(selectedOption.getAttribute('data-th-fm')) || 0;
    const thPm = parseFloat(selectedOption.getAttribute('data-th-pm')) || 0;
    const inFm = parseFloat(selectedOption.getAttribute('data-in-fm')) || 0;
    const inPm = parseFloat(selectedOption.getAttribute('data-in-pm')) || 0;

    document.getElementById('map_th_ch').value = thCh.toFixed(2);
    document.getElementById('map_in_ch').value = inCh.toFixed(2);
    document.getElementById('map_th_fm').value = thFm.toFixed(2);
    document.getElementById('map_th_pm').value = thPm.toFixed(2);
    document.getElementById('map_in_fm').value = inFm.toFixed(2);
    document.getElementById('map_in_pm').value = inPm.toFixed(2);

    calculateMappingTotals();
}

function calculateMappingTotals() {
    const thCh = parseFloat(document.getElementById('map_th_ch').value) || 0;
    const inCh = parseFloat(document.getElementById('map_in_ch').value) || 0;
    const thFm = parseFloat(document.getElementById('map_th_fm').value) || 0;
    const inFm = parseFloat(document.getElementById('map_in_fm').value) || 0;

    const totalCh = (thCh + inCh).toFixed(2);
    const totalFm = (thFm + inFm).toFixed(2);

    document.getElementById('map_credit_hours').value = totalCh;
    document.getElementById('map_total_ch_display').innerText = totalCh + ' CH';
    document.getElementById('map_total_fm_display').innerText = totalFm + ' FM';
}

function calculateEditTotals() {
    const thCh = parseFloat(document.getElementById('editThCh').value) || 0;
    const inCh = parseFloat(document.getElementById('editInCh').value) || 0;
    const thFm = parseFloat(document.getElementById('editThFm').value) || 0;
    const inFm = parseFloat(document.getElementById('editInFm').value) || 0;

    const totalCh = (thCh + inCh).toFixed(2);
    const totalFm = (thFm + inFm).toFixed(2);

    document.getElementById('editCreditHours').value = totalCh;
    document.getElementById('edit_total_ch_display').innerText = totalCh + ' CH';
    document.getElementById('edit_total_fm_display').innerText = totalFm + ' FM';
}

function editMapping(item) {
    document.getElementById('editMappingId').value = item.id;
    document.getElementById('editWeeklyPeriods').value=item.weekly_periods??'';
    document.getElementById('editIsOptional').value = (item.is_optional == '1') ? '1' : '0';

    document.getElementById('editThCh').value = parseFloat(item.th_credit_hours || 0).toFixed(2);
    document.getElementById('editInCh').value = parseFloat(item.in_credit_hours || 0).toFixed(2);
    document.getElementById('editThFm').value = parseFloat(item.th_full_marks || 75).toFixed(2);
    document.getElementById('editThPm').value = parseFloat(item.th_pass_marks || 26.25).toFixed(2);
    document.getElementById('editInFm').value = parseFloat(item.in_full_marks || 25).toFixed(2);
    document.getElementById('editInPm').value = parseFloat(item.in_pass_marks || 10).toFixed(2);

    calculateEditTotals();
    const thematic=item.assessment_method==='THEMATIC_CONTINUOUS';
    ['editThCh','editInCh','editThFm','editThPm','editInFm','editInPm'].forEach(id=>{const field=document.getElementById(id);field.disabled=thematic;field.required=!thematic;if(thematic){field.value='';field.placeholder='Not specified';}});
    if(thematic){document.getElementById('editCreditHours').value=item.credit_hours;document.getElementById('edit_total_ch_display').innerText=item.credit_hours+' CH';document.getElementById('edit_total_fm_display').innerText='Achievement 0–100%';}

    // Uncheck all edit checkboxes
    const checkboxes = document.querySelectorAll('.edit-teacher-check');
    checkboxes.forEach(cb => cb.checked = false);

    // Check assigned teachers
    if (item.teacher_id) {
        const ids = item.teacher_id.toString().split(',');
        ids.forEach(tid => {
            const cb = document.getElementById('edit_teacher_' + tid.trim());
            if (cb) {
                cb.checked = true;
            }
        });
    }

    var editModal = new ErpUI.Modal(document.getElementById('editMappingModal'));
    editModal.show();
}

// Live Toggle for Subject Status (Compulsory or Optional)
document.addEventListener('DOMContentLoaded', function() {
    const toastElem = document.getElementById('liveToast');
    const toast = new ErpUI.Toast(toastElem, { delay: 3000 });
    const toastMsg = document.getElementById('toastMessage');

    document.querySelectorAll('.subject-status-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            const mappingId = this.getAttribute('data-id');
            const isActive = this.checked ? 1 : 0;
            const label = document.getElementById('label_' + mappingId);
            const row = document.getElementById('row_mapping_' + mappingId);

            // Optimistic UI update
            if (isActive) {
                label.innerHTML = '<span class="badge bg-success bg-opacity-10 text-success border border-success"><i class="fas fa-check me-1"></i>Active</span>';
                if (row) row.classList.remove('table-secondary', 'opacity-75');
            } else {
                label.innerHTML = '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary"><i class="fas fa-eye-slash me-1"></i>Hidden</span>';
                if (row) row.classList.add('table-secondary', 'opacity-75');
            }

            // AJAX Request
            const formData = new FormData();
            formData.append('action', 'toggle_subject_status');
            formData.append('mapping_id', mappingId);
            formData.append('is_active', isActive);
            formData.append('is_ajax', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    toastElem.className = 'toast align-items-center text-white bg-success border-0 shadow';
                    toastMsg.innerHTML = '<i class="fas fa-check-circle me-1"></i> ' + (data.message || 'Status updated');
                    toast.show();
                } else {
                    throw new Error(data.error || 'Update failed');
                }
            })
            .catch(err => {
                // Revert on error
                toggle.checked = !isActive;
                toastElem.className = 'toast align-items-center text-white bg-danger border-0 shadow';
                toastMsg.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Failed to update: ' + err.message;
                toast.show();
            });
        });
    });
});


scope.bindActions({"classes-subjects-action-0":function(event,args){editMapping(JSON.parse(args[0]||'null'))},
"classes-subjects-action-1":function(event,args){editMapping(JSON.parse(args[0]||'null'))},
"classes-subjects-action-2":function(event,args){populateSubjectDetails(this)},
"classes-subjects-action-3":function(event,args){calculateMappingTotals()},
"classes-subjects-action-4":function(event,args){calculateMappingTotals()},
"classes-subjects-action-5":function(event,args){calculateMappingTotals()},
"classes-subjects-action-6":function(event,args){calculateMappingTotals()},
"classes-subjects-action-7":function(event,args){calculateEditTotals()},
"classes-subjects-action-8":function(event,args){calculateEditTotals()},
"classes-subjects-action-9":function(event,args){calculateEditTotals()},
"classes-subjects-action-10":function(event,args){calculateEditTotals()}});
}
