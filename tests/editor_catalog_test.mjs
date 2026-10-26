import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {JSDOM} from 'jsdom';
import {trackForms,mutationState} from '../frontend/app/dirty-forms.js';

test('asynchronous exam catalog hydration preserves the saved chapter and draft baseline',async()=>{
    const dom=new JSDOM('<main><form method="post" id="examQuestionForm"><select id="examClass" name="class_id"><option value="1">Class 9</option></select><select id="examSubject" name="subject_id"><option value="2">Math</option></select><select id="examChapter" name="chapter"><option>Sets</option></select><select id="examType" name="type"><option>Long</option></select><select id="examVisibility" name="visibility"><option>private</option></select></form></main>',{url:'https://school.example/exam-questions',runScripts:'outside-only'});
    const win=dom.window,root=win.document.querySelector('main'),form=root.querySelector('form');let release;
    try{
        win.examQuestionData={subjects:{1:[{id:2,name_en:'Math'}]},form:{subject_id:2,chapter:'Sets'}};
        win.fetch=()=>new Promise(resolve=>release=resolve);
        await new Promise(resolve=>win.document.addEventListener('DOMContentLoaded',resolve,{once:true}));
        win.eval(readFileSync('assets/js/exam-questions.js','utf8'));
        assert.equal(root.querySelector('#examChapter').value,'Sets');
        const dispose=trackForms(root);
        release({ok:true,json:async()=>({chapters:[{id:3,name:'Sets'},{id:4,name:'Algebra'}],topics:{}})});
        await new Promise(resolve=>setImmediate(resolve));
        assert.equal(mutationState(form),'clean');
        root.querySelector('#examChapter').value='Algebra';root.querySelector('#examChapter').dispatchEvent(new win.Event('change',{bubbles:true}));assert.equal(mutationState(form),'dirty');
        dispose();
    }finally{win.close();}
});
