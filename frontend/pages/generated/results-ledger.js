// Mechanically extracted from modules/results/ledger.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function applySorting() {
    const selector = document.getElementById('sortSelector').value;
    const tbody = document.getElementById('ledgerBody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    rows.sort((a, b) => {
        if (selector === 'roll') {
            return parseInt(a.getAttribute('data-roll')) - parseInt(b.getAttribute('data-roll'));
        } else if (selector === 'name') {
            return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
        } else if (selector === 'gpa-desc') {
            return parseFloat(b.getAttribute('data-gpa')) - parseFloat(a.getAttribute('data-gpa'));
        } else if (selector === 'gpa-asc') {
            return parseFloat(a.getAttribute('data-gpa')) - parseFloat(b.getAttribute('data-gpa'));
        }
        return 0;
    });

    // Clear and append
    tbody.innerHTML = '';
    rows.forEach(r => tbody.appendChild(r));
}

function applyFiltering() {
    const filter = document.getElementById('filterSelector').value;
    const tbody = document.getElementById('ledgerBody');
    const rows = tbody.querySelectorAll('tr');

    rows.forEach(row => {
        const status = row.getAttribute('data-status');
        if (filter === 'all') {
            row.style.display = '';
        } else if (filter === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}


scope.bindActions({"results-ledger-action-0":function(event,args){window.print()},
"results-ledger-action-1":function(event,args){this.form.elements.subject.value='';this.form.submit()},
"results-ledger-action-2":function(event,args){this.form.elements.section.value='';this.form.elements.subject.value='';this.form.submit()},
"results-ledger-action-3":function(event,args){this.form.submit()},
"results-ledger-action-4":function(event,args){this.form.elements.section.value='';this.form.submit()},
"results-ledger-action-5":function(event,args){this.form.submit()},
"results-ledger-action-6":function(event,args){applySorting()},
"results-ledger-action-7":function(event,args){applyFiltering()}});
}
