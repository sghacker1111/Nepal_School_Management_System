const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
class Element {
 constructor(tag='div'){this.tag=tag;this.children=[];this.listeners={};this.value='';this.hidden=false;}
 append(...children){for(const child of children){child.parent=this;this.children.push(child);}}
 add(child){this.append(child);}
 replaceChildren(...children){this.children=[];this.append(...children);}
 addEventListener(name,fn){this.listeners[name]=fn;}
 setAttribute(name,value){this[name]=String(value);}
 remove(){this.parent.children=this.parent.children.filter(child=>child!==this);}
}
class Option extends Element{constructor(text,value){super('option');this.textContent=String(text);this.value=String(value);}}
const ids=['testPaperRows','testPaperForm','testPaperAdd','testPaperTotals','testPaperError','testPaperConfig','testPaperDuration'];
const elements=Object.fromEntries(ids.map(id=>[id,new Element()]));
elements.testPaperDuration.value='30';
const data={chapters:[{id:10,name:'Sets <one>'},{id:11,name:'Geometry'}],topics:{10:[{id:20,name:'Union'},{id:21,name:'Intersection'}],11:[{id:22,name:'Angles'}]},types:['MCQ','Short'],initial:{rows:[{chapter_id:10,topic_id:21,type:'Short',count:1,total_marks:'2.5'}]}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/learning-test-paper.js'),'utf8'),{Option,window:{learningTestPaperData:data},document:{getElementById:id=>elements[id],createElement:tag=>new Element(tag)}});
const root=elements.testPaperRows,form=elements.testPaperForm;
assert.equal(root.children.length,1);
const first=root.children[0]._fields;
assert.equal(first.chapter.children[1].textContent,'Sets <one>','Catalog names remain literal text');
assert.equal(first.topic.value,'21','Failed generation retains the selected topic');
assert.equal(first.marks.value,'2.5','Decimal row marks are retained');
assert.match(elements.testPaperTotals.textContent,/1 questions · 2.50 marks/);
elements.testPaperAdd.listeners.click();
assert.equal(root.children.length,2);
const second=root.children[1]._fields;
assert.equal(second.count.value,0,'New row count defaults to zero');
assert.equal(second.topic.disabled,true,'Choose a chapter before its topics');
second.chapter.value='11';second.chapter.listeners.change();
assert.equal(second.topic.disabled,false);
assert.deepEqual(second.topic.children.map(option=>option.textContent),['All topics in chapter','Angles']);
second.topic.value='22';second.type.value='MCQ';second.count.value='2';second.marks.value='2';second.marks.listeners.input();
assert.match(elements.testPaperTotals.textContent,/3 questions · 4.50 marks/);
let event={preventDefault(){this.prevented=true;}};
form.listeners.submit(event);assert.equal(event.prevented,undefined);
const submitted=JSON.parse(elements.testPaperConfig.value);
assert.equal(submitted.duration_minutes,'30');
assert.deepEqual(submitted.rows.map(row=>row.topic_id),['21','22']);
assert.deepEqual(submitted.rows.map(row=>row.type),['Short','MCQ']);
second.chapter.value='10';second.chapter.listeners.change();
assert.equal(second.topic.value,'','Changing chapter clears the old topic');
second.count.value='1.5';event={preventDefault(){this.prevented=true;}};form.listeners.submit(event);
assert.equal(event.prevented,true,'Fractional question counts are rejected');
assert.equal(elements.testPaperError.hidden,false);
second.count.value='0';first.count.value='0';event={preventDefault(){this.prevented=true;}};form.listeners.submit(event);
assert.equal(event.prevented,true,'At least one active row is required');
root.children[1].children[5].children[0].listeners.click();
assert.equal(root.children.length,1,'Rows can be removed');
for(let i=0;i<55;i++)elements.testPaperAdd.listeners.click();
assert.equal(root.children.length,50,'Builder is bounded to 50 rows');
console.log('Learning test-paper UI passed: chapter/topic reset, defaults, row preservation, decimal totals, mixed types, serialization and bounded validation.');
