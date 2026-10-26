import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {trackForms,hasUnsavedChanges,confirmNavigation,setMutationPending,markSaved,setMutationUncertain,mutationState} from '../frontend/app/dirty-forms.js';
function fixture(){
 const dom=new JSDOM('<main><form method="post"><input name="name" value="Original"><input type="checkbox" name="active"><select name="class"><option value="1">One</option><option value="10">Ten</option></select><textarea name="question">Text</textarea><input type="file" name="file"></form><form method="get"><input name="search"></form></main>',{url:'https://school.example/erp/students'});
 const root=dom.window.document.querySelector('main'),form=root.querySelector('form'),context={signal:new dom.window.AbortController().signal,config:{}};
 return {dom,root,form,dispose:trackForms(root,context)};
}
test('text/check/select/editor/grid changes track reversions without persisting drafts',async()=>{
 const {dom,root,form,dispose}=fixture(),input=form.elements.name;
 assert.equal(hasUnsavedChanges(),false);input.value='Unsaved';input.dispatchEvent(new dom.window.Event('input',{bubbles:true}));assert(hasUnsavedChanges());
 const url=dom.window.location.href;dom.window.confirm=()=>false;
 assert.equal(await confirmNavigation(),false);assert.equal(input.value,'Unsaved');assert.equal(dom.window.location.href,url);
 input.value='Original';assert.equal(hasUnsavedChanges(),false);
 for(const [name,value] of [['class','10'],['question','Editor sync']]){form.elements[name].value=value;assert(hasUnsavedChanges());markSaved(form);assert.equal(hasUnsavedChanges(),false);}
 form.elements.active.checked=true;assert(hasUnsavedChanges());markSaved(form);
 root.dispatchEvent(new dom.window.CustomEvent('erp:dirty',{bubbles:true}));assert(hasUnsavedChanges());
 assert.equal(dom.window.localStorage.length,0);assert.equal(dom.window.sessionStorage.length,0);
 dispose();assert.equal(hasUnsavedChanges(),false);dom.window.close();
});
test('file selection and dynamic forms are tracked',async()=>{
 const {dom,root,form,dispose}=fixture(),file=form.elements.file;
 Object.defineProperty(file,'files',{configurable:true,value:[new dom.window.File(['x'],'questions.xlsx',{lastModified:1})]});file.dispatchEvent(new dom.window.Event('change',{bubbles:true}));assert(hasUnsavedChanges());markSaved(form);assert.equal(hasUnsavedChanges(),false);
 Object.defineProperty(file,'files',{value:[]});assert(hasUnsavedChanges());markSaved(form);
 const added=dom.window.document.createElement('form');added.method='post';added.innerHTML='<input name="extra" value="new">';root.append(added);await new Promise(resolve=>dom.window.queueMicrotask(resolve));
 added.elements.extra.value='changed';added.elements.extra.dispatchEvent(new dom.window.Event('input',{bubbles:true}));assert(hasUnsavedChanges());dispose();dom.window.close();
});
test('pending and uncertain mutations cannot be duplicated or discarded',async()=>{
 const {dom,form,dispose}=fixture();dom.window.confirm=()=>true;form.elements.name.value='Retained';
 setMutationPending(form,true);assert.equal(mutationState(form),'pending');assert.throws(()=>setMutationPending(form,true),/pending|progress/i);
 assert.equal(await confirmNavigation(),false);setMutationPending(form,false);assert.equal(mutationState(form),'dirty');assert.equal(form.elements.name.value,'Retained');
 setMutationPending(form,true);setMutationUncertain(form,'/erp/students');assert.equal(mutationState(form),'uncertain');assert.equal(await confirmNavigation(),false);
 assert.throws(()=>setMutationPending(form,true),/uncertain/i);assert.equal(form.querySelector('[data-mutation-recovery] a').href,'https://school.example/erp/students');assert(!form.querySelector('[data-mutation-recovery] button'));
 const event=new dom.window.Event('beforeunload',{cancelable:true});dom.window.dispatchEvent(event);assert.equal(event.defaultPrevented,true);
 markSaved(form);assert.equal(hasUnsavedChanges(),false);assert.equal(mutationState(form),'clean');dispose();dom.window.close();
 const source=readFileSync('frontend/app/dirty-forms.js','utf8');assert(!/(?:localStorage|sessionStorage)\s*\./.test(source));
});

test('background defaults and hidden editor controls stay clean; actual edits and pending saves warn',async()=>{
 const {dom,root,form,dispose}=fixture(),win=dom.window;
 form.elements.question.value='<p>Text</p>';form.elements.class.value='10';
 const chrome=win.document.createElement('div');chrome.className='tox';chrome.innerHTML='<input value="Editor search">';form.append(chrome);
 assert(!hasUnsavedChanges());assert.equal(await confirmNavigation(),true);
 form.elements.name.dispatchEvent(new win.Event('focusin',{bubbles:true}));form.elements.name.value='Draft';form.elements.name.dispatchEvent(new win.Event('input',{bubbles:true}));assert(hasUnsavedChanges());
 win.confirm=()=>false;assert.equal(await confirmNavigation(),false);form.elements.name.value='Original';assert(!hasUnsavedChanges());
 form.elements.name.value='Draft again';assert(hasUnsavedChanges());form.remove();assert(!hasUnsavedChanges(),'Removed panel forms cannot leave stale drafts');dispose();dom.window.close();
});
