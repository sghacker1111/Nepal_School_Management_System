// Mechanically extracted from modules/subjects/internal.php; PHP values are inert page data.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;
document.querySelectorAll('[data-add-component]').forEach(b=>b.onclick=()=>{const kind=b.dataset.addComponent,body=document.querySelector('tbody[data-kind="'+kind+'"]'),i=Date.now();const tr=document.createElement('tr');tr.innerHTML='<td><input class="form-control" required name="components['+kind+']['+i+'][key]" placeholder="custom_field"></td><td><input class="form-control" required name="components['+kind+']['+i+'][label]" maxlength="120"></td><td><input class="form-control" required type="number" min="0" step="0.01" name="components['+kind+']['+i+'][max]"></td><td><button type="button" class="btn btn-outline-danger">Remove</button></td>';tr.querySelector('button').onclick=()=>tr.remove();body.append(tr);});

scope.bindActions({"subjects-internal-action-0":function(event,args){this.closest('tr').remove()}});
}
