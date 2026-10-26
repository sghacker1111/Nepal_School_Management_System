/** Delegated handlers also cover question-list pagination/filter fragment swaps. */
export function initializeQuestionBulk(root,context){
 const win=root.ownerDocument.defaultView;
 const form=()=>root.querySelector('[data-question-bulk-form]');
 const boxes=()=>[...root.querySelectorAll('[data-question-select]')].filter(box=>!box.disabled&&box.form===form());
 const close=()=>{const current=form();if(!current)return;current.querySelector('[data-question-bulk-confirm]').hidden=true;const password=current.elements.namedItem('current_password');password.value='';password.disabled=true;};
 const update=()=>{const current=form();if(!current)return;const all=boxes(),count=all.filter(box=>box.checked).length,select=current.querySelector('[data-question-select-all]');select.checked=all.length>0&&count===all.length;select.indeterminate=count>0&&count<all.length;current.querySelector('[data-question-selection-count]').textContent=count+' selected';current.querySelector('[data-question-bulk-open]').disabled=!count;close();};
 root.addEventListener('change',event=>{if(event.target.matches('[data-question-select-all]')){for(const box of boxes())box.checked=event.target.checked;update();}else if(event.target.matches('[data-question-select]'))update();},{signal:context.signal});
 root.addEventListener('click',event=>{if(event.target.closest('[data-question-bulk-cancel]'))close();if(event.target.closest('[data-question-bulk-open]')){const current=form();if(!current||!boxes().some(box=>box.checked))return;current.querySelector('[data-question-bulk-confirm]').hidden=false;const password=current.elements.namedItem('current_password');password.disabled=false;password.focus();}},{signal:context.signal});
 root.addEventListener('submit',event=>{if(!event.target.matches('[data-question-bulk-form]'))return;const count=boxes().filter(box=>box.checked).length;if(!count||!win.confirm('Archive '+count+' selected question(s)? They will be removed from lists and paper generation. You can restore them from Deleted / archived.'))event.preventDefault();},{signal:context.signal});
 context.onBeforeSubmit?.(submitted=>{if(submitted.matches('[data-question-bulk-form]')&&!boxes().some(box=>box.checked))throw new Error('Select questions to archive.');});
 context.signal?.addEventListener('abort',close,{once:true});update();
}
