// Bundled from assets/js/paper-chapters.js; uses only this page's scope.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;
const module=undefined; // Browser-only build; retain upstream CommonJS unit-test exports.
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

scope.expose({renderPaperChapters:window.renderPaperChapters});
}
