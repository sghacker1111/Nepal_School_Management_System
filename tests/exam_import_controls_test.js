const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const listeners=[],forms=['mcq','other'].map(kind=>({
 dataset:{examImportForm:kind},events:{},attributes:{},mode:{value:'normal'},button:{disabled:false,textContent:kind==='mcq'?'Upload & Import MCQs':'Upload & Import Other Questions'},
 addEventListener(e,f){this.events[e]=f;},querySelector(s){return s==='button[type="submit"]'?this.button:this.mode;},
 setAttribute(k,v){this.attributes[k]=v;},removeAttribute(k){delete this.attributes[k];}
}));
const links=['mcq','other'].flatMap(kind=>['normal','html'].map(mode=>({dataset:{examImportKind:kind,examImportMode:mode},events:{},addEventListener(e,f){this.events[e]=f;}})));
const context={window:{addEventListener(e,f){assert.equal(e,'pageshow');listeners.push(f);}},document:{readyState:'complete',getElementById:()=>null,querySelectorAll:s=>s==='[data-exam-import-form]'?forms:links}};
vm.runInNewContext(fs.readFileSync('assets/js/exam-questions.js','utf8'),context);
links[1].events.click();assert.equal(forms[0].mode.value,'html');assert.equal(forms[1].mode.value,'normal','MCQ templates do not change Other Questions mode');
links[3].events.click();links[0].events.click();assert.equal(forms[0].mode.value,'normal');assert.equal(forms[1].mode.value,'html','Other Questions templates target only their own form');
let prevented=0;const event={preventDefault(){prevented++;}};
forms[0].events.submit(event);assert.equal(prevented,0);assert.equal(forms[0].button.disabled,true);assert.equal(forms[0].attributes['aria-busy'],'true');assert.equal(forms[1].button.disabled,false);
forms[0].events.submit(event);assert.equal(prevented,1,'A double click must not create another MCQ batch');
forms[1].events.submit(event);assert.equal(prevented,1,'Separate forms have independent submit guards');
listeners.forEach(f=>f());
forms.forEach((form,i)=>{assert.equal(form.button.disabled,false);assert.equal(form.button.textContent,i===0?'Upload & Import MCQs':'Upload & Import Other Questions');assert.equal(form.attributes['aria-busy'],undefined);});
forms[0].events.submit(event);assert.equal(prevented,1,'Back navigation restores both forms');
console.log('Exam import controls passed: separate forms, category-scoped samples/modes, independent duplicate-submit guards and back-navigation recovery.');
