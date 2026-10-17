// Mechanically extracted from modules/classes/routine.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;


scope.bindActions({"classes-routine-action-0":function(event,args){window.print()},
"classes-routine-action-1":function(event,args){this.form.querySelector('[name=profile_id]').value='0';this.form.submit()},
"classes-routine-action-2":function(event,args){this.form.submit()},
"classes-routine-action-3":function(event,args){return confirm('Activate this saved profile? The previous timetable will be retained in history.')},
"classes-routine-action-4":function(event,args){return confirm('Replace selected routines? Previous schedules will be retained in history. Teacher conflicts or insufficient periods cancel the whole operation.')}});
}
