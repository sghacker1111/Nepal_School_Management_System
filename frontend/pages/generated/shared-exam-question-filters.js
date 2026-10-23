// Mechanically extracted from includes/exam_question_filters.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;


scope.bindActions({"shared-exam-question-filters-action-0":function(event,args){this.form.subject_id.value='';this.form.chapter.value='';if(this.form.filter_topic)this.form.filter_topic.value='';this.form.requestSubmit()},
"shared-exam-question-filters-action-1":function(event,args){this.form.chapter.value='';if(this.form.filter_topic)this.form.filter_topic.value='';this.form.requestSubmit()},
"shared-exam-question-filters-action-2":function(event,args){if(this.form.filter_topic)this.form.filter_topic.value='';this.form.requestSubmit()},
"shared-exam-question-filters-action-3":function(event,args){this.form.requestSubmit()},
"shared-exam-question-filters-action-4":function(event,args){this.form.requestSubmit()}});
}
