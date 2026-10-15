const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function select(value=''){
 return {value,disabled:false,options:[],events:{},replaceChildren(...options){this.options=options;this.value='';},add(o){this.options.push(o);},addEventListener(e,fn){this.events[e]=fn;}};
}
async function run(){
 const fields={class:select('1'),subject:select('10'),chapter:select(),topic:select()},status={textContent:''};
 const link={href:'http://localhost/questions.php?action=download_template',events:{},attrs:{},addEventListener(e,fn){this.events[e]=fn;},setAttribute(k,v){this.attrs[k]=v;}};
 const form={events:{},validations:0,addEventListener(e,fn){this.events[e]=fn;},reportValidity(){this.validations++;},querySelectorAll(){return [link];}};
 const root={dataset:{catalogUrl:'http://localhost/questions.php?action=import_catalog'},querySelector(s){if(s==='[data-import-subjects]')return {textContent:JSON.stringify({1:[{id:10,name_en:'Mathematics'},{id:11,name_en:'English'}],2:[{id:20,name_en:'Science'}]})};if(s==='[data-import-context-status]')return status;return fields[s.match(/="(.*?)"/)[1]];},closest(s){return s==='form'?form:null;}};
 const pending=[];
 vm.runInNewContext(fs.readFileSync('assets/js/question-import-context.js','utf8'),{URL,Option:function(text,value){this.text=text;this.value=String(value);},fetch(url){return new Promise(resolve=>pending.push({url,resolve}));},window:{location:{href:'http://localhost/questions.php'}},document:{readyState:'complete',querySelectorAll(){return [root];}}});
 const flush=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
 let blocked=0;const event=()=>({preventDefault(){blocked++;}});
 form.events.submit(event());assert.equal(blocked,1);assert.equal(fields.chapter.disabled,true,'Cannot import while catalog is loading');
 pending[0].resolve({ok:true,json:async()=>({chapters:[{id:100,name:'Sets'}],topics:{100:[{id:1000,name:'Union'}]}})});await flush();
 fields.chapter.value='100';fields.chapter.events.change();assert.equal(fields.topic.options[1].text,'Union');
 fields.topic.value='1000';fields.topic.events.change();
 assert.equal(link.attrs['aria-disabled'],'false');assert.equal(new URL(link.href).searchParams.get('import_topic_id'),'1000');
 assert.match(status.textContent,/Ready/);form.events.submit(event());assert.equal(blocked,1,'A complete context allows submission');
 fields.class.value='2';fields.class.events.change();assert.deepEqual(fields.subject.options.map(o=>o.value),['','20']);assert.equal(fields.chapter.value,'');assert.equal(fields.topic.value,'');
 link.events.click(event());assert.equal(blocked,2,'Changing class invalidates old sample links');
 fields.class.value='1';fields.class.events.change();fields.subject.value='10';fields.subject.events.change();
 fields.subject.value='11';fields.subject.events.change();
 pending[2].resolve({ok:true,json:async()=>({chapters:[{id:200,name:'English chapter'}],topics:{200:[]}})});await flush();
 pending[1].resolve({ok:true,json:async()=>({chapters:[{id:999,name:'Stale mathematics'}],topics:{}})});await flush();
 assert.equal(fields.chapter.options[1].text,'English chapter','An older response cannot replace current subject choices');
 fields.chapter.value='200';fields.chapter.events.change();assert.match(status.textContent,/No existing topics/);form.events.submit(event());assert.equal(blocked,3);
 fields.subject.events.change();pending[3].resolve({ok:false});await flush();assert.match(status.textContent,/could not be loaded/);assert.equal(fields.chapter.disabled,true);
 console.log('Question import context controls passed: required selectors, exact subject lists, reset, scoped samples, stale-response and empty/error handling.');
}
run().catch(e=>{console.error(e);process.exitCode=1;});
