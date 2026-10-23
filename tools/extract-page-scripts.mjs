// Auditable, one-time mechanical extraction. No database/config/upload IO.
// Generated JavaScript is ordinary reviewed source, never evaluated from HTML.
import {readFile,writeFile,mkdir} from 'node:fs/promises';
import {execFileSync} from 'node:child_process';
import {JSDOM} from 'jsdom';
import {transform} from 'esbuild';
const routes=JSON.parse(execFileSync('php',['-r','require "includes/routes.php"; echo json_encode(schoolRoutes());'],{encoding:'utf8'}));
const wanted=new Set(['students','teachers','classes','subjects','exams','settings','super_admin','auth','attendance','results','reports','learning','accounting']);
const excluded=new Set(['students/admit_card','exams/admit_cards','auth/login','auth/logout','auth/verify_password','super_admin/switch_school','results/report_card']);
const files=routes.filter(r=>r.controller==='index.php'||(wanted.has(r.controller.split('/')[1])&&!excluded.has(r.controller.replace('modules/','').replace('.php','')))).map(r=>[r.controller,r.pageId]);
for(const name of ['enrollment_selectors','exam_date_controls','report_issue_controls','exam_question_page','exam_question_editor_form','exam_question_import_form','exam_question_filters','question_editor','exam_grid_editor','exam_chapter_editor','exam_mixed_editor','exam_paper_page','learning_test_paper_page','question_correction_page','question_bank_actions'])files.push(['includes/'+name+'.php','shared-'+name.replaceAll('_','-')]);
const external=['exam-questions','question-import-context','exam-grid-editor','exam-chapter-editor','exam-mixed-editor','learning-test-paper','paper-chapters','mcq-practice','accounting'];
const bindings='document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters';
const decoder=new JSDOM().window.document.createElement('textarea');
const decode=text=>{decoder.innerHTML=text;return decoder.value;};
await mkdir('frontend/pages/generated',{recursive:true});
const manifest={};
// Determine whether a PHP echo is in a JavaScript string. Tags are processed as
// atomic tokens, so quotes inside PHP do not alter the surrounding JS state.
function stateAfter(text,state){
 for(let i=0;i<text.length;i++){
  const c=text[i],n=text[i+1];
  if(state==='line'){if(c==='\n')state=null;continue;}
  if(state==='comment'){if(c==='*'&&n==='/'){state=null;i++;}continue;}
  if(state){if(c==='\\')i++;else if(c===state)state=null;continue;}
  if(c==='/'&&n==='/'){state='line';i++;}else if(c==='/'&&n==='*'){state='comment';i++;}else if(c==='\''||c==='"'||c==='`')state=c;
 }return state;
}
for(const [file,id]of files){
 let source=await readFile(file,'utf8');if(source.includes('schoolPageModule('+JSON.stringify(id))||source.includes('schoolPageActionArgs('+JSON.stringify(id))){manifest[id]='./generated/'+id+'.js';continue;}
 const original=source;
 source=source.replace(/<link\b((?:<\?[\s\S]*?\?>|[^>])*?)>/gi,(whole,attrs)=>{
  const name=attrs.match(/\/assets\/css\/(exam-question-(?:bank|language))\.css/)?.[1];
  return name?'<?php $pageStyles[]="/assets/css/'+name+'.css"; ?>':whole;
 });
 const scripts=[],php=[];let serial=0;
 function compile(code,attribute=false){
  let js='',last=0,state=null;const values=[];
  for(const match of code.matchAll(/<\?(=|php)([\s\S]*?)\?>/g)){
   const text=code.slice(last,match.index);js+=text;state=stateAfter(text,state);last=match.index+match[0].length;
   if(match[1]==='='){
    const key=id+'-value-'+serial++,expr=match[2].trim();let value;
    if(attribute){value=`args[${values.length}]`;values.push(`(string)(${expr})`);}
    else {value=`config.values[${JSON.stringify(key)}]`;php.push(`schoolPageValue(${JSON.stringify(key)},(string)(${expr}));`);}
    if(state==='\''||state==='"')js+=`${state}+(${value}??'')+${state}`;
    else if(state==='`')js+='${'+value+'??\'\'}';
    else js+=`JSON.parse(${value}||'null')`;
   }else{
    const tag=match[2].trim(),condition=tag.match(/^if\s*\(([\s\S]*)\)\s*:\s*$/);
    if(condition){const key=id+'-value-'+serial++;php.push(`schoolPageValue(${JSON.stringify(key)},(${condition[1]})?'true':'false');`,tag);js+=`if(JSON.parse(config.values[${JSON.stringify(key)}])){`;}
    else if(/^endif\s*;?$/.test(tag)){php.push(tag);js+='}';}
    else if(/^else\s*:$/.test(tag)){php.push(tag);js+='}else{';}
    else throw new Error('Review unsupported PHP script block in '+file+': '+tag);
   }
  }
  js+=code.slice(last);return{js,values};
 }
 source=source.replace(/<script\b((?:<\?[\s\S]*?\?>|[^>])*?)>([\s\S]*?)<\/script>/gi,(whole,attrs,body)=>{
  if(/application\/json/i.test(attrs))return whole;
  if(/\bsrc\s*=/i.test(attrs)){
   const library=/tinymce@6/.test(attrs)?'tinymce':/mathjax@3/.test(attrs)?'mathjax':/tesseract\.js@5/.test(attrs)?'tesseract':/pdf\.js\/3\.4\.120/.test(attrs)?'pdfjs':null;
   if(library)return '<?php $pageAssets[]='+JSON.stringify(library)+'; ?>';
   if(/\/assets\/js\/(question-editor|exam-question-language|curriculum-delete)\.js/.test(attrs))return '';
   const name=attrs.match(/\/assets\/js\/([a-z-]+)\.js/)?.[1];
   if(external.includes(name))return '<?php schoolPageModule("external-'+name+'"); ?>';
   throw new Error('Review unbundled page script: '+file+' '+attrs);
  }
  if(/^\s*window\.MathJax\s*=/.test(body))return '<?php $pageAssets[]="mathjax"; ?>';
  const start=php.length;const {js}=compile(body);scripts.push(js);
  return '<?php schoolPageModule('+JSON.stringify(id)+');'+php.splice(start).join('\n')+' ?>';
 });
 const handlers=[];
 // PHP tags are atomic alternatives so their own quotes cannot close an attribute.
 source=source.replace(/\bon(click|change|input|submit|keyup|focus)\s*=\s*(["'])((?:<\?[\s\S]*?\?>|(?!\2)[\s\S])*?)\2/gi,(whole,event,quote,body)=>{
  const placeholders=[];body=body.replace(/<\?[\s\S]*?\?>/g,tag=>{placeholders.push(tag);return '__ERP_PHP_'+(placeholders.length-1)+'__';});
  body=decode(body).replace(/__ERP_PHP_(\d+)__/g,(_,index)=>placeholders[index]);
  const {js,values}=compile(body,true),key=id+'-action-'+handlers.length;handlers.push([key,js]);
  return 'data-erp-'+event.toLowerCase()+'="'+key+'" data-erp-'+event.toLowerCase()+'-args="<?= schoolPageActionArgs('+JSON.stringify(id)+',['+values.join(',')+']) ?>"';
 });
 if(!scripts.length&&!handlers.length){if(source!==original)await writeFile(file,source);continue;}
 const declarations=new Set([...scripts.join('\n').matchAll(/\bfunction\s+([a-zA-Z_$][\w$]*)\s*\(/g)].map(match=>match[1]));
 const globals=new Set(['confirm','alert','verifyPasswordAndProceed','confirmDelete','JSON','Number','String','parseInt','parseFloat','isNaN','Math','Date']);
 const actions=handlers.map(([key,code])=>{
  code=code.replace(/(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(/g,(call,name)=>declarations.has(name)||globals.has(name)||['if','function','return'].includes(name)?call:'scope.actions.'+call);
  return JSON.stringify(key)+':function(event,args){'+code+'}';
 });
 const exposed=id==='shared-enrollment-selectors'?['loadSections']:id==='shared-exam-date-controls'?['toggleExamCalendar']:id==='shared-report-issue-controls'?['toggleReportIssueDate','changeIssueCalendar']:[];
 const output=`// Mechanically extracted from ${file}; PHP values are inert page data.\nexport default function(scope,config){\nconst {${bindings}}=scope;\n${scripts.join('\n')}\n${exposed.length?'scope.expose({'+exposed.join(',')+'});':''}\nscope.bindActions({${actions.join(',\n')}});\n}\n`;
 await transform(output,{loader:'js',target:'es2020'}); // Syntax gate before touching templates.
 await writeFile('frontend/pages/generated/'+id+'.js',output);
 await writeFile(file,source);manifest[id]='./generated/'+id+'.js';console.log(file+' -> '+id);
}
for(const name of external){const id='external-'+name,code=await readFile('assets/js/'+name+'.js','utf8'),output=`// Bundled from assets/js/${name}.js; uses only this page's scope.\nexport default function(scope,config){\nconst {${bindings}}=scope;\nconst module=undefined; // Browser-only build; retain upstream CommonJS unit-test exports.\n${code}\n${name==='paper-chapters'?'scope.expose({renderPaperChapters:window.renderPaperChapters});':''}\n}\n`;await transform(output,{loader:'js',target:'es2020'});await writeFile('frontend/pages/generated/'+id+'.js',output);manifest[id]='./generated/'+id+'.js';}
const imports=Object.entries(manifest).map(([id,path],index)=>`import m${index} from ${JSON.stringify(path)};`);
for(const id of Object.keys(manifest)){
 const file='frontend/pages/generated/'+id+'.js';let code=await readFile(file,'utf8');
 code=code.replace('ErpUI,Swal,Option,navigator,Blob,URL,Event,confirm,alert,verifyPasswordAndProceed,confirmDelete}=scope;','ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI}=scope;');
 code=code.replace(/[\t ]+$/gm,'');
 await transform(code,{loader:'js',target:'es2020'});await writeFile(file,code);
}
for(const[file]of files){const before=await readFile(file,'utf8'),after=before.replace(/(data-erp-[^\r\n]*?)[\t ]+(?=\r?$)/gm,'$1');if(after!==before)await writeFile(file,after);}
await writeFile('frontend/pages/generated-registry.js',imports.join('\n')+'\nexport default {'+Object.keys(manifest).map((id,index)=>JSON.stringify(id)+':m'+index).join(',')+'};\n');
