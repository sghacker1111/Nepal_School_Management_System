// Mechanically extracted from modules/super_admin/subjects.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

let subjectModal = null;

document.addEventListener('DOMContentLoaded', function() {
    subjectModal = new ErpUI.Modal(document.getElementById('subjectModal'));
});

function calculateModalTotals() {
    const thCh = parseFloat(document.getElementById('subjectThCh').value) || 0;
    const inCh = parseFloat(document.getElementById('subjectInCh').value) || 0;
    const thFm = parseFloat(document.getElementById('subjectThFm').value) || 0;
    const inFm = parseFloat(document.getElementById('subjectInFm').value) || 0;

    const totalCh = (thCh + inCh).toFixed(2);
    const totalFm = (thFm + inFm).toFixed(2);

    document.getElementById('modal_total_ch_display').innerText = totalCh + ' CH';
    document.getElementById('modal_total_fm_display').innerText = totalFm + ' FM';
}

function showAddSubjectForm() {
    document.getElementById('subjectSchoolDefault').value='auto';
    ['subjectThCh','subjectInCh','subjectThFm','subjectThPm','subjectInFm','subjectInPm'].forEach(id=>document.getElementById(id).disabled=false);
    document.getElementById('subjectAction').value = 'add';
    document.getElementById('subjectId').value = '';
    document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-book"></i> Add Universal Subject';

    document.getElementById('subjectCode').value = '';
    document.getElementById('subjectNameEn').value = '';
    document.getElementById('subjectNameNp').value = '';
    document.getElementById('subjectIsOptional').value = '0';

    document.getElementById('subjectThCh').value = '3.00';
    document.getElementById('subjectThFm').value = '75.00';
    document.getElementById('subjectThPm').value = '26.25';

    document.getElementById('subjectInCh').value = '1.00';
    document.getElementById('subjectInFm').value = '25.00';
    document.getElementById('subjectInPm').value = '10.00';

    calculateModalTotals();

    // Show and require class selection for adding
    document.getElementById('subjectMapClassWrapper').style.display = 'block';
    document.getElementById('subjectMapClassId').required = true;
    document.getElementById('subjectMapClassId').value = '';

    subjectModal.show();
}

function editSubject(s) {
    document.getElementById('subjectSchoolDefault').value='auto';
    document.getElementById('subjectAction').value = 'edit';
    document.getElementById('subjectId').value = s.id;
    document.getElementById('subjectModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Universal Subject';

    document.getElementById('subjectCode').value = s.code;
    document.getElementById('subjectNameEn').value = s.name_en;
    document.getElementById('subjectNameNp').value = s.name_np || '';
    document.getElementById('subjectIsOptional').value = (s.type === 'optional') ? '1' : '0';

    document.getElementById('subjectThCh').value = parseFloat(s.th_credit_hours || 0).toFixed(2);
    document.getElementById('subjectThFm').value = parseFloat(s.th_full_marks || 75).toFixed(2);
    document.getElementById('subjectThPm').value = parseFloat(s.th_pass_marks || 26.25).toFixed(2);

    document.getElementById('subjectInCh').value = parseFloat(s.in_credit_hours || 0).toFixed(2);
    document.getElementById('subjectInFm').value = parseFloat(s.in_full_marks || 25).toFixed(2);
    document.getElementById('subjectInPm').value = parseFloat(s.in_pass_marks || 10).toFixed(2);

    calculateModalTotals();
    const thematic=s.assessment_method==='THEMATIC_CONTINUOUS';
    ['subjectThCh','subjectInCh','subjectThFm','subjectThPm','subjectInFm','subjectInPm'].forEach(id=>{const el=document.getElementById(id);el.disabled=thematic;if(thematic){el.value='';el.placeholder='Not specified';}});
    if(thematic){document.getElementById('modal_total_ch_display').innerText=s.credit_hours+' CH';document.getElementById('modal_total_fm_display').innerText='Achievement 0–100%';}

    // Hide and don't require class selection for editing
    document.getElementById('subjectMapClassWrapper').style.display = 'none';
    document.getElementById('subjectMapClassId').required = false;
    document.getElementById('subjectMapClassId').value = '';

    subjectModal.show();
}

function loadChaptersList() {
    const classId = document.getElementById('chapterFilterClass').value;
    const subjectId = document.getElementById('chapterFilterSubject').value;
    const wrapper = document.getElementById('chaptersListWrapper');
    const addWrapper = document.getElementById('addChapterWrapper');

    if (!classId || !subjectId) {
        wrapper.innerHTML = `
            <div class="text-center text-muted py-5">
                <i class="fas fa-filter fa-2x mb-3 text-muted"></i>
                <p>Select both Class and Subject filters to manage chapters.</p>
            </div>
        `;
        addWrapper.style.display = 'none';
        return;
    }

    addWrapper.style.display = 'block';

    // Fetch chapters via AJAX (fallback to cdc curriculum if empty)
    $.ajax({
        url: JSON.parse(config.values["super-admin-subjects-value-0"]||'null'),
        data: {
            action_get_chapters: 1,
            class_id: classId,
            subject_id: subjectId
        },
        dataType: 'json',
        success: function(chapters) {
            let html = '';
            if (chapters.length > 0) {
                html = `
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Chapter Name</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                // Chapter names are rendered using textContent below.

                html += `
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                html = `
                    <div class="text-center text-muted py-4">
                        <p>No chapters found for this combination. Fallback catalog has no default chapters either.</p>
                    </div>
                `;
            }
            wrapper.innerHTML = html;
            const body=wrapper.querySelector('tbody');
            if(body)chapters.forEach(ch=>{
                const row=document.createElement('tr'),label=document.createElement('td'),action=document.createElement('td'),button=document.createElement('button');
                label.textContent=typeof ch==='string'?ch:ch.name;button.type='button';button.className='btn btn-sm btn-outline-danger';button.textContent='Delete';
                button.onclick=()=>deleteChapter(typeof ch==='string'?ch:ch.name);action.append(button);row.append(label,action);body.append(row);
            });
        },
        error: function() {
            wrapper.innerHTML = `<div class="alert alert-danger">Error loading chapters list.</div>`;
        }
    });
}

function addChapter() {
    const classId = document.getElementById('chapterFilterClass').value;
    const subjectId = document.getElementById('chapterFilterSubject').value;
    const name = document.getElementById('newChapterName').value.trim();
    const nameNp = document.getElementById('newChapterNameNp').value.trim();
    const sort = document.getElementById('newChapterSort').value;

    if (!name) {
        alert("Chapter name is required.");
        return;
    }

    $.ajax({
        url: JSON.parse(config.values["super-admin-subjects-value-1"]||'null'),
        type: 'POST',
        data: {
            action_chapter: 'add',
            class_id: classId,
            subject_id: subjectId,
            name: name,
            name_np: nameNp,
            sort_order: sort
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                document.getElementById('newChapterName').value = '';
                document.getElementById('newChapterNameNp').value = '';
                document.getElementById('newChapterSort').value = '0';
                loadChaptersList();
            } else {
                alert("Error: " + res.message);
            }
        },
        error: function() {
            alert("Error adding chapter.");
        }
    });
}

