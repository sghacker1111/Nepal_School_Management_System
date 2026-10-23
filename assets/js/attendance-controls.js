'use strict';
function changeAttendanceCalendar(select) {
    const previous=select.dataset.previous, next=select.value, form=select.form;
    try {
        // Validate every conversion before changing any field: failed switches are atomic.
        const conversions=[];
        for(const name of ['date_input','range_start','range_end']) {
            const input=form.elements[name];
            if(input)conversions.push([input,schoolConvertDate(input.value,previous,next)]);
        }
        let focus;
        if(form.elements.year) {
            const date=form.elements.year.value+'-'+String(form.elements.month?.value||1).padStart(2,'0')+'-01';
            focus=schoolConvertDate(date,previous,next);
        }
        for(const [input,value] of conversions){input.type=next==='AD'?'date':'text';input.value=value;}
        if(focus){form.elements.year.value=focus.slice(0,4);if(form.elements.month)form.elements.month.value=String(Number(focus.slice(5,7)));}
        select.dataset.previous=next;
        form.submit();
    } catch(error) {select.value=previous;schoolNotify(error.message,'error');}
}
const attendanceScopes=new WeakMap();
window.initAttendanceControls=function(root=document,context={}){
    attendanceScopes.get(root)?.();
    const win=root.ownerDocument.defaultView,cleanups=[];
    const listen=(target,type,fn)=>{if(!target)return;target.addEventListener(type,fn,{signal:context.signal});cleanups.push(()=>target.removeEventListener(type,fn));};
    listen(root.querySelector('#attendanceCalendar'),'change',event=>changeAttendanceCalendar(event.target));
    listen(root.querySelector('#attendanceApply'),'click',()=>{
        const status=root.querySelector('#attendanceBulk')?.value;
        root.querySelectorAll('.attendance-cell:not(:disabled)').forEach(input=>{
            if(input.value===status)return;
            input.value=status;input.dispatchEvent(new win.Event('change',{bubbles:true}));
        });
    });
    const syncPrint=()=>root.querySelectorAll('.attendance-cell').forEach(input=>{
        const printed=input.parentElement.querySelector('.attendance-print-value');
        if(printed)printed.textContent=input.value||'—';
    });
    listen(root,'change',event=>{if(event.target.matches('.attendance-cell'))syncPrint();});
    context.onBeforeSubmit?.(form=>{if(form.id==='attendanceSheet')syncPrint();});
    const dispose=()=>{cleanups.splice(0).reverse().forEach(fn=>fn());attendanceScopes.delete(root);};
    attendanceScopes.set(root,dispose);context.signal?.addEventListener('abort',dispose,{once:true});return dispose;
};
