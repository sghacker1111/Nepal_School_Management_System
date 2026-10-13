/* Client behaviour with a mock DOM; API grading is separately tested over HTTP. */
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Node {
 constructor(){this.value='';this.textContent='';this.innerHTML='';this.hidden=false;this.disabled=false;this.children=[];this.listeners={};this.attrs={};this.dataset={};this.style={};this.className='';this.tagName='DIV';this.classList={add:(c)=>{this.className+=' '+c;},toggle:(c,on)=>{if(on)this.className+=' '+c;}};}
 addEventListener(event,callback){this.listeners[event]=callback;}
 append(...nodes){this.children.push(...nodes);}
 replaceChildren(...nodes){this.children=nodes;}
 add(node){this.children.push(node);}
 setAttribute(name,value){this.attrs[name]=value;}
 focus(){}
}
const nodes=new Map(),el=id=>{if(!nodes.has(id))nodes.set(id,new Node());return nodes.get(id);};
el('practiceBootstrap').textContent=JSON.stringify({current:null,limit:100});el('practiceCount').value='2';el('practiceSelection').value='random';
el('practiceApp').querySelectorAll=()=>el('practiceOptions').children;
const doc=new Node();doc.getElementById=el;doc.querySelector=()=>({content:'test-csrf'});doc.createElement=()=>new Node();
const tones=[],gains=[],requests=[],stores=new Map(),animations=[];let reducedMotion=false;
Node.prototype.animate=function(frames,options){animations.push({frames,options});};
class AudioContext {
 constructor(){this.state='running';this.currentTime=1;this.destination={};}
 createOscillator(){return {frequency:{setValueAtTime:(f)=>tones.push(f),exponentialRampToValueAtTime:(f)=>tones.push(f)},connect(){},start(){},stop(){},disconnect(){}};}
 createGain(){return {gain:{setValueAtTime(){},exponentialRampToValueAtTime:(n)=>gains.push(n)},connect(){},disconnect(){}};}
}
const question={id:1,text:'Question',nepali:'',options:{A:{text:'One'},B:{text:'Two'},C:{text:'Three'},D:{text:'Four'}}};
const state={token:'session',index:0,total:2,score:0,answered:0,title:'Class 9 · Mathematics',complete:false,question};
let current={...state},apiCalls=0;
const context={document:doc,window:{AudioContext,matchMedia:()=>({matches:reducedMotion}),location:{pathname:'/practice.php',href:'http://localhost/practice.php'}},localStorage:{getItem:k=>stores.get(k),setItem:(k,v)=>stores.set(k,v)},URL,URLSearchParams,Option:class extends Node{constructor(t,v){super();this.textContent=t;this.value=v;}},fetch:async(url,options)=>{
 const params=options.body||new URL(url).searchParams,action=params.get('action');requests.push({action,params});apiCalls++;
 if(action==='start')current={...state};
 if(action==='answer'){const correct=params.get('choice')==='B';current={...current,score:correct?1:0,answered:1,feedback:{correct,selected:params.get('choice'),correct_option:'B',correct_text:'Two',correct_nepali:'',explanation:''}};}
 if(action==='next')current={...state,index:1,answered:1,question:{...question,id:2}};
 if(action==='reset')current=null;
 return {ok:true,json:async()=>({success:true,practice:current})};
}};
el('practiceApp').requestFullscreen=async()=>{doc.fullscreenElement=el('practiceApp');doc.listeners.fullscreenchange();};
doc.exitFullscreen=async()=>{doc.fullscreenElement=null;doc.listeners.fullscreenchange();};
vm.runInNewContext(fs.readFileSync('assets/js/mcq-practice.js','utf8'),context);
async function fire(id,event='click',arg={preventDefault(){}}){await el(id).listeners[event](arg);}
(async()=>{
 await fire('practiceSetupForm','submit');assert.equal(requests[0].params.get('csrf_token'),'test-csrf');assert.equal(el('practiceOptions').children.length,4);
 let before=apiCalls;const wrong=el('practiceOptions').children[3];await Promise.all([wrong.listeners.click(),wrong.listeners.click()]);assert.equal(apiCalls,before+1,'Busy state prevents duplicate clicks');
 assert.match(el('practiceOptions').children[3].className,/is-wrong/);assert.match(el('practiceOptions').children[1].className,/is-correct/);assert(tones.includes(155)&&tones.includes(45),'Wrong answer plays falling boom tone');
 assert(el('practiceOptions').children.every(b=>b.disabled));assert.equal(el('practiceNext').hidden,false);
 await fire('practiceNext');await el('practiceOptions').children[1].listeners.click();assert(tones.includes(660)&&tones.includes(880),'Correct answer plays rising chime');assert.equal(el('practiceFeedback').children[0].textContent,'✓ Correct!');
 await fire('practiceSound');assert.equal(stores.get('school.practice.sound'),'off');assert.equal(el('practiceSound').attrs['aria-pressed'],'false');
 await fire('practiceAgain');await fire('practiceSetupForm','submit');before=tones.length;await el('practiceOptions').children[1].listeners.click();assert.equal(tones.length,before,'Mute suppresses audio');
 await fire('practiceReviewBack');assert.equal(requests.at(-1).action,'reset');assert.equal(el('practiceSetup').hidden,false,'Review Back restores class/subject selection');assert.equal(el('practiceSummary').hidden,true);
 await fire('practiceFullscreen');assert.equal(el('practiceFullscreen').textContent,'Exit full screen');await fire('practiceFullscreen');assert.equal(el('practiceFullscreen').textContent,'Full screen');
 assert(gains.includes(.22)&&gains.includes(.28),'Both answer sounds use the louder peaks');assert(gains.every(n=>n<=.28),'Audio envelopes retain clipping headroom');
 assert(animations.length>0&&animations.every(a=>a.options.duration===300),'Question panels use smooth short animations');
 reducedMotion=true;before=animations.length;await fire('practiceSetupForm','submit');assert.equal(animations.length,before,'Reduced-motion preference skips panel animation');
 console.log('MCQ client checks passed: larger-feedback flow, louder boom/chime, mute, smooth/reduced motion, full screen, review Back, four choices, CSRF and duplicate-click guard.');
})().catch(error=>{console.error(error);process.exitCode=1;});
