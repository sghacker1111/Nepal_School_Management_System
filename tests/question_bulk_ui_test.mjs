import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';
import {initializeQuestionBulk} from '../frontend/pages/question-bulk.js';
const markup=()=>`<section id="questionBankPanel"><form method="post" id="questionBulkArchive" data-question-bulk-form><input name="action" value="bulk_delete"><input type="checkbox" data-question-select-all><span data-question-selection-count></span><button type="button" data-question-bulk-open disabled>Delete selected</button><div data-question-bulk-confirm hidden><input type="password" name="current_password" required disabled><button type="button" data-question-bulk-cancel>Cancel</button></div></form><article><input type="checkbox" form="questionBulkArchive" name="question_ids[]" value="12" data-question-select></article><article><input type="checkbox" form="questionBulkArchive" name="question_ids[]" value="15" data-question-select></article><form><input type="checkbox" data-question-select value="999"></form></section>`;
test('bulk selection uses external form controls, warns, requires a password and cancels safely',()=>{
 const dom=new JSDOM('<main>'+markup()+'</main>'),win=dom.window,root=win.document.querySelector('main'),controller=new win.AbortController();let confirm=false,calls=0;win.confirm=()=>{calls++;return confirm;};initializeQuestionBulk(root,{signal:controller.signal});
 const get=sel=>root.querySelector(sel),change=node=>node.dispatchEvent(new win.Event('change',{bubbles:true}));
 const first=get('[value="12"]'),second=get('[value="15"]'),all=get('[data-question-select-all]'),password=get('[name="current_password"]');
 assert.equal(get('[data-question-bulk-open]').disabled,true);first.checked=true;change(first);assert.equal(all.indeterminate,true);assert.equal(get('[data-question-selection-count]').textContent,'1 selected');
 all.checked=true;change(all);assert.equal(second.checked,true);assert.equal(get('[value="999"]').checked,false);
 get('[data-question-bulk-open]').click();assert.equal(get('[data-question-bulk-confirm]').hidden,false);assert.equal(password.disabled,false);assert.equal(password.required,true);assert.equal(win.document.activeElement,password);
 password.value='test-password';const data=new win.FormData(get('form'));assert.deepEqual(data.getAll('question_ids[]'),['12','15']);assert.equal(data.get('current_password'),'test-password');
 let event=new win.Event('submit',{bubbles:true,cancelable:true});get('form').dispatchEvent(event);assert.equal(event.defaultPrevented,true);confirm=true;event=new win.Event('submit',{bubbles:true,cancelable:true});get('form').dispatchEvent(event);assert.equal(event.defaultPrevented,false);assert.equal(calls,2);
 get('[data-question-bulk-cancel]').click();assert.equal(password.value,'');assert.equal(password.disabled,true);assert.equal(new win.FormData(get('form')).has('current_password'),false);
 controller.abort();all.checked=false;change(all);assert.equal(first.checked,true);win.close();
});
test('delegation supports filtered/paginated replacements and discards old selections',()=>{
 const dom=new JSDOM('<main>'+markup()+'</main>'),win=dom.window,root=win.document.querySelector('main'),controller=new win.AbortController();initializeQuestionBulk(root,{signal:controller.signal});
 root.innerHTML=markup();const all=root.querySelector('[data-question-select-all]');all.checked=true;all.dispatchEvent(new win.Event('change',{bubbles:true}));assert.equal(root.querySelector('[data-question-selection-count]').textContent,'2 selected');root.querySelector('[data-question-bulk-open]').click();assert.equal(root.querySelector('[name="current_password"]').disabled,false);controller.abort();assert.equal(root.querySelector('[name="current_password"]').value,'');win.close();
});
