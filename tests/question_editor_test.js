'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('frontend/pages/generated/learning-questions.js','utf8');
new vm.Script('('+source.replace('export default','')+')');
function definition(name){const start=source.indexOf('function '+name+'('),end=source.indexOf('\n}',start);assert(start>=0&&end>start,name);return source.slice(start,end+2);}
const nodes=new Map(),element=id=>{if(!nodes.has(id))nodes.set(id,{value:'',style:{},options:[{value:'MCQ',style:{}},{value:'Short',style:{}}],querySelectorAll:()=>[]});return nodes.get(id);};
const links=[{href:'http://localhost/questions.php?type=mcq&format=xlsx'},{href:'http://localhost/questions.php?type=other&format=csv'}];let mode='normal';
const context={canManageCatalog:false,document:{getElementById:element,querySelector:()=>({value:mode}),querySelectorAll:()=>links},window:{location:{href:'http://localhost/questions.php'},scrollTo(){}},URL,onModalClassChange(){},setOptionVal(id,value){element(id).value=value;},selectCorrectOption(){}};
vm.createContext(context);
for(const name of ['toggleMCQFields','editQuestion','showAddForm','updateImportContentMode'])vm.runInContext(definition(name),context);
for(const type of ['MCQ','Very Short','Short','Long','Reasoning','Difference Between']){
    context.editQuestion({id:1,type,marks:3,class_id:10,subject_id:20,question_text:'<p>\\(x^2\\)</p>',options:'{"A":"1","B":"2","C":"3","D":"4"}',answer:'<p>Explanation</p>'});
    assert.equal(element('modalMarks').value,3,'Editing preserves saved weight for '+type);
    assert.equal(element('answerDetails').style.display,'block','Model answer remains editable for '+type);
    assert.equal(element('modalAnswer').value,'<p>Explanation</p>');
}
context.showAddForm('MCQ');assert.equal(element('modalAction').value,'add');assert.equal(element('modalMarks').value,'1');
assert.equal(element('modalVisibility').value,'private','New school questions default Private');
context.showAddForm('other');assert.equal(element('modalType').value,'Short');assert.equal(element('modalMarks').value,'5');
context.updateImportContentMode();assert(links.every(link=>new URL(link.href).searchParams.get('content_mode')==='normal'));
mode='html';context.updateImportContentMode();assert(links.every(link=>new URL(link.href).searchParams.get('content_mode')==='html'));assert.match(element('importModeHelp').textContent,/LaTeX/);
assert.match(element('importModeHelp').textContent,/unsafe attributes are removed/);
console.log('Question editor checks passed: bundled controller parses; saved weights, MCQ explanations, new-question defaults and mode-matched templates.');
