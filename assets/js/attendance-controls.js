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
        form.submit();
    } catch(error) {select.value=previous;schoolNotify(error.message,'error');}
}
