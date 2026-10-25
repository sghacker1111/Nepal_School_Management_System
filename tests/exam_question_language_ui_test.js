'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const controls=['both','both'].map(value=>({value,events:{},addEventListener(k,f){this.events[k]=f;}}));
const views=['both','both_en','en','ne'].map(value=>({dataset:{examLanguageView:value},hidden:value!=='both'}));
let printed=0,fontsReady=false,mathReady=false,answerMode=false;
const buttons=['questions','answers'].map(value=>({dataset:{examPrint:value},disabled:false,events:{},addEventListener(k,f){this.events[k]=f;}}));
const events={};
const context={document:{querySelectorAll(sel){return sel==='[data-exam-language-control]'?controls:sel==='[data-exam-language-view]'?views:buttons;},body:{classList:{toggle(k,v){answerMode=v;},remove(){answerMode=false;}}},fonts:{ready:Promise.resolve().then(()=>{fontsReady=true;})}},window:{MathJax:{startup:{promise:Promise.resolve()},async typesetPromise(){mathReady=true;}},print(){assert(fontsReady&&mathReady);printed++;},alert(){throw Error('Unexpected print error');},addEventListener(k,f){events[k]=f;}}};
vm.runInNewContext(fs.readFileSync('assets/js/exam-question-language.js','utf8'),context);
async function run(){
 for(const lang of ['en','ne','both_en','both']){controls[1].value=lang;controls[1].events.change();assert(controls.every(c=>c.value===lang));assert(views.every(v=>v.hidden===(v.dataset.examLanguageView!==lang)));}
 const snapshot=views.map(v=>v.hidden);controls[0].value='invalid';controls[0].events.change();assert.deepEqual(views.map(v=>v.hidden),snapshot);
 await buttons[1].events.click();assert(answerMode);assert.equal(printed,1);assert(!buttons[1].disabled);
 events.afterprint();assert(!answerMode);
 await buttons[0].events.click();assert(!answerMode);assert.equal(printed,2);
 let warned=false;context.window.alert=()=>{warned=true;};context.window.MathJax.typesetPromise=async()=>{throw Error('Synthetic renderer failure');};
 await buttons[1].events.click();assert(warned&&!answerMode&&!buttons[1].disabled,'Failed print preparation clears answer mode and re-enables printing');
 console.log('Exam language UI passed: synchronized selectors, same-paper language switching, hidden alternatives and math/font-ready printing.');
}
run().catch(e=>{console.error(e);process.exitCode=1;});
