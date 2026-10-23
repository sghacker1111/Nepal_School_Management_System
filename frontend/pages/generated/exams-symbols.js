// Mechanically extracted from modules/exams/symbols.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;
function toggleSymbolMode(){const mode=document.getElementById('symbol_mode').value;document.getElementById('first_symbol_group').hidden=mode!=='alphabetical';document.getElementById('previous_symbol_group').hidden=mode!=='previous';document.querySelectorAll('.symbol-input').forEach(i=>i.readOnly=mode!=='manual');}document.getElementById('symbol_search').addEventListener('input',function(){const q=this.value.toLowerCase();document.querySelectorAll('.symbol-row').forEach(r=>r.hidden=!(r.textContent+' '+r.querySelector('input').value).toLowerCase().includes(q));});

scope.bindActions({"exams-symbols-action-0":function(event,args){this.form.submit()},
"exams-symbols-action-1":function(event,args){toggleSymbolMode()}});
}