function deleteChapter(name) {
    const classId = document.getElementById('chapterFilterClass').value;
    const subjectId = document.getElementById('chapterFilterSubject').value;
    Swal.fire({
        title: 'Delete Chapter',
        text: `Delete ${name} only when it has no active questions, notes or exam grids. You must confirm your current password.`,
        icon: 'warning',
        input: 'password',
        inputLabel: 'Current password',
        inputAttributes: {autocomplete: 'current-password', maxlength: '1024'},
        inputValidator: value => value ? undefined : 'Enter your current password to continue.',
        showCancelButton: true,
        confirmButtonColor: '#b42338',
        confirmButtonText: 'Confirm and delete'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: JSON.parse(config.values["super-admin-subjects-value-2"]||'null'),
            type: 'POST',
            data: {action_chapter: 'delete_by_name', class_id: classId, subject_id: subjectId, name, current_password: result.value},
            dataType: 'json',
            success: res => {
                if (res.success) {loadChaptersList(); Swal.fire('Deleted', 'The unused chapter was archived for recovery.', 'success');}
                else if (res.blocked) CurriculumDeleteUI.showBlocked(res, JSON.parse(config.values["super-admin-subjects-value-3"]||'null'));
                else Swal.fire('Deletion cancelled', res.message || 'The chapter was not deleted.', 'error');
            },
            error: () => Swal.fire('Deletion not confirmed', 'The request failed. Reload the chapter list before trying again.', 'error')
        });
    });
}


scope.bindActions({"super-admin-subjects-action-0":function(event,args){this.form.submit()},
"super-admin-subjects-action-1":function(event,args){this.form.submit()},
"super-admin-subjects-action-2":function(event,args){showAddSubjectForm()},
"super-admin-subjects-action-3":function(event,args){editSubject(JSON.parse(args[0]||'null'))},
"super-admin-subjects-action-4":function(event,args){window.location.href='?filter_class='+this.value+'&show_hidden='+(args[0]??'')+''},
"super-admin-subjects-action-5":function(event,args){loadChaptersList()},
"super-admin-subjects-action-6":function(event,args){addChapter()},
"super-admin-subjects-action-7":function(event,args){calculateModalTotals()},
"super-admin-subjects-action-8":function(event,args){calculateModalTotals()},
"super-admin-subjects-action-9":function(event,args){calculateModalTotals()},
"super-admin-subjects-action-10":function(event,args){calculateModalTotals()}});
}
