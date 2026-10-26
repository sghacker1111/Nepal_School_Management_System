import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import '../frontend/pages/attendance.js';
import '../frontend/pages/results.js';
import {mountPage,disposePage} from '../frontend/app/page-lifecycle.js';
import {confirmNavigation,mutationState} from '../frontend/app/dirty-forms.js';
import {installForms,submitForm} from '../frontend/app/forms.js';
function fixture(path){
 const dom=new JSDOM('<main id="main-content"></main>',{url:'https://school.example'+path,runScripts:'outside-only'});
 for(const file of ['assets/dist/vendor.js','assets/dist/ui-components.js','assets/js/school-dates.js','assets/js/attendance-controls.js'])dom.window.eval(readFileSync(file,'utf8'));
 return{dom,win:dom.window,root:dom.window.document.querySelector('main')};
}
test('repeated attendance grids have one calendar control and bulk edits are dirty',async()=>{
 const f=fixture('/attendance');let changes=0;
 for(let visit=0;visit<3;visit++){
  f.root.innerHTML='<form method="get"><input name="from" type="date" value="2026-10-01"></form><form method="post" id="attendanceSheet"><select class="attendance-cell" name="attendance_grid[1][2026-10-01]"><option value="">—</option><option value="P">P</option></select><span class="attendance-print-value">—</span><select id="attendanceBulk"><option>P</option></select><button type="button" id="attendanceApply">Apply</button></form>';
  await mountPage(f.root,{pageId:'attendance-index',assets:[],values:{}});
  assert.equal(f.root.querySelectorAll('[name="calendar_fields[from]"]').length,1);
  f.root.querySelector('.attendance-cell').addEventListener('change',()=>changes++);
  f.root.querySelector('#attendanceApply').click();assert.equal(changes,visit+1);
  assert.equal(mutationState(f.root.querySelector('#attendanceSheet')),'dirty');
  f.win.confirm=()=>false;assert.equal(await confirmNavigation(),false);
  assert.equal(f.root.querySelector('.attendance-cell').value,'P');
  await disposePage();assert.equal(f.root.querySelectorAll('[name="calendar_fields[from]"]').length,0);
 }
 f.dom.window.close();
});
test('result lists mount once, empty classes have no table, and ledger controls stay scoped',async()=>{
 const f=fixture('/results');
 for(let visit=0;visit<3;visit++){
  f.root.innerHTML='<table id="resultsTable"><thead><tr><th>Roll</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>';
  await mountPage(f.root,{pageId:'results-index',assets:[],values:{}});assert.equal(f.win.jQuery.fn.dataTable.tables().length,1);await disposePage();assert.equal(f.win.jQuery.fn.dataTable.tables().length,0);
  f.root.innerHTML='<p role="status">No students found</p>';await mountPage(f.root,{pageId:'results-index',assets:[],values:{}});assert.equal(f.win.jQuery.fn.dataTable.tables().length,0);await disposePage();
 }
 f.root.innerHTML='<select id="sortSelector" data-erp-change="results-ledger-action-6"><option value="name">Name</option></select><select id="filterSelector" data-erp-change="results-ledger-action-7"><option value="pass">Pass</option></select><table><tbody id="ledgerBody"><tr data-name="Zulu" data-status="fail"></tr><tr data-name="Alpha" data-status="pass"></tr></tbody></table>';
 await mountPage(f.root,{pageId:'results-ledger',assets:[],values:{}});
 f.root.querySelector('#sortSelector').dispatchEvent(new f.win.Event('change',{bubbles:true}));assert.equal(f.root.querySelector('tbody tr').dataset.name,'Alpha');
 f.root.querySelector('#filterSelector').dispatchEvent(new f.win.Event('change',{bubbles:true}));assert.equal(f.root.querySelector('[data-status="fail"]').style.display,'none');
 const old=f.root.querySelector('#sortSelector');await disposePage();f.root.innerHTML='<p>Other class</p>';old.dispatchEvent(new f.win.Event('change',{bubbles:true}));assert.equal(f.root.textContent,'Other class');f.dom.window.close();
});
test('invalid marks retain the exact draft and canceled navigation never submits it',async()=>{
 const f=fixture('/results/marks-entry');let requests=0;
 f.root.innerHTML='<form method="post"><input name="marks[1][1][theory]" value="20"><button name="save_marks" value="1">Save</button></form>';
 await mountPage(f.root,{pageId:'results-marks-entry',assets:[],values:{}});
 const form=f.root.querySelector('form'),input=form.querySelector('input');input.value='invalid draft';input.dispatchEvent(new f.dom.window.Event('input',{bubbles:true}));
 const config=JSON.stringify({routeId:'results-marks-entry',pageId:'results-marks-entry',mutation:{outcome:'invalid'}}).replaceAll('"','&quot;');
 const stop=installForms({}, {window:f.win,isEnhancedURL:()=>true,request:async()=>{requests++;return{status:422,text:'<div hidden data-erp-config="'+config+'"></div><div class="alert alert-danger">Marks exceed the maximum.</div>',header:name=>name==='X-ERP-Fragment'?'app-page':name==='Content-Type'?'text/html':null};},applyPageResponse:()=>assert.fail('Invalid draft was replaced'),cancelNavigation(){}});
 f.win.confirm=()=>false;assert.equal(await confirmNavigation(),false);assert.equal(requests,0);
 await submitForm(form,form.querySelector('button'));assert.equal(requests,1);assert.strictEqual(form.querySelector('input'),input);assert.equal(input.value,'invalid draft');assert.equal(mutationState(form),'dirty');stop();await disposePage();f.dom.window.close();
});
test('a newly loaded bulk-print selection prints even after the original document load',async()=>{
 const f=fixture('/results/print-bulk');let prints=0;f.win.print=()=>prints++;
 if(f.win.document.readyState!=='complete')await new Promise(resolve=>f.win.addEventListener('load',resolve,{once:true}));
 await mountPage(f.root,{pageId:'results-print-bulk',assets:[],modules:['shared-report-issue-controls'],values:{'shared-report-issue-controls-value-0':'true'}});
 assert.equal(prints,1);await disposePage();f.dom.window.close();
});
