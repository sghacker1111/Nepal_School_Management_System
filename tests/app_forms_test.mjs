import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';
import {installForms,submitForm} from '../frontend/app/forms.js';
import {trackForms,mutationState,confirmNavigation} from '../frontend/app/dirty-forms.js';
const marker=outcome=>`<div hidden data-erp-config="${JSON.stringify({routeId:'students-add',pageId:'students-add',mutation:{outcome}}).replaceAll('"','&quot;')}"></div>`;
const result=(status=200,outcome='saved',extra={})=>({status,text:marker(outcome)+(outcome==='invalid'?'<div class="alert alert-danger">Name is invalid.</div>':'<p>Saved</p>'),header:name=>name==='X-ERP-Fragment'?'app-page':name==='Content-Type'?'text/html':null,...extra});
function fixture(request=async()=>result(),extra={}){
 const dom=new JSDOM('<main id="main-content"><form method="post" action="/students/add"><input name="name" value="Draft" required><input type="hidden" name="csrf_token" value="old"><input type="hidden" name="idempotency_key" value="original-key"><input type="file" name="excel_file"><button name="action" value="save">Save</button></form></main>',{url:'https://school.example/students/add'});
 const root=dom.window.document.querySelector('main'),form=root.querySelector('form');dom.window.CSRF_TOKEN='fresh-token';
 const untrack=trackForms(root,{});let applied=0,toasts=0,gets=0;
 dom.window.schoolNotify=()=>toasts++;
 const deps={window:dom.window,request,isEnhancedURL:()=>true,preparePageSubmit:async()=>{},applyPageResponse:async()=>applied++,navigate:async()=>gets++,cancelNavigation(){},...extra};
 const off=installForms({},deps);
 return{dom,form,deps,dispose(){off();untrack();dom.window.close();},get applied(){return applied;},get toasts(){return toasts;},get gets(){return gets;}};
}
test('editor sync precedes validation; invalid native constraints send nothing',async()=>{
 let calls=0;const f=fixture(async()=>{calls++;return result();});f.form.elements.name.value='';
 await submitForm(f.form,f.form.querySelector('button'));assert.equal(calls,0);
 f.deps.preparePageSubmit=async form=>form.elements.name.value='Synced editor';
 await submitForm(f.form,f.form.querySelector('button'));assert.equal(calls,1);assert.equal(f.form.elements.name.value,'Synced editor');assert.equal(mutationState(f.form),'clean');f.dispose();
});
test('one double click sends one multipart mutation with submitter, file, CSRF and original key',async()=>{
 let release,calls=0,sent;const f=fixture(async(url,init)=>{calls++;sent=init;return await new Promise(resolve=>release=resolve);});
 const first=submitForm(f.form,f.form.querySelector('button')),second=submitForm(f.form,f.form.querySelector('button'));await new Promise(resolve=>setImmediate(resolve));
 assert.equal(calls,1);assert.equal(sent.method,'POST');assert.equal(sent.body.get('csrf_token'),'fresh-token');assert.equal(sent.body.get('action'),'save');assert.equal(sent.body.get('idempotency_key'),'original-key');assert.equal(sent.body.getAll('excel_file').length,1);assert(!('signal' in sent),'Pending POST must not be aborted by navigation');assert.equal(await confirmNavigation(),false);
 release(result());await Promise.all([first,second]);assert.equal(f.applied,1);f.dispose();
});
test('204 HX-Location performs a GET only, never reposting the mutation',async()=>{
 const methods=[];const f=fixture(async(url,init)=>{methods.push(init.method);return {status:204,text:'',header:name=>name==='HX-Location'?JSON.stringify({path:'/students',target:'#main-content',swap:'innerHTML'}):null};});
 f.deps.navigate=async()=>methods.push('GET');await submitForm(f.form,f.form.querySelector('button'));assert.equal(methods.join(','),'POST,GET');assert.equal(mutationState(f.form),'clean');f.dispose();
});
test('422 and expired-token errors keep the same draft DOM and do not announce success',async()=>{
 for(const status of [422,419]){const f=fixture(async()=>result(status,'invalid')),input=f.form.elements.name;input.value='Retained';await submitForm(f.form,f.form.querySelector('button'));
  assert.strictEqual(f.form.elements.name,input);assert.equal(input.value,'Retained');assert.equal(f.applied,0);assert.equal(f.toasts,0);assert.equal(mutationState(f.form),'dirty');assert(f.form.querySelector('[data-form-error]'));f.dispose();}
});
test('auth expiry retains draft and provides a clean same-origin sign-in link',async()=>{
 const f=fixture(async()=>({status:401,text:'',header:name=>name==='HX-Redirect'?'/login':null}));
 await submitForm(f.form,f.form.querySelector('button'));assert.equal(f.form.elements.name.value,'Draft');assert.equal(f.applied,0);assert.equal(f.form.querySelector('[data-form-error] a').pathname,'/login');assert.equal(f.toasts,0);f.dispose();
});
test('dynamic confirmation forms are protected without waiting for an observer tick',async()=>{
 let sent=0;const f=fixture(async()=>{sent++;return result();}),form=f.dom.window.document.createElement('form');form.method='post';form.action='/students/add';form.innerHTML='<input name="confirm_password" value="synthetic-confirmation"><button>Confirm</button>';f.dom.window.document.querySelector('main').append(form);
 await submitForm(form,form.querySelector('button'));assert.equal(sent,1);f.dispose();
});
test('500, network uncertainty and unmarked challenge never retry or mark a save successful',async()=>{
 for(const request of [async()=>result(500,'unknown'),async()=>{throw new Error('Connection lost');},async()=>({status:200,text:'Browser challenge',header:()=>null})]){
  let calls=0;const f=fixture(async(...args)=>{calls++;return request(...args);});await submitForm(f.form,f.form.querySelector('button'));await submitForm(f.form,f.form.querySelector('button'));
  assert.equal(calls,1);assert.equal(f.applied,0);assert.equal(f.toasts,0);assert.equal(mutationState(f.form),'uncertain');assert.equal(f.form.elements.name.value,'Draft');f.dispose();
 }
});
