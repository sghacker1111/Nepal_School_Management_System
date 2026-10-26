import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
function fixture() {
    const dom=new JSDOM('<section id="questionBankPanel"><p id="examQuestionCount">10 questions</p></section><p id="erpUpdateStatus"></p>',{runScripts:'outside-only',url:'https://school.example.test/modules/learning/exam_questions.php'});
    dom.window.erpFormState={hasUnsavedChanges:()=>true};dom.window.CSRF_TOKEN='test-token';dom.window.eval(readFileSync('assets/js/dynamic-workspace.js','utf8'));
    const config={};dom.window.erpDynamic.install({config});
    const fire=(name,detail)=>{const event=new dom.window.CustomEvent(name,{detail,bubbles:true,cancelable:true});dom.window.document.dispatchEvent(event);return event;};
    return {dom,config,fire,panel:dom.window.document.getElementById('questionBankPanel')};
}
test('sensitive HTMX caching, eval and script execution are disabled',()=>{
    const {dom,config}=fixture();assert.equal(config.historyCacheSize,0);assert.equal(config.selfRequestsOnly,true);assert.equal(config.allowEval,false);assert.equal(config.allowScriptTags,false);assert.equal(config.refreshOnHistoryMiss,false);dom.window.close();
});
test('only same-origin GET requests get a CSRF header',()=>{
    const {dom,fire}=fixture();const detail={path:'/modules/learning/exam_questions.php',verb:'get',headers:{}};
    assert(!fire('htmx:configRequest',detail).defaultPrevented);assert.equal(detail.headers['X-CSRF-Token'],'test-token');
    for(const path of ['https://attacker.invalid/','javascript:alert(1)'])assert(fire('htmx:configRequest',{path,verb:'get',headers:{}}).defaultPrevented);
    assert(fire('htmx:configRequest',{path:'/',verb:'post',headers:{}}).defaultPrevented);dom.window.close();
});
test('main app requests require the scoped transport source',()=>{
    const {dom,fire}=fixture();const target=dom.window.document.createElement('main');target.id='main-content';
    assert(fire('htmx:beforeRequest',{target}).defaultPrevented);
    const source=dom.window.document.createElement('span');source.dataset.erpTransport='';
    assert(!fire('htmx:beforeRequest',{target,elt:source}).defaultPrevented);dom.window.close();
});
test('hosting challenge or expired-login HTML never replaces school content',()=>{
    const {dom,fire,panel}=fixture();
    const detail={target:panel,shouldSwap:true,xhr:{status:200,responseURL:'https://school.example.test/login.php',getResponseHeader:()=>null},requestConfig:{path:'/modules/learning/exam_questions.php?class_id=1'}};
    fire('htmx:beforeSwap',detail);assert.equal(detail.shouldSwap,false);assert.equal(panel.textContent,'10 questions');
    assert.equal(dom.window.document.querySelector('#erpRequestError a').origin,dom.window.location.origin);dom.window.close();
});
test('valid fragments announce results; errors never link outside this origin',()=>{
    const {dom,fire,panel}=fixture();const detail={target:panel,shouldSwap:true,xhr:{status:200,getResponseHeader:name=>name==='X-ERP-Fragment'?'question-list':'text/html; charset=UTF-8'}};
    fire('htmx:beforeSwap',detail);assert(detail.shouldSwap);fire('htmx:afterSwap',{target:panel});assert.equal(dom.window.document.getElementById('erpUpdateStatus').textContent,'10 questions');
    fire('htmx:sendError',{target:panel,requestConfig:{path:'https://attacker.invalid/'}});assert.equal(dom.window.document.querySelector('#erpRequestError a').origin,dom.window.location.origin);dom.window.close();
});
test('outerHTML swaps initialize accessible labels and print controls on the live panel',()=>{
    const {dom,fire,panel}=fixture();
    const live=dom.window.document.createElement('section');live.id='questionBankPanel';
    live.innerHTML='<label class="form-label">Subject</label><select></select><p id="examQuestionCount">New count</p>';
    panel.replaceWith(live);let initialized=null;dom.window.initExamQuestionLanguage=node=>{initialized=node;};
    fire('htmx:afterSwap',{target:panel});
    assert.equal(live.querySelector('label').htmlFor,live.querySelector('select').id);assert(live.querySelector('select').id);
    assert.equal(initialized,live);assert.equal(dom.window.document.getElementById('erpUpdateStatus').textContent,'New count');dom.window.close();
});
