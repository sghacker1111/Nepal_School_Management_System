import {test} from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import {JSDOM} from 'jsdom';
import '../frontend/pages/learning.js';import '../frontend/pages/questions.js';import '../frontend/pages/papers.js';import '../frontend/pages/curriculum.js';import '../frontend/pages/practice.js';
import {mountPage,disposePage,preparePageSubmit} from '../frontend/app/page-lifecycle.js';
import {createPageScope} from '../frontend/pages/page-scope.js';
import {confirmNavigation} from '../frontend/app/dirty-forms.js';
import {installForms} from '../frontend/app/forms.js';

test('TinyMCE 6 get() registry synchronizes current editors and removes stale instances on navigation',async()=>{
 const dom=new JSDOM('<main id="main-content"><form method="post"><textarea id="owned">Old</textarea></form></main><textarea id="outside"></textarea>',{url:'https://school.example/exam-questions'}),win=dom.window,root=win.document.querySelector('main');
 let saved=0,foreignSaved=0,removed=0,foreignRemoved=0;const node=root.querySelector('textarea'),outside=win.document.querySelector('#outside');
 const editor={id:'owned',getElement:()=>node,getContent:()=>'<p>Updated \\(x^2\\)</p>',save(){saved++;node.value=this.getContent();},remove(){removed++;instances.splice(instances.indexOf(this),1);}};
 const foreign={id:'outside',getElement:()=>outside,save(){foreignSaved++;},remove(){foreignRemoved++;}};const instances=[editor,foreign];
 win.tinymce={get(id){return id===undefined?instances.slice():instances.find(e=>e.id===id);}};
 const controller=new win.AbortController(),scope=createPageScope(root,{signal:controller.signal});
 scope.tinymce.triggerSave();assert.equal(saved,1);assert.equal(foreignSaved,0);scope.dispose();
 await mountPage(root,{pageId:'learning-exam-questions',assets:[],values:{}});await preparePageSubmit(root.querySelector('form'));
 assert.equal(saved,2);assert.match(node.value,/Updated/);await disposePage();assert.equal(removed,1);assert.equal(foreignRemoved,0);win.close();
});
test('editor revisits own one TeX dialog and retain imported equation source',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/exam-questions',runScripts:'outside-only'}),win=dom.window,root=win.document.querySelector('main');
 win.CSS??={};win.CSS.escape??=value=>value;const editors=new Map();
 win.tinymce={editors:[],get:id=>editors.get(id),init(config){for(const node of win.document.querySelectorAll(config.selector)){const handlers={},e={id:node.id,on(events,fn){for(const type of events.split(' '))handlers[type]=fn;},getElement:()=>node,getContent:()=>node.value,getWin:()=>({}),getDoc:()=>win.document,save(){},remove(){editors.delete(this.id);win.tinymce.editors=win.tinymce.editors.filter(editor=>editor!==this);},selection:{getContent:()=>''},ui:{registry:{addButton(){}}}};editors.set(e.id,e);win.tinymce.editors.push(e);config.setup(e);e.handlers=handlers;}}};
 win.eval(readFileSync('assets/dist/ui-components.js','utf8'));win.eval(readFileSync('assets/js/question-editor.js','utf8'));
 for(let visit=0;visit<3;visit++){
  root.innerHTML='<form method="post"><textarea id="exam_question_text" class="exam-rich">\\(\\frac{1}{2}\\)</textarea><textarea id="exam_question_text_np" class="exam-rich"></textarea></form><div class="modal" id="texModal"><textarea id="texInput"></textarea><select id="texDisplayMode"><option value="inline">Inline</option></select><div id="texPreview"></div><button id="btnInsertTex"></button></div>';
  await mountPage(root,{pageId:'learning-exam-questions',assets:[],values:{}});
  assert.deepEqual(win.tinymce.editors.map(e=>e.id).sort(),['exam_question_text','exam_question_text_np']);
  const span=win.document.createElement('span');span.className='math-tex';span.textContent='\\(\\frac{1}{2}\\)';
  editors.get('exam_question_text').handlers.dblclick({target:span,preventDefault(){},stopPropagation(){}});assert.equal(root.querySelector('#texInput').value,'\\frac{1}{2}');assert.equal(root.querySelectorAll('#texModal').length,1);
  await preparePageSubmit(root.querySelector('form'));await disposePage();assert.equal(win.tinymce.editors.length,0);
 }
 dom.window.close();
});
test('practice disposal stops its audio, observers, animation frames and owned fullscreen',async()=>{
 const source=readFileSync('modules/learning/practice_mcqs.php','utf8'),html=source.slice(source.indexOf('<section'),source.indexOf('<?php $content'));
 const dom=new JSDOM('<main id="main-content">'+html.replace(/<\?[^]*?\?>/g,'')+'</main>',{url:'https://school.example/learning/practice-mcqs',runScripts:'outside-only'}),win=dom.window,root=win.document.querySelector('main');
 root.querySelector('#practiceBootstrap').textContent=JSON.stringify({current:null,limit:100});let closed=0,disconnected=0,exited=0;const frames=new Set();
 win.AudioContext=class{state='running';close(){closed++;return Promise.resolve();}};win.ResizeObserver=class{observe(){}disconnect(){disconnected++;}};win.requestAnimationFrame=fn=>{frames.add(fn);return fn;};win.cancelAnimationFrame=id=>frames.delete(id);
 Object.defineProperty(win.document,'fullscreenElement',{configurable:true,get:()=>root.querySelector('#practiceApp')});win.document.exitFullscreen=async()=>exited++;
 await mountPage(root,{pageId:'learning-practice-mcqs',assets:[],modules:['external-mcq-practice'],values:{}});root.querySelector('#practiceSound').click();root.querySelector('#practiceSound').click();await disposePage();
 assert.equal(closed,1);assert.equal(disconnected,1);assert.equal(frames.size,0);assert.equal(exited,1);dom.window.close();
});
test('old OCR resources terminate and cannot select an editor on the next page',async()=>{
 const dom=new JSDOM('<main><textarea id="ocrEditor"></textarea></main>',{url:'https://school.example/learning/ocr-scanner'}),win=dom.window,root=win.document.querySelector('main'),controller=new win.AbortController();let stopped=0,release;
 win.Tesseract={createWorker:async()=>({recognize:()=>new Promise(resolve=>release=resolve),terminate:async()=>stopped++})};let element=root.querySelector('textarea');const editor={getElement:()=>element};win.tinymce={get:()=>editor};
 const scope=createPageScope(root,{signal:controller.signal});const pending=scope.Tesseract.recognize('synthetic-image','eng');await new Promise(resolve=>setImmediate(resolve));controller.abort();
 root.innerHTML='<textarea id="ocrEditor">New page</textarea>';element=root.querySelector('textarea');assert.equal(scope.tinymce.get('ocrEditor'),undefined);release({data:{text:'Old page'}});await assert.rejects(pending,{name:'AbortError'});await new Promise(resolve=>setImmediate(resolve));assert.equal(stopped,1);assert.equal(element.value,'New page');dom.window.close();
});
test('unnamed paper-allocation controls are protected as unsaved drafts',async()=>{
 const dom=new JSDOM('<main id="main-content"><form method="post"><input id="allocationCount" type="number" value="1"></form></main>',{url:'https://school.example/exam-paper-generator'}),root=dom.window.document.querySelector('main');
 await mountPage(root,{pageId:'learning-exam-paper-generator',assets:[],values:{}});dom.window.confirm=()=>false;root.querySelector('input').value='2';root.querySelector('input').dispatchEvent(new dom.window.Event('input',{bubbles:true}));assert.equal(await confirmNavigation(),false);await disposePage();dom.window.close();
});
test('OCR filenames are displayed as text rather than inserted as HTML',async()=>{
 const source=readFileSync('modules/learning/ocr_scanner.php','utf8'),html=source.slice(source.indexOf('<div class="container-fluid'),source.lastIndexOf('<?php schoolPageModule("learning-ocr-scanner")'));
 const dom=new JSDOM('<main id="main-content">'+html.replace(/<\?[^]*?\?>/g,'')+'</main>',{url:'https://school.example/learning/ocr-scanner'}),win=dom.window,root=win.document.querySelector('main');win.pdfjsLib={GlobalWorkerOptions:{}};win.tinymce={editors:[],init:async()=>[],get(){}};
 await mountPage(root,{pageId:'learning-ocr-scanner',assets:[],modules:['learning-ocr-scanner'],values:{}});
 const input=root.querySelector('#fileInput'),name='<img src=x onerror=alert(1)>.png';Object.defineProperty(input,'files',{value:[new win.File(['synthetic'],name,{type:'image/png'})]});input.dispatchEvent(new win.Event('change',{bubbles:true}));
 assert.equal(root.querySelector('.ocr-file-name').textContent,name);assert.equal(root.querySelector('#fileList img'),null);assert.equal(root.querySelectorAll('[data-ocr-remove]').length,1);await disposePage();dom.window.close();
});
test('an enhanced import can be corrected and submitted again after validation fails',async()=>{
 const dom=new JSDOM('<main id="main-content"><form method="post" data-exam-import-form="mcq"><input name="import_content_mode" value="normal"><button type="submit">Import MCQs</button></form></main>',{url:'https://school.example/exam-mcqs'}),win=dom.window,root=win.document.querySelector('main');let calls=0;
 const off=installForms({}, {window:win,isEnhancedURL:()=>true,cancelNavigation(){},request:async()=>{calls++;return{status:422,text:'<div hidden data-erp-config="{&quot;routeId&quot;:&quot;learning-exam-mcqs&quot;,&quot;pageId&quot;:&quot;learning-exam-mcqs&quot;,&quot;mutation&quot;:{&quot;outcome&quot;:&quot;invalid&quot;}}"></div><div class="alert alert-danger">Fix row 2.</div>',header:name=>name==='X-ERP-Fragment'?'app-page':name==='Content-Type'?'text/html':null};}});
 const forms=await import('../frontend/app/forms.js');win.erpForms=forms;
 await mountPage(root,{pageId:'learning-exam-mcqs',modules:['external-exam-questions'],assets:[],values:{}});
 for(let attempt=0;attempt<2;attempt++){root.querySelector('form').requestSubmit();for(let n=0;n<50&&!root.querySelector('[data-form-error]');n++)await new Promise(resolve=>setImmediate(resolve));assert.equal(calls,attempt+1);assert.equal(root.querySelector('button').disabled,false);root.querySelector('[data-form-error]')?.remove();}
 await disposePage();off();win.close();
});

