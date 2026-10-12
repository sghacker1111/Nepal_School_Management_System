(function () {
    'use strict';
    const form = document.querySelector('[data-routine-editor]');
    if (!form) return;
    function refresh() {
        const manual = form.querySelector('[name="mode"]').value === 'manual';
        form.querySelectorAll('.routine-manual').forEach(cell => { cell.hidden = !manual; });
        form.querySelectorAll('.routine-day').forEach(day => {
            const count = Number(day.querySelector('.routine-count').value);
            day.querySelectorAll('[data-period]').forEach(row => {
                const enabled = Number(row.dataset.period) <= count;
                row.hidden = !enabled;
                row.querySelectorAll('input,select').forEach(input => {
                    input.disabled = !enabled || (!manual && input.tagName === 'SELECT');
                });
                const subject = row.querySelector('.routine-subject');
                const teacher = row.querySelector('.routine-teacher');
                let ids = [];
                try { ids = JSON.parse(subject.selectedOptions[0]?.dataset.teachers || '[]').map(String); } catch (_) { /* server validates */ }
                Array.from(teacher.options).forEach(option => {
                    option.hidden = Boolean(option.value) && !ids.includes(option.value);
                });
                // Do not silently erase loaded legacy selections; invalid assignments are rejected on save.
            });
        });
    }
    form.addEventListener('change', event => {
        if (event.target.matches('.routine-subject')) {
            const row = event.target.closest('[data-period]');
            const teacher = row.querySelector('.routine-teacher');
            let ids = [];
            try { ids = JSON.parse(event.target.selectedOptions[0]?.dataset.teachers || '[]').map(String); } catch (_) { /* server validates */ }
            if (!ids.includes(teacher.value)) teacher.value = ids.length === 1 && Array.from(teacher.options).some(option => option.value === ids[0]) ? ids[0] : '';
        }
        refresh();
    });
    form.addEventListener('submit', event => {
        if (event.submitter?.hasAttribute('data-confirm-activation') && !window.confirm('Activate this weekly routine? The previous timetable will be retained in history. Teacher time conflicts will cancel this change.')) event.preventDefault();
    });
    form.addEventListener('invalid', event => {
        const day = event.target.closest('.routine-day');
        if (day) day.open = true;
    }, true);
    refresh();
})();
