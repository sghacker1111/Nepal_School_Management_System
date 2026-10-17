// Mechanically extracted from modules/learning/question_grid.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;

function applyPreset(mcq, short, long, title) {
    document.getElementsByName('mcq_count')[0].value = mcq;
    document.getElementsByName('short_count')[0].value = short;
    document.getElementsByName('long_count')[0].value = long;

    const titleInput = document.getElementsByName('exam_title')[0];
    if (titleInput.value.includes('Terminal') || titleInput.value.includes('Examination') || titleInput.value === '') {
        titleInput.value = title;
    }

    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'CDC Preset Applied',
        text: 'Exam parameters populated.',
        showConfirmButton: false,
        timer: 1500
    });
}


scope.bindActions({"learning-question-grid-action-0":function(event,args){this.form.elements.subject.value='';this.form.submit()},
"learning-question-grid-action-1":function(event,args){this.form.submit()},
"learning-question-grid-action-2":function(event,args){applyPreset(11, 8, 4, 'CDC Mathematics scope.actions.Grid (75 Marks)')},
"learning-question-grid-action-3":function(event,args){applyPreset(15, 10, 5, 'CDC Science scope.actions.Grid (75 Marks)')},
"learning-question-grid-action-4":function(event,args){applyPreset(15, 10, 5, 'CDC Language/Social scope.actions.Grid (75 Marks)')},
"learning-question-grid-action-5":function(event,args){applyPreset(10, 5, 2, 'Standard CDC scope.actions.Grid (50 Marks)')}});
}
