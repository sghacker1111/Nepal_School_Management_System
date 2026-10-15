'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
    constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.textContent='';}
    append(...children){this.children.push(...children);}
    addEventListener(event,callback){this.listeners[event]=callback;}
}
let modal;const window={};
vm.runInNewContext(fs.readFileSync('assets/js/curriculum-delete.js','utf8'),{window,document:{createElement:tag=>new Element(tag)},Swal:{fire(...args){modal=args;}}});
const report={blocked:true,message:'Used by 2 records',dependencies:{groups:[{section:'Exam Questions',count:2,items:[{id:9,school:'<img src=x onerror=alert(1)>',class:'Class 1',subject:'English',owner:'Teacher',url:'/modules/learning/exam_questions.php?record_id=9'},{id:10,school:'Other school',class:'Class 10',subject:'English',owner:'Creator',url:null}]}]}};
window.CurriculumDeleteUI.showBlocked(report,'https://example.test');
assert.equal(modal[0].title,'Deletion blocked: linked content');
function descendants(node){return [node,...(node.children||[]).flatMap(descendants)];}
let nodes=descendants(modal[0].html);
assert(nodes.some(n=>n.textContent.includes('<img src=x')),'Labels remain literal text');
assert(!nodes.some(n=>n.innerHTML),'Untrusted labels are never inserted as HTML');
assert.equal(nodes.filter(n=>n.tag==='a').length,1);assert.equal(nodes.find(n=>n.tag==='a').href,'https://example.test/modules/learning/exam_questions.php?record_id=9');
window.CurriculumDeleteUI.showBlocked({message:'Reload'},'');assert.deepEqual(modal,['Cannot delete','Reload','error']);
let restored=null;
window.CurriculumDeleteUI.showDeleted([{kind:'topics',id:1,name:'Child',chapter:'Chapter',parent_deleted:true},{kind:'chapters',id:2,name:'Chapter'}],(kind,id)=>restored=[kind,id]);
nodes=descendants(modal[0].html);const buttons=nodes.filter(n=>n.tag==='button');assert.equal(buttons[0].disabled,true);assert.equal(buttons[1].disabled,false);
buttons[1].listeners.click();assert.equal(buttons[1].disabled,true);assert.deepEqual(restored,['chapters',2]);
console.log('Curriculum delete UI passed: safe dependency details, authorized links, cross-school privacy, recoverable entries and parent-first restore.');
