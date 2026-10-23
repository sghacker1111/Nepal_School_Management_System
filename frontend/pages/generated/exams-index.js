// Mechanically extracted from modules/exams/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

$(document).ready(function() {
    var table = $('#examsTable').DataTable({
        pageLength: 10,
        order: [[2, 'desc'], [0, 'asc']],
        language: {
            emptyTable: "No exams created yet."
        },
        dom: 'rtip'
    });

    $('#filter_exam_name').on('keyup change', function() {
        table.column(0).search(this.value).draw();
    });

    $('#filter_year').on('change', function() {
        table.column(2).search(this.value).draw();
    });
});

function confirmDeleteExam(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = ''+(config.values["exams-index-value-0"]??'')+'?delete=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this exam (all marks and configurations will be lost):');
}


scope.bindActions({"exams-index-action-0":function(event,args){confirmDeleteExam(JSON.parse(args[0]||'null'))}});
}
