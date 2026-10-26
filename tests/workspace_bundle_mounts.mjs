// Exercise the shipped shell and cold dynamic assets, not mocked page adapters.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM,requestInterceptor,VirtualConsole} from 'jsdom';
const fixture=JSON.parse(readFileSync(process.argv[2],'utf8')),errors=[],logs=new VirtualConsole();
logs.on('jsdomError',error=>errors.push(error.message));logs.on('warn',(...args)=>errors.push(args.map(String).join(' ')));
const interceptor=requestInterceptor(request=>{
 const url=new URL(request.url);if(url.origin==='https://fonts.googleapis.com')return new Response('',{headers:{'Content-Type':'text/css'}});assert.equal(url.origin,fixture.base);
 if(url.pathname.startsWith('/assets/'))return new Response(readFileSync('.'+url.pathname),{headers:{'Content-Type':url.pathname.endsWith('.css')?'text/css':'application/javascript'}});
 const page=fixture.pages[url.pathname];assert(page,'Unexpected request '+url.pathname);
 return new Response(page,{headers:{'Content-Type':'text/html; charset=UTF-8','X-ERP-Fragment':'app-page'}});
});
const dom=new JSDOM(fixture.full,{url:fixture.base+'/',runScripts:'dangerously',pretendToBeVisual:true,resources:{interceptors:[interceptor]},virtualConsole:logs,beforeParse(win){
 // jsdom requires optional XPath arguments that native browsers default.
 const evaluate=win.XPathExpression.prototype.evaluate;win.XPathExpression.prototype.evaluate=function(node,type=0,result=null){return evaluate.call(this,node,type,result);};
 win.scrollTo=()=>{};win.matchMedia=()=>({matches:false,addListener(){},removeListener(){}});win.CSS={escape:value=>value};win.fetch=async()=>new Response('{}');win.Headers=Headers;win.Request=Request;
}});
try {
 await new Promise((resolve,reject)=>{const timer=setTimeout(()=>reject(new Error('Shell load timed out')),15000);dom.window.addEventListener('load',()=>{clearTimeout(timer);resolve();},{once:true});});
 const win=dom.window,doc=win.document;await win.erpAppReady;
 const toggle=doc.getElementById('navigationToggle');toggle.click();await new Promise(resolve=>setImmediate(resolve));assert(doc.body.classList.contains('erp-sidebar-collapsed'));assert.equal(toggle.getAttribute('aria-expanded'),'false');
 toggle.click();await new Promise(resolve=>setImmediate(resolve));assert(!doc.body.classList.contains('erp-sidebar-collapsed'));
 // Actual HTMX GET transport + adapter + MathJax startup on first navigation.
 for(const path of ['/learning/paper-generator','/exam-questions','/learning/paper-generator']){
  assert.equal(await win.erpNavigation.navigate(path),true);assert(!doc.getElementById('erpRequestError'));assert.equal(win.location.pathname,path);assert(!win.erpFormState.hasUnsavedChanges());
 }
 const search=doc.getElementById('navigationSearch');search.value='import questions';search.dispatchEvent(new win.Event('input',{bubbles:true}));await new Promise(resolve=>setTimeout(resolve,180));
 assert(!doc.getElementById('navigationFeatures').hidden);assert(doc.querySelector('#navigationFeatureList li:not([hidden]) a').textContent.includes('Import questions'));
 assert.deepEqual(errors,[]);console.log('Shipped shell passed: desktop toggles, feature search and cold HTMX paper/question navigation.');
}finally{await dom.window.erpPages?.disposePage();dom.window.close();}
