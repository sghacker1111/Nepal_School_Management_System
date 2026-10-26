// Mechanically extracted from modules/subjects/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

$(document).ready(function() {
    var table = $('#subjectTable').DataTable({
        pageLength: 25,
        order: [],
        language: {
            emptyTable: "No subjects configured."
        },
        dom: 'rtip'
    });

    $('#filter_subject').on('keyup change', function() {
        table.search(this.value).draw();
    });

    $('#filter_teacher').on('change', function() {
        table.column(3).search(this.value).draw();
    });
});


scope.bindActions({"subjects-index-action-0":function(event,args){this.form.submit()},
"subjects-index-action-1":function(event,args){return confirm('Hide this subject from student forms and exam lists? Historical marks remain safe.');}});
}
