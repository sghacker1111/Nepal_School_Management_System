(function(){'use strict';
 const start=()=>document.querySelectorAll('[data-question-import-context]').forEach(root=>{
  const fields=Object.fromEntries(['class','subject','chapter','topic'].map(k=>[k,root.querySelector('[data-import-field="'+k+'"]')]).filter(([,field])=>field));
  const labels=fields.topic?'Class, Subject, Chapter and Topic':'Class, Subject and Chapter',amount=fields.topic?'four':'three';
  const subjects=JSON.parse(root.querySelector('[data-import-subjects]').textContent),status=root.querySelector('[data-import-context-status]');
  const form=root.closest('form'),scope=root.closest('[data-exam-import-panel]')||form;
  const links=scope.querySelectorAll('[data-exam-import-mode],[data-import-template]');let version=0,catalog={chapters:[],topics:{}};
  function reset(select,label){select.replaceChildren(new Option(label,''));}
  function selected(){return Object.values(fields).every(s=>s.value&&!s.disabled);}
  function update(){
   const ready=selected();links.forEach(link=>{const url=new URL(link.href,window.location.href);Object.entries(fields).forEach(([key,s])=>url.searchParams.set('import_'+key+'_id',s.value));link.href=url.href;link.setAttribute('aria-disabled',ready?'false':'true');});
   if(ready)status.textContent='Ready. Every spreadsheet row must match these '+amount+' selected entries.';
  }
  function topics(value=''){
   if(!fields.topic){update();return;}
   reset(fields.topic,'Choose topic');(catalog.topics[fields.chapter.value]||[]).forEach(t=>fields.topic.add(new Option(t.name,t.id)));fields.topic.value=value;fields.topic.disabled=false;
   if(fields.chapter.value&&fields.topic.options.length===1)status.textContent='No existing topics in this chapter. Ask super admin to add a topic first.';update();
  }
  async function load(chapter='',topic=''){
   const current=++version;catalog={chapters:[],topics:{}};reset(fields.chapter,'Choose chapter');if(fields.topic){reset(fields.topic,'Choose topic');fields.topic.disabled=true;}fields.chapter.disabled=true;update();
   if(!fields.subject.value){status.textContent='Choose class and subject first.';return;}
   status.textContent=fields.topic?'Loading existing chapters and topics…':'Loading existing chapters…';
   try{
    const url=new URL(root.dataset.catalogUrl,window.location.href);url.searchParams.set('class_id',fields.class.value);url.searchParams.set('subject_id',fields.subject.value);
    const response=await fetch(url,{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Catalog could not be loaded. Reload and choose again.');
    const data=await response.json();if(current!==version)return;catalog=data;
    data.chapters.forEach(c=>fields.chapter.add(new Option(c.name,c.id)));fields.chapter.disabled=false;fields.chapter.value=chapter;
    status.textContent=data.chapters.length?'Choose an existing chapter'+(fields.topic?' and topic.':'.'):'No existing chapters. Ask super admin to add the catalog entries first.';topics(topic);
   }catch(e){if(current===version)status.textContent=e.message;}
  }
  function changeClass(){version++;reset(fields.subject,'Choose subject');(subjects[fields.class.value]||[]).forEach(s=>fields.subject.add(new Option(s.display_name||s.name_en,s.id)));load();}
  fields.class.addEventListener('change',changeClass);fields.subject.addEventListener('change',()=>load());fields.chapter.addEventListener('change',()=>{status.textContent=fields.topic?'Choose an existing topic.':'Choose an existing chapter.';topics();});fields.topic?.addEventListener('change',()=>{status.textContent='Choose all '+amount+' existing catalog entries.';update();});
  links.forEach(link=>link.addEventListener('click',event=>{if(!selected()){event.preventDefault();status.textContent='Select '+labels+' before downloading a matching sample.';form.reportValidity();}}));
  form.addEventListener('submit',event=>{if(!selected()){event.preventDefault();status.textContent='Select all '+amount+' existing catalog entries before importing.';form.reportValidity();}});
  load(fields.chapter.value,fields.topic?.value||'');
 });
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
