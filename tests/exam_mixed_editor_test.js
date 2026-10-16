'use strict';
const assert=require('node:assert/strict');
const {summarize}=require('../assets/js/exam-mixed-editor.js');
const row=(type,count,total_marks)=>({chapter_id:'1',type,count,total_marks});
assert.deepEqual(summarize([row('MCQ','1','0.10'),row('Short','2','0.30'),row('Long','0','900')]),{questions:3,units:40,active:2,valid:true});
for(const rows of [[row('MCQ',1,1)],[row('Short',1,1)],[row('MCQ','1.5',1),row('Short',1,1)],[row('MCQ',1,1),row('Short',200,1000)],[row('MCQ',1,'1.001'),row('Short',1,2)]])assert.equal(summarize(rows).valid,false);
console.log('Mixed editor passed: exact decimals, zero skips, both kinds and safe count/mark limits.');
