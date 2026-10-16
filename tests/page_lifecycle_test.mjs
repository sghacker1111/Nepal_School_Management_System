import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {registerPage,mountPage,disposePage,preparePageSubmit,readPageMetadata} from '../frontend/app/page-lifecycle.js';
const fixture=()=>new JSDOM('<main id="main-content"><button>Run</button><form><input></form></main>',{url:'https://school.example/erp/students',runScripts:'outside-only'});
test('mount/dispose/remount owns exactly one handler and one submit hook',async()=>{
 const dom=fixture(),root=dom.window.document.querySelector('main');let calls=0,disposals=0,submits=0,previous;
 registerPage('lifecycle-example',(node,context)=>{
  previous=context;node.querySelector('button').addEventListener('click',()=>calls++,{signal:context.signal});
  context.onBeforeSubmit(()=>submits++);return()=>disposals++;
 });
 await mountPage(root,{pageId:'lifecycle-example',assets:[]});const first=previous;
 root.querySelector('button').click();await preparePageSubmit(root.querySelector('form'));
 assert.equal(calls,1);assert.equal(submits,1);
 await mountPage(root,{pageId:'lifecycle-example',assets:[]});assert.equal(first.signal.aborted,true);assert.equal(disposals,1);
 root.querySelector('button').click();await preparePageSubmit(root.querySelector('form'));assert.equal(calls,2);assert.equal(submits,2);
 await disposePage();assert.equal(disposals,2);dom.window.close();
});
test('late initialization is cleaned and cannot become the current page',async()=>{
 const dom=fixture(),root=dom.window.document.querySelector('main');let release,lateDisposed=0,late,started;
 const ready=new Promise(resolve=>started=resolve);
 registerPage('lifecycle-slow',async(node,context)=>{late=context;await new Promise(resolve=>{release=resolve;started();});return()=>lateDisposed++;});
 registerPage('lifecycle-fast',()=>{});
 const pending=mountPage(root,{pageId:'lifecycle-slow'});await ready;
 await mountPage(root,{pageId:'lifecycle-fast'});release();await pending;
 assert(late.signal.aborted);assert.equal(lateDisposed,1);await disposePage();dom.window.close();
});
test('adapters and asset IDs are finite and untrusted scripts/config lookalikes are inert',async()=>{
 const dom=fixture(),root=dom.window.document.querySelector('main');
 assert.throws(()=>registerPage('lifecycle-fast',()=>{}),/duplicate/i);
 await assert.rejects(mountPage(root,{pageId:'missing'}),/adapter/i);
 for(const asset of ['https://evil.test/evil.js','/assets/evil.js','unknown'])await assert.rejects(mountPage(root,{pageId:'lifecycle-fast',assets:[asset]}),/asset/i);
 root.innerHTML='<div class="exam-bank-rich"><div data-erp-config="{}"></div><script>window.untrustedScriptRuns=1</script></div>';
 assert.throws(()=>readPageMetadata(root),/metadata/i);
 const marker=dom.window.document.createElement('div');marker.dataset.erpConfig=JSON.stringify({pageId:'lifecycle-fast',routeId:'students-index',assets:[]});marker.hidden=true;root.prepend(marker);
 assert.equal(readPageMetadata(root).pageId,'lifecycle-fast');await mountPage(root,readPageMetadata(root));assert.equal(dom.window.untrustedScriptRuns,undefined);
 await disposePage();dom.window.close();
 const source=readFileSync('frontend/app/page-lifecycle.js','utf8');assert(!/\beval\s*\(|new\s+Function\b/.test(source));
});
test('trusted CSS loads are deduplicated and unknown URLs are not requested',async()=>{
 const dom=fixture(),root=dom.window.document.querySelector('main');dom.window.APP_URL='https://school.example/erp';
 const first=mountPage(root,{pageId:'lifecycle-fast',assets:['attendance-css']});
 await Promise.resolve();const link=dom.window.document.querySelector('link');assert(link);assert.equal(link.href,'https://school.example/erp/assets/css/attendance.css');link.dispatchEvent(new dom.window.Event('load'));await first;
 await mountPage(root,{pageId:'lifecycle-fast',assets:['attendance-css']});assert.equal(dom.window.document.querySelectorAll('link').length,1);
 await disposePage();dom.window.close();
});
test('dispose touches only page-owned editors, tables and modal instances',async()=>{
 const dom=fixture(),root=dom.window.document.querySelector('main');root.innerHTML+='<textarea id="owned"></textarea><table></table><div class="modal"></div>';
 const outside=dom.window.document.createElement('textarea');dom.window.document.body.append(outside);let owned=0,foreign=0,tables=0,modals=0;
 dom.window.tinymce={editors:[{getElement:()=>root.querySelector('textarea'),remove:()=>owned++},{getElement:()=>outside,remove:()=>foreign++}]};
 const $=()=>({DataTable:()=>({destroy:()=>tables++})});$.fn={dataTable:{isDataTable:()=>true}};dom.window.jQuery=$;
 dom.window.ErpUI={Modal:{getInstance:node=>node.classList.contains('modal')?{dispose:()=>modals++}:null}};
 await mountPage(root,{pageId:'lifecycle-fast'});await disposePage();assert.equal(owned,1);assert.equal(foreign,0);assert.equal(tables,1);assert.equal(modals,1);dom.window.close();
});
test('date controls initialize the live page once and detach on disposal',async()=>{
 const dom=fixture(),root=dom.window.document.querySelector('main');root.innerHTML='<input type="date" name="payment_date" value="2026-10-17">';
 dom.window.eval(readFileSync('assets/js/school-dates.js','utf8'));
 const controller=new dom.window.AbortController();
 dom.window.initSchoolDates(root,{signal:controller.signal});dom.window.initSchoolDates(root,{signal:controller.signal});
 assert.equal(root.querySelectorAll('select').length,1);controller.abort();assert.equal(root.querySelectorAll('select').length,0);dom.window.close();
});
