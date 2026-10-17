import {test} from 'node:test';import assert from 'node:assert/strict';import {readFileSync} from 'node:fs';import {JSDOM} from 'jsdom';
import '../frontend/pages/school.js';import '../frontend/pages/academics.js';import '../frontend/pages/administration.js';
import {mountPage,disposePage} from '../frontend/app/page-lifecycle.js';
import {createPageScope} from '../frontend/pages/page-scope.js';
import {trackForms,confirmNavigation} from '../frontend/app/dirty-forms.js';
test('three list/edit/list visits create one table and one action handler per live page',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/students',runScripts:'outside-only'});dom.window.eval(readFileSync('assets/dist/vendor.js','utf8'));dom.window.eval(readFileSync('assets/dist/ui-components.js','utf8'));
 const root=dom.window.document.querySelector('main');let confirmations=0;dom.window.verifyPasswordAndProceed=()=>confirmations++;
 for(let visit=0;visit<3;visit++){
  root.innerHTML='<table id="studentTable"><thead><tr><th>ID</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><button data-erp-click="students-index-action-3" data-erp-click-args="[&quot;1&quot;]">Archive</button>';
  await mountPage(root,{pageId:'students-index',assets:[],values:{}});
  assert.equal(dom.window.jQuery.fn.dataTable.tables().length,1);root.querySelector('button').click();assert.equal(confirmations,visit+1);
  await disposePage();assert.equal(dom.window.jQuery.fn.dataTable.tables().length,0);
  root.innerHTML='<form method="post"><input name="name" value="Test"></form>';await mountPage(root,{pageId:'students-edit',assets:[],values:{}});await disposePage();
 }
 dom.window.close();
});
test('page JSON saves are not duplicated or aborted by navigation',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/classes/subjects'}),win=dom.window,root=win.document.querySelector('main');let requests=0,release,options;
 win.fetch=async(url,init)=>{requests++;options=init;return await new Promise(resolve=>release=resolve);};
 const controller=new win.AbortController(),scope=createPageScope(root,{signal:controller.signal}),stop=trackForms(root,{});
 const first=scope.fetch('/classes/subjects',{method:'POST',body:'mapping=1'}),duplicate=scope.fetch('/classes/subjects',{method:'POST',body:'mapping=1'});
 assert.equal(requests,1);assert(!options.signal);assert.equal(await confirmNavigation(),false);
 release(new Response(JSON.stringify({success:true}),{headers:{'Content-Type':'application/json'}}));await Promise.all([first,duplicate]);assert.equal(await confirmNavigation(),true);
 controller.abort();stop();dom.window.close();
});
test('JSON page saves also retain edits newer than the submitted snapshot',async()=>{
 const dom=new JSDOM('<main><form method="post"><input name="name" value="Submitted"><button type="button">Save</button></form></main>',{url:'https://school.example/classes/subjects'}),win=dom.window,root=win.document.querySelector('main');let release,pending;
 win.fetch=async()=>new Promise(resolve=>release=resolve);const controller=new win.AbortController(),scope=createPageScope(root,{signal:controller.signal}),off=trackForms(root,{});
 scope.bindActions({save(){pending=scope.fetch('/classes/subjects',{method:'POST',body:'name=Submitted'});}});root.querySelector('button').setAttribute('data-erp-click','save');root.querySelector('button').click();root.querySelector('input').value='Newer';release(new Response('{"success":true}',{headers:{'Content-Type':'application/json'}}));await pending;win.confirm=()=>false;assert.equal(await confirmNavigation(),false);assert.equal(root.querySelector('input').value,'Newer');controller.abort();off();win.close();
});
