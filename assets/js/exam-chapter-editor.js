(function () {
    'use strict';
    const editor = document.getElementById('examChapterEditor');
    const summary = document.getElementById('examChapterTotals');
    if (!editor || !summary) return;
    function updateTotals() {
        let questions = 0, units = 0, chapters = 0, invalid = false;
        editor.querySelectorAll('[data-exam-chapter]').forEach(row => {
            const count = row.querySelector('[data-chapter-count]').value;
            if (!/^\d{1,3}$/.test(count) || Number(count) > 200) { invalid = true; return; }
            if (Number(count) === 0) return;
            questions += Number(count);
            chapters++;
            const marks = row.querySelector('[data-chapter-marks]').value;
            if (!/^\d+(?:\.\d{1,2})?$/.test(marks)) { invalid = true; return; }
            const parts = marks.split('.');
            const value = Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
            if (value <= 0 || value > 100000) invalid = true;
            units += value;
        });
        summary.textContent = invalid ? 'Enter valid counts and positive chapter marks for selected chapters.' :
            `${chapters} selected chapters · ${questions} questions · ${(units / 100).toFixed(2)} total marks`;
        summary.classList.toggle('text-danger', invalid || questions > 200 || chapters > 50 || units > 100000);
    }
    editor.addEventListener('input', updateTotals);
    updateTotals();
})();
