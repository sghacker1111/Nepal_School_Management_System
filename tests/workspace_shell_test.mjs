import {test} from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from 'jsdom';
import {workspaceShell,filterNavigation,renderFeatureSearch} from '../frontend/app/workspace-shell.js';

test('desktop menu hiding persists and mobile drawer remains independent',()=>{
 const dom=new JSDOM('<main></main>',{url:'https://school.example/'}),win=dom.window;
 const state=workspaceShell(win);state.init();assert(state.navigationVisible);state.toggleNavigation();assert(!state.navigationVisible);assert.equal(state.navigationLabel,'Show menus');assert.equal(win.localStorage.getItem('erp.sidebarCollapsed'),'true');
 state.closeAll();assert(state.sidebarCollapsed);const next=workspaceShell(win);next.init();assert(next.sidebarCollapsed);
 win.innerWidth=390;win.dispatchEvent(new win.Event('resize'));state.$nextTick=fn=>fn();state.$root=win.document;state.toggleNavigation();assert(state.navigationVisible);state.closeNavigation();assert(!state.navigationVisible);assert(state.sidebarCollapsed);
 state.destroy();next.destroy();dom.window.close();
});

test('feature search matches internal actions and words, then restores main menus',()=>{
 const dom=new JSDOM('<nav id="sidebar"><section class="nav-group"><ul><li class="nav-item">Question bank</li></ul></section><section class="nav-group" id="navigationFeatures" hidden><ul id="navigationFeatureList"></ul></section><p id="navigationSearchEmpty" hidden></p></nav>',{url:'https://school.example/'}),doc=dom.window.document;
 renderFeatureSearch(doc,[{label:'Import questions — Exam Questions',url:'/exam-questions?view=import',keywords:'excel CSV answers template'},{label:'Enter marks',url:'/results/marks-entry',keywords:'scores grades'},{label:'unsafe',url:'https://other.test/'}]);
 filterNavigation(doc,'EXCEL questions');assert.equal(doc.querySelector('#navigationFeatureList a').href,'https://school.example/exam-questions?view=import');assert(!doc.querySelector('#navigationFeatures').hidden);assert.equal(doc.querySelectorAll('#navigationFeatureList li').length,2);assert(doc.querySelector('#sidebar .nav-item').hidden);
 filterNavigation(doc,'zzzz');assert(!doc.querySelector('#navigationSearchEmpty').hidden);
 filterNavigation(doc,'');assert(doc.querySelector('#navigationFeatures').hidden);assert(!doc.querySelector('#sidebar .nav-item').hidden);assert(doc.querySelector('#navigationSearchEmpty').hidden);dom.window.close();
});
