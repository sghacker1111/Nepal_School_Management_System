// Ship the same MIT TinyMCE 6 and MathJax 3 runtimes previously loaded from CDNs.
// Versioned directories keep iframe plugins/fonts in sync with their loader.
import {mkdir,cp,copyFile} from 'node:fs/promises';
import path from 'node:path';

export async function copyEditorAssets(root) {
    const output=path.join(root,'assets/vendor');
    const tiny=path.join(root,'node_modules/tinymce'),tinyOut=path.join(output,'tinymce-6.8.6');
    const math=path.join(root,'node_modules/mathjax'),mathOut=path.join(output,'mathjax-3.2.2');
    await mkdir(tinyOut,{recursive:true});await mkdir(mathOut,{recursive:true});
    for(const name of ['tinymce.min.js','license.txt','notices.txt'])await copyFile(path.join(tiny,name),path.join(tinyOut,name));
    for(const directory of ['icons','models','plugins','themes','skins']) {
        await cp(path.join(tiny,directory),path.join(tinyOut,directory),{recursive:true,filter:source=>!path.extname(source)||source.endsWith('.min.js')||source.endsWith('.min.css')});
    }
    // The CHTML bundle plus lazy TeX/menu/accessibility components and fonts.
    // Do not copy unused combined SVG/full/speech bundles or Node tooling.
    for(const name of ['tex-mml-chtml.js','core.js','loader.js','startup.js','a11y','adaptors','input','output','sre','ui'])await cp(path.join(math,'es5',name),path.join(mathOut,name),{recursive:true});
    await copyFile(path.join(math,'LICENSE'),path.join(mathOut,'LICENSE.txt'));
    const pdf=path.join(root,'node_modules/pdfjs-dist'),pdfOut=path.join(output,'pdfjs-6.4.299');
    await mkdir(pdfOut,{recursive:true});
    // The upstream compatibility build includes polyfills needed by older browsers.
    for(const name of ['pdf.min.mjs','pdf.worker.min.mjs'])await copyFile(path.join(pdf,'legacy','build',name),path.join(pdfOut,name));
    for(const name of ['cmaps','standard_fonts','wasm'])await cp(path.join(pdf,name),path.join(pdfOut,name),{recursive:true});
    await copyFile(path.join(pdf,'LICENSE'),path.join(pdfOut,'LICENSE.txt'));
}
