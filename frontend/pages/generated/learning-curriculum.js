// Mechanically extracted from modules/learning/curriculum.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

const canManageCatalog=JSON.parse(config.values["learning-curriculum-value-0"]||'null');
let currentClassId = 0;
let currentSubjectId = 0;

function onClassChange() {
    const classSelect = document.getElementById('curriculumClass');
    currentClassId = parseInt(classSelect.value) || 0;

    const subjectSection = document.getElementById('subjectSection');
    const subjectSelect = document.getElementById('curriculumSubject');

    if (currentClassId === 0) {
        subjectSection.style.display = 'none';
        resetTreeContainer();
        return;
    }

    // Load subjects for selected class
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_subjects', class_id: currentClassId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                subjectSelect.innerHTML = '<option value="">-- Choose Subject --</option>';
                res.subjects.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name_en + ' (' + sub.code + ')';
                    subjectSelect.appendChild(opt);
                });
                subjectSection.style.display = 'block';
                document.getElementById('curriculumInstructions').classList.remove('d-none');
                resetTreeContainer();
            } else {
                alert("Error loading subjects: " + res.message);
            }
        }
    });
}

function onSubjectChange() {
    const subjectSelect = document.getElementById('curriculumSubject');
    currentSubjectId = parseInt(subjectSelect.value) || 0;

    if (currentSubjectId === 0) {
        resetTreeContainer();
        return;
    }

    loadChaptersTree();
}

function resetTreeContainer() {
    document.getElementById('placeholderView').style.display = 'block';
    document.getElementById('treeContainer').style.display = 'none';
    document.getElementById('btnAddChapter').classList.add('d-none');
}

function loadChaptersTree() {
    document.getElementById('placeholderView').style.display = 'none';
    document.getElementById('treeContainer').style.display = 'block';
    if(canManageCatalog)document.getElementById('btnAddChapter').classList.remove('d-none');

    const accordion = document.getElementById('chaptersAccordion');
    accordion.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>';

    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_chapters', class_id: currentClassId, subject_id: currentSubjectId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.chapters.length === 0) {
                    accordion.innerHTML = `
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-folder-open fa-2x mb-2"></i>
                            <p class="mb-0">No chapters added yet for this subject.</p>
                            ${canManageCatalog ? '<button class="btn btn-sm btn-outline-success mt-2" data-curriculum-action="add-chapter">Add First Chapter</button>' : '<p>Ask super admin to add curriculum chapters.</p>'}
                        </div>
                    `;
                    return;
                }

                accordion.innerHTML = '';
                res.chapters.forEach(ch => {
                    const chapterItem = document.createElement('div');
                    chapterItem.className = 'accordion-item border mb-3 rounded-3 overflow-hidden shadow-sm';
                    chapterItem.innerHTML = `
                        <h2 class="accordion-header" id="headingCh_${ch.id}">
                            <div class="accordion-button collapsed bg-light d-flex justify-content-between align-items-center" type="button" data-ui-toggle="collapse" data-ui-target="#collapseCh_${ch.id}">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-folder text-warning me-2"></i>
                                    <div>
                                        <span class="fw-bold chapter-name"></span>
                                        <span class="text-muted ms-2 chapter-name-np"></span>
                                    </div>
                                </div>
                                <div class="ms-auto me-3 no-print d-flex gap-1" data-curriculum-actions>
                                    <button class="btn btn-xs btn-outline-primary px-2 edit-chapter" title="Edit chapter">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-xs btn-outline-danger px-2" data-curriculum-action="delete-chapter" data-id="${Number(ch.id)}" title="Delete chapter" aria-label="Delete chapter">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </h2>
                        <div id="collapseCh_${ch.id}" class="accordion-collapse collapse" data-ui-parent="#chaptersAccordion">
                            <div class="accordion-body bg-white" id="topicsContainer_${ch.id}">
                                <div class="text-center py-2"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div></div>
                            </div>
                        </div>
                        <div class="p-2 border-top bg-light no-print">
                            <button type="button" class="btn btn-sm btn-outline-success add-chapter-topic"><i class="fas fa-plus"></i> Add Topic</button>
                        </div>
                    `;
                    chapterItem.querySelector('.chapter-name').textContent=ch.name;
                    chapterItem.querySelector('.chapter-name-np').textContent=ch.name_np?'('+ch.name_np+')':'';
                    chapterItem.querySelector('.edit-chapter').addEventListener('click',()=>showEditChapterModal(ch.id,ch.name,ch.name_np||'',ch.sort_order));
                    chapterItem.querySelector('.add-chapter-topic').addEventListener('click',()=>showAddTopicModal(ch.id));
                    if(!canManageCatalog){chapterItem.querySelector('.no-print').remove();chapterItem.querySelector('.add-chapter-topic').parentElement.remove();}
                    chapterItem.querySelector('.accordion-collapse').addEventListener('show.erp.collapse',()=>loadTopicsList(ch.id));
                    accordion.appendChild(chapterItem);
                });
            } else {
                accordion.textContent = 'Error: ' + res.message;
            }
        }
    });
}

