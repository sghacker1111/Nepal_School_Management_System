import {test} from 'node:test';import assert from 'node:assert/strict';import {readFileSync,readdirSync} from 'node:fs';import {JSDOM} from 'jsdom';
import {installNavigation,navigate} from '../frontend/app/navigation.js';
import {readPageMetadata} from '../frontend/app/page-lifecycle.js';
const config=(contextVersion='one')=>({routeId:'students-index',pageId:'students-index',canonicalUrl:'https://school.example/students',contextVersion,assets:[],routes:[{url:'/students',enhanced:true}]});
const html=context=>'<div hidden data-erp-config="'+JSON.stringify(config(context)).replaceAll('"','&quot;')+'"></div><p>Private school record</p>';
test('login/challenge/wrong media responses never replace private page content',async()=>{
 for(const response of [{status:200,text:'<h1>Sign in</h1>',header:()=>null},{status:200,text:'<h1>Browser challenge</h1>',header:name=>name==='X-ERP-Fragment'?'app-page':name==='Content-Type'?'application/json':null}]){
  const dom=new JSDOM('<nav id="sidebar"></nav><main id="main-content">'+html('one')+'</main>',{url:'https://school.example/students'});dom.window.scrollTo=()=>{};
  const off=installNavigation({config:{},process(){}},{window:dom.window,request:async()=>response,mountPage:async()=>{},disposePage:async()=>{},confirmNavigation:async()=>true});await off.ready;
  assert.equal(await navigate('/students'),false);assert.match(dom.window.document.querySelector('main').textContent,/Private school record/);assert.equal(dom.window.localStorage.length,0);assert.equal(dom.window.sessionStorage.length,0);off();dom.window.close();
 }
});
test('a changed session/school clears the old content and menu before native rebuilding',async()=>{
 const dom=new JSDOM('<nav id="sidebar"><section class="nav-group">Private menu</section></nav><main id="main-content">'+html('one')+'</main>',{url:'https://school.example/students'});let reloads=0;
 const off=installNavigation({config:{},process(){}},{window:dom.window,request:async()=>({status:200,text:html('other'),header:name=>name==='X-ERP-Fragment'?'app-page':name==='Content-Type'?'text/html':null}),mountPage:async()=>{},disposePage:async()=>{},confirmNavigation:async()=>true,fullNavigate(){reloads++;}});await off.ready;
 await navigate('/students');assert.equal(reloads,1);assert.equal(dom.window.document.querySelector('main').textContent,'');assert.equal(dom.window.document.querySelectorAll('#sidebar .nav-group').length,0);assert.equal(dom.window.localStorage.length,0);off();dom.window.close();
});
test('rich-content metadata lookalikes cannot choose executable modules or page identity',()=>{
 const dom=new JSDOM('<main><article><div hidden data-erp-config="{&quot;routeId&quot;:&quot;admin&quot;,&quot;pageId&quot;:&quot;admin&quot;}"></div></article></main>');assert.throws(()=>readPageMetadata(dom.window.document.querySelector('main')),/Missing trusted/);dom.window.close();
 for(const file of readdirSync('frontend/app'))if(file.endsWith('.js'))assert(!/\beval\s*\(|new\s+Function\s*\(/.test(readFileSync('frontend/app/'+file,'utf8')),file+' must not evaluate response scripts');
});
test('a narrow question-list response cannot cross the current school context',async()=>{
 const dom=new JSDOM('<nav id="sidebar"><section class="nav-group">Private menu</section></nav><main id="main-content">'+html('one')+'<section id="questionBankPanel">Old questions</section></main>',{url:'https://school.example/exam-questions',runScripts:'outside-only'}),win=dom.window;let disposed=0;win.erpPages={disposePage:async()=>disposed++};win.eval(readFileSync('assets/js/dynamic-workspace.js','utf8'));win.erpDynamic.install({config:{}});
 const detail={target:win.document.querySelector('#questionBankPanel'),shouldSwap:true,requestConfig:{path:'/exam-questions'},xhr:{status:200,getResponseHeader:name=>({'X-ERP-Fragment':'question-list','Content-Type':'text/html','X-ERP-Context':'other'}[name])}};
 win.document.dispatchEvent(new win.CustomEvent('htmx:beforeSwap',{detail}));assert.equal(detail.shouldSwap,false);assert.equal(win.document.querySelector('main').textContent,'');assert.equal(win.document.querySelectorAll('#sidebar .nav-group').length,0);await new Promise(resolve=>setImmediate(resolve));assert.equal(disposed,1);dom.window.close();
});
test('CSRF fetch wrapping preserves URL objects used by catalog/import/practice clients',async()=>{
 const dom=new JSDOM('',{url:'https://school.example/exam-questions',runScripts:'outside-only'}),win=dom.window;let received;
 win.Headers=Headers;win.Request=Request;win.CSRF_TOKEN='fresh';win.fetch=async(input,init)=>{received={input,init};return new Response('{}');};win.eval(readFileSync('assets/js/request-security.js','utf8'));
 win.CSRF_TOKEN='fresh';
 const url=new win.URL('https://outside.example/catalog');await win.fetch(url);assert.strictEqual(received.input,url);assert.equal(received.init.headers,undefined,'Never send school CSRF tokens to a foreign URL object');
 const own=new win.URL('/exam-questions?action=catalog&class_id=12',win.location.href);await win.fetch(own);assert.equal(received.init.headers.get('X-CSRF-Token'),'fresh');
 dom.window.close();
});
