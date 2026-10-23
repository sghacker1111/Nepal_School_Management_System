// Mechanically extracted from modules/results/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

$(document).ready(function() {
    if(document.getElementById('resultsTable')) $('#resultsTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']]
    });
});


scope.bindActions({"results-index-action-0":function(event,args){this.form.submit()},
"results-index-action-1":function(event,args){this.form.submit()},
"results-index-action-2":function(event,args){this.form.submit()}});
}