function loadTopicsList(chapterId) {
    const container = document.getElementById('topicsContainer_' + chapterId);
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_topics', chapter_id: chapterId, class_id: currentClassId, subject_id: currentSubjectId },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                if (res.topics.length === 0) {
                    container.innerHTML = `
                        <div class="text-center text-muted py-3">
                            <p class="mb-0 small">No topics added for this chapter.</p>
                            ${canManageCatalog ? `<button class="btn btn-xs btn-outline-success mt-2" data-curriculum-action="add-topic" data-id="${Number(chapterId)}">Add First Topic</button>` : ''}
                        </div>
                    `;
                    return;
                }

                const list=document.createElement('ul');list.className='list-group list-group-flush';
                res.topics.forEach(t => {
                    const item=document.createElement('li');item.className='list-group-item d-flex justify-content-between align-items-center py-2 px-1';
                    item.innerHTML = `
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file-alt text-info me-2 small"></i>
                                <span class="topic-name"></span>
                                <span class="text-muted ms-2 topic-name-np"></span>
                            </div>
                            <div class="no-print d-flex gap-1">
                                <button class="btn btn-xs btn-link text-primary p-0 edit-topic" title="Edit Topic">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-xs btn-link text-danger p-0" data-curriculum-action="delete-topic" data-id="${Number(t.id)}" data-chapter="${Number(chapterId)}" title="Delete Topic">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                    `;
                    item.querySelector('.topic-name').textContent=t.name;
                    item.querySelector('.topic-name-np').textContent=t.name_np?'('+t.name_np+')':'';
                    item.querySelector('.edit-topic').addEventListener('click',()=>showEditTopicModal(t.id,chapterId,t.name,t.name_np||'',t.sort_order));
                    if(!canManageCatalog)item.querySelector('.no-print').remove();
                    list.appendChild(item);
                });
                container.replaceChildren(list);
            } else {
                container.innerHTML = '<span class="text-danger small">Error loading topics.</span>';
            }
        }
    });
}

// Modal Triggers & Submissions
function showMapSubjectModal() {
    $.ajax({
        url: window.location.pathname,
        data: { action: 'get_all_subjects' },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                const select = document.getElementById('modalMapSubjectSelect');
                select.innerHTML = '';
                res.subjects.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.name_en + ' (' + sub.code + ')';
                    select.appendChild(opt);
                });
                const modal = new ErpUI.Modal(document.getElementById('mapSubjectModal'));
                modal.show();
            }
        }
    });
}

