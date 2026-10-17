// Mechanically extracted from modules/subjects/add.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function calcTotals() {
    var th_ch = parseFloat(document.getElementById('th_credit_hours').value) || 0;
    var in_ch = parseFloat(document.getElementById('in_credit_hours').value) || 0;
    document.getElementById('total_credit_hours').value = (th_ch + in_ch).toFixed(2);

    var th_fm = parseFloat(document.getElementById('th_full_marks').value) || 0;
    var in_fm = parseFloat(document.getElementById('in_full_marks').value) || 0;
    document.getElementById('total_full_marks').value = (th_fm + in_fm).toFixed(2);

    var th_pm = parseFloat(document.getElementById('th_pass_marks').value) || 0;
    var in_pm = parseFloat(document.getElementById('in_pass_marks').value) || 0;
    document.getElementById('total_pass_marks').value = (th_pm + in_pm).toFixed(2);
}

// Bootstrap form validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()


scope.bindActions({"subjects-add-action-0":function(event,args){calcTotals()},
"subjects-add-action-1":function(event,args){calcTotals()},
"subjects-add-action-2":function(event,args){calcTotals()},
"subjects-add-action-3":function(event,args){calcTotals()},
"subjects-add-action-4":function(event,args){calcTotals()},
"subjects-add-action-5":function(event,args){calcTotals()}});
}
