// A real PHP fixture provides these synthetic pages; all JS executes in isolated DOMs.
import {readFileSync} from 'node:fs';import assert from 'node:assert/strict';import {JSDOM,VirtualConsole} from 'jsdom';
import '../frontend/pages/school.js';import '../frontend/pages/academics.js';import '../frontend/pages/administration.js';
import {mountPage,disposePage} from '../frontend/app/page-lifecycle.js';
const pages=JSON.parse(readFileSync(process.argv[2],'utf8'));
if(pages.some(page=>/^(attendance|results|reports)-/.test(page.config.pageId))){await import('../frontend/pages/attendance.js');await import('../frontend/pages/results.js');}
if(pages.some(page=>/^(learning-|super-admin-question-corrections)/.test(page.config.pageId)))for(const group of ['learning','questions','papers','curriculum','practice'])await import('../frontend/pages/'+group+'.js');
for(const page of pages){
 const errors=[],console=new VirtualConsole();console.on('jsdomError',error=>errors.push(error.message));
 const dom=new JSDOM('<meta name="csrf-token"><main id="main-content">'+page.html+'</main>',{url:page.url,runScripts:'outside-only',virtualConsole:console});
 const win=dom.window;win.APP_URL=new URL(page.url).origin;win.CSRF_TOKEN=page.config.csrfToken;win.fetch=async()=>{throw new Error('Unexpected HTTP request during initial page mount: '+page.url);};win.Headers=Headers;win.Request=Request;win.confirm=()=>false;win.alert=message=>errors.push('Unexpected dialog: '+message);
 for(const file of ['assets/dist/vendor.js','assets/dist/ui-components.js','assets/js/main.js','assets/js/school-dates.js','assets/js/routine-controls.js','assets/js/attendance-controls.js','assets/js/curriculum-delete.js'])win.eval(readFileSync(file,'utf8'));
 win.CSS??={};win.CSS.escape??=value=>value;
 win.MathJax={startup:{promise:Promise.resolve()},typesetPromise:async()=>{},typesetClear(){}};
 win.pdfjsLib={GlobalWorkerOptions:{}};win.Tesseract={};
 const editors=new Map();win.tinymce={editors:[],get:id=>editors.get(id),triggerSave(){for(const e of editors.values())e.save();},init(config){for(const node of config.target?[config.target]:win.document.querySelectorAll(config.selector)){const e={id:node.id,on(){},getElement:()=>node,getContent:()=>node.value,getWin:()=>({}),save(){},remove(){editors.delete(e.id);win.tinymce.editors=win.tinymce.editors.filter(item=>item!==e);},ui:{registry:{addButton(){}}}};editors.set(e.id,e);win.tinymce.editors.push(e);config.setup?.(e);}return Promise.resolve([...editors.values()]);}};
 for(const file of ['assets/js/question-editor.js','assets/js/exam-question-language.js'])win.eval(readFileSync(file,'utf8'));
 win.fetch=async()=>new Response(JSON.stringify({success:true,chapters:[],topics:{},subjects:[]}),{headers:{'Content-Type':'application/json'}});
 const styles={'attendance-css':'attendance','ledger-css':'result-ledger','question-language-css':'exam-question-language','question-bank-css':'exam-question-bank','practice-css':'mcq-practice','exam-paper-css':'exam-paper'};
 for(const id of page.config.assets||[])if(styles[id]){const link=win.document.createElement('link');link.rel='stylesheet';link.href=win.APP_URL+'/assets/css/'+styles[id]+'.css';win.document.head.append(link);}
 await mountPage(win.document.querySelector('main'),page.config);
 await new Promise(resolve=>setImmediate(resolve));
 if(page.config.pageId==='settings-users'){
  const add=win.document.querySelector('[data-erp-click="settings-users-action-0"]');add.click();assert(win.document.getElementById('accountModal').classList.contains('show'),'Account dialog opens without inline scripts');
 }
 await disposePage();assert.deepEqual(errors,[],page.url);assert.equal(win.document.querySelectorAll('.modal-backdrop').length,0,'Page modal backdrop disposed');dom.window.close();
}
console.log('Mounted and disposed '+pages.length+' synthetic school pages with table/dialog/date controllers.');