function mapSubjectSubmit(e) {
    e.preventDefault();
    const subjectId = document.getElementById('modalMapSubjectSelect').value;
    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: {
            action: 'map_subject',
            class_id: currentClassId,
            subject_id: subjectId
        },
        dataType: 'json',
        success: function(res) {
            ErpUI.Modal.getInstance(document.getElementById('mapSubjectModal')).hide();
            if (res.success) {
                Swal.fire('Success', 'Subject mapped successfully to class.', 'success');
                onClassChange();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

// Chapter modals
function showAddChapterModal() {
    document.getElementById('chapterModalAction').value = 'add';
    document.getElementById('chapterModalTitle').textContent = 'Add Chapter';
    document.getElementById('modalChapterName').value = '';
    document.getElementById('modalChapterNameNp').value = '';
    document.getElementById('modalChapterSort').value = '0';

    const modal = new ErpUI.Modal(document.getElementById('chapterModal'));
    modal.show();
}

function showEditChapterModal(id, name, nameNp, sortOrder) {
    document.getElementById('chapterModalAction').value = 'edit';
    document.getElementById('chapterModalId').value = id;
    document.getElementById('chapterModalTitle').textContent = 'Edit Chapter';
    document.getElementById('modalChapterName').value = name;
    document.getElementById('modalChapterNameNp').value = nameNp;
    document.getElementById('modalChapterSort').value = sortOrder;

    const modal = new ErpUI.Modal(document.getElementById('chapterModal'));
    modal.show();
}

function chapterSubmit(e) {
    e.preventDefault();
    const action = document.getElementById('chapterModalAction').value;
    const name = document.getElementById('modalChapterName').value;
    const nameNp = document.getElementById('modalChapterNameNp').value;
    const sort = document.getElementById('modalChapterSort').value;
    const id = document.getElementById('chapterModalId').value;

    const postData = {
        action: action === 'add' ? 'add_chapter' : 'edit_chapter',
        class_id: currentClassId,
        subject_id: currentSubjectId,
        name: name,
        name_np: nameNp,
        sort_order: sort,
        id: id
    };

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(res) {
            ErpUI.Modal.getInstance(document.getElementById('chapterModal')).hide();
            if (res.success) {
                loadChaptersTree();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

function requestCurriculumDelete(action,id,title,warning,refresh) {
    Swal.fire({
        title,
        text: warning + ' Active questions, notes or exam grids will block deletion and show their locations. This action archives the unused curriculum entry for recovery.',
        icon: 'warning',
        input: 'password',
        inputLabel: 'Confirm with your current password',
        inputPlaceholder: 'Current password',
        inputAttributes: {autocomplete: 'current-password', maxlength: '1024'},
        inputValidator: value => value ? undefined : 'Enter your current password to continue.',
        showCancelButton: true,
        confirmButtonColor: '#b42338',
        confirmButtonText: 'Confirm and delete'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            data: {action, id, current_password: result.value},
            dataType: 'json',
            success: res => {
                if (res.success) {
                    refresh();
                    Swal.fire('Deleted', res.message, 'success');
                } else if (res.blocked) {
                    CurriculumDeleteUI.showBlocked(res, JSON.parse(config.values["learning-curriculum-value-1"]||'null'));
                } else {
                    Swal.fire('Deletion cancelled', res.message || 'The entry was not deleted.', 'error');
                }
            },
            error: () => Swal.fire('Deletion not confirmed', 'The request failed. Reload the page and check the curriculum before trying again.', 'error')
        });
    });
}

function deleteChapter(id) {
    requestCurriculumDelete('delete_chapter', id, 'Delete Chapter', 'Delete this chapter and its empty topics?', loadChaptersTree);
}

// Topic Modals
function showAddTopicModal(chapterId) {
    document.getElementById('topicModalAction').value = 'add';
    document.getElementById('topicModalChapterId').value = chapterId;
    document.getElementById('topicModalTitle').textContent = 'Add Topic';
    document.getElementById('modalTopicName').value = '';
    document.getElementById('modalTopicNameNp').value = '';
    document.getElementById('modalTopicSort').value = '0';

    const modal = new ErpUI.Modal(document.getElementById('topicModal'));
    modal.show();
}

function showEditTopicModal(id, chapterId, name, nameNp, sortOrder) {
    document.getElementById('topicModalAction').value = 'edit';
    document.getElementById('topicModalId').value = id;
    document.getElementById('topicModalChapterId').value = chapterId;
    document.getElementById('topicModalTitle').textContent = 'Edit Topic';
    document.getElementById('modalTopicName').value = name;
    document.getElementById('modalTopicNameNp').value = nameNp;
    document.getElementById('modalTopicSort').value = sortOrder;

    const modal = new ErpUI.Modal(document.getElementById('topicModal'));
    modal.show();
}

function topicSubmit(e) {
    e.preventDefault();
    const action = document.getElementById('topicModalAction').value;
    const name = document.getElementById('modalTopicName').value;
    const nameNp = document.getElementById('modalTopicNameNp').value;
    const sort = document.getElementById('modalTopicSort').value;
    const id = document.getElementById('topicModalId').value;
    const chapterId = document.getElementById('topicModalChapterId').value;

    const postData = {
        action: action === 'add' ? 'add_topic' : 'edit_topic',
        class_id: currentClassId,
        subject_id: currentSubjectId,
        chapter_id: chapterId,
        name: name,
        name_np: nameNp,
        sort_order: sort,
        id: id
    };

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(res) {
            ErpUI.Modal.getInstance(document.getElementById('topicModal')).hide();
            if (res.success) {
                // Reload specific topics container
                loadTopicsList(chapterId);
                ErpUI.Collapse.getOrCreateInstance(document.getElementById('collapseCh_'+chapterId),{toggle:false}).show();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

function deleteTopic(id, chapterId) {
    requestCurriculumDelete('delete_topic', id, 'Delete Topic', 'Delete this topic only when it has no linked content?', () => loadTopicsList(chapterId));
}
function showDeletedCurriculum(){
    if(!currentClassId||!currentSubjectId){Swal.fire('Choose class and subject','Select a class and subject first.','info');return;}
    $.getJSON(window.location.pathname,{action:'get_deleted',class_id:currentClassId,subject_id:currentSubjectId},res=>{
        if(!res.success){Swal.fire('Error',res.message,'error');return;}
        CurriculumDeleteUI.showDeleted(res.items,(kind,id)=>{
            $.ajax({url:window.location.pathname,type:'POST',dataType:'json',data:{action:kind==='chapters'?'restore_chapter':'restore_topic',id},success:result=>{
                if(!result.success){Swal.fire('Cannot restore',result.message,'warning');return;}
                loadChaptersTree();showDeletedCurriculum();
            },error:()=>Swal.fire('Error','Could not restore this entry. Reload and try again.','error')});
        });
    }).fail(()=>Swal.fire('Error','Could not load deleted curriculum.','error'));
}
document.addEventListener('click',event=>{
    if(event.target.closest('[data-curriculum-actions]'))event.stopPropagation();
    const button=event.target.closest('[data-curriculum-action]');if(!button)return;
    event.preventDefault();
    if(button.dataset.curriculumAction==='add-chapter')showAddChapterModal();
    if(button.dataset.curriculumAction==='add-topic')showAddTopicModal(Number(button.dataset.id));
    if(button.dataset.curriculumAction==='delete-chapter')deleteChapter(Number(button.dataset.id));
    if(button.dataset.curriculumAction==='delete-topic')deleteTopic(Number(button.dataset.id),Number(button.dataset.chapter));
});


scope.bindActions({"learning-curriculum-action-0":function(event,args){onClassChange()},
"learning-curriculum-action-1":function(event,args){showMapSubjectModal()},
"learning-curriculum-action-2":function(event,args){onSubjectChange()},
"learning-curriculum-action-3":function(event,args){showDeletedCurriculum()},
"learning-curriculum-action-4":function(event,args){showAddChapterModal()},
"learning-curriculum-action-5":function(event,args){mapSubjectSubmit(event)},
"learning-curriculum-action-6":function(event,args){chapterSubmit(event)},
"learning-curriculum-action-7":function(event,args){topicSubmit(event)}});
}
