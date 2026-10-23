import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
import {JSDOM,requestInterceptor,VirtualConsole} from 'jsdom';
import {registerPage,mountPage,disposePage} from '../frontend/app/page-lifecycle.js';
import {trackForms,mutationState} from '../frontend/app/dirty-forms.js';

const tick=()=>new Promise(resolve=>setImmediate(resolve));
registerPage('editor-assets-fixture',()=>{});
test('question editor dependencies are versioned same-origin files, including iframe maths',()=>{
 const lifecycle=readFileSync('frontend/app/page-lifecycle.js','utf8');
 assert.match(lifecycle,/tinymce: '\/assets\/vendor\/tinymce-6\.8\.6\/tinymce\.min\.js'/);
 assert.match(lifecycle,/mathjax: '\/assets\/vendor\/mathjax-3\.2\.2\/tex-mml-chtml\.js'/);
 const editor=readFileSync('assets/js/question-editor.js','utf8');
 assert(!editor.includes('https://cdn.jsdelivr.net/npm/mathjax'));
 for(const config of [lifecycle,editor]){assert.match(config,/load:\s*\[\s*'ui\/safe'\s*\]/);assert.match(config,/URLs:\s*'none'/);}
 for(const name of ['tinymce-6.8.6/tinymce.min.js','tinymce-6.8.6/themes/silver/theme.min.js','tinymce-6.8.6/models/dom/model.min.js','tinymce-6.8.6/skins/ui/oxide/skin.min.css','tinymce-6.8.6/plugins/code/plugin.min.js','tinymce-6.8.6/plugins/table/plugin.min.js','mathjax-3.2.2/tex-mml-chtml.js','mathjax-3.2.2/input/tex/extensions/ams.js','mathjax-3.2.2/output/chtml/fonts/woff-v2/MathJax_Main-Regular.woff'])assert(existsSync('assets/vendor/'+name),name+' must be built for deployment');
});

test('timed-out script is removed so the next page can retry rather than await a dead tag',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/erp/questions'}),win=dom.window,root=win.document.querySelector('main');
 win.APP_URL='https://school.example/erp';let expire;win.setTimeout=fn=>{expire=fn;return 1;};win.clearTimeout=()=>{};
 try{
  const pending=mountPage(root,{pageId:'editor-assets-fixture',assets:['tinymce']});await tick();
  const old=win.document.querySelector('script');assert(old);expire();await assert.rejects(pending,/timed out/);
  assert(!old.isConnected,'A timed-out tag cannot be reused: its load event may already be gone');
  const retry=mountPage(root,{pageId:'editor-assets-fixture',assets:['tinymce']});await tick();
  const fresh=win.document.querySelector('script');assert(fresh&&fresh!==old);assert.equal(fresh.src,'https://school.example/erp/assets/vendor/tinymce-6.8.6/tinymce.min.js');
  win.tinymce={init(){},editors:[]};fresh.dispatchEvent(new win.Event('load'));await retry;
 }finally{await disposePage();win.close();}
});

test('a 200 response without the expected editor API is a failed dependency, not a successful load',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/questions'}),win=dom.window,root=win.document.querySelector('main');
 try{
  const pending=mountPage(root,{pageId:'editor-assets-fixture',assets:['tinymce']});await tick();
  const script=win.document.querySelector('script');script.dispatchEvent(new win.Event('load'));
  await assert.rejects(pending,/unavailable/);assert(!script.isConnected);
 }finally{await disposePage();win.close();}
});

