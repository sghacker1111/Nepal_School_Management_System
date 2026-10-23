// Mechanically extracted from includes/report_issue_controls.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function toggleReportIssueDate(){const manual=document.getElementById('issue_date_mode').value==='manual';document.getElementById('manual_issue_date_group').hidden=!manual;const date=document.getElementById('issue_date');date.disabled=!manual;date.required=manual;}
function changeIssueCalendar(select){const date=document.getElementById('issue_date');try{date.value=window.schoolConvertDate(date.value,date.dataset.calendar,select.value);date.dataset.calendar=select.value;date.type=select.value==='AD'?'date':'text';}catch(e){select.value=date.dataset.calendar;window.alert(e.message);}}
if(JSON.parse(config.values["shared-report-issue-controls-value-0"])){window.addEventListener('load',()=>window.print());}

scope.expose({toggleReportIssueDate,changeIssueCalendar});
scope.bindActions({"shared-report-issue-controls-action-0":function(event,args){toggleReportIssueDate()},
"shared-report-issue-controls-action-1":function(event,args){changeIssueCalendar(this)}});
}
