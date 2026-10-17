// Mechanically extracted from includes/exam_date_controls.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;
function toggleExamCalendar(){const bs=document.getElementById('exam_date_calendar').value==='BS';document.querySelectorAll('[data-date-ad]').forEach(x=>x.disabled=bs);document.querySelectorAll('[data-date-bs]').forEach(x=>x.disabled=!bs);}
scope.expose({toggleExamCalendar});
scope.bindActions({"shared-exam-date-controls-action-0":function(event,args){toggleExamCalendar()}});
}
