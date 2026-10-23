import {navigate,isEnhancedURL} from '../app/navigation.js';
import {markSaved,setMutationPending,setMutationUncertain,formSnapshot} from '../app/dirty-forms.js';
import {pageLibraries} from './page-libraries.js';
let serial=0;
/** Keeps selectors/callbacks attached to this page, never a later school's DOM. */
export function createPageScope(root,context){
 const doc=root.ownerDocument,win=doc.defaultView,signal=context.signal,ready=[],cleanups=[],actions={},namespace='.erpPage'+(++serial),locals=new Map();
 const page=doc.createElement('div');page.dataset.erpPageRoot='';
 for(const node of [...root.childNodes])if(!(node.nodeType===1&&node.hasAttribute('data-erp-config')))page.append(node);
 const libraries=pageLibraries(page,context,cleanups);
 root.append(page);let lastForm=null,writeCount=0,uncertain=false,mutationForm;const writes=new Map();
 const writeKey=(url,body)=>String(url)+'|'+(body instanceof win.FormData?JSON.stringify([...body].map(([key,value])=>[key,typeof value==='string'?value:[value.name,value.size,value.lastModified]])):typeof body==='string'?body:JSON.stringify(body));
 const guard=fn=>function(...args){if(signal.aborted)return;lastForm=this?.closest?.('form')||args[0]?.target?.closest?.('form')||lastForm;return fn.apply(this,args);};
 const listen=(target,type,handler,options={})=>{const fn=guard(handler);target.addEventListener(type,fn,typeof options==='boolean'?{capture:options,signal}:{...options,signal});cleanups.push(()=>target.removeEventListener(type,fn,typeof options==='boolean'?options:options.capture));};
 const scopedDoc=new Proxy(doc,{get(target,key){
  if(key==='getElementById')return id=>page.querySelector('[id="'+String(id).replace(/["\\]/g,'\\$&')+'"]');
  if(key==='querySelector')return selector=>selector==='meta[name="csrf-token"]'?doc.querySelector(selector):page.querySelector(selector);
  if(key==='querySelectorAll')return selector=>page.querySelectorAll(selector);
  if(key==='getElementsByName')return name=>[...page.querySelectorAll('[name]')].filter(node=>node.name===name);
  if(key==='body')return page;
  if(key==='readyState')return 'complete';
  if(key==='addEventListener')return(type,fn,options)=>type==='DOMContentLoaded'?ready.push(fn):listen(page,type,fn,options);
  const value=Reflect.get(target,key,target);return typeof value==='function'?value.bind(target):value;
 }});
 const location=new Proxy(win.location,{get(target,key){if(key==='reload')return()=>{if(!signal.aborted)void navigate(win.location.href,{history:'replace'});};const value=Reflect.get(target,key,target);return typeof value==='function'?value.bind(target):value;},set(target,key,value){if(key==='href'){if(signal.aborted)return true;isEnhancedURL(value)?void navigate(value):win.location.assign(value);return true;}return Reflect.set(target,key,value,target);}});
 const scopedWin=new Proxy(win,{get(target,key){
  if(key==='document')return scopedDoc;if(key==='location')return location;
  if(locals.has(key))return locals.get(key);
  if(Object.hasOwn(libraries,key))return libraries[key];
  if(key==='erpRegisterPageCleanup')return fn=>cleanups.push(fn);
  if(key==='SchoolQuestionEditor')return{init:selector=>win.SchoolQuestionEditor?.init(selector,root,context)};
  if(['AudioContext','webkitAudioContext','ResizeObserver','FileReader','Image','AbortController','MutationObserver'].includes(key))return target[key];
  if(key==='requestAnimationFrame'&&win.requestAnimationFrame)return fn=>{const id=win.requestAnimationFrame(guard(fn));cleanups.push(()=>win.cancelAnimationFrame(id));return id;};
  if(key==='addEventListener')return(type,fn,options)=>{if(type==='load'&&doc.readyState==='complete')ready.push(fn);else listen(win,type,fn,options);};
  const value=Reflect.get(target,key,target);return typeof value==='function'?guard(value.bind(target)):value;
 },set(target,key,value){if(/Data$/.test(String(key))){locals.set(key,value);return true;}return Reflect.set(target,key,value,target);}});
 const startWrite=()=>{
  if(uncertain)throw new Error('A previous save is unconfirmed. Check saved records before submitting again.');
  if(!mutationForm){mutationForm=doc.createElement('form');mutationForm.method='post';mutationForm.dataset.erpNative='';mutationForm.dataset.erpMutationMonitor='';page.append(mutationForm);}
  if(writeCount++===0)setMutationPending(mutationForm,true);
 };
 const endWrite=(saved,unknown=false,form=lastForm,submitted)=>{
  writeCount=Math.max(0,writeCount-1);
  if(unknown){uncertain=true;setMutationUncertain(mutationForm,win.location.href);}
  else if(!writeCount&&!uncertain){setMutationPending(mutationForm,false);markSaved(mutationForm);}
  if(saved&&form)markSaved(form,submitted);
 };
 const fetchPage=(input,init={})=>{
  const write=!['GET','HEAD'].includes((init.method||'GET').toUpperCase()),form=lastForm,submitted=form?formSnapshot(form):undefined;
  const key=writeKey(input,init.body);if(write&&writes.has(key))return writes.get(key);
  const run=async()=>{
  if(write)startWrite();
  try{
   const response=await win.fetch(input,write?init:{...init,signal});
   if(write){const json=await response.clone().json().catch(()=>null);endWrite(response.ok&&json?.success===true,response.status>=500||response.ok&&typeof json?.success!=='boolean',form,submitted);}
   return response;
  }catch(error){if(write)endWrite(false,true,form);throw error;}
  };
  const request=run();if(write){const result=request.finally(()=>writes.delete(key));writes.set(key,result);return result;}return request;
 };
 const jq=win.jQuery;
 const wrapJQ=value=>new Proxy(value,{get(target,key){
  if(key==='DataTable')return(...args)=>{for(const table of target.toArray())table.querySelectorAll('tbody tr').forEach(row=>{if(row.children.length===1&&row.firstElementChild.hasAttribute('colspan'))row.remove();});if(jq.fn.dataTable.isDataTable(target[0]))return target.DataTable();return target.DataTable(...args);};
  if(key==='ready')return fn=>{ready.push(fn);return wrapJQ(target);};
  if(['on','one'].includes(key))return(events,...args)=>{
   const names=String(events).split(/\s+/).map(name=>name+namespace).join(' ');const wrapped=args.map(arg=>typeof arg==='function'?guard(arg):arg);target[key](names,...wrapped);cleanups.push(()=>target.off(namespace));return wrapJQ(target);
  };
  if(['click','change','keyup','input','submit'].includes(key))return(...args)=>{if(typeof args[0]==='function'){wrapJQ(target).on(key,args[0]);return wrapJQ(target);}return target[key](...args);};
  const value=target[key];if(typeof value!=='function')return value;
  return(...args)=>{const result=value.apply(target,args);return result===target?wrapJQ(target):result;};
 }});
 const $=(selector,ctx)=>{
  if(typeof selector==='function'){ready.push(selector);return;}
  const selected=selector===scopedDoc? page:selector===scopedWin?win:selector;
  return wrapJQ(jq(selected,ctx||((typeof selected==='string'&&!selected.trim().startsWith('<'))?page:undefined)));
 };
 if(jq)for(const key of Object.keys(jq))if(!(key in $))$[key]=jq[key];
 $.ajax=options=>{
  const write=!['GET','HEAD'].includes((options.method||options.type||'GET').toUpperCase()),form=lastForm,submitted=form?formSnapshot(form):undefined;
  const key=writeKey(options.url,options.data);if(write&&writes.has(key))return writes.get(key);
  if(write)startWrite();
  const request=jq.ajax({...options,
   success:guard(function(data,...args){if(write)endWrite(data?.success===true,typeof data?.success!=='boolean',form,submitted);return options.success?.call(this,data,...args);}),
   error:function(xhr,...args){if(write)endWrite(false,xhr.status===0||xhr.status>=500,form);if(!signal.aborted)options.error?.call(this,xhr,...args);},
   complete:function(...args){if(write)writes.delete(key);if(!signal.aborted)return options.complete?.apply(this,args);},
  });
  if(write)writes.set(key,request);else signal.addEventListener('abort',()=>request.abort(),{once:true});return request;
 };
 $.getJSON=(url,data,success)=>$.ajax({url,data,dataType:'json',success});
 const scope={document:scopedDoc,window:scopedWin,$,fetch:fetchPage,actions,
  setTimeout(fn,delay,...args){const id=win.setTimeout(guard(fn),delay,...args);cleanups.push(()=>win.clearTimeout(id));return id;},clearTimeout:win.clearTimeout.bind(win),
  ErpUI:win.ErpUI,Swal:new Proxy(win.Swal||{},{get(target,key){const value=target[key];return typeof value==='function'?guard(value.bind(target)):value;}}),
  Option:win.Option,navigator:win.navigator,Blob:win.Blob,URL:win.URL,Event:win.Event,FormData:win.FormData,
  ...libraries,MathJax:win.MathJax,FileReader:win.FileReader,Image:win.Image,NodeFilter:win.NodeFilter,localStorage:win.localStorage,URLSearchParams:win.URLSearchParams,fixDevanagariInMathJax:win.fixDevanagariInMathJax,renderPaperChapters:(...args)=>actions.renderPaperChapters?.(...args),
  confirm:guard(win.confirm.bind(win)),alert:guard(win.alert.bind(win)),verifyPasswordAndProceed:guard(win.verifyPasswordAndProceed||(()=>{})),confirmDelete:guard(win.confirmDelete||(()=>{})),schoolConvertDate:win.schoolConvertDate,CurriculumDeleteUI:win.CurriculumDeleteUI,
  expose(values){Object.assign(actions,values);},
  bindActions(values){Object.assign(actions,values);},
  run(initialize){
   // Capture direct native bindings made by extracted code, including ready
   // callbacks. Restore immediately; no global prototype patch remains active.
   const proto=win.EventTarget.prototype,add=proto.addEventListener;
   proto.addEventListener=function(type,fn,options){const owned=this instanceof win.Node&&page.contains(this);const actual=owned&&typeof fn==='function'?guard(fn):fn;add.call(this,type,actual,options);if(owned)cleanups.push(()=>this.removeEventListener(type,actual,options));};
   try{initialize(scope);while(ready.length)guard(ready.shift()).call(page);}finally{proto.addEventListener=add;}
  },
  dispose(){cleanups.splice(0).reverse().forEach(fn=>fn());page.querySelectorAll('*').forEach(node=>{for(const event of ['onclick','onchange','oninput','onsubmit','onkeyup'])node[event]=null;});},
 };
 for(const type of ['click','change','input','submit','keyup','focus'])listen(page,type,function(event){
  const element=event.target.closest?.('[data-erp-'+type+']');if(!element||!page.contains(element))return;
  const fn=actions[element.getAttribute('data-erp-'+type)];if(typeof fn!=='function')return;
  let args;try{args=JSON.parse(element.getAttribute('data-erp-'+type+'-args')||'[]');}catch{return;}
  const result=fn.call(element,event,args);if(result===false){event.preventDefault();event.stopPropagation();}
 },{capture:type==='focus'});
 signal.addEventListener('abort',()=>scope.dispose(),{once:true});return scope;
}
