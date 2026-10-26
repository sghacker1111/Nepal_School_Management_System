import {editorInstances} from '../app/editor-instances.js';
// Async libraries may finish after navigation. They own actual nodes, not IDs
// which could now belong to another school or a newly mounted editor.
export function pageLibraries(page,context,cleanups){
 const win=page.ownerDocument.defaultView,signal=context.signal;
 const current=()=>{if(signal.aborted)throw new win.DOMException('Page disposed','AbortError');};
 const owns=editor=>editor&&page.contains(editor.getElement?.());
 const tinymce=win.tinymce&&new Proxy(win.tinymce,{get(target,key){
  if(key==='get')return id=>{const editor=target.get(id);return owns(editor)?editor:undefined;};
  if(key==='triggerSave')return()=>{if(!signal.aborted)for(const editor of editorInstances(win))if(owns(editor))editor.save();};
  if(key==='init')return options=>{
   current();const nodes=options.target?[options.target]:[...page.querySelectorAll(options.selector)];
   const plugins=typeof options.plugins==='string'?options.plugins.split(/\s+/).filter(name=>name!=='media').join(' '):options.plugins;
   return Promise.all(nodes.map(node=>target.init({...options,plugins,invalid_elements:'script,style,svg,math,iframe,object,embed,video,audio,source,form,input,button,textarea,select,meta,link,base',selector:undefined,target:node,setup(editor){
    if(signal.aborted){editor.remove?.();return;}
    editor.on?.('BeforeSetContent PastePreProcess',event=>{
     if(!win.SchoolEditorSecurity?.sanitize)throw new Error('Editor security could not load. Reload the page.');
     event.content=win.SchoolEditorSecurity.sanitize(event.content);
    });
    options.setup?.(editor);
   }}))).then(groups=>{const editors=groups.flat();if(signal.aborted)editors.forEach(editor=>editor.remove?.());return editors;});
  }
  return target[key];
 }});
 const workers=new Map();
 const Tesseract=win.Tesseract&&new Proxy(win.Tesseract,{get(target,key){
  if(key!=='recognize')return target[key];
  return async(image,language,options={})=>{
   current();let pending=workers.get(language);
   if(!pending){pending=target.createWorker(language,1,{...options,logger:message=>{if(!signal.aborted)options.logger?.(message);}});workers.set(language,pending);let stopped=false;
    const stop=()=>{if(stopped)return;stopped=true;Promise.resolve(pending).then(worker=>worker.terminate()).catch(()=>{});};cleanups.push(stop);if(signal.aborted)stop();
   }
   const worker=await pending;current();const result=await worker.recognize(image);current();return result;
  };
 }});
 const pdfjsLib=win.pdfjsLib&&new Proxy(win.pdfjsLib,{get(target,key){
  if(key!=='getDocument')return target[key];
  return(source)=>{
   current();
   if(!source||typeof source!=='object'||!Object.hasOwn(source,'data'))throw new Error('Choose a local PDF file.');
   const base=(win.APP_URL||win.location.origin).replace(/\/$/,'')+'/assets/vendor/pdfjs-6.4.299/';
   const task=target.getDocument({...source,isEvalSupported:false,enableXfa:false,cMapUrl:base+'cmaps/',cMapPacked:true,standardFontDataUrl:base+'standard_fonts/',wasmUrl:base+'wasm/'});
   cleanups.push(()=>Promise.resolve(task.destroy()).catch(()=>{}));return new Proxy(task,{get(loading,name){
   if(name==='promise')return loading.promise.then(pdf=>{current();return new Proxy(pdf,{get(document,key){if(key==='getPage')return async(...args)=>{current();return document.getPage(...args);};const value=document[key];return typeof value==='function'?value.bind(document):value;}});});
   const value=loading[name];return typeof value==='function'?value.bind(loading):value;
  }});};
 }});
 return{tinymce,Tesseract,pdfjsLib};
}
