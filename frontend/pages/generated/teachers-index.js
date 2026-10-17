// Mechanically extracted from modules/teachers/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

$(document).ready(function() {
    $('#teacherTable').DataTable({
        pageLength: 10,
        order: [[0, 'asc']],
        language: {
            emptyTable: "No teachers found."
        }
    });
});

function confirmDeleteTeacher(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = ''+(config.values["teachers-index-value-0"]??'')+'?delete=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently archive this teacher:');
}


scope.bindActions({"teachers-index-action-0":function(event,args){confirmDeleteTeacher(JSON.parse(args[0]||'null'))}});
}
