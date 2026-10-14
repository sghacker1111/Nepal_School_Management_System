'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const read=path=>fs.readFileSync(path,'utf8');
for(const path of ['assets/css/style.css','assets/css/workspace.css']){
 const css=read(path);assert.match(css,/--erp-kokila-size:\s*20pt/,'Shared Kokila default is 20 pt');
 assert.match(css,/font-size:\s*var\(--erp-kokila-size,\s*20pt\)\s*!important/,'Nepali typography cannot be shrunk by legacy inline styles');
}
const editor=read('assets/js/question-editor.js');
assert.match(editor,/body\.nepali-editor\s*\{[^}]*font-size:\s*20pt\s*!important/s);
assert.match(editor,/doc\.body\.style\.fontSize\s*=\s*"20pt"/);
assert(!editor.includes('font-size: 0.95rem;'),'Question previews no longer have a small Nepali override');
const practice=read('assets/css/mcq-practice.css');
assert.match(practice,/--practice-nepali-size:\s*28pt/,'Practice is larger than the editor default');
assert.match(practice,/--practice-option-size:\s*1\.3rem/,'English choices are enlarged');
assert.match(practice,/\.practice-nepali\{[^}]*font-weight:800[^}]*color:inherit/s,'Nepali questions match the English question weight and contrast');
assert.match(practice,/font-size:inherit!important/,'Imported small inline sizes cannot shrink practice text');
assert(!practice.includes('max-width:1000px'),'Fullscreen is not constrained to the old 1000px box');
assert.match(practice,/@keyframes practice-correct-pop/);assert.match(practice,/@keyframes practice-wrong-nudge/);
assert.match(practice,/@media\(prefers-reduced-motion:reduce\)\{\.mcq-practice \*\{animation:none!important;transition:none!important/);
assert.match(practice,/\.mcq-practice img\{max-width:100%/,'Images stay within the larger mobile choices');
assert.match(read('modules/learning/paper_generator.php'),/font-size:\s*var\(--erp-kokila-size,\s*20pt\)/);
assert(!read('modules/learning/index.php').includes('font-size:0.9rem; line-height: 1.6;'),'Study note text is enlarged too');
console.log('Learning readability passed: 20 pt Kokila, larger practice/notes, mobile content bounds and reduced-motion safeguards.');
