// Mechanically extracted from modules/settings/years.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function switchYear(id, label) {
    Swal.fire({
        title: 'Confirm Year Switch',
        text: 'Are you sure you want to switch the active academic year to ' + label + '? All modules will display data for this year.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, proceed'
    }).then((result) => {
        if (result.isConfirmed) {
            // Prompt for password verification
            verifyPasswordAndProceed(function(password) {
                document.getElementById('activeYearId').value = id;
                document.getElementById('activeYearPassword').value = password;
                document.getElementById('setActiveYearForm').submit();
            }, 'Verify Administrator Password', 'Please enter your password to authorize this academic year switch:');
        }
    });
}

function toggleHiddenYear(id, hide) {
    const actionText = hide ? 'hide' : 'show';
    const confirmBtnText = hide ? 'Yes, hide it' : 'Yes, show it';
    Swal.fire({
        title: (hide ? 'Hide' : 'Show') + ' Academic Year',
        text: 'Are you sure you want to ' + actionText + ' this academic year?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: hide ? '#e0a800' : '#28a745',
        cancelButtonColor: '#3085d6',
        confirmButtonText: confirmBtnText
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = ''+(config.values["settings-years-value-0"]??'')+'?toggle_hidden=' + id;
        }
    });
}


scope.bindActions({"settings-years-action-0":function(event,args){switchYear(JSON.parse(args[0]||'null'), ''+(args[1]??'')+' / '+(args[2]??'')+'')},
"settings-years-action-1":function(event,args){toggleHiddenYear(JSON.parse(args[0]||'null'), false)},
"settings-years-action-2":function(event,args){toggleHiddenYear(JSON.parse(args[0]||'null'), true)}});
}
