export function initializeMathematicsAreas(root,context){
 const form=root.querySelector('[data-math-areas-form]');if(!form)return;const doc=root.ownerDocument,win=doc.defaultView,data=JSON.parse(form.querySelector('[data-math-areas-data]').value),list=form.querySelector('[data-math-area-rows]');let serial=0;
 const listen=(node,type,fn)=>node.addEventListener(type,fn,{signal:context.signal});
 const renumber=()=>[...list.children].forEach((row,index)=>row.querySelectorAll('[data-area-field]').forEach(field=>field.name='areas['+index+']['+field.dataset.areaField+']'+(field.multiple?'[]':'')));
 function add(value={}){if(list.children.length>=30)return;const row=doc.createElement('section');row.className='border rounded p-3 mb-3';
  const key=doc.createElement('input');key.type='hidden';key.dataset.areaField='key';let index=1;const used=[...list.querySelectorAll('[data-area-field="key"]')].map(node=>node.value);while(used.includes('custom-'+index))index++;key.value=value.key||'custom-'+index;
  const name=doc.createElement('input');name.className='form-control mb-2';name.maxLength=100;name.required=true;name.value=value.name||'';name.dataset.areaField='name';name.id='mathAreaName'+(++serial);const label=doc.createElement('label');label.htmlFor=name.id;label.className='form-label';label.textContent='Area name';
  const chapters=doc.createElement('select');chapters.className='form-select mb-2';chapters.multiple=true;chapters.required=true;chapters.size=5;chapters.dataset.areaField='chapter_ids';chapters.id='mathAreaChapters'+serial;const chapterLabel=doc.createElement('label');chapterLabel.htmlFor=chapters.id;chapterLabel.className='form-label';chapterLabel.textContent='Chapters in this area';for(const chapter of data.chapters){const option=new win.Option(chapter.name,String(chapter.id));option.selected=(value.chapter_ids||[]).map(String).includes(option.value);chapters.add(option);}
  const remove=doc.createElement('button');remove.type='button';remove.className='btn btn-sm btn-outline-danger';remove.textContent='Remove area';listen(remove,'click',()=>{row.remove();renumber();});row.append(key,label,name,chapterLabel,chapters,remove);list.append(row);renumber();
 }
 for(const area of data.areas)add(area);listen(form.querySelector('[data-math-area-add]'),'click',()=>add());context.onBeforeSubmit?.(submitted=>{if(submitted===form)renumber();});
}
