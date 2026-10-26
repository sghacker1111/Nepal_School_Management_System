import {test} from 'node:test';import assert from 'node:assert/strict';import {JSDOM} from 'jsdom';
import controller from '../frontend/pages/generated/learning-index.js';

test('note editor switches class, subject, chapter and topic, ignores stale responses and retains body',async()=>{
 const dom=new JSDOM('<form id="noteForm"><div id="noteModal"></div><select id="modalNoteClass"><option value="1">One</option><option value="2">Two</option></select><select id="modalNoteSubject"></select><select id="modalNoteChapter"></select><select id="modalNoteTopic"></select><select id="modalNoteVisibility"><option>private</option><option>public</option></select><input id="modalActionNote"><input id="modalNoteId"><div id="modalNoteTitle"></div><input id="modalNoteTitleInput"><textarea id="modalNoteContent"></textarea><div id="noteCatalogStatus"></div><button type="submit">Save</button>'+['chapter_id','topic_id','catalog_chapter_id','catalog_topic_id'].map(k=>'<input id="noteRef_'+k+'">').join('')+'</form>',{url:'https://school.example/learning'});
 const w=dom.window,d=w.document,f=id=>d.getElementById(id),change=id=>f(id).dispatchEvent(new w.Event('change'));
 const catalog={chapters:[{id:10,name:"King's & Queens"},{id:11,name:'Second'}],topics:{10:[{id:20,name:'First topic'}],11:[{id:21,name:'Second topic'}]}};
 let actions,opened=0,saveCount=0;const pending=[];
 const scope={document:d,window:w,URL:w.URL,URLSearchParams:w.URLSearchParams,Option:w.Option,confirm:()=>true,ErpUI:{Modal:class{show(){opened++;}}},tinymce:{get:()=>null,triggerSave(){saveCount++;}},fetch:url=>new Promise(resolve=>pending.push({url:String(url),resolve})),bindActions(value){actions=value;}};
 controller(scope,{values:{'note-editor':JSON.stringify({class_id:1,subject_id:5,chapter:"King's & Queens",subjects:{1:[{id:5,name_en:'Math'}],2:[{id:6,name_en:'Nepali'},{id:7,name_en:'Science'}]},catalog,url:'/learning'}),'learning-index-value-0':'"private"','learning-index-value-1':JSON.stringify("King's & Queens")}});
 actions['learning-index-action-0']();assert.equal(opened,1);assert.equal(f('modalNoteClass').value,'1');assert.equal(f('modalNoteChapter').value,"King's & Queens");assert.equal(f('modalNoteTopic').options[1].text,'First topic');
 f('modalNoteContent').value='<p>Formula \\(x^2\\)</p>';
 f('modalNoteChapter').value='Second';actions['learning-index-action-10']();assert.equal(f('modalNoteTopic').options[1].text,'Second topic');
 f('modalNoteClass').value='2';change('modalNoteClass');assert.equal(f('modalNoteSubject').options.length,3);assert.equal(f('modalNoteTopic').options.length,1);assert.equal(d.querySelector('button').disabled,true);
 f('modalNoteSubject').value='6';change('modalNoteSubject');f('modalNoteSubject').value='7';change('modalNoteSubject');assert.equal(pending.length,2);
 pending[1].resolve({ok:true,json:async()=>({chapters:[{id:30,name:'Science chapter'}],topics:{30:[{name:'Science topic'}]}})});await new Promise(r=>setImmediate(r));
 pending[0].resolve({ok:true,json:async()=>catalog});await new Promise(r=>setImmediate(r));assert.equal(f('modalNoteChapter').options[1].text,'Science chapter');
 f('modalNoteChapter').value='Science chapter';actions['learning-index-action-10']();assert.equal(f('modalNoteTopic').options[1].text,'Science topic');
 assert.match(f('modalNoteContent').value,/Formula/);assert.equal(d.querySelector('button').disabled,false);actions['learning-index-action-9']();assert.equal(saveCount,1);
 actions['learning-index-action-7'](null,[JSON.stringify({id:8,class_id:1,subject_id:5,chapter:"King's & Queens",topic:'First topic',title:'Heading & title',content:'<p>Existing content</p>',visibility:'private',chapter_id:10,topic_id:20})]);await new Promise(r=>setImmediate(r));
 assert.equal(f('modalNoteTopic').value,'First topic');assert.equal(f('noteRef_topic_id').value,'20');assert.equal(f('modalNoteTitleInput').value,'Heading & title');assert.equal(f('modalNoteContent').value,'<p>Existing content</p>');
 dom.window.close();
});
