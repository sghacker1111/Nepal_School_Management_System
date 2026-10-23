// Mechanically extracted from modules/exams/add.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

if(JSON.parse(config.values["exams-add-value-0"])){['type','result_system','has_internal','display_calendar','date_format'].forEach(id=>document.getElementById(id).disabled=true);}
document.getElementById('type').addEventListener('change',function(){
    const internal=document.getElementById('has_internal');internal.disabled=this.value==='unit';
    if(internal.disabled)internal.checked=false;
});


scope.bindActions({"exams-add-action-0":function(event,args){this.form.submit()},
"exams-add-action-1":function(event,args){this.form.submit()}});
}