test('the actual shipped TinyMCE uses get() and actual local MathJax renders a fraction without CDN requests',async()=>{
 const requests=[],errors=[];
 const localRuntime=requestInterceptor(request=>{
  const parsed=new URL(request.url);assert.equal(parsed.origin,'https://school.example');assert(parsed.pathname.startsWith('/assets/vendor/'));assert(!parsed.pathname.includes('..'));
  requests.push(parsed.pathname);return new Response(readFileSync('.'+parsed.pathname),{headers:{'Content-Type':parsed.pathname.endsWith('.css')?'text/css':'application/javascript'}});
 });
 const console=new VirtualConsole();console.on('jsdomError',error=>errors.push(error.message));
 const dom=new JSDOM('<!doctype html><html><head><script>window.MathJax={tex:{inlineMath:[["\\\\(","\\\\)"]]},loader:{load:["ui/safe"]},options:{enableMenu:false,safeOptions:{allow:{URLs:"none",classes:"safe",cssIDs:"none",styles:"safe"}}},startup:{typeset:false}};</script><script src="/assets/vendor/tinymce-6.8.6/tinymce.min.js"></script><script src="/assets/vendor/mathjax-3.2.2/tex-mml-chtml.js"></script></head><body><div id="math">\\(\\frac{1}{2}+x^2\\)</div></body></html>',{
  url:'https://school.example/exam-questions',runScripts:'dangerously',pretendToBeVisual:true,resources:{interceptors:[localRuntime]},virtualConsole:console,
  beforeParse(win){win.matchMedia=()=>({matches:false,addListener(){},removeListener(){}});win.CSS={escape:value=>value};}
 });
 try {
  await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('Local math runtime timed out')),10000);dom.window.addEventListener('load',()=>{clearTimeout(timer);resolve();},{once:true});});
  const win=dom.window;assert.equal(typeof win.tinymce.init,'function');assert.equal(win.tinymce.editors,undefined);assert.deepEqual([...win.tinymce.get()],[]);
  await win.MathJax.startup.promise;await win.MathJax.typesetPromise([win.document.querySelector('#math')]);
  assert(win.document.querySelector('#math mjx-container mjx-mfrac'),'The real local renderer must render a fraction');
  assert.deepEqual(errors,[]);assert(requests.some(path=>path.endsWith('/ui/safe.js')));
  const dangerous=win.document.createElement('div');dangerous.textContent='\\(\\href{javascript:alert(1)}{x}+\\href{java^^73cript:alert(2)}{y}+\\frac{1}{2}\\)';win.document.body.append(dangerous);
  await win.MathJax.typesetPromise([dangerous]);assert(dangerous.querySelector('mjx-mfrac'));
  assert.equal(dangerous.querySelector('[href]'),null,'TeX must never create actionable links after HTML sanitization');
  const root=win.document.createElement('main');root.id='main-content';root.innerHTML='<form method="post"><textarea name="question_text" id="exam_question_text" class="exam-rich"></textarea></form><div class="modal" id="texModal"><textarea id="texInput"></textarea><select id="texDisplayMode"><option value="inline">Inline</option><option value="block">Block</option></select><div id="texPreview"></div><button id="btnInsertTex">Insert</button></div>';win.document.body.append(root);win.APP_URL=win.location.origin;win.scrollTo=()=>{};
  const {createEditorSanitizer}=await import('../frontend/app/editor-security.js');
  win.SchoolEditorSecurity={sanitize:createEditorSanitizer(win)};
  win.eval(readFileSync('assets/dist/ui-components.js','utf8'));win.eval(readFileSync('assets/js/question-editor.js','utf8'));
  let initialization;const init=win.tinymce.init;win.tinymce.init=options=>(initialization=init.call(win.tinymce,options));
  root.querySelector('textarea').value='<p>Initial saved question</p>';
  const dispose=win.SchoolQuestionEditor.init('.exam-rich',root);
  const disposeTracking=trackForms(root);await initialization;
  assert.equal(mutationState(root.querySelector('form')),'clean','Loading an existing rich question must not invent an unsaved change');
  const editor=win.tinymce.get('exam_question_text');assert(editor.initialized,'The real rich-text editor must initialize');
  // jsdom has no layout-backed innerText; TinyMCE uses it for text selections.
  Object.defineProperty(editor.getWin().HTMLElement.prototype,'innerText',{configurable:true,get(){return this.textContent;},set(value){this.textContent=value;}});
  editor.getWin().Range.prototype.getClientRects=()=>[];
  editor.getWin().Range.prototype.getBoundingClientRect=()=>({x:0,y:0,left:0,top:0,right:0,bottom:0,width:0,height:0});
  editor.setContent('<p>Edited question \\(\\frac{3}{4}\\)</p>');editor.save();assert.match(root.querySelector('textarea').value,/\\frac\{3\}\{4\}/);
  assert.equal(mutationState(root.querySelector('form')),'dirty','Actual rich edits must remain protected');
  assert(!root.querySelector('textarea').value.includes('mjx-container'),'Only portable TeX is saved, never renderer markup');
  // Establish the caret a browser normally supplies when the author clicks in.
  editor.focus();editor.selection.select(editor.getBody(),true);editor.selection.collapse(false);
  editor.ui.registry.getAll().buttons.math_tex.onAction();assert(root.querySelector('#texModal').classList.contains('show'));
  root.querySelector('#texInput').value='x^2+1';root.querySelector('#btnInsertTex').click();assert.deepEqual(errors,[]);assert.match(root.querySelector('textarea').value,/x\^2\+1/,'Editor DOM after insertion: '+editor.getBody().innerHTML);
  disposeTracking();dispose();assert.equal(win.tinymce.get('exam_question_text'),null);
 } finally {dom.window.close();}
});
