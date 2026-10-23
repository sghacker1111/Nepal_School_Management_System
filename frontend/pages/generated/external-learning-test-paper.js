// Bundled from assets/js/learning-test-paper.js; uses only this page's scope.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;
const module=undefined; // Browser-only build; retain upstream CommonJS unit-test exports.
(function(){'use strict';
const data=window.learningTestPaperData;if(!data)return;
const root=document.getElementById('testPaperRows'),form=document.getElementById('testPaperForm');let serial=0;
function select(label,items,value=''){
 const td=document.createElement('td'),field=document.createElement('select');field.className='form-select';field.setAttribute('aria-label',label);field.id='testAllocation_'+(++serial);
 items.forEach(item=>field.add(new Option(item.label??item.name,item.value??item.id)));field.value=String(value);td.append(field);return {td,field};
}
function number(label,value,step,max){const td=document.createElement('td'),field=document.createElement('input');field.type='number';field.className='form-control';field.min='0';field.max=max;field.step=step;field.value=value;field.setAttribute('aria-label',label);td.append(field);return {td,field};}
function add(row={}){
 if(root.children.length>=50)return;
 const tr=document.createElement('tr'),chapter=select('Chapter',[{label:'Choose chapter',value:''},...data.chapters],row.chapter_id??''),topic=select('Topic',[{label:'All topics in chapter',value:''}]),kind=select('Question type',data.types.map(type=>({label:type,value:type})),row.type??'MCQ'),count=number('Number of questions',row.count??0,'1','200'),marks=number('Total row marks',row.total_marks??0,'0.01','1000');
 function topics(value=''){topic.field.replaceChildren(new Option('All topics in chapter',''));(data.topics[chapter.field.value]||[]).forEach(t=>topic.field.add(new Option(t.name,t.id)));topic.field.value=String(value);if(!topic.field.value)topic.field.value='';topic.field.disabled=!chapter.field.value;}
 chapter.field.addEventListener('change',()=>topics());topics(row.topic_id??'');
 const removeCell=document.createElement('td'),remove=document.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent='Remove';remove.addEventListener('click',()=>{tr.remove();totals();});removeCell.append(remove);
 tr.append(chapter.td,topic.td,kind.td,count.td,marks.td,removeCell);tr._fields={chapter:chapter.field,topic:topic.field,type:kind.field,count:count.field,marks:marks.field};root.append(tr);
 count.field.addEventListener('input',totals);marks.field.addEventListener('input',totals);totals();
}
function rows(){return [...root.children].map(tr=>{const f=tr._fields;return {chapter_id:f.chapter.value,topic_id:f.topic.value,type:f.type.value,count:f.count.value,total_marks:f.marks.value};});}
function totals(){const active=rows().filter(r=>Number(r.count)>0);document.getElementById('testPaperTotals').textContent=active.reduce((n,r)=>n+(Number(r.count)||0),0)+' questions · '+(active.reduce((n,r)=>n+Math.round((Number(r.total_marks)||0)*100),0)/100).toFixed(2)+' marks';}
document.getElementById('testPaperAdd').addEventListener('click',()=>add());
form.addEventListener('submit',event=>{
 const config=rows(),active=config.filter(r=>Number(r.count)>0),error=document.getElementById('testPaperError');error.hidden=true;
 if(!active.length||active.some(r=>!r.chapter_id||!/^\d+$/.test(r.count)||Number(r.count)>200||!(Number(r.total_marks)>0))){event.preventDefault();error.textContent='Choose a chapter, type, positive whole question count and total marks for every active row.';error.hidden=false;return;}
 document.getElementById('testPaperConfig').value=JSON.stringify({duration_minutes:document.getElementById('testPaperDuration').value,rows:config});
});
(data.initial?.rows?.length?data.initial.rows:[{}]).forEach(add);
})();


}
