'use strict';
window.schoolConvertDate=function(value,from,to){
 if(!value||from===to)return value;
 const digits='०१२३४५६७८९';value=value.replace(/[०-९]/g,d=>String(digits.indexOf(d)));
 if(!/^\d{4}-\d{2}-\d{2}$/.test(value))throw new Error('Enter a valid YYYY-MM-DD date before changing calendar.');
 const [y,m,d]=value.split('-').map(Number);
 if(from==='AD'){const check=new Date(y,m-1,d);if(check.getFullYear()!==y||check.getMonth()!==m-1||check.getDate()!==d)throw new Error('Enter a valid AD calendar date.');}
 const Converter=window.NepaliDate?.default||window.NepaliDate;
 if(!Converter)throw new Error('Date conversion is unavailable. Choose the calendar and enter the date again.');
 const date=from==='BS'?new Converter(y,m-1,d):new Converter(new Date(y,m-1,d));
 if(from==='BS'&&(date.getYear()!==y||date.getMonth()!==m-1||date.getDate()!==d))throw new Error('Enter a valid BS calendar date.');
 if(to==='BS')return [date.getYear(),date.getMonth()+1,date.getDate()].map((n,i)=>String(n).padStart(i===0?4:2,'0')).join('-');
 const ad=date.toJsDate();return [ad.getFullYear(),ad.getMonth()+1,ad.getDate()].map((n,i)=>String(n).padStart(i===0?4:2,'0')).join('-');
};
const schoolDateScopes=new WeakMap();
window.initSchoolDates=(root=document,context={})=>{
 schoolDateScopes.get(root)?.();const cleanups=[];
 const listen=(node,type,callback)=>{node.addEventListener(type,callback);cleanups.push(()=>node.removeEventListener?.(type,callback));};
 root.querySelectorAll('input[type="date"]').forEach(input=>{
  const partnerName=input.name.replace(/_ad$/,'_bs');
  if(!input.name||input.dataset.dateAd||input.dataset.calendar||(partnerName!==input.name&&input.form?.querySelector('[name="'+partnerName+'"]')))return;
  if(!['date','date_ad','dob_ad','join_date','perm_issue_date','temp_issue_date','start_date','end_date','bill_date','due_date','payment_date','expense_date','effective_from','effective_to','from','to','subscription_start','subscription_end'].includes(input.name))return;
  const select=document.createElement('select');select.name='calendar_fields['+input.name+']';select.className='form-select form-select-sm mt-1';select.setAttribute('aria-label','Calendar for '+input.name.replaceAll('_',' '));
  ['AD','BS'].forEach(cal=>{const option=document.createElement('option');option.value=cal;option.textContent=cal==='AD'?'AD (English date)':'BS (नेपाली मिति)';select.append(option);});
  let calendar='AD';listen(select,'change',()=>{try{const value=window.schoolConvertDate(input.value,calendar,select.value);input.type=select.value==='AD'?'date':'text';input.value=value;calendar=select.value;input.placeholder='YYYY-MM-DD';}catch(error){select.value=calendar;window.schoolNotify?schoolNotify(error.message,'error'):window.alert(error.message);}});
  input.after(select);
  cleanups.push(()=>{if(calendar==='BS'&&input.value){input.value=window.schoolConvertDate(input.value,'BS','AD');input.type='date';}select.remove?.();});
 });
 root.querySelectorAll('[data-date-ad],[data-date-bs]').forEach(input=>listen(input,'change',()=>{
  const isAd=!!input.dataset.dateAd;const field=input.dataset.dateAd||input.dataset.dateBs;
  const partner=root.querySelector('[data-date-'+(isAd?'bs':'ad')+'="'+field+'"]');
  try{if(partner)partner.value=window.schoolConvertDate(input.value,isAd?'AD':'BS',isAd?'BS':'AD');input.setCustomValidity('');}
  catch(e){input.setCustomValidity(e.message);input.reportValidity();}
}));
 const dispose=()=>{cleanups.splice(0).reverse().forEach(fn=>fn());schoolDateScopes.delete(root);};
 schoolDateScopes.set(root,dispose);context.signal?.addEventListener('abort',dispose,{once:true});return dispose;
};
document.addEventListener('DOMContentLoaded',()=>window.initSchoolDates(document.querySelector?.('#main-content')||document));
