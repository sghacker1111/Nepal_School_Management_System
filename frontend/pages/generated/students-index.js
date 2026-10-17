// Mechanically extracted from modules/students/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

$(document).ready(function() {
    $('#studentTable').DataTable({
        pageLength: 25,
        order: [[0, 'desc']],
        language: {
            emptyTable: "No students found"
        },
        dom: 'rtip'
    });

});

function confirmDeleteStudent(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = ''+(config.values["students-index-value-0"]??'')+'?delete=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently archive this student:');
}

function exportToExcel(tableId) {
    // Simple table to CSV export
    let downloadLink;
    const dataType = 'application/vnd.ms-excel';
    const tableSelect = document.getElementById(tableId);
    const tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');

    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);

    if(navigator.msSaveOrOpenBlob){
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob(blob, 'students_list.xls');
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = 'students_list.xls';
        downloadLink.click();
    }
}


scope.bindActions({"students-index-action-0":function(event,args){this.form.elements.section.value='';this.form.submit()},
"students-index-action-1":function(event,args){this.form.submit()},
"students-index-action-2":function(event,args){this.form.submit()},
"students-index-action-3":function(event,args){confirmDeleteStudent(JSON.parse(args[0]||'null'))},
"students-index-action-4":function(event,args){exportToExcel('studentTable')},
"students-index-action-5":function(event,args){window.print()}});
}
