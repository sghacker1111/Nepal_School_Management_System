const assert=require('node:assert/strict');
const {renderPaperChapters}=require('../assets/js/paper-chapters.js');
global.Option=class {constructor(text,value){this.textContent=text;this.value=value;this.selected=false;}};
const field={children:[],replaceChildren(...rows){this.children=rows;},appendChild(row){this.children.push(row);}};
renderPaperChapters(field,[{id:7,name:"King's & Queens"},'Legacy chapter',{name:'Sets'},{name:'Sets'},null],"King's & Queens");
assert.deepEqual(field.children.map(x=>x.textContent),['All Chapters (Full Syllabus)',"King's & Queens",'Legacy chapter','Sets']);
assert.equal(field.children[1].value,"King's & Queens");assert.equal(field.children[1].selected,true);
assert.equal(field.children.some(x=>x.textContent==='[object Object]'),false);
console.log('Paper chapters passed: object/string catalog names, safe text, exact selection and duplicates.');
