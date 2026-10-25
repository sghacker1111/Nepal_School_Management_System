import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';import DOMPurify from 'dompurify';
import {initializeQuestionParts,partSuffix,questionPartCategories} from '../frontend/pages/question-parts.js';

function fixture(){
 const dom=new JSDOM(`<main><form method="post" id="examQuestionForm"><select id="examClass"><option value="1">9</option></select><select id="examSubject"><option value="2">Math</option></select><select id="examType"><option>Long</option><option>MCQ</option></select><input name="marks" value="99"><select name="cognitive_domain"><option>Knowledge</option></select><div id="questionLayoutControl"><select id="questionLayout" name="question_layout"><option value="plain">Plain</option><option value="structured">Parts</option></select></div><div id="questionPartsPanel"><input type="hidden" data-question-parts-config><textarea id="questionStatementNp" name="statement_np"></textarea><textarea id="questionStatementEn" name="statement_en"></textarea><select id="questionPartDisplay" name="part_display"><option value="both">Both</option><option value="marks">Marks</option><option value="cognitive">Category</option></select><p id="questionMathRule"></p><div id="questionSubparts"></div><button type="button" id="questionPartAdd">Add</button><button type="button" id="questionPartsPreview">Preview</button><strong id="questionPartsTotal"></strong><div id="questionPartsError"></div><div id="questionPartsPreviewBody"></div></div><div data-question-plain><textarea id="exam_question_text" name="question_text">Original</textarea></div><div data-question-plain><textarea id="exam_question_text_np" name="question_text_np"></textarea></div></form></main>`,{url:'https://school.example/exam-questions'}),win=dom.window,root=win.document.querySelector('main');
 const draft={display:'both',statement_en:'<p>A depositor invests.</p>',statement_np:'<p>एक व्यक्तिले जम्मा गरे।</p>',parts:[{label:'a',en:'<p>State the formula.</p>',np:'<p>सूत्र लेख्नुहोस्।</p>',marks:'1',levels:['K']},{label:'b',en:'<p>Find interest.</p><p>Explain it.</p>',np:'<p>ब्याज निकाल्नुहोस्।</p>',marks:'2',levels:['KU']},{label:'c',en:'<p>Compare years.</p>',np:'<p>वर्षको तुलना गर्नुहोस्।</p>',marks:'1',levels:['HA']}]};
 root.querySelector('[data-question-parts-config]').value=JSON.stringify({draft,stored:true,new:false,subjects:{1:[{id:2,name_en:'Mathematics'}]}});
 win.SchoolEditorSecurity={sanitize:DOMPurify(win).sanitize};let refreshes=0;win.SchoolQuestionEditor={refresh(){refreshes++;}};
 const controller=new win.AbortController(),hooks=[];const api=initializeQuestionParts(root,{signal:controller.signal,onBeforeSubmit:fn=>hooks.push(fn)});
 return {dom,win,root,api,hooks,controller,refreshes:()=>refreshes,el:id=>root.querySelector('#'+id),rows:()=>[...root.querySelector('#questionSubparts').children],value:(row,key)=>row.querySelector('[data-part-key="'+key+'"]')};
}
test('structured authoring pairs languages, saves independent KU, totals decimals and switches three suffix styles',()=>{
 const f=fixture(),{win,root,el,api}=f;assert.equal(f.rows().length,3);assert.equal(el('questionMathRule').hidden,false);assert.equal(root.querySelector('[name="marks"]').value,'4');assert.equal(root.querySelector('[name="marks"]').readOnly,true);assert.equal(api.valid(),true);
 assert.deepEqual(Object.keys(questionPartCategories),['K','U','A','HA','KU']);
 f.value(f.rows()[1],'marks').value='2.25';f.value(f.rows()[1],'marks').dispatchEvent(new win.Event('input',{bubbles:true}));assert.equal(root.querySelector('[name="marks"]').value,'4.25');
 for(const [mode,suffix]of [['both','(2.25KU)'],['marks','(2.25)'],['cognitive','(KU)']]){
  el('questionPartDisplay').value=mode;el('questionPartsPreview').click();const preview=el('questionPartsPreviewBody');assert.equal(preview.hidden,false);assert.match(preview.textContent,new RegExp(suffix.replace(/[()]/g,'\\$&')));const rows=preview.querySelectorAll('.exam-bilingual-row');assert.equal(rows.length,4);assert.equal(rows[2].querySelector('.exam-language-pair').firstElementChild.lang,'ne');assert.equal(rows[2].querySelector('.exam-part-label').parentElement.tagName,'P');assert.match(rows[2].querySelector('.exam-language-en p:last-child').textContent,/Explain it\./);assert.equal(rows[2].querySelectorAll('.exam-part-score').length,1);
 }
 const data=new win.FormData(root.querySelector('form'));assert.equal(data.get('subquestions[1][level]'),'KU');assert.equal(data.get('marks'),'4.25');assert.equal(data.has('question_text'),false);assert.equal(data.has('cognitive_domain'),false);f.controller.abort();win.close();
});
test('dynamic subquestions preserve rich editor data on reorder, reindex fields and reject KU as K',()=>{
 const f=fixture(),{win,el,api}=f;f.value(f.rows()[0],'level').value='KU';assert.equal(api.valid(),false);assert.match(el('questionPartsError').textContent,/KU is a separate/);f.value(f.rows()[0],'level').value='K';
 el('questionPartAdd').click();assert.equal(f.rows().length,4);const row=f.rows()[3];f.value(row,'en').value='<p>Custom <b>proof</b>.</p>';f.value(row,'label').value='iv';f.value(row,'level').value='HA';
 let saves=0,removes=0;const area=f.value(row,'en');const editor={getElement:()=>area,save(){saves++;area.value='<p>Unsaved rich draft.</p>';},remove(){removes++;win.tinymce.get=()=>[];}};win.tinymce={get:()=>[editor]};row.querySelector('[data-move-up]').click();assert.equal(saves,1);assert.equal(removes,1);assert.equal(f.rows()[2],row);assert.equal(area.name,'subquestions[2][en]');assert.match(area.value,/Unsaved rich draft/);assert.ok(f.refreshes()>=3);
 row.querySelectorAll('button')[2].click();assert.equal(f.rows().length,3);assert.equal(f.value(f.rows()[2],'en').name,'subquestions[2][en]');assert.equal(api.valid(),true);
 el('questionLayout').value='plain';el('questionLayout').dispatchEvent(new win.Event('change'));assert.equal(el('questionPartsPanel').hidden,true);assert.equal(el('exam_question_text').disabled,false);assert.equal(f.value(f.rows()[0],'marks').disabled,true);assert.equal(new win.FormData(f.root.querySelector('form')).has('subquestions[0][marks]'),false);f.controller.abort();win.close();
});
test('preview rejects active content, keeps drafts across mode changes and disposes handlers',()=>{
 const f=fixture(),{win,el}=f;const text=f.value(f.rows()[1],'en');text.value='<p onclick="alert(1)">Safe</p><img src=x onerror=alert(1)><script>alert(1)</script>';
 el('questionPartsPreview').click();const preview=el('questionPartsPreviewBody');assert.equal(preview.querySelector('script,[onclick],[onerror]'),null);assert.match(preview.textContent,/Safe/);
 for(const mode of ['plain','structured']){el('questionLayout').value=mode;el('questionLayout').dispatchEvent(new win.Event('change'));}assert.match(text.value,/Safe/);
 const count=f.rows().length;f.controller.abort();el('questionPartAdd').click();assert.equal(f.rows().length,count);assert.equal(partSuffix('2','KU','both'),'(2KU)');win.close();
});
test('structured MCQs keep one bilingual prompt editable with its statement and category',()=>{
 const f=fixture(),{win,el,api}=f;el('examType').value='MCQ';el('examType').dispatchEvent(new win.Event('change'));
 assert.equal(el('questionLayout').value,'structured');assert.equal(api.valid(),false);assert.match(el('questionPartsError').textContent,/one prompt/);
 for(let i=0;i<2;i++)f.rows().at(-1).querySelectorAll('button')[2].click();f.value(f.rows()[0],'level').value='KU';assert.equal(api.valid(),true);
 assert.equal(el('questionPartAdd').disabled,true);assert.equal(new win.FormData(f.root.querySelector('form')).get('subquestions[0][level]'),'KU');f.controller.abort();win.close();
});