test('OCR auto-format keeps line boundaries and treats recognized markup as text',async()=>{
 const source=readFileSync('modules/learning/ocr_scanner.php','utf8'),html=source.slice(source.indexOf('<div class="container-fluid'),source.lastIndexOf('<?php schoolPageModule("learning-ocr-scanner")'));
 const dom=new JSDOM('<main id="main-content">'+html.replace(/<\?[^]*?\?>/g,'')+'</main>',{url:'https://school.example/learning/ocr-scanner'}),win=dom.window,root=win.document.querySelector('main');
 let content='<p>1. &lt;img src=x onerror=alert(1)&gt;<br>(a) Find x &lt; 5</p><p>Plain text &amp; formula \\(x^2\\)</p>';
 const editor={getElement:()=>root.querySelector('#ocrEditor'),getContent:()=>content,setContent(value){content=value;},remove(){}};
 win.pdfjsLib={GlobalWorkerOptions:{}};win.tinymce={get:id=>id?editor:[editor]};
 await mountPage(root,{pageId:'learning-ocr-scanner',assets:[],modules:['learning-ocr-scanner'],values:{}});
 root.querySelector('[data-erp-click="learning-ocr-scanner-action-3"]').click();
 const parsed=win.document.createElement('div');parsed.innerHTML=content;assert.equal(parsed.querySelector('img'),null);assert.equal(parsed.querySelectorAll('p').length,3);assert.match(parsed.textContent,/<img src=x onerror=alert\(1\)>/);assert.match(content,/\\\(x\^2\\\)/);
 await disposePage();win.close();
});
