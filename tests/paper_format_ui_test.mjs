import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';import {initializePaperFormat} from '../frontend/pages/paper-format.js';
function fixture(){
 const dom=new JSDOM('<main><form method="post"><input name="exam_title" value="Custom exam"><select data-paper-exam-preset><option value=""></option><option>Unit Test</option></select><select data-paper-heading-style><option>Group</option><option>Section</option><option>None</option></select><section data-paper-sections-editor><input data-paper-sections-data><input data-paper-sections-duration value="150"><div data-paper-section-list></div><button type="button" data-paper-section-add>Add block</button><p data-paper-sections-total></p><div data-paper-sections-error hidden></div><input name="sections_json" data-paper-sections-json></section></form></main>'),win=dom.window,root=win.document.querySelector('main');
 root.querySelector('[data-paper-sections-data]').value=JSON.stringify({chapters:[{id:1,name:'Sets <literal>'},{id:2,name:'Algebra'}],types:['MCQ','Very Short','Short','Long'],initial:{sections:[{title:'Objective',rows:[{chapter_ids:[1],type:'MCQ',count:2,total_marks:2,marks_each:1}]}]}});
 const controller=new win.AbortController(),hooks=[],api=initializePaperFormat(root,{signal:controller.signal,onBeforeSubmit:fn=>hooks.push(fn)});return {win,root,controller,hooks,api,el:sel=>root.querySelector(sel),blocks:()=>[...root.querySelector('[data-paper-section-list]').children]};
}
test('header presets, section titles, chapter pools and exact calculated marks serialize safely',()=>{
 const f=fixture(),{win,el,api}=f;const first=f.blocks()[0],allocation=first.querySelector('[data-section-rows]').firstElementChild;
 assert.equal(allocation._fields.chapters.options[0].textContent,'Sets <literal>');assert.equal(el('[data-paper-sections-total]').textContent,'2 questions · 2.00 full marks');
 const preset=el('[data-paper-exam-preset]');preset.value='Unit Test';preset.dispatchEvent(new win.Event('change'));assert.equal(el('[name="exam_title"]').value,'Unit Test');
 allocation._fields.count.value='10';allocation._fields.count.dispatchEvent(new win.Event('input',{bubbles:true}));assert.equal(allocation._fields.marks.value,'10.00');assert.equal(api.validate(),true);
 el('[data-paper-section-add]').click();const second=f.blocks()[1],row=second.querySelector('[data-section-rows]').firstElementChild;row._fields.chapters.options[1].selected=true;row._fields.type.value='Long';row._fields.marks.value='4';second._title.value='<script>Literal title</script>';assert.equal(api.validate(),true);
 const config=JSON.parse(el('[data-paper-sections-json]').value);assert.equal(config.duration_minutes,'150');assert.equal(config.sections[1].title,'<script>Literal title</script>');assert.equal(el('script'),null);assert.equal(config.sections[1].rows[0].chapter_ids[0],'2');
 const heading=el('[data-paper-heading-style]');heading.value='Section';heading.dispatchEvent(new win.Event('change'));assert.equal(first.querySelector('[data-section-label]').textContent,'Section - A');
 second.querySelector('[data-section-up]').click();assert.equal(f.blocks()[0],second);assert.equal(api.read().sections[0].title,'<script>Literal title</script>');
 heading.value='None';heading.dispatchEvent(new win.Event('change'));assert.equal(second.querySelector('[data-section-label]').textContent,'Block - A');f.hooks[0](el('form'));assert.equal(JSON.parse(el('[data-paper-sections-json]').value).sections.length,2);
 f.controller.abort();el('[data-paper-section-add]').click();assert.equal(f.blocks().length,2);win.close();
});
test('invalid rows and empty blocks cannot submit; removing blocks and variable marks work',()=>{
 const f=fixture(),{win,el,api}=f;el('[data-paper-section-add]').click();assert.equal(api.validate(),false);let event=new win.Event('submit',{bubbles:true,cancelable:true});el('form').dispatchEvent(event);assert.equal(event.defaultPrevented,true);assert.equal(el('[data-paper-sections-error]').hidden,false);
 const second=f.blocks()[1],remove=[...second.querySelectorAll('button')].find(button=>button.textContent==='Remove block');remove.click();assert.equal(api.validate(),true);
 const row=f.blocks()[0].querySelector('[data-section-rows]').firstElementChild;row._fields.count.value='1.5';assert.equal(api.validate(),false);row._fields.count.value='2';row._fields.each.value='';row._fields.marks.value='3.5';assert.equal(api.validate(),true);assert.equal(api.read().sections[0].rows[0].total_marks,'3.5');
 f.controller.abort();win.close();
});
