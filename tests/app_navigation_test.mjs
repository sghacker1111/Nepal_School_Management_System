import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';
import {installNavigation,navigate,appRequest} from '../frontend/app/navigation.js';
const routes=['students','exams','classes'].map(name=>({url:'/'+name,enhanced:true}));
const meta=(name,extra={})=>({routeId:name+'-index',pageId:name+'-index',title:name+' · School',canonicalUrl:'https://school.example/'+name,contextVersion:'one',csrfToken:'fresh-token',activePage:name,assets:[],navigation:{School:[{key:name,label:name,icon:'school',url:'/'+name}]},routes,...extra});
const html=(name,extra={})=>`<div hidden data-erp-config="${JSON.stringify(meta(name,extra)).replaceAll('&','&amp;').replaceAll('"','&quot;')}"></div><h1>${name}</h1><p>Fresh data</p>`;
const response=(name,extra={})=>({status:200,text:html(name,extra),header:name=>name==='X-ERP-Fragment'?'app-page':name==='Content-Type'?'text/html; charset=UTF-8':null});
async function fixture(request=async url=>response(new URL(url).pathname.slice(1)),extra={}){
 const dom=new JSDOM(`<header id="shell">School</header><nav id="sidebar"><section class="nav-group">Old menu</section></nav><div id="erpProgress"></div><p id="erpUpdateStatus"></p><main id="main-content">${html('students')}</main>`,{url:'https://school.example/students',runScripts:'outside-only'});
 dom.window.scrollTo=()=>{};let mounts=0,disposes=0,reloads=0;
 const deps={window:dom.window,routes,request,confirmNavigation:async()=>true,mountPage:async()=>mounts++,disposePage:async()=>disposes++,fullNavigate:()=>reloads++,...extra};
 const htmx={config:{},process(){},trigger(){}};const dispose=installNavigation(htmx,deps);await dispose.ready;
 return {dom,htmx,dispose,deps,get mounts(){return mounts;},get disposes(){return disposes;},get reloads(){return reloads;}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function waitFor(check){for(let n=0;n<100;n++){if(check())return;await new Promise(resolve=>setTimeout(resolve,5));}assert(check(),'Expected browser history transition');}
test('internal navigation keeps shell/main, updates metadata and never runs fragment scripts',async()=>{
 const f=await fixture(async()=>({...response('exams'),text:html('exams')+'<script>window.attack=1</script><div onclick="window.attack=2">Text</div>'}));
 const doc=f.dom.window.document,header=doc.querySelector('header'),main=doc.querySelector('main');
 assert.equal(await navigate('/exams'),true);assert.strictEqual(doc.querySelector('header'),header);assert.strictEqual(doc.querySelector('main'),main);
 assert.equal(f.dom.window.location.pathname,'/exams');assert.equal(main.dataset.routeId,'exams-index');assert.equal(doc.title,'exams · School');assert.equal(doc.activeElement,main.querySelector('h1'));
 assert.equal(f.dom.window.CSRF_TOKEN,'fresh-token');assert.equal(f.dom.window.attack,undefined);assert(!main.querySelector('script,[onclick]'));assert.equal(f.reloads,0);
 assert.equal(f.htmx.config.historyCacheSize,0);assert.equal(f.htmx.config.refreshOnHistoryMiss,false);assert.equal(f.dom.window.localStorage.length,0);f.dispose();f.dom.window.close();
});
test('modified, hash, external, download, new-tab and rich-content links stay native',async()=>{
 const f=await fixture(),doc=f.dom.window.document;
 for(const attrs of ['href="https://other.test/"','href="#part"','href="/exams" target="_blank"','href="/exams" download','href="/missing"','href="/exams?print=1"']){
  const a=doc.createElement('a');a.outerHTML; a.setAttribute('href','/');const holder=doc.createElement('div');holder.innerHTML='<a '+attrs+'>Open</a>';doc.body.append(holder);
  const event=new f.dom.window.MouseEvent('click',{bubbles:true,cancelable:true});holder.firstChild.dispatchEvent(event);assert(!event.defaultPrevented);holder.remove();
 }
 const holder=doc.createElement('div');holder.className='exam-bank-rich';holder.innerHTML='<a href="/exams">untrusted</a>';doc.body.append(holder);
 let event=new f.dom.window.MouseEvent('click',{bubbles:true,cancelable:true});holder.firstChild.dispatchEvent(event);assert(!event.defaultPrevented);
 holder.className='';event=new f.dom.window.MouseEvent('click',{bubbles:true,cancelable:true,ctrlKey:true});holder.firstChild.dispatchEvent(event);assert(!event.defaultPrevented);f.dispose();f.dom.window.close();
});
test('latest navigation wins when an old GET arrives late',async()=>{
 const requests=new Map();const f=await fixture(url=>new Promise(resolve=>requests.set(new URL(url).pathname,resolve)));
 const a=navigate('/exams');await tick();const b=navigate('/classes');await tick();requests.get('/classes')(response('classes'));await b;requests.get('/exams')(response('exams'));await a;
 assert.equal(f.dom.window.location.pathname,'/classes');assert.equal(f.dom.window.document.querySelector('h1').textContent,'classes');f.dispose();f.dom.window.close();
});
test('back/forward refetches across three routes and a narrow panel history update',async()=>{
 let calls=0;const f=await fixture(async url=>{calls++;return response(new URL(url).pathname.slice(1));});
 await navigate('/exams');await navigate('/classes');f.dom.window.history.back();await waitFor(()=>f.dom.window.document.querySelector('h1').textContent==='exams');
 assert.equal(f.dom.window.location.pathname,'/exams');assert.equal(f.dom.window.document.querySelector('h1').textContent,'exams');
 f.dom.window.history.forward();await waitFor(()=>f.dom.window.document.querySelector('h1').textContent==='classes');
 f.dom.window.history.pushState({htmx:true},'','/classes?chapter=Sets');f.dom.window.document.dispatchEvent(new f.dom.window.CustomEvent('htmx:pushedIntoHistory',{detail:{path:'/classes?chapter=Sets'}}));
 assert(f.dom.window.history.state.erp);f.dom.window.history.back();await waitFor(()=>f.dom.window.location.search==='');assert(calls>=5);assert.equal(f.reloads,0);f.dispose();f.dom.window.close();
});
test('cancelled dirty Back restores URL and leaves draft intact',async()=>{
 const f=await fixture();await navigate('/exams');const doc=f.dom.window.document;doc.querySelector('main').insertAdjacentHTML('beforeend','<input value="draft">');f.deps.confirmNavigation=async()=>false;
 f.dom.window.history.back();await waitFor(()=>f.dom.window.location.pathname==='/students');await waitFor(()=>f.dom.window.location.pathname==='/exams');assert.equal(doc.querySelector('main input').value,'draft');f.dispose();f.dom.window.close();
});
test('HTMX transport captures a marked response without executing its default swap handler',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/students'});let context,method;
 const htmx={ajax:async(verb,path,options)=>{method=verb;context=options;options.handler(options.source,{xhr:{status:200,responseText:html('exams'),getResponseHeader:name=>name==='X-ERP-Fragment'?'app-page':'text/html'}});},trigger(){}};
 const result=await appRequest(htmx,dom.window.document,'/exams');assert.equal(method,'GET');assert.equal(context.target,'#main-content');assert.equal(context.swap,'none');assert.equal(context.headers['HX-Target'],'main-content');assert.equal(result.header('X-ERP-Fragment'),'app-page');assert(!dom.window.document.querySelector('[data-erp-transport]'));assert.equal(dom.window.document.querySelector('main').textContent,'');dom.window.close();
});
test('changed context discards private content and forces authorized shell rebuild',async()=>{
 const f=await fixture(async()=>response('exams',{contextVersion:'other-school'}));await navigate('/exams');assert.equal(f.reloads,1);assert(!f.dom.window.document.querySelector('main').textContent.includes('Fresh data'));f.dispose();f.dom.window.close();
});
test('unmarked challenge and timeout never replace existing content',async()=>{
 const f=await fixture(async()=>({status:200,text:'<h1>Browser check</h1>',header:()=>null}));const doc=f.dom.window.document;
 assert.equal(await navigate('/exams'),false);assert.equal(doc.querySelector('h1').textContent,'students');assert.equal(doc.querySelector('#erpRequestError a').origin,f.dom.window.location.origin);
 f.deps.request=async()=>{throw new Error('Timeout');};assert.equal(await navigate('/classes'),false);assert.equal(doc.querySelector('h1').textContent,'students');assert.equal(f.dom.window.location.pathname,'/students');f.dispose();f.dom.window.close();
});
