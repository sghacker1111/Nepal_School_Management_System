/* Practice feedback is graded by the server; this controller never changes exam marks. */
(() => {
 'use strict';
 const root=document.getElementById('practiceApp');if(!root)return;
 const boot=JSON.parse(document.getElementById('practiceBootstrap').textContent),el=id=>document.getElementById(id);
 let catalog=[],selected=new Set(),current=boot.current,busy=false,loadVersion=0,audioContext,sound=true;
 try{sound=localStorage.getItem('school.practice.sound')!=='off';}catch{}
 const key=text=>String(text||'').trim().replace(/\s+/g,' ').toLowerCase();
 function error(message=''){el('practiceError').textContent=message;el('practiceError').hidden=!message;}
 function soundLabel(){el('practiceSound').textContent=sound?'Sound on':'Sound off';el('practiceSound').setAttribute('aria-pressed',String(sound));}
 function unlockSound(){
  if(!sound)return;try{const Audio=window.AudioContext||window.webkitAudioContext;if(!Audio)return;audioContext??=new Audio();if(audioContext.state==='suspended')audioContext.resume().catch(()=>{});}catch{}
 }
 function playSound(correct){
  if(!sound||!audioContext||audioContext.state!=='running')return;
  try{
   const now=audioContext.currentTime;
   const tone=(frequency,start,duration,type,end)=>{const oscillator=audioContext.createOscillator(),gain=audioContext.createGain();oscillator.type=type;oscillator.frequency.setValueAtTime(frequency,start);if(end)oscillator.frequency.exponentialRampToValueAtTime(end,start+duration);gain.gain.setValueAtTime(.001,start);gain.gain.exponentialRampToValueAtTime(correct?.055:.09,start+.015);gain.gain.exponentialRampToValueAtTime(.001,start+duration);oscillator.connect(gain);gain.connect(audioContext.destination);oscillator.start(start);oscillator.stop(start+duration+.02);oscillator.onended=()=>{oscillator.disconnect();gain.disconnect();};};
   if(correct){tone(660,now,.18,'sine');tone(880,now+.13,.24,'sine');}else tone(155,now,.45,'triangle',45);
  }catch{}
 }
 async function request(action,data={},post=false){
  const url=new URL(window.location.pathname,window.location.href),params=new URLSearchParams({action});
  Object.entries(data).forEach(([name,value])=>Array.isArray(value)?value.forEach(item=>params.append(name+'[]',item)):params.set(name,String(value)));
  const options={headers:{Accept:'application/json'},credentials:'same-origin'};
  if(post){params.set('csrf_token',document.querySelector('meta[name="csrf-token"]').content);options.method='POST';options.body=params;}else url.search=params.toString();
  const response=await fetch(url,options),payload=await response.json();if(!response.ok||!payload.success)throw new Error(payload.message||'Practice could not be loaded. Please retry.');return payload;
 }
 function options(select,rows,placeholder){select.replaceChildren(new Option(placeholder,''));const names=new Map();rows.forEach(name=>{if(name&&!names.has(key(name)))names.set(key(name),name);});names.forEach(name=>select.add(new Option(name,name)));}
 function pool(){const chapter=key(el('practiceChapter').value),topic=key(el('practiceTopic').value),search=key(el('practiceSearch').value);return catalog.filter(q=>(!chapter||key(q.chapter)===chapter)&&(!topic||key(q.topic)===topic)&&(!search||key(q.search_text||q.preview).includes(search)));}
 function renderChoices(){
  const questions=pool(),chosen=el('practiceSelection').value==='chosen';
  selected=new Set([...selected].filter(id=>questions.some(q=>String(q.id)===id)));
  el('practiceChoices').hidden=!chosen;el('practiceCount').readOnly=chosen;
  const available=chosen?selected.size:questions.length,maximum=Math.min(available,boot.limit);
  el('practiceCount').max=String(Math.max(maximum,1));
  if(chosen)el('practiceCount').value=String(selected.size||1);else if(Number(el('practiceCount').value)>maximum&&maximum)el('practiceCount').value=String(maximum);
  el('practiceCountHint').textContent=chosen?'Number follows your selected MCQs.':'Up to '+boot.limit+' per practice.';
  el('practiceAvailable').textContent=catalog.length?`${questions.length} matching MCQs available${chosen?' · '+selected.size+' selected':''}.`:(el('practiceSubject').value?'No usable MCQs found. Try another subject.':'Choose a class and subject to load MCQs.');
  el('practiceSelectedCount').textContent=selected.size+' selected';el('practiceStart').disabled=busy||!maximum;
  const list=el('practiceQuestionList'),scroll=list.scrollTop||0,focused=document.activeElement?.closest?.('.practice-question-choice')?.querySelector('input')?.value;list.replaceChildren();
  if(chosen)questions.forEach(q=>{
   const label=document.createElement('label');label.className='practice-question-choice';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.value=String(q.id);checkbox.checked=selected.has(checkbox.value);const text=document.createElement('span');text.textContent=q.preview;label.append(checkbox,text);list.append(label);
   checkbox.addEventListener('change',()=>{if(checkbox.checked&&selected.size>=boot.limit){checkbox.checked=false;error('Choose up to '+boot.limit+' MCQs.');return;}if(checkbox.checked)selected.add(checkbox.value);else selected.delete(checkbox.value);renderChoices();});
  });
  list.scrollTop=scroll;if(focused)list.querySelector('input[value="'+focused+'"]')?.focus({preventScroll:true});
 }
 function updateTopics(){options(el('practiceTopic'),catalog.filter(q=>!el('practiceChapter').value||key(q.chapter)===key(el('practiceChapter').value)).map(q=>q.topic),'All topics');renderChoices();}
 async function loadSubjects(){
  const version=++loadVersion;catalog=[];selected.clear();error();const select=el('practiceSubject');select.replaceChildren(new Option('Choose subject',''));select.disabled=true;clearCatalog();
  if(!el('practiceClass').value)return;
  try{const data=await request('subjects',{class_id:el('practiceClass').value});if(version!==loadVersion)return;data.subjects.forEach(s=>select.add(new Option(s.name_en+(s.name_np?' · '+s.name_np:''),s.id)));select.disabled=false;}catch(e){if(version===loadVersion)error(e.message);}
 }
 function clearCatalog(){options(el('practiceChapter'),[],'All chapters');options(el('practiceTopic'),[],'All topics');el('practiceSearch').value='';['practiceChapter','practiceTopic','practiceSearch'].forEach(id=>el(id).disabled=true);renderChoices();}
 async function loadCatalog(){
  const version=++loadVersion;catalog=[];selected.clear();error();clearCatalog();if(!el('practiceSubject').value)return;
  el('practiceAvailable').textContent='Loading MCQs…';
  try{const data=await request('catalog',{class_id:el('practiceClass').value,subject_id:el('practiceSubject').value});if(version!==loadVersion)return;catalog=data.questions;options(el('practiceChapter'),catalog.map(q=>q.chapter),'All chapters');['practiceChapter','practiceTopic','practiceSearch'].forEach(id=>el(id).disabled=!catalog.length);updateTopics();}catch(e){if(version===loadVersion)error(e.message);}
 }
 function math(){if(window.MathJax?.typesetPromise)window.MathJax.typesetPromise([root]).then(()=>window.fixDevanagariInMathJax?.(document)).catch(()=>{});}
 function richNode(tag,html,className=''){const node=document.createElement(tag);node.className=className;node.innerHTML=html||'';return node;}
 function correctAnswer(feedback){const box=document.createElement('div');box.className='practice-correct-answer';const title=document.createElement('strong');title.textContent='Correct answer: '+feedback.correct_option;box.append(title,richNode('div',feedback.correct_text));if(feedback.correct_nepali)box.append(richNode('div',feedback.correct_nepali,'font-nepali'));return box;}
 function renderPractice(state,focus=true){
  window.MathJax?.typesetClear?.([root]);
  current=state;error();el('practiceSetup').hidden=!!state;el('practiceQuiz').hidden=!state||state.complete;el('practiceSummary').hidden=!state?.complete;
  if(!state)return;
  if(state.complete){
   el('practiceScore').textContent=state.score+' / '+state.total;el('practiceSummaryText').textContent=`${Math.round(state.score/state.total*100)}% correct · ${state.total-state.score} incorrect. These are practice results.`;
   el('practiceReview').replaceChildren();state.review.forEach((q,index)=>{const item=document.createElement('div');item.className='practice-review-item';const label=document.createElement('strong');label.textContent=`${index+1}. ${q.correct?'Correct':'Incorrect'} · Your answer: ${q.selected}`;item.append(label,richNode('div',q.text));if(q.nepali)item.append(richNode('div',q.nepali,'font-nepali'));item.append(correctAnswer(q));el('practiceReview').append(item);});math();return;
  }
  el('practicePosition').textContent=`Question ${state.index+1} of ${state.total}`;el('practiceLiveScore').textContent=`${state.score} correct · ${state.answered} answered`;el('practiceProgressFill').style.width=(state.answered/state.total*100)+'%';el('practiceProgress').setAttribute('aria-valuenow',String(Math.round(state.answered/state.total*100)));el('practiceQuizTitle').textContent=state.title;
  el('practiceQuestion').innerHTML=state.question.text;el('practiceQuestionNp').innerHTML=state.question.nepali;el('practiceQuestionNp').hidden=!state.question.nepali;
  el('practiceOptions').replaceChildren();Object.entries(state.question.options).forEach(([letter,option])=>{
   const button=document.createElement('button');button.type='button';button.className='practice-option';button.dataset.choice=letter;button.disabled=!!state.feedback||busy;const badge=document.createElement('span');badge.className='practice-option-letter';badge.textContent=letter;const body=document.createElement('span');body.className='practice-option-body';body.append(richNode('span',option.text));if(option.nepali)body.append(richNode('span',option.nepali,'practice-option-nepali font-nepali'));button.append(badge,body);button.addEventListener('click',()=>answer(letter));el('practiceOptions').append(button);
  });
  el('practiceFeedback').replaceChildren();el('practiceFeedback').hidden=!state.feedback;el('practiceNext').hidden=!state.feedback;el('practiceNext').textContent=state.index===state.total-1?'See results →':'Next question →';
  if(state.feedback){
   const feedback=state.feedback;root.querySelectorAll('.practice-option').forEach(button=>{if(button.dataset.choice===feedback.correct_option)button.classList.add('is-correct');else if(button.dataset.choice===feedback.selected)button.classList.add('is-wrong');});
   const heading=document.createElement('h3');heading.textContent=feedback.correct?'✓ Correct!':'Incorrect — keep going.';el('practiceFeedback').classList.toggle('is-wrong',!feedback.correct);el('practiceFeedback').append(heading,correctAnswer(feedback));
   if(feedback.explanation||feedback.explanation_np){const explanation=richNode('div',feedback.explanation,'practice-explanation');if(feedback.explanation_np)explanation.append(richNode('div',feedback.explanation_np,'font-nepali'));el('practiceFeedback').append(explanation);}
  }
  math();if(focus&&!state.feedback)el('practiceQuestion').focus({preventScroll:true});
 }
 function busyState(value){busy=value;el('practiceStart').disabled=value;el('practiceNext').disabled=value;el('practiceLeave').disabled=value;root.querySelectorAll('.practice-option').forEach(b=>b.disabled=value||!!current?.feedback);}
 async function answer(choice){
  if(busy||!current||current.feedback||current.complete)return;unlockSound();busyState(true);
  try{const data=await request('answer',{token:current.token,index:current.index,question_id:current.question.id,choice},true);renderPractice(data.practice,false);playSound(data.practice.feedback.correct);}catch(e){error(e.message);}finally{busyState(false);}
 }
 async function leave(){if(busy)return;busyState(true);try{await request('reset',{},true);renderPractice(null);renderChoices();}catch(e){error(e.message);}finally{busyState(false);renderChoices();}}
 el('practiceSetupForm').addEventListener('submit',async event=>{
  event.preventDefault();if(busy)return;unlockSound();busyState(true);error();
  try{const data=await request('start',{class_id:el('practiceClass').value,subject_id:el('practiceSubject').value,selection_mode:el('practiceSelection').value,count:el('practiceCount').value,chapter:el('practiceChapter').value,topic:el('practiceTopic').value,search:el('practiceSearch').value,question_ids:[...selected]},true);renderPractice(data.practice);}catch(e){error(e.message);}finally{busyState(false);renderChoices();}
 });
 el('practiceNext').addEventListener('click',async()=>{if(busy||!current?.feedback)return;busyState(true);try{const data=await request('next',{token:current.token,index:current.index,question_id:current.question.id},true);renderPractice(data.practice);}catch(e){error(e.message);try{const data=await request('state');if(data.practice?.token===current.token)renderPractice(data.practice);}catch{}}finally{busyState(false);}});
 el('practiceLeave').addEventListener('click',leave);el('practiceAgain').addEventListener('click',leave);el('practiceReviewBack').addEventListener('click',leave);
 el('practiceClass').addEventListener('change',loadSubjects);el('practiceSubject').addEventListener('change',loadCatalog);el('practiceChapter').addEventListener('change',updateTopics);['practiceTopic','practiceSelection'].forEach(id=>el(id).addEventListener('change',renderChoices));el('practiceSearch').addEventListener('input',renderChoices);
 el('practiceSelectAll').addEventListener('click',()=>{selected=new Set(pool().slice(0,boot.limit).map(q=>String(q.id)));renderChoices();});el('practiceClear').addEventListener('click',()=>{selected.clear();renderChoices();});
 el('practiceSound').addEventListener('click',()=>{sound=!sound;try{localStorage.setItem('school.practice.sound',sound?'on':'off');}catch{}unlockSound();soundLabel();});
 el('practiceFullscreen').addEventListener('click',async()=>{try{if(document.fullscreenElement)await document.exitFullscreen();else if(root.requestFullscreen)await root.requestFullscreen();else error('Full screen is not available in this browser.');}catch{error('Full screen is not available in this browser. You can continue practicing here.');}});
 document.addEventListener('fullscreenchange',()=>{el('practiceFullscreen').textContent=document.fullscreenElement?'Exit full screen':'Full screen';});
 document.addEventListener('keydown',event=>{if(!current||current.complete||current.feedback||busy||event.altKey||event.ctrlKey||event.metaKey||/INPUT|SELECT|TEXTAREA/.test(event.target.tagName))return;const letter={'1':'A','2':'B','3':'C','4':'D'}[event.key];if(letter){event.preventDefault();answer(letter);}});
 soundLabel();if(el('practiceClass').value)loadSubjects();renderPractice(current,false);
})();
