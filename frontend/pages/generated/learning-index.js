// Mechanically extracted from modules/learning/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

let noteModalObj;
$(document).ready(function() {
    noteModalObj = new ErpUI.Modal(document.getElementById('noteModal'));

    // Initialize TinyMCE on note content
    tinymce.init({
        selector: '#modalNoteContent',
        height: 350,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount',
        toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | fullscreen code',
        branding: false,
        promotion: false,
        setup: function (editor) {
            editor.on('change keyup', function () {
                editor.save();
                if (window.MathJax && window.MathJax.typesetPromise) {
                    window.MathJax.typesetPromise().then(() => {
                        if (typeof fixDevanagariInMathJax === 'function') {
                            fixDevanagariInMathJax();
                        }
                    });
                }
            });
        }
    });
});

function showAddNoteModal() {
    clearNoteReferences();
    document.getElementById('modalNoteVisibility').value=JSON.parse(config.values["learning-index-value-0"]||'null');
    document.getElementById('modalActionNote').value = 'add';
    document.getElementById('modalNoteId').value = '';
    document.getElementById('modalNoteTitle').innerText = 'Add Study Note';

    document.getElementById('modalNoteChapter').value = JSON.parse(config.values["learning-index-value-1"]||'null');
    document.getElementById('modalNoteTopic').value = '';
    document.getElementById('modalNoteTitleInput').value = '';

    if (tinymce.get('modalNoteContent')) {
        tinymce.get('modalNoteContent').setContent('');
    } else {
        document.getElementById('modalNoteContent').value = '';
    }

    noteModalObj.show();
}

function editNote(note) {
    clearNoteReferences();
    if(!note.needs_catalog_review)for(const key of ['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'])document.getElementById('noteRef_'+key).value=note[key]||'';
    document.getElementById('modalNoteVisibility').value=note.visibility;
    document.getElementById('modalActionNote').value = 'edit';
    document.getElementById('modalNoteId').value = note.id;
    document.getElementById('modalNoteTitle').innerText = 'Edit Study Note';

    document.getElementById('modalNoteChapter').value = note.chapter;
    if(note.needs_catalog_review)document.getElementById('modalNoteChapter').value=JSON.parse(config.values["learning-index-value-2"]||'null');
    document.getElementById('modalNoteTopic').value = note.topic;
    document.getElementById('modalNoteTitleInput').value = note.title;

    if (tinymce.get('modalNoteContent')) {
        tinymce.get('modalNoteContent').setContent(note.content || '');
    } else {
        document.getElementById('modalNoteContent').value = note.content || '';
    }

    noteModalObj.show();
}
function clearNoteReferences(){for(const key of ['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'])document.getElementById('noteRef_'+key).value='';}


scope.bindActions({"learning-index-action-0":function(event,args){showAddNoteModal()},
"learning-index-action-1":function(event,args){this.form.elements.subject_id.value='';this.form.elements.chapter.value='';this.form.submit();},
"learning-index-action-2":function(event,args){this.form.elements.chapter.value='';this.form.submit();},
"learning-index-action-3":function(event,args){document.getElementById('hubFilterForm').submit();},
"learning-index-action-4":function(event,args){this.form.submit()},
"learning-index-action-5":function(event,args){this.form.submit()},
"learning-index-action-6":function(event,args){this.form.submit()},
"learning-index-action-7":function(event,args){editNote(JSON.parse(args[0]||'null'))},
"learning-index-action-8":function(event,args){return confirm('Change note visibility? Public means within this school only.')},
"learning-index-action-9":function(event,args){tinymce.triggerSave()},
"learning-index-action-10":function(event,args){clearNoteReferences();document.getElementById('modalNoteTopic').value='';},
"learning-index-action-11":function(event,args){clearNoteReferences()}});
}
