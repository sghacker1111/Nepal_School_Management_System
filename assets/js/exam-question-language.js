(function(){
'use strict';
const wired=new WeakSet();
window.initExamQuestionLanguage=function(root=document,context={}){
const cleanups=[],listen=(node,type,fn)=>{node.addEventListener(type,fn);cleanups.push(()=>{node.removeEventListener?.(type,fn);wired.delete(node);});};
root.querySelectorAll('[data-exam-language-control]').forEach(control=>{if(wired.has(control))return;wired.add(control);listen(control,'change',()=>{
    if(context.signal?.aborted)return;
    if(!['both','both_en','en','ne'].includes(control.value))return;
    root.querySelectorAll('[data-exam-language-control]').forEach(other=>{other.value=control.value;});
    root.querySelectorAll('[data-exam-language-view]').forEach(view=>{view.hidden=view.dataset.examLanguageView!==control.value;});
});});
root.querySelectorAll('[data-exam-print]').forEach(button=>{if(wired.has(button))return;wired.add(button);listen(button,'click',async()=>{
    if(context.signal?.aborted)return;
    button.disabled=true;
    try{
        document.body.classList.toggle('print-exam-answers',button.dataset.examPrint==='answers');
        if(document.fonts?.ready)await document.fonts.ready;
        if(window.MathJax?.startup?.promise)await window.MathJax.startup.promise;
        if(window.MathJax?.typesetPromise)await window.MathJax.typesetPromise([root]);
        if(context.signal?.aborted)return;
        window.print();
    }catch(error){document.body.classList.remove('print-exam-answers');window.alert('The print preview could not be prepared. Please reload and try again.');}
    finally{button.disabled=false;}
});});
context.signal?.addEventListener('abort',()=>{cleanups.splice(0).forEach(fn=>fn());document.body.classList.remove('print-exam-answers');},{once:true});
};
window.initExamQuestionLanguage();
window.addEventListener('afterprint',()=>document.body.classList.remove('print-exam-answers'));
})();
