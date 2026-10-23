// Bundled from assets/js/exam-mixed-editor.js; uses only this page's scope.
export default function(scope,config){
const {document,window,$,fetch,setTimeout,clearTimeout,ErpUI,Swal,Option,navigator,Blob,URL,Event,FormData,confirm,alert,verifyPasswordAndProceed,confirmDelete,schoolConvertDate,CurriculumDeleteUI,tinymce,MathJax,pdfjsLib,Tesseract,FileReader,Image,NodeFilter,localStorage,URLSearchParams,fixDevanagariInMathJax,renderPaperChapters}=scope;
const module=undefined; // Browser-only build; retain upstream CommonJS unit-test exports.
(function () {
    'use strict';
    const types = ['MCQ', 'Very Short', 'Short', 'Long', 'Reasoning', 'Difference Between'];
    function summarize(rows) {
        let questions = 0, units = 0, active = 0, valid = Array.isArray(rows) && rows.length > 0 && rows.length <= 50, mcq = false, written = false;
        if (!Array.isArray(rows)) return {questions, units, active, valid: false};
        rows.forEach(row => {
            if (!row || !/^[1-9]\d*$/.test(String(row.chapter_id)) || !types.includes(row.type) || !/^\d{1,3}$/.test(String(row.count)) || Number(row.count) > 200) { valid = false; return; }
            if (Number(row.count) === 0) return;
            active++; questions += Number(row.count);
            const text = String(row.total_marks);
            if (!/^\d+(?:\.\d{1,2})?$/.test(text)) { valid = false; return; }
            const parts = text.split('.'), value = Number(parts[0]) * 100 + Number((parts[1] || '').padEnd(2, '0'));
            if (!Number.isSafeInteger(value) || value <= 0 || value > 100000) valid = false;
            units += value;
            if (row.type === 'MCQ') mcq = true; else written = true;
        });
        return {questions, units, active, valid: valid && mcq && written && questions <= 200 && units <= 100000};
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = {summarize};
    if (typeof document === 'undefined') return;
    const editor = document.getElementById('examMixedEditor');
    if (!editor) return;
    const data = window.examMixedEditorData, table = document.getElementById('examMixedRows'), field = document.getElementById('examMixedConfig'), duration = document.getElementById('chapterDuration'), kind = document.getElementById('chapterGenerationKind'), single = document.getElementById('examSingleKindEditor'), totals = document.getElementById('examMixedTotals');
    function readRows() {
        return Array.from(table.rows).map(row => Object.fromEntries(Array.from(row.querySelectorAll('[data-field]')).map(input => [input.dataset.field, input.value])));
    }
    function update() {
        const mixed = kind.value === 'mixed'; editor.hidden = !mixed; single.hidden = mixed; field.disabled = !mixed;
        single.querySelectorAll('input,select').forEach(input => { input.disabled = mixed; });
        table.querySelectorAll('input,select').forEach(input => { input.disabled = !mixed; });
        const rows = readRows(), result = summarize(rows);
        totals.textContent = `${result.active} selected rows · ${result.questions} questions · ${(result.units / 100).toFixed(2)} total marks` + (result.valid ? '' : ' — select both kinds; use valid counts and positive marks (max 200 questions / 1,000 marks).');
        totals.classList.toggle('text-danger', !result.valid);
        field.value = JSON.stringify({duration_minutes: duration.value, rows});
        document.getElementById('examMixedAdd').disabled = table.rows.length >= 50;
    }
    function addRow(values = {}) {
        if (table.rows.length >= 50) return;
        const row = table.insertRow();
        function select(key, options, value) {
            const input = document.createElement('select'); input.className = 'form-select'; input.dataset.field = key; input.setAttribute('aria-label', key === 'chapter_id' ? 'Allocation chapter' : 'Allocation question type');
            options.forEach(option => input.add(new Option(option.name, String(option.id))));
            input.value = String(value ?? options[0]?.id ?? ''); row.insertCell().append(input);
        }
        select('chapter_id', data.chapters, values.chapter_id); select('type', data.types.map(type => ({id: type, name: type})), values.type || 'MCQ');
        for (const [key, label, max, step] of [['count', 'Number of questions', 200, 1], ['total_marks', 'Row total marks', 1000, 0.01]]) {
            const input = document.createElement('input'); input.className = 'form-control'; input.type = 'number'; input.min = '0'; input.max = String(max); input.step = String(step); input.dataset.field = key; input.value = String(values[key] ?? 0); input.required = true; input.setAttribute('aria-label', label); row.insertCell().append(input);
        }
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline-danger btn-sm'; remove.textContent = 'Remove'; remove.addEventListener('click', () => { row.remove(); update(); }); row.insertCell().append(remove); update();
    }
    const initial = Array.isArray(data.initial?.rows) ? data.initial.rows.slice(0, 50) : [{type: 'MCQ'}, {type: 'Short'}];
    initial.forEach(addRow);
    editor.addEventListener('input', update); kind.addEventListener('change', update); duration.addEventListener('input', update); document.getElementById('examMixedAdd').addEventListener('click', () => addRow({type: 'Short'}));
    document.getElementById('examPaperForm').addEventListener('submit', update); update();
})();


}
