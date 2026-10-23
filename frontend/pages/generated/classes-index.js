// Mechanically extracted from modules/classes/index.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;

function confirmDeleteClass(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = ''+(config.values["classes-index-value-0"]??'')+'?delete_class=' + id;
    }, 'Confirm Archive', 'Please enter your administrator password to archive this class. Its students, marks and all linked records will be retained:');
}

function confirmDeleteSection(id) {
    verifyPasswordAndProceed(function() {
        window.location.href = ''+(config.values["classes-index-value-1"]??'')+'?delete_section=' + id;
    }, 'Confirm Deletion', 'Please enter your administrator password to permanently delete this section:');
}


scope.bindActions({"classes-index-action-0":function(event,args){return confirm('Remove only this class named exactly 7 from active lists? Its linked records will be retained.')},
"classes-index-action-1":function(event,args){confirmDeleteClass(JSON.parse(args[0]||'null'))},
"classes-index-action-2":function(event,args){confirmDeleteSection(JSON.parse(args[0]||'null'))}});
}
