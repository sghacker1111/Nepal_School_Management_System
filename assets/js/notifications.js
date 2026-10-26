// Accessible, non-blocking feedback shared by every signed-in role.
(() => {
 if(window.erpNotificationsInstalled)return;
 window.erpNotificationsInstalled=true;
 const recent=new Map();
 window.schoolNotify=(message,type='success')=>{
  message=String(message||'').replace(/\s+/g,' ').trim();
  if(!message)return;const key=type+message;if(Date.now()-(recent.get(key)||0)<3000)return;recent.set(key,Date.now());
  let area=document.getElementById('school-notifications');
  if(!area){area=document.createElement('div');area.id='school-notifications';area.className='no-print';area.style.cssText='position:fixed;right:16px;bottom:16px;z-index:3000;max-width:min(420px,90vw);display:grid;gap:8px';document.body.append(area);}
  const toast=document.createElement('div');toast.setAttribute('role',type==='error'?'alert':'status');toast.style.cssText='padding:14px 18px;border-radius:10px;background:white;box-shadow:0 3px 24px #0003;border-left:5px solid '+(type==='error'?'#b42318':type==='warning'?'#a15c00':'#067647');
  const title=document.createElement('strong');title.textContent=type==='error'?'Error found':type==='warning'?'Please review':type==='info'?'Information':/\b(saved|created|updated|deleted|archived|restored|imported|published|submitted|generated|recorded|completed successfully)\b/i.test(message)?'Saved successfully':'Status update';
  const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Dismiss notification');close.style.cssText='float:right;border:0;background:transparent;font-size:22px;cursor:pointer';close.onclick=()=>toast.remove();
  const body=document.createElement('div');body.textContent=message;toast.append(close,title,body);area.append(toast);
  if(type==='success')setTimeout(()=>toast.remove(),6500);
 };
 const inspect=()=>{
  if(!window.document?.body)return;
  document.querySelectorAll('.alert-success,.alert-danger,.alert-warning,.alert-info[data-notify]').forEach(el=>{
   if(el.dataset.notified||el.closest('#school-notifications,[hidden],.modal:not(.show)'))return;el.dataset.notified='1';
   // Dismiss controls are not part of the explanatory sentence.
   const copy=el.cloneNode(true);copy.querySelectorAll('button').forEach(b=>b.remove());
   schoolNotify(copy.textContent,el.classList.contains('alert-danger')?'error':el.classList.contains('alert-warning')?'warning':el.classList.contains('alert-info')?'info':'success');
  });
 };
 const start=()=>{inspect();new MutationObserver(inspect).observe(document.body,{childList:true,subtree:true});};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
 const nativeFetch=window.fetch.bind(window);
 window.fetch=async(input,init)=>{
  const same=new URL(typeof input==='string'?input:input.url,location.href).origin===location.origin;
  try{const response=await nativeFetch(input,init);
   if(same){const json=response.headers.get('content-type')?.includes('application/json')?await response.clone().json().catch(()=>null):null;
    if(!response.ok||json?.success===false)schoolNotify(json?.message||'The request failed ('+response.status+'). Reload and try again.','error');
    else if(json?.success===true&&json.message)schoolNotify(json.message);
   }return response;
  }catch(error){if(same&&error?.name!=='AbortError')schoolNotify('The request could not reach the server. Check your connection and retry.','error');throw error;}
 };
 if(window.jQuery){jQuery(document).ajaxError((event,xhr)=>schoolNotify(xhr.responseJSON?.message||'The request failed ('+xhr.status+'). Please retry.','error'));jQuery(document).ajaxSuccess((event,xhr)=>{const data=xhr.responseJSON;if(data?.success===false)schoolNotify(data.message||'The change could not be saved.','error');else if(data?.success===true&&data.message)schoolNotify(data.message);});}
})();
