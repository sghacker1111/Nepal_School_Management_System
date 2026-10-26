export function initializePaperFormat(root,context){
 const doc=root.ownerDocument,win=doc.defaultView,listen=(node,event,fn)=>node?.addEventListener(event,fn,{signal:context.signal});
 const preset=root.querySelector('[data-paper-exam-preset]');
 listen(preset,'change',()=>{if(!preset.value)return;const title=root.querySelector('[name="exam_title"],[name="paper_title"]');if(title){title.value=preset.value;title.dispatchEvent(new win.Event('input',{bubbles:true}));}});
 const editor=root.querySelector('[data-paper-sections-editor]');if(!editor)return;
 const data=JSON.parse(editor.querySelector('[data-paper-sections-data]').value),list=editor.querySelector('[data-paper-section-list]'),duration=editor.querySelector('[data-paper-sections-duration]'),json=editor.querySelector('[data-paper-sections-json]'),form=editor.closest('form'),heading=form.querySelector('[data-paper-heading-style]');let serial=0;
 const make=(tag,cls,text)=>{const node=doc.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;};
 const button=(text,fn)=>{const node=make('button','btn btn-sm btn-outline-secondary',text);node.type='button';listen(node,'click',fn);return node;};
 const field=(parent,text,node)=>{const wrap=make('div','col-md-4'),label=make('label','form-label',text);node.id='paperSectionField'+(++serial);label.htmlFor=node.id;wrap.append(label,node);parent.append(wrap);return node;};
 const input=(value,type='text')=>{const node=make('input','form-control');node.type=type;node.value=String(value);return node;};
 const blocks=()=>[...list.children];
 const rows=block=>[...block.querySelector('[data-section-rows]').children];
 const read=()=>({duration_minutes:duration.value,sections:blocks().map(block=>({title:block._title.value,rows:rows(block).map(row=>{const f=row._fields;return {chapter_ids:[...f.chapters.selectedOptions].map(option=>option.value),type:f.type.value,count:f.count.value,total_marks:f.marks.value,marks_each:f.each.value};})}))});
 function refresh(){
  const style=heading?.value||'Group',config=read();let count=0,units=0;
  blocks().forEach((block,i)=>{block.querySelector('[data-section-label]').textContent=(style==='None'?'Block':style)+' - '+String.fromCharCode(65+i);block.querySelector('[data-section-up]').disabled=i===0;block.querySelector('[data-section-down]').disabled=i===blocks().length-1;});
  for(const section of config.sections)for(const row of section.rows)if(Number(row.count)>0){count+=Number(row.count)||0;units+=Math.round((Number(row.total_marks)||0)*100);}
  editor.querySelector('[data-paper-sections-total]').textContent=count+' questions · '+(units/100).toFixed(2)+' full marks';json.value=JSON.stringify(config);editor.querySelector('[data-paper-section-add]').disabled=blocks().length>=26;
 }
 function addRow(block,value={}){
  if(!value||typeof value!=='object'||Array.isArray(value))value={};
  if(blocks().reduce((n,block)=>n+rows(block).length,0)>=50)return;
  const row=make('section','border rounded p-3 mb-3'),layout=make('div','row g-3'),chapters=make('select','form-select');chapters.multiple=true;chapters.size=Math.min(5,Math.max(2,data.chapters.length));
  for(const chapter of data.chapters){const option=new win.Option(chapter.name,String(chapter.id));option.selected=(Array.isArray(value.chapter_ids)?value.chapter_ids:[]).map(String).includes(option.value);chapters.add(option);}field(layout,'Chapters (select one or several)',chapters);
  const type=make('select','form-select');for(const kind of data.types)type.add(new win.Option(kind,kind));type.value=value.type||'MCQ';field(layout,'Question type',type);
  const count=input(value.count??1,'number');count.min='0';count.max='200';count.step='1';count.required=true;field(layout,'Number of questions',count);
  const each=input(value.marks_each??'','number');each.min='0.01';each.max='1000';each.step='0.01';field(layout,'Marks each (optional; blank for variable marks)',each);
  const marks=input(value.total_marks??1,'number');marks.min='0';marks.max='1000';marks.step='0.01';marks.required=true;field(layout,'Total allocation marks',marks);
  const recalc=()=>{if(each.value)marks.value=(Math.round(Number(each.value)*100)*Number(count.value)/100).toFixed(2);refresh();};listen(each,'input',recalc);listen(count,'input',recalc);
  row.append(layout,button('Remove allocation',()=>{row.remove();refresh();}));row._fields={chapters,type,count,each,marks};block.querySelector('[data-section-rows]').append(row);refresh();
 }
 function addBlock(value={}){
  if(!value||typeof value!=='object'||Array.isArray(value))value={};
  if(blocks().length>=26)return;
  const block=make('section','border rounded p-3 mb-3'),tools=make('div','d-flex flex-wrap align-items-center gap-2 mb-3'),label=make('h3','h5 mb-0');label.dataset.sectionLabel='';
  const title=input(value.title??'');title.maxLength=80;title.placeholder='Optional section title';title.setAttribute('aria-label','Section title');block._title=title;
  const move=offset=>{const other=blocks()[blocks().indexOf(block)+offset];if(!other)return;if(offset<0)list.insertBefore(block,other);else list.insertBefore(block,other.nextSibling);refresh();};
  const up=button('Move up',()=>move(-1));up.dataset.sectionUp='';const down=button('Move down',()=>move(1));down.dataset.sectionDown='';tools.append(label,title,up,down,button('Remove block',()=>{block.remove();refresh();}));
  const allocationList=make('div');allocationList.dataset.sectionRows='';block.append(tools,allocationList,button('Add question allocation',()=>addRow(block)));list.append(block);for(const row of Array.isArray(value.rows)&&value.rows.length?value.rows.slice(0,50):[{}])addRow(block,row);refresh();
 }
 function validate(){
  refresh();const config=read();const error=editor.querySelector('[data-paper-sections-error]');let message='';let count=0,units=0;
  if(!config.sections.length)message='Add a group / section.';
  for(const section of config.sections){const active=section.rows.filter(row=>Number(row.count)>0);if(!active.length)message='Every block needs at least one active question allocation.';for(const row of active){count+=Number(row.count);units+=Math.round(Number(row.total_marks)*100);if(!row.chapter_ids.length||!/^\d{1,3}$/.test(row.count)||Number(row.count)>200||!(Number(row.total_marks)>0))message='Select chapters, a positive whole question count and marks for each active allocation.';if(row.marks_each&&Math.round(Number(row.marks_each)*100)*Number(row.count)!==Math.round(Number(row.total_marks)*100))message='Count × marks each must equal total allocation marks.';}}
  if(count>200||units>100000)message='Use at most 200 questions and 1,000 marks.';error.textContent=message;error.hidden=!message;return !message;
 }
 for(const block of Array.isArray(data.initial?.sections)&&data.initial.sections.length?data.initial.sections.slice(0,26):[{title:'',rows:[{type:'MCQ'}]}])addBlock(block);
 listen(editor.querySelector('[data-paper-section-add]'),'click',()=>addBlock());listen(editor,'input',refresh);listen(editor,'change',refresh);listen(heading,'change',refresh);listen(form,'submit',event=>{if(!validate())event.preventDefault();});context.onBeforeSubmit?.(submitted=>{if(submitted===form)refresh();});refresh();
 return {read,validate};
}
