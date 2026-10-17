import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';
import '../frontend/pages/accounting.js';import {mountPage,disposePage} from '../frontend/app/page-lifecycle.js';
import {installForms,submitForm} from '../frontend/app/forms.js';import {confirmNavigation,mutationState} from '../frontend/app/dirty-forms.js';
const billHtml='<form method="post" data-manual-bill data-tax-registered="0"><input name="idempotency_key" value="original-key"><div data-student-picker><input type="search"><input type="hidden" name="student_id"><div data-student-results></div></div><select data-catalog></select><button type="button" data-add-catalog>Catalog</button><button type="button" data-add-item>Add</button><table><tbody></tbody></table><input name="bill_discount" value="0"><div data-bill-preview></div><button type="submit" name="action" value="issue">Issue</button></form><script id="acCatalog" type="application/json">[]</script><script id="acBillRows" type="application/json">[{"particular":"Monthly Tuition Fee","quantity":"1","rate":"2500.00"},{"particular":"Examination Fee","quantity":"1","rate":"500.00"}]</script>';
test('three bill visits bind one add/remove/preview/search controller each',async()=>{
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example/accounting/create-bill'}),win=dom.window,root=win.document.querySelector('main');win.APP_URL=win.location.origin;let lookups=0;
 win.fetch=async()=>{lookups++;return new Response(JSON.stringify([{id:1,label:'Synthetic Student'}]),{headers:{'Content-Type':'application/json'}});};
 for(let visit=0;visit<3;visit++){
  root.innerHTML=billHtml;await mountPage(root,{pageId:'accounting-create-bill',assets:[],modules:['external-accounting'],values:{}});
  assert.equal(root.querySelectorAll('tbody tr').length,2);assert.match(root.querySelector('[data-bill-preview]').textContent,/Total: Rs\. 3,000\.00/);
  root.querySelector('[data-add-item]').click();assert.equal(root.querySelectorAll('tbody tr').length,3);root.querySelector('tbody tr:last-child button').click();assert.equal(root.querySelectorAll('tbody tr').length,2);
  const search=root.querySelector('input[type="search"]');search.value='Test';search.dispatchEvent(new win.Event('input',{bubbles:true}));
  await new Promise(resolve=>win.setTimeout(resolve,300));assert.equal(lookups,visit+1);assert.equal(root.querySelectorAll('[data-student-results] button').length,1);root.querySelector('[data-student-results] button').click();assert.equal(root.querySelector('[name="student_id"]').value,'1');await disposePage();
 }
 win.close();
});
test('an uncertain payment posts once, retains its key and blocks leaving/recollection',async()=>{
 const dom=new JSDOM('<main id="main-content"><form method="post"><input name="amount" value="100"><input name="idempotency_key" value="same-payment-key"><button name="action" value="payment">Pay</button></form></main>',{url:'https://school.example/accounting/payments'}),win=dom.window,root=win.document.querySelector('main');let count=0;
 await mountPage(root,{pageId:'accounting-payments',assets:[],values:{}});const form=root.querySelector('form');const stop=installForms({}, {window:win,isEnhancedURL:()=>true,request:async(url,init)=>{count++;assert.equal(init.body.get('idempotency_key'),'same-payment-key');throw new Error('Unconfirmed connection');},cancelNavigation(){}});
 await submitForm(form,form.querySelector('button'));await submitForm(form,form.querySelector('button'));assert.equal(count,1);assert.equal(mutationState(form),'uncertain');assert.equal(await confirmNavigation(),false);assert.equal(form.elements.amount.value,'100');stop();await disposePage();win.close();
});
