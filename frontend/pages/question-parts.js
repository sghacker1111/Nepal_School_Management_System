import {editorInstances} from '../app/editor-instances.js';

export const questionPartCategories={K:'Knowledge',U:'Understanding',A:'Application',HA:'Higher Ability',KU:'Knowledge and Understanding'};
export function partSuffix(marks,level,display){return '('+(display==='cognitive'?'':marks)+(display==='marks'?'':level)+')';}
const labelAt=index=>{let text='';do{text=String.fromCharCode(97+index%26)+text;index=Math.floor(index/26)-1;}while(index>=0);return text;};
const number=units=>String(Number((units/100).toFixed(2)));

export function initializeQuestionParts(root,context){
 const panel=root.querySelector('#questionPartsPanel');if(!panel)return;
 const doc=root.ownerDocument,win=doc.defaultView,form=panel.closest('form'),el=id=>root.querySelector('#'+id),layout=el('questionLayout'),rows=el('questionSubparts'),marks=form.elements.namedItem('marks'),domain=form.elements.namedItem('cognitive_domain'),type=el('examType');
 const config=JSON.parse(panel.querySelector('[data-question-parts-config]').value),draft=config.draft||{};let serial=0,initialized=false;
 const listen=(node,event,handler)=>node?.addEventListener(event,handler,{signal:context.signal});
 const saveEditors=()=>{for(const editor of editorInstances(win))if(form.contains(editor.getElement?.()))editor.save();};
 const removeEditors=container=>{for(const editor of editorInstances(win))if(container.contains(editor.getElement?.())){editor.save();editor.remove();}};
 const refresh=()=>win.SchoolQuestionEditor?.refresh?.(root);
 const dirty=()=>{root.dispatchEvent(new win.Event('erp:dirty',{bubbles:true}));el('questionPartsPreviewBody').hidden=true;};
 const element=(tag,className,text)=>{const node=doc.createElement(tag);if(className)node.className=className;if(text!==undefined)node.textContent=text;return node;};
 const field=(parent,labelText,node)=>{const wrap=element('div'),label=element('label','form-label',labelText);node.id||='questionPartField'+(++serial);label.htmlFor=node.id;wrap.append(label,node);parent.append(wrap);return node;};
 const button=(text,handler)=>{const node=element('button','btn btn-sm btn-outline-secondary',text);node.type='button';listen(node,'click',handler);return node;};
 const allRows=()=>[...rows.children];
 function renumber(){allRows().forEach((row,index)=>{for(const input of row.querySelectorAll('[data-part-key]'))input.name='subquestions['+index+']['+input.dataset.partKey+']';row.querySelector('[data-row-title]').textContent='Subquestion '+(index+1);row.querySelector('[data-move-up]').disabled=index===0;row.querySelector('[data-move-down]').disabled=index===rows.children.length-1;});totals();}
 function totals(){let units=0;allRows().forEach(row=>{const value=Number(row.querySelector('[data-part-key="marks"]').value);if(Number.isFinite(value))units+=Math.round(value*100);});el('questionPartsTotal').textContent='Total: '+number(units)+' marks';if(layout.value==='structured')marks.value=number(units);return units;}
 function move(row,offset){const other=allRows()[allRows().indexOf(row)+offset];if(!other)return;removeEditors(row);if(offset<0)rows.insertBefore(row,other);else rows.insertBefore(row,other.nextSibling);renumber();refresh();dirty();}
 function addPart(part={}){
  if(rows.children.length>=40)return;
  const index=rows.children.length,row=element('section','question-subpart'),tools=element('div','question-part-tools'),title=element('strong','','Subquestion '+(index+1));title.dataset.rowTitle='';tools.append(title);
  const used=new Set(allRows().map(row=>row.querySelector('[data-part-key="label"]').value.toLowerCase()));let free=0;while(used.has(labelAt(free)))free++;
  const label=element('input','form-control');label.type='text';label.maxLength=8;label.value=String(part.label??labelAt(free));label.required=true;label.dataset.partKey='label';field(tools,'Label',label);
  const score=element('input','form-control');score.type='number';score.min='0.01';score.max='1000';score.step='0.01';score.required=true;score.value=String(part.marks??'1');score.dataset.partKey='marks';field(tools,'Marks',score);
  const level=element('select','form-select');level.dataset.partKey='level';for(const [key,name]of Object.entries(questionPartCategories))level.append(new win.Option(key+' — '+name,key));level.value=part.level||part.levels?.[0]||'U';field(tools,'Cognitive category',level);
  const up=button('Move up',()=>move(row,-1));up.dataset.moveUp='';const down=button('Move down',()=>move(row,1));down.dataset.moveDown='';
  tools.append(up,down,button('Remove',()=>{removeEditors(row);row.remove();renumber();dirty();}));row.append(tools);
  const languages=element('div','row g-3');for(const [key,text]of [['np','Nepali'],['en','English']]){const wrap=element('div','col-md-6'),area=element('textarea','form-control exam-rich'+(key==='np'?' font-nepali':''));area.rows=3;area.id='questionPart'+(++serial)+(key==='np'?'Np':'En');area.dataset.partKey=key;area.value=typeof part[key]==='string'?part[key]:'';field(wrap,'Subquestion ('+text+')',area);languages.append(wrap);}row.append(languages);rows.append(row);renumber();if(initialized)refresh();
 }
 function subjectRule(){const cls=el('examClass')?.value,subject=el('examSubject')?.value,selected=(config.subjects?.[cls]||[]).find(s=>String(s.id)===subject),name=(selected?.name_en||'')+' '+(selected?.name_np||'');const math=/(?:\bmath(?:ematic)?s?\b|\bmathematics\b|गणित)/i.test(name);el('questionMathRule').hidden=!math;return math;}
 function mode(){
  const mcq=type.value==='MCQ';el('questionLayoutControl').hidden=false;el('questionPartAdd').disabled=mcq;
  const structured=layout.value==='structured';panel.hidden=!structured;
  panel.querySelectorAll('input,select,textarea').forEach(node=>node.disabled=!structured);
  panel.querySelectorAll('textarea').forEach(node=>node.classList.toggle('exam-rich',structured));
  root.querySelectorAll('[data-question-plain]').forEach(wrap=>{wrap.hidden=structured;wrap.querySelectorAll('textarea').forEach(node=>{node.disabled=structured;node.classList.toggle('exam-rich',!structured);});});
  marks.readOnly=structured;domain.disabled=structured;subjectRule();if(mcq)el('questionMathRule').hidden=true;if(structured)totals();
 }
 function error(message){const notice=el('questionPartsError');notice.textContent=message;notice.hidden=!message;if(message)notice.scrollIntoView?.({block:'center'});}
 function valid(){
  if(layout.value!=='structured')return true;saveEditors();error('');const parts=allRows(),units=totals(),labels=new Set();
  const has=value=>{const node=doc.createElement('div');node.innerHTML=win.SchoolEditorSecurity?.sanitize?.(value)||'';return !!node.textContent.trim()||!!node.querySelector('img');};
  if(!has(el('questionStatementEn').value)&&!has(el('questionStatementNp').value)){error('Enter the statement in English or Nepali.');return false;}
  if(!parts.length||units<=0||units>100000){error('Add subquestions with a total between 0.01 and 1,000 marks.');return false;}
  for(const row of parts){const value=key=>row.querySelector('[data-part-key="'+key+'"]').value,label=value('label').trim().toLowerCase();if(!/^[\p{L}\p{N}]{1,8}$/u.test(label)||labels.has(label)){error('Use unique labels of 1–8 letters or digits.');return false;}labels.add(label);if(!has(value('en'))&&!has(value('np'))){error('Enter English or Nepali text for subquestion ('+label+').');return false;}}
  if(type.value==='MCQ'&&parts.length!==1){error('A structured MCQ needs one prompt and one set of four options.');return false;}
  if(type.value!=='MCQ'&&subjectRule()&&(el('questionMathematicsRule')?.value||'strict')==='strict'){
   const category=row=>row.querySelector('[data-part-key="level"]').value;
   if(parts.length<2||category(parts[0])!=='K'||category(parts.at(-1))!=='HA'){error('Mathematics requires K first and HA last. KU is a separate category.');return false;}
   if(parts.length===3&&!['U','A','KU'].includes(category(parts[1]))){error('For three mathematics parts, choose U, A or KU in the middle.');return false;}
  }
  return true;
 }
 el('questionStatementEn').value=typeof draft.statement_en==='string'?draft.statement_en:'';el('questionStatementNp').value=typeof draft.statement_np==='string'?draft.statement_np:'';el('questionPartDisplay').value=['both','marks','cognitive'].includes(draft.display)?draft.display:'both';
 const parts=Array.isArray(draft.parts)?draft.parts:[];for(const part of parts.slice(0,40))if(part&&typeof part==='object')addPart(part);initialized=true;
 layout.value=config.stored||(config.new&&type.value!=='MCQ')?'structured':'plain';mode();refresh();
 listen(layout,'change',()=>{saveEditors();mode();refresh();dirty();});listen(type,'change',()=>{saveEditors();mode();refresh();dirty();});
 listen(el('examSubject'),'change',subjectRule);listen(el('examClass'),'change',subjectRule);
 listen(el('questionPartAdd'),'click',()=>{addPart({level:subjectRule()?(rows.children.length?'HA':'K'):'U'});mode();dirty();});
 listen(panel,'input',()=>{totals();dirty();});listen(panel,'change',dirty);
 listen(form,'submit',event=>{if(!valid())event.preventDefault();});
 context.onBeforeSubmit?.(submitted=>{if(submitted===form){saveEditors();totals();}});
 listen(el('questionPartsPreview'),'click',()=>{
  if(!valid())return;const preview=el('questionPartsPreviewBody');preview.replaceChildren();const content=element('div','exam-bilingual-content exam-structured-content');content.style.setProperty('--exam-label-width',Math.max(3,...allRows().map(row=>[...row.querySelector('[data-part-key="label"]').value].length+1.5))+'rem');
  const append=(label,ne,en,suffix='')=>{const row=element('div','exam-bilingual-row'),pair=element('div','exam-language-pair'+(label?' exam-subquestion-pair':''));for(const [language,html]of [['ne',ne],['en',en]]){if(!html.trim())continue;const block=element('div','exam-language-'+language+(language==='ne'?' font-nepali':''));block.lang=language;block.innerHTML=win.SchoolEditorSecurity?.sanitize?.(html)||'';if(label&&(language==='ne'||!ne.trim())){const prefix=element('span','exam-part-label','('+label+')');let first=block.firstElementChild;while(first&&['DIV','BLOCKQUOTE'].includes(first.tagName)&&first.firstElementChild&&['DIV','BLOCKQUOTE','P','H1','H2','H3','H4','H5','H6'].includes(first.firstElementChild.tagName))first=first.firstElementChild;if(first&&['P','DIV','H1','H2','H3','H4','H5','H6','BLOCKQUOTE'].includes(first.tagName))first.prepend(prefix);else block.prepend(prefix);}if(suffix&&(language==='en'||!en.trim())){const score=element('span','exam-part-score',' '+suffix),last=block.lastElementChild;if(last?.tagName==='P')last.append(score);else block.append(score);}pair.append(block);}row.append(pair);content.append(row);};
  append('',el('questionStatementNp').value,el('questionStatementEn').value);for(const row of allRows()){const value=key=>row.querySelector('[data-part-key="'+key+'"]').value;append(value('label'),value('np'),value('en'),partSuffix(number(Math.round(Number(value('marks'))*100)),value('level'),el('questionPartDisplay').value));}preview.append(content);preview.hidden=false;
  Promise.resolve(win.MathJax?.startup?.promise).then(()=>{if(!context.signal.aborted&&preview.isConnected)return win.MathJax?.typesetPromise?.([preview]);}).catch(()=>{});
 });
 return {valid,addPart,totals};
}
