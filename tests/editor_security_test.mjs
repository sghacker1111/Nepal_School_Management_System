import test from 'node:test';
import assert from 'node:assert/strict';
import {JSDOM} from 'jsdom';
import {createEditorSanitizer} from '../frontend/app/editor-security.js';

test('editor rejects active and TinyMCE protected content before its parser',()=>{
    const dom=new JSDOM('<!doctype html>');
    try{
        const sanitize=createEditorSanitizer(dom.window);
        const payloads=[
            '<svg><a href="javascript:alert(1)">bad</a></svg>',
            '<object data="https://example.test/payload.svg"></object><embed src="https://example.test/payload.svg">',
            '<img src="x" data-mce-src="javascript:alert(1)" onerror="alert(2)">',
            '<span data-mce-object="iframe" data-mce-p-src="javascript:alert(1)">bad</span>',
            '<!--mce:protected %3Cscript%3Ealert(1)%3C/script%3E-->',
            '<div style="background:url(https://example.test/payload)">text</div>',
            '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>">',
        ];
        for(const payload of payloads){
            const output=sanitize(payload),root=dom.window.document.createElement('div');root.innerHTML=output;
            assert.equal(root.querySelector('svg,math,script,style,object,embed,iframe'),null,output);
            for(const node of root.querySelectorAll('*'))for(const attr of node.attributes){
                assert(!attr.name.startsWith('data-mce-')&&!attr.name.startsWith('on'),output);
                assert(!/javascript:|url\s*\(/i.test(attr.value),output);
            }
            assert(!output.includes('mce:protected'),output);
            assert.equal(sanitize(output),output,'Repeated parsing must remain safe');
        }
        const input='<p style="color: red"><strong>नेपाल English</strong></p><table><tbody><tr><td>Cell</td></tr></tbody></table><span class="math-tex" data-tex="x^2" data-mode="inline" contenteditable="false">\\(x^2\\)</span>';
        const clean=sanitize(input);
        assert.match(clean,/नेपाल English/);assert.match(clean,/<table>/);assert.match(clean,/color: red/);
        assert.match(clean,/data-tex="x\^2"/);assert.match(clean,/\\\(x\^2\\\)/);
    }finally{dom.window.close();}
});
