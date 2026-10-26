import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {JSDOM} from 'jsdom';
import {pageLibraries} from '../frontend/pages/page-libraries.js';

function samplePdf(){
    const text='BT /F1 18 Tf 40 140 Td (Synthetic school PDF) Tj ET';
    const objects=['<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        `<< /Length ${text.length} >>\nstream\n${text}\nendstream`];
    let body='%PDF-1.4\n';const offsets=[0];
    for(const [i,object] of objects.entries()){offsets.push(Buffer.byteLength(body));body+=`${i+1} 0 obj\n${object}\nendobj\n`;}
    const start=Buffer.byteLength(body);body+=`xref\n0 ${objects.length+1}\n0000000000 65535 f \n`;
    for(const offset of offsets.slice(1))body+=String(offset).padStart(10,'0')+' 00000 n \n';
    body+=`trailer\n<< /Size ${objects.length+1} /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`;
    return new Uint8Array(Buffer.from(body));
}

test('PDF adapter forces safe local-data options and destroys disposed loads',async()=>{
    const dom=new JSDOM('<main></main>',{url:'https://school.example/erp/learning/ocr-scanner'});
    try{
        const win=dom.window;win.APP_URL='https://school.example/erp';let options,destroyed=0;
        win.pdfjsLib={getDocument(input){options=input;return {promise:Promise.resolve({}),destroy:async()=>destroyed++};}};
        const controller=new win.AbortController(),cleanups=[];
        const lib=pageLibraries(win.document.querySelector('main'),{signal:controller.signal},cleanups).pdfjsLib;
        const data=samplePdf();await lib.getDocument({data,isEvalSupported:true,enableXfa:true}).promise;
        assert.equal(options.data,data);assert.equal(options.isEvalSupported,false);assert.equal(options.enableXfa,false);
        assert.equal(options.wasmUrl,'https://school.example/erp/assets/vendor/pdfjs-6.4.299/wasm/');
        assert.throws(()=>lib.getDocument('https://outside.example/private.pdf'),/local PDF/);
        for(const cleanup of cleanups)await cleanup();assert.equal(destroyed,1);
    }finally{dom.window.close();}
});

test('deployed PDF engine and matching worker parse and render a school PDF',async()=>{
    const source=readFileSync('assets/js/pdfjs-runtime.mjs','utf8');assert(!source.includes('https://'));
    assert.match(readFileSync('frontend/app/page-lifecycle.js','utf8'),/pdfjs: '\/assets\/js\/pdfjs-runtime\.mjs'/);
    for(const file of ['pdf.min.mjs','pdf.worker.min.mjs','LICENSE.txt'])assert(existsSync('assets/vendor/pdfjs-6.4.299/'+file));
    const pdf=await import('../assets/vendor/pdfjs-6.4.299/pdf.min.mjs');
    assert.equal(pdf.version,'6.4.299');
    pdf.GlobalWorkerOptions.workerSrc=new URL('../assets/vendor/pdfjs-6.4.299/pdf.worker.min.mjs',import.meta.url).href;
    const task=pdf.getDocument({data:samplePdf(),isEvalSupported:false,enableXfa:false,
        standardFontDataUrl:fileURLToPath(new URL('../assets/vendor/pdfjs-6.4.299/standard_fonts/',import.meta.url)).replace(/\\/g,'/')});
    try{
        const document=await task.promise;assert.equal(document.numPages,1);
        const page=await document.getPage(1),text=await page.getTextContent();
        assert(text.items.some(item=>item.str==='Synthetic school PDF'));
        const {createCanvas}=await import('@napi-rs/canvas');
        const viewport=page.getViewport({scale:1}),canvas=createCanvas(viewport.width,viewport.height);
        await page.render({canvasContext:canvas.getContext('2d'),viewport}).promise;
        assert(canvas.toBuffer('image/png').length>1000);
    }finally{await task.destroy();}
});
