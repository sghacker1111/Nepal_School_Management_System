'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync(require.resolve('../assets/js/main.js'),'utf8');
const start=source.indexOf('function fixDevanagariInMathJax(root)');
const end=source.indexOf('window.fixDevanagariInMathJax',start);
assert.ok(start>=0&&end>start,'Shared MathJax repair must exist');
const created=[];
const ownerDocument={createElement(tag){const node={tagName:tag,style:{},textContent:''};created.push(node);return node;}};
function mathNode(text){
    const node={tagName:'MJX-MI',ownerDocument,textContent:text,remove(){this.removed=true;},replaceChildren(child){this.children=[child];this.textContent=child.textContent;}};
    Object.defineProperty(node,'innerHTML',{set(){throw new Error('Untrusted math must not be parsed as HTML');}});
    return node;
}
const original='नेपाल <img src=x onerror=alert(1)>';
const first=mathNode(original);const second=mathNode(' गणित');
const container={attributes:{},getAttribute(name){return this.attributes[name]??null;},setAttribute(name,value){this.attributes[name]=value;},querySelectorAll(selector){return selector==='mjx-mtext'?[]:[{children:[first,second]}];}};
const root={querySelectorAll:()=>[container]};
const context={console:{error(message){throw new Error(message);}}};
vm.runInNewContext(source.slice(start,end),context);
context.fixDevanagariInMathJax(root);
assert.equal(first.children[0].tagName,'mjx-utext');
assert.equal(first.children[0].textContent,original+' गणित','Markup-looking text remains literal');
assert.equal(second.removed,true,'Nepali fragments merge');
assert.match(first.children[0].style.cssText,/Kokila/);
context.fixDevanagariInMathJax(root);
assert.equal(created.length,1,'Already-repaired math is not rebuilt');
console.log('MathJax content safety passed: literal markup, Nepali merging, font and idempotency.');
