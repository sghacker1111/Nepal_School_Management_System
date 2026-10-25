import {editorInstances} from '../app/editor-instances.js';
import {initializeQuestionParts} from './question-parts.js';
import {initializeQuestionBulk} from './question-bulk.js';
export function initializeLearning(root,context){
 const win=root.ownerDocument.defaultView;
 initializeQuestionParts(root,context);
 initializeQuestionBulk(root,context);
 win.initExamQuestionLanguage?.(root,context);
 if(root.querySelector('.exam-rich')&&!root.querySelector('#examQuestionForm'))win.SchoolQuestionEditor?.init('.exam-rich',root,context);
 context.onBeforeSubmit?.(()=>{for(const editor of editorInstances(win))if(root.contains(editor.getElement?.()))editor.save();});
 const dirty=event=>{if(event.target.closest('form[method="post" i]')&&!event.target.name&&event.target.matches('input,select,textarea'))root.dispatchEvent(new win.Event('erp:dirty',{bubbles:true}));};
 root.addEventListener('input',dirty,{signal:context.signal});root.addEventListener('change',dirty,{signal:context.signal});
 root.addEventListener('click',event=>{const button=event.target.closest('button');if(button?.closest('#examGridRows,#examMixedEditor,#testPaperRows')||['examGridAdd','testPaperAdd'].includes(button?.id))root.dispatchEvent(new win.Event('erp:dirty',{bubbles:true}));},{signal:context.signal});
 const math=win.MathJax;
 if(math?.typesetPromise)Promise.resolve(math.startup?.promise).then(()=>{if(!context.signal.aborted)return math.typesetPromise([root]);}).then(()=>{if(!context.signal.aborted)win.fixDevanagariInMathJax?.(root);}).catch(()=>{});
}
