// Mechanically extracted from modules/results/marks_entry.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;
document.querySelectorAll('.elective-select').forEach(box=>box.addEventListener('change',()=>{document.querySelectorAll('input[type="number"][data-elective="'+box.dataset.elective+'"]').forEach(field=>field.disabled=!box.checked);}));

$(document).ready(function() {
    // Disable scrolling changing value
    $('table').on('wheel', 'input[type="number"]', function(e) {
        $(this).blur();
    });

    // Keyboard navigation
    $('table').on('keydown', 'input[type="number"]', function(e) {
        var key = e.which || e.keyCode;

        // 38 = Arrow Up, 40 = Arrow Down, 13 = Enter
        if (key === 38 || key === 40 || key === 13) {
            e.preventDefault();

            var $rows = $('table tbody tr');
            var $currentRow = $(this).closest('tr');
            var rowIndex = $rows.index($currentRow);

            var $rowInputs = $currentRow.find('input[type="number"]');
            var colIndex = $rowInputs.index(this);

            var targetRowIndex = rowIndex;
            if (key === 38) {
                targetRowIndex = rowIndex - 1; // Up
            } else {
                targetRowIndex = rowIndex + 1; // Down (Arrow Down or Enter)
            }

            if (targetRowIndex >= 0 && targetRowIndex < $rows.length) {
                var $targetRowInputs = $rows.eq(targetRowIndex).find('input[type="number"]');
                if ($targetRowInputs.length > colIndex) {
                    $targetRowInputs.eq(colIndex).focus().select();
                }
            }
        }
    });
});


scope.bindActions({"results-marks-entry-action-0":function(event,args){this.form.elements.subject.value='';this.form.submit()},
"results-marks-entry-action-1":function(event,args){this.form.elements.section.value='';this.form.elements.subject.value='';this.form.submit()},
"results-marks-entry-action-2":function(event,args){this.form.elements.subject.value='';this.form.submit()},
"results-marks-entry-action-3":function(event,args){this.form.submit()}});
}
