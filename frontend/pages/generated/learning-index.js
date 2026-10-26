// Learning Hub controls. Catalog requests stay scoped to this mounted page.
export default function(scope,config){
const {document,window,fetch,ErpUI,Option,confirm,tinymce,URL,URLSearchParams}=scope;
const data=JSON.parse(config.values['note-editor']||'null');
const field=id=>document.getElementById(id);
let noteModalObj,catalog=data?.catalog||{chapters:[],topics:{}},version=0;
const cache=new Map();
if(data)cache.set(data.class_id+':'+data.subject_id,catalog);
const references=['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'];
function clearNoteReferences(){for(const key of references)field('noteRef_'+key).value='';}
function topicOptions(selected=''){
 const chapter=catalog.chapters.find(c=>c.name===field('modalNoteChapter').value);
 const topics=chapter?catalog.topics[chapter.id]||[]:[];
 field('modalNoteTopic').replaceChildren(new Option('No topic',''));
 topics.forEach(t=>field('modalNoteTopic').add(new Option(t.name,t.name)));
 field('modalNoteTopic').value=selected;if(!field('modalNoteTopic').value)field('modalNoteTopic').value='';
 field('modalNoteTopic').disabled=!chapter;
}
function showCatalog(chapter='',topic=''){
 field('modalNoteChapter').replaceChildren(new Option('Choose chapter',''));
 catalog.chapters.forEach(c=>field('modalNoteChapter').add(new Option((c.area_name?c.area_name+' — ':'')+c.name,c.name)));
 field('modalNoteChapter').value=chapter;field('modalNoteChapter').disabled=false;topicOptions(topic);
 field('noteCatalogStatus').textContent=catalog.chapters.length?'':'No chapters are available for this subject. Ask the main admin to add them.';
}
async function loadCatalog(chapter='',topic=''){
 const current=++version,key=field('modalNoteClass').value+':'+field('modalNoteSubject').value;
 const save=field('noteForm').querySelector('button[type="submit"]');save.disabled=true;
 field('modalNoteChapter').disabled=true;field('modalNoteTopic').disabled=true;
 field('noteCatalogStatus').textContent='Loading chapters and topics…';
 try{
  if(!field('modalNoteSubject').value){catalog={chapters:[],topics:{}};showCatalog();field('noteCatalogStatus').textContent='Choose a subject.';return;}
  if(cache.has(key))catalog=cache.get(key);
  else{
   const url=new URL(data.url,window.location.origin);url.search=new URLSearchParams({action:'note_catalog',class_id:field('modalNoteClass').value,subject_id:field('modalNoteSubject').value});
   const response=await fetch(url,{headers:{Accept:'application/json'}}),body=await response.json();
   if(current!==version)return;
   if(!response.ok||!Array.isArray(body.chapters))throw new Error(body.error||'Unable to load the curriculum. Choose the subject again to retry.');
   catalog=body;cache.set(key,body);
  }
  if(current!==version)return;showCatalog(chapter,topic);save.disabled=!catalog.chapters.length;
 }catch(error){if(current===version){catalog={chapters:[],topics:{}};field('modalNoteChapter').replaceChildren(new Option('Unable to load chapters',''));field('modalNoteTopic').replaceChildren(new Option('No topic',''));field('noteCatalogStatus').textContent=error.message;}}
}
function subjects(selected='',chapter='',topic=''){
 const rows=data.subjects[field('modalNoteClass').value]||[];
 field('modalNoteSubject').replaceChildren(new Option('Choose subject',''));
 rows.forEach(s=>field('modalNoteSubject').add(new Option(s.display_name||s.name_en,s.id)));
 field('modalNoteSubject').value=String(selected);return loadCatalog(chapter,topic);
}
function setContent(content){field('modalNoteContent').value=content;tinymce.get('modalNoteContent')?.setContent(content);}
function showAddNoteModal() {
    clearNoteReferences();
    field('modalNoteVisibility').value=JSON.parse(config.values['learning-index-value-0']||'"private"');
    field('modalActionNote').value='add';field('modalNoteId').value='';field('modalNoteTitle').textContent='Add Study Note';
    document.getElementById('modalNoteChapter').value = JSON.parse(config.values["learning-index-value-1"]||'null');
    field('modalNoteClass').value=String(data.class_id);
    if(!field('modalNoteClass').value)field('modalNoteClass').selectedIndex=0;
    subjects(data.subject_id,data.chapter);field('modalNoteTitleInput').value='';setContent('');noteModalObj.show();
}
async function editNote(note){
 clearNoteReferences();field('modalNoteVisibility').value=note.visibility||'private';
 field('modalActionNote').value=note.action_note==='add'?'add':'edit';field('modalNoteId').value=note.id||note.note_id||'';
 field('modalNoteTitle').textContent=field('modalActionNote').value==='edit'?'Edit Study Note':'Add Study Note';
 field('modalNoteClass').value=String(note.needs_catalog_review?data.class_id:note.class_id);
 field('modalNoteTitleInput').value=note.title||'';setContent(note.content||'');noteModalObj.show();
 await subjects(note.needs_catalog_review?data.subject_id:note.subject_id,note.needs_catalog_review?data.chapter:note.chapter,note.topic||'');
 if(!note.needs_catalog_review&&String(field('modalNoteId').value)===String(note.id||note.note_id||'')&&String(field('modalNoteClass').value)===String(note.class_id)&&String(field('modalNoteSubject').value)===String(note.subject_id)&&field('modalNoteChapter').value===note.chapter)for(const key of references)field('noteRef_'+key).value=note[key]||'';
}
if(data&&field('noteModal')){
 noteModalObj=new ErpUI.Modal(field('noteModal'));
 field('modalNoteClass').addEventListener('change',()=>{clearNoteReferences();subjects();});
 field('modalNoteSubject').addEventListener('change',()=>{clearNoteReferences();loadCatalog();});
 subjects(data.subject_id,data.chapter);
 if(data.retry)editNote(data.retry);
}
scope.bindActions({
 'learning-index-action-0':()=>showAddNoteModal(),
 'learning-index-action-1':function(){this.form.elements.subject_id.value='';this.form.elements.chapter.value='';this.form.submit();},
 'learning-index-action-2':function(){this.form.elements.chapter.value='';this.form.submit();},
 'learning-index-action-3':function(){this.form.submit();},
 'learning-index-action-4':function(){this.form.submit();},
 'learning-index-action-5':function(){this.form.submit();},
 'learning-index-action-6':function(){this.form.submit();},
 'learning-index-action-7':(event,args)=>{editNote(JSON.parse(args[0]));},
 'learning-index-action-8':()=>confirm('Change note visibility? Public school notes are visible within this school.'),
 'learning-index-action-9':()=>{tinymce.triggerSave();if(!field('modalNoteContent').value.trim()){field('noteCatalogStatus').textContent='Enter note content before saving.';return false;}},
 'learning-index-action-10':()=>{clearNoteReferences();topicOptions();},
 'learning-index-action-11':()=>clearNoteReferences()
});
}
