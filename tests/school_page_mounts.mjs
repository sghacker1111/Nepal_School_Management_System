// A real PHP fixture provides these synthetic pages; all JS executes in isolated DOMs.
import {readFileSync} from 'node:fs';import assert from 'node:assert/strict';import {JSDOM,VirtualConsole} from 'jsdom';
import '../frontend/pages/school.js';import '../frontend/pages/academics.js';import '../frontend/pages/administration.js';
import {mountPage,disposePage} from '../frontend/app/page-lifecycle.js';
const pages=JSON.parse(readFileSync(process.argv[2],'utf8'));
if(pages.some(page=>/^(attendance|results|reports)-/.test(page.config.pageId))){await import('../frontend/pages/attendance.js');await import('../frontend/pages/results.js');}
for(const page of pages){
 const errors=[],console=new VirtualConsole();console.on('jsdomError',error=>errors.push(error.message));
 const dom=new JSDOM('<meta name="csrf-token"><main id="main-content">'+page.html+'</main>',{url:page.url,runScripts:'outside-only',virtualConsole:console});
 const win=dom.window;win.APP_URL=new URL(page.url).origin;win.CSRF_TOKEN=page.config.csrfToken;win.fetch=async()=>{throw new Error('Unexpected HTTP request during initial page mount: '+page.url);};win.Headers=Headers;win.Request=Request;win.confirm=()=>false;win.alert=message=>errors.push('Unexpected dialog: '+message);
 for(const file of ['assets/dist/vendor.js','assets/dist/ui-components.js','assets/js/main.js','assets/js/school-dates.js','assets/js/routine-controls.js','assets/js/attendance-controls.js','assets/js/curriculum-delete.js'])win.eval(readFileSync(file,'utf8'));
 const styles={'attendance-css':'attendance','ledger-css':'result-ledger'};
 for(const id of page.config.assets||[])if(styles[id]){const link=win.document.createElement('link');link.rel='stylesheet';link.href=win.APP_URL+'/assets/css/'+styles[id]+'.css';win.document.head.append(link);}
 await mountPage(win.document.querySelector('main'),page.config);
 await new Promise(resolve=>setImmediate(resolve));
 if(page.config.pageId==='settings-users'){
  const add=win.document.querySelector('[data-erp-click="settings-users-action-0"]');add.click();assert(win.document.getElementById('accountModal').classList.contains('show'),'Account dialog opens without inline scripts');
 }
 await disposePage();assert.deepEqual(errors,[],page.url);assert.equal(win.document.querySelectorAll('.modal-backdrop').length,0,'Page modal backdrop disposed');dom.window.close();
}
console.log('Mounted and disposed '+pages.length+' synthetic school pages with table/dialog/date controllers.');
