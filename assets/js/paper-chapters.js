(function(root){
 'use strict';
 function renderPaperChapters(select,chapters,selected){
  select.replaceChildren(new Option('All Chapters (Full Syllabus)',''));
  const seen=new Set();
  for(const chapter of Array.isArray(chapters)?chapters:[]){
   const name=typeof chapter==='string'?chapter:chapter&&typeof chapter.name==='string'?chapter.name:'';
   if(!name||seen.has(name))continue;seen.add(name);
   const option=new Option(name,name);option.selected=name===selected;select.appendChild(option);
  }
 }
 if(typeof module==='object'&&module.exports)module.exports={renderPaperChapters};
 else root.renderPaperChapters=renderPaperChapters;
})(typeof window==='object'?window:globalThis);
