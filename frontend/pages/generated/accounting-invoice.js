// Mechanically extracted from modules/accounting/invoice.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;
document.getElementById('paperSize').onchange=function(){const thermal=this.value==='80mm';document.getElementById('paperStyle').textContent='@media print{@page{size:'+ (thermal?'80mm 250mm':this.value)+';margin:'+(thermal?'4mm':'12mm')+'}.ac-document{'+(thermal?'max-width:72mm;font-size:10px;':'')+'}}';};

scope.bindActions({"accounting-invoice-action-0":function(event,args){window.print()}});
}
