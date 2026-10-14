const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const code = fs.readFileSync(path.join(__dirname, '../assets/js/exam-grid-editor.js'), 'utf8');

class Element {
    constructor(tag='div') { this.tag=tag; this.children=[]; this.listeners={}; this._value=''; this.className=''; this.hidden=false; }
    set value(v) { this._value=String(v); if(this.tag==='select') for(const o of this.children)o.selected=o.value===this._value; }
    get value() { return this._value; }
    get options() { return this.children; }
    get selectedOptions() { return this.children.filter(o=>o.selected); }
    append(...children) { for(const c of children){c.parent=this; this.children.push(c);} }
    prepend(c) { c.parent=this; this.children.unshift(c); }
    add(c) { this.append(c); }
    replaceChildren(...children) { this.children=[]; this.append(...children); }
    addEventListener(name,fn) { this.listeners[name]=fn; }
    setAttribute(name,v) { this[name]=String(v); }
    querySelector(tag) { for(const child of this.children){if(child.tag===tag)return child; const found=child.querySelector(tag); if(found)return found;} return null; }
    closest() { return form; }
    remove() { this.parent.children=this.parent.children.filter(c=>c!==this); }
    scrollIntoView() { this.scrolled=true; }
}
class Option extends Element { constructor(text,value){super('option');this.textContent=String(text);this.value=value;this.selected=false;} }
const ids=['examGridFull','examGridDuration','examGridRows','examChapterTargets','examGridTotals','examGridPreset','examGridReference','examGridConfig','examGridError','examGridAdd',...['K','U','A','HA'].map(d=>'examCognitive_'+d)];
const elements=Object.fromEntries(ids.map(id=>[id,new Element()]));
elements.examGridReference.hidden=true;
const form=new Element('form');
const config={reference_key:'verified',full_marks:4,duration_minutes:15,rows:[{name:'MCQs',type:'MCQ',chapter_ids:[10],topic_ids:[20],count:4,total_marks:4,marks_each:1,domains:{K:1,U:1,A:1,HA:1}}],chapter_targets:[{chapter_id:10,count:4,marks:4}],cognitive_marks:{K:1,U:1,A:1,HA:1}};
const data={chapters:[{id:10,name:'Sets <one>',alias_ids:[10]}],topics:{10:[{id:20,name:'Cardinality'}]},initial:config,saved:[{id:1,name:'Saved CDC working copy',config}],defaults:[{key:'verified',name:'Verified reference',config,url:'https://www.moecdc.gov.np/',warning:'Review composite questions manually.',area_marks:{Algebra:4}}]};
vm.runInNewContext(code,{Option,window:{examGridEditorData:data},document:{readyState:'complete',getElementById:id=>elements[id],createElement:tag=>new Element(tag),querySelectorAll:()=>elements.examGridRows.children}});
assert.equal(elements.examGridFull.value,'4');
assert.equal(elements.examGridRows.children.length,1);
const row=elements.examGridRows.children[0]._refs;
assert.equal(row.chapters.selectedOptions[0].textContent,'Sets <one>', 'Chapter names remain plain option text');
assert.equal(row.topics.selectedOptions[0].value,'20');
assert.equal(row.K.value,'1');
assert.equal(elements.examGridReference.hidden,false,'Opening a saved reference must show its source warning immediately');
assert.equal(elements.examGridReference.querySelector('a').href,'https://www.moecdc.gov.np/');
elements.examGridPreset.listeners.change({target:{value:'saved:1'}});
assert.equal(elements.examGridReference.hidden,false,'Loading a saved copy must retain the CDC warning');
const event={preventDefault(){this.prevented=true;}};
form.listeners.submit(event);
assert.equal(event.prevented,undefined);
const submitted=JSON.parse(elements.examGridConfig.value);
assert.equal(submitted.reference_key,'verified');
assert.deepEqual(submitted.rows[0].domains,{K:'1',U:'1',A:'1',HA:'1'});
assert.deepEqual(submitted.chapter_targets,[{chapter_id:10,count:'4',marks:'4'}]);
elements.examGridFull.value='5'; form.listeners.submit(event);
assert.equal(event.prevented,true);
assert.match(elements.examGridError.textContent,/must equal full marks/);
console.log('Exam grid editor passed: saved reference warnings, named chapters/topics, quotas, exact targets, safe text and submit validation.');
