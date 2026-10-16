(function(){
'use strict';
const wired=new WeakSet();
window.initExamQuestionLanguage=function(root=document){
root.querySelectorAll('[data-exam-language-control]').forEach(control=>{if(wired.has(control))return;wired.add(control);control.addEventListener('change',()=>{
    if(!['both','en','ne'].includes(control.value))return;
    document.querySelectorAll('[data-exam-language-control]').forEach(other=>{other.value=control.value;});
    document.querySelectorAll('[data-exam-language-view]').forEach(view=>{view.hidden=view.dataset.examLanguageView!==control.value;});
});});
root.querySelectorAll('[data-exam-print]').forEach(button=>{if(wired.has(button))return;wired.add(button);button.addEventListener('click',async()=>{
    button.disabled=true;
    try{
        document.body.classList.toggle('print-exam-answers',button.dataset.examPrint==='answers');
        if(document.fonts?.ready)await document.fonts.ready;
        if(window.MathJax?.startup?.promise)await window.MathJax.startup.promise;
        if(window.MathJax?.typesetPromise)await window.MathJax.typesetPromise();
        window.print();
    }catch(error){document.body.classList.remove('print-exam-answers');window.alert('The print preview could not be prepared. Please reload and try again.');}
    finally{button.disabled=false;}
});});
};
window.initExamQuestionLanguage();
window.addEventListener('afterprint',()=>document.body.classList.remove('print-exam-answers'));
})();
