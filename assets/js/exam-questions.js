(function(){'use strict';
 const start=()=>{
  document.querySelectorAll('[data-exam-import-form]').forEach(importForm=>{
   const kind=importForm.dataset.examImportForm,importMode=importForm.querySelector('[name="import_content_mode"]');if(!importMode)return;
   document.querySelectorAll('[data-exam-import-mode]').forEach(link=>{if(link.dataset.examImportKind===kind)link.addEventListener('click',()=>{importMode.value=link.dataset.examImportMode;});});
   let busy=false;const button=importForm.querySelector('button[type="submit"]'),label=button?.textContent;
   importForm.addEventListener('submit',event=>{if(event.defaultPrevented)return;if(busy){event.preventDefault();return;}busy=true;importForm.setAttribute('aria-busy','true');if(button){button.disabled=true;button.textContent='Importing questions…';}});
   window.addEventListener('pageshow',()=>{busy=false;importForm.removeAttribute('aria-busy');if(button){button.disabled=false;button.textContent=label;}});
  });
  const data=window.examQuestionData,form=document.getElementById('examQuestionForm');if(!data||!form)return;
  const field=id=>document.getElementById(id);let version=0,catalog={chapters:[],topics:{}};
  function topics(selected=''){
   const ch=catalog.chapters.find(c=>c.name===field('examChapter').value),select=field('examTopic');select.replaceChildren(new Option('No topic',''));
   (ch?catalog.topics[ch.id]||[]:[]).forEach(t=>{const o=new Option(t.name,t.id);o.dataset.scope=t.scope;select.add(o);});select.value=String(selected);if(!select.value)select.value='';syncTopic();
  }
  function syncTopic(){const o=field('examTopic').selectedOptions[0];field('examLocalTopic').value=o?.dataset.scope==='local'?o.value:'';field('examCatalogTopic').value=o?.dataset.scope==='shared'?o.value:'';}
  async function loadCatalog(chapter='',topic=''){
   const current=++version;field('examChapter').replaceChildren(new Option('Loading chapters…',''));field('examChapter').disabled=true;topics();
   if(!field('examSubject').value){field('examChapter').replaceChildren(new Option('Choose subject first',''));return;}
   try{const url=new URL(window.location.pathname,window.location.origin);url.search=new URLSearchParams({action:'catalog',class_id:field('examClass').value,subject_id:field('examSubject').value});const r=await fetch(url,{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('Catalog unavailable. Reload the page.');const loaded=await r.json();if(current!==version)return;catalog=loaded;field('examChapter').replaceChildren(new Option('Choose chapter',''));loaded.chapters.forEach(c=>field('examChapter').add(new Option(c.name,c.name)));field('examChapter').disabled=false;field('examChapter').value=chapter;topics(topic);}
   catch(e){if(current===version){field('examChapter').replaceChildren(new Option(e.message,''));}}
  }
  function subjects(selected='',chapter='',topic=''){field('examSubject').replaceChildren(new Option('Choose subject',''));(data.subjects[field('examClass').value]||[]).forEach(s=>field('examSubject').add(new Option(s.display_name||s.name_en,s.id)));field('examSubject').value=String(selected);loadCatalog(chapter,topic);}
  field('examClass').addEventListener('change',()=>subjects());field('examSubject').addEventListener('change',()=>loadCatalog());field('examChapter').addEventListener('change',()=>topics());field('examTopic').addEventListener('change',syncTopic);
  const type=()=>{const options=field('examOptions');if(!options)return;const hidden=field('examType').value!=='MCQ';options.hidden=hidden;options.querySelectorAll('textarea,select').forEach(e=>e.disabled=hidden);};field('examType').addEventListener('change',type);type();
  form.addEventListener('submit',event=>{if(window.tinymce)tinymce.triggerSave();if(field('examVisibility').value==='private')field('examRelease').checked=false;if(field('examRelease').checked&&!window.confirm('Release this exam question, its options and model answer to enrolled students?'))event.preventDefault();});
  subjects(data.form.subject_id||'',data.form.chapter||'',data.form.catalog_topic_id||data.form.topic_id||'');
  window.SchoolQuestionEditor?.init('.exam-rich');
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start);else start();
})();
