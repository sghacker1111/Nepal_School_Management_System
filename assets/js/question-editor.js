/* Shared Question Bank / Exam Questions editor: formatting and portable TeX. */
(function(){
'use strict';
let activeEditorId='',texModalObj,activeTexSpan=null,activeTexBookmark=null;
const editorScopes=new WeakMap();
function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function fixNepaliInLatex(tex) {
    if (!tex || typeof tex !== 'string') return tex;
    if (!/[\u0900-\u097F]/.test(tex)) return tex;

    return tex.replace(/(\\text\{[^{}]*\}|\\mbox\{[^{}]*\}|\\mathrm\{[^{}]*\}|\\mathbf\{[^{}]*\}|\\mathit\{[^{}]*\})|(\\[a-zA-Z]+)|([\u0900-\u097F]+(?:[ \t\u00A0]+[\u0900-\u097F]+)*)/g, function(match, textCmd, otherCmd, nepaliText) {
        if (textCmd) return textCmd;
        if (otherCmd) return otherCmd;
        if (nepaliText) return '\\text{' + nepaliText + '}';
        return match;
    });
}

function convertLatexToSpan(content) {
    if (!content) return content;
    const root=document.createElement('div');root.innerHTML=content;
    // Older HTML imports retained the widget class but the server sanitizer
    // removed data-tex. Recover the portable delimiters before ignoring widgets.
    root.querySelectorAll('span.math-tex').forEach(span=>{
        const source=mathSource(span);
        if(!source)return;
        span.setAttribute('data-tex',source.tex);span.setAttribute('data-mode',source.mode);
        span.setAttribute('contenteditable','false');
        span.textContent=(source.mode==='block'?'\\[':'\\(')+source.tex+(source.mode==='block'?'\\]':'\\)');
    });
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT),nodes=[];
    while(walker.nextNode())if(!walker.currentNode.parentElement.closest('.math-tex,script,style'))nodes.push(walker.currentNode);
    // Only text nodes are converted: never HTML attributes or existing widgets.
    // Every supported delimiter becomes a portable widget that saves back to TeX.
    for(const node of nodes){
        const pattern=/\\\(([\s\S]*?)\\\)|\\\[([\s\S]*?)\\\]|\$\$([\s\S]*?)\$\$|(?<!\\)\$(?!\$)([^$\r\n]+)(?<!\\)\$/g;
        const fragment=document.createDocumentFragment();let end=0,matched=false;
        for(const match of node.textContent.matchAll(pattern)){
            matched=true;fragment.append(document.createTextNode(node.textContent.slice(end,match.index)));
            const block=match[2]!==undefined||match[3]!==undefined,tex=fixNepaliInLatex(match[1]??match[2]??match[3]??match[4]);
            const span=document.createElement('span');span.className='math-tex';span.setAttribute('data-tex',tex);span.setAttribute('data-mode',block?'block':'inline');span.setAttribute('contenteditable','false');
            span.textContent=(block?'\\[':'\\(')+tex+(block?'\\]':'\\)');fragment.append(span);end=match.index+match[0].length;
        }
        if(matched){fragment.append(document.createTextNode(node.textContent.slice(end)));node.replaceWith(fragment);}
    }
    return root.innerHTML;
}

function unwrapMath(text, mode='inline') {
    text=String(text??'').trim();
    for(const [open,close,kind] of [['\\[','\\]','block'],['$$','$$','block'],['\\(','\\)','inline'],['$','$','inline']]){
        if(text.startsWith(open)&&text.endsWith(close)&&text.length>open.length+close.length)return {tex:text.slice(open.length,-close.length).trim(),mode:kind};
    }
    return text?{tex:text,mode:mode==='block'?'block':'inline'}:null;
}
function mathSource(span) {
    const stored=span.getAttribute('data-tex');
    if(stored&&stored.trim())return unwrapMath(stored,span.getAttribute('data-mode'));
    const carrier=span.querySelector('script[type^="math/tex"],annotation[encoding="application/x-tex"]');
    if(carrier)return unwrapMath(carrier.textContent,/display/.test(carrier.getAttribute('type')||'')?'block':'inline');
    // Never mistake rendered glyphs for TeX; only portable delimited text is safe.
    const text=span.textContent.trim();
    if(/^(?:\\\(|\\\[|\$)/.test(text))return unwrapMath(text,span.getAttribute('data-mode'));
    return null;
}

function init(selector,root=document,context={}){
 editorScopes.get(root)?.();
 const owned=new Set(),removers=[];let disposed=false;
 const listen=(node,event,handler)=>{node.addEventListener(event,handler);removers.push(()=>node.removeEventListener?.(event,handler));};
 const dispose=()=>{
  if(disposed)return;disposed=true;removers.splice(0).forEach(fn=>fn());
  owned.forEach(editor=>editor.remove?.());owned.clear();texModalObj?.dispose?.();
  activeEditorId='';activeTexSpan=null;activeTexBookmark=null;editorScopes.delete(root);
 };
 editorScopes.set(root,dispose);context.signal?.addEventListener('abort',dispose,{once:true});
 context.onBeforeSubmit?.(form=>{for(const editor of owned)if(editor.getElement?.()?.form===form)editor.save();});
 const start=()=>{
    if(disposed)return;

    // Initialize TeX Modal
    const modal=document.getElementById('texModal');if(!modal)return;
    texModalObj = ErpUI.Modal.getOrCreateInstance?ErpUI.Modal.getOrCreateInstance(modal):new ErpUI.Modal(modal);

    // Clear activeTexSpan when modal hidden
    listen(modal,'hidden.erp.modal', function () {
        activeTexSpan = null;activeTexBookmark=null;
    });

    // Event listeners for live MathJax TeX preview
    listen(document.getElementById('texInput'),'input',updateTexPreview);
    listen(document.getElementById('texDisplayMode'),'change',updateTexPreview);
    listen(document.getElementById('btnInsertTex'),'click',insertTexIntoEditor);

    // Initialize TinyMCE editors
    if (window.tinymce) {
        window.tinymce.init({
            selector:root===document?selector:'#'+CSS.escape(root.id)+' '+selector.split(',').join(',#'+CSS.escape(root.id)+' '),
            height: 420,
            min_height: 380,
            toolbar_mode: 'wrap',
            font_family_formats: 'Arial=arial,helvetica,sans-serif; Kokila=Kokila,Noto Sans Devanagari,Kalimati,sans-serif; Courier New=courier new,courier; Georgia=georgia,palatino; Tahoma=tahoma,arial,helvetica,sans-serif; Times New Roman=times new roman,times; Trebuchet MS=trebuchet ms,geneva; Verdana=verdana,geneva',
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime table wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | math_tex | fullscreen code',
            branding: false,
            promotion: false,
            extended_valid_elements: 'span[class|style|lang|title|contenteditable|data-tex|data-mode]',
            invalid_elements: 'script,style,svg,math,iframe,object,embed,video,audio,source,form,input,button,textarea,select,meta,link,base',
            // Styling inside editor iframe to support Arial for English, Kokila for Nepali, and locked TeX equations
            content_style: `
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila'), url('${window.APP_URL}/assets/fonts/kokila.ttf') format('truetype');
                    font-weight: normal;
                    font-style: normal;
                }
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila Bold'), url('${window.APP_URL}/assets/fonts/kokilab.ttf') format('truetype');
                    font-weight: bold;
                    font-style: normal;
                }
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila Italic'), url('${window.APP_URL}/assets/fonts/kokilai.ttf') format('truetype');
                    font-weight: normal;
                    font-style: italic;
                }
                @font-face {
                    font-family: 'Kokila';
                    src: local('Kokila Bold Italic'), url('${window.APP_URL}/assets/fonts/kokilabi.ttf') format('truetype');
                    font-weight: bold;
                    font-style: italic;
                }
                body {
                    font-family: Arial, Helvetica, sans-serif;
                    font-size: 18px;
                }
                body.nepali-editor {
                    font-family: 'Kokila', 'Noto Sans Devanagari', 'Kalimati', sans-serif !important;
                    font-size: 20pt !important;
                    line-height: 1.55;
                }
                body.english-editor {
                    font-family: Arial, Helvetica, sans-serif !important;
                    font-size: 18px !important;
                }
                span.math-tex {
                    background: transparent !important;
                    border: none !important;
                    cursor: pointer !important;
                    display: inline-block !important;
                    user-select: none !important;
                    margin: 0 2px !important;
                }
                span.math-tex:hover, span.math-tex:focus, span.math-tex[data-mce-selected] {
                    outline: 1px dashed #0d6efd !important;
                    background: rgba(13, 110, 253, 0.05) !important;
                    border-radius: 2px !important;
                }
                mjx-utext, mjx-mtext, .MathJax mtext, mjx-c {
                    font-family: 'Kokila', 'Noto Sans Devanagari', 'Segoe UI', 'Kalimati', sans-serif !important;
                    font-style: normal !important;
                }
            `,
            setup: function (editor) {
                if(disposed){editor.remove?.();return;}owned.add(editor);
                // When content is loaded, wrap LaTeX with span so it can render inside iframe
                editor.on('BeforeSetContent', function (e) {
                    if(!window.SchoolEditorSecurity?.sanitize)throw new Error('Editor security could not load. Reload the page.');
                    e.content = convertLatexToSpan(window.SchoolEditorSecurity.sanitize(e.content));
                });
                editor.on('PastePreProcess', function (e) {
                    if(!window.SchoolEditorSecurity?.sanitize)throw new Error('Editor security could not load. Reload the page.');
                    e.content = window.SchoolEditorSecurity.sanitize(e.content);
                });

                // When retrieving content, strip MathJax HTML markup and restore clean LaTeX tags
                editor.on('PostProcess', function (e) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = e.content;
                    const spans = tempDiv.querySelectorAll('span.math-tex');
                    spans.forEach(span => {
                        const source=mathSource(span);if(!source)return;
                        const {tex,mode}=source;
                        const replacement = (mode === 'inline') ? '\\(' + tex + '\\)' : '\\[' + tex + '\\]';
                        span.replaceWith(document.createTextNode(replacement));
                    });
                    e.content = tempDiv.innerHTML;
                });

                // Load MathJax inside the editor iframe dynamically and configure English/Nepali base font
                editor.on('init', function () {
                    if(disposed){editor.remove?.();return;}
                    const doc = editor.getDoc();
                    const win = editor.getWin();

                    // Set default font: English -> Arial, Nepali -> Kokila
                    if (editor.id.endsWith('Np') || editor.getElement()?.classList.contains('font-nepali') || /_np$/.test(editor.getElement()?.name || '')) {
                        doc.body.classList.add('nepali-editor');
                        doc.body.style.fontFamily = "'Kokila', 'Noto Sans Devanagari', 'Kalimati', sans-serif";
                        doc.body.style.fontSize = "20pt";
                    } else {
                        doc.body.classList.add('english-editor');
                        doc.body.style.fontFamily = "Arial, Helvetica, sans-serif";
                        doc.body.style.fontSize = "18px";
                    }

                    // Repair the iframe after typesetting (below and on SetContent).
                    // A parent-window MutationObserver can reject TinyMCE's body;
                    // editor events already cover all content changes here.

                    const configScript = doc.createElement('script');
                    configScript.text = `
                        window.MathJax = {
                            loader: {load: ['ui/safe']},
                            options: {safeOptions: {allow: {URLs: 'none', classes: 'safe', cssIDs: 'none', styles: 'safe'}}},
                            tex: {
                                inlineMath: [['\\\\(', '\\\\)'], ['$', '$']],
                                displayMath: [['\\\\[', '\\\\]'], ['$$', '$$']],
                                processEscapes: true
                            },
                            startup: {
                                typeset: false
                            }
                        };
                    `;
                    doc.head.appendChild(configScript);

                    const script = doc.createElement('script');
                    script.src = (window.APP_URL || window.location.origin).replace(/\/$/, '') + '/assets/vendor/mathjax-3.2.2/tex-mml-chtml.js';
                    script.async = true;
                    script.onload = function() {
                        if (win.MathJax && win.MathJax.typesetPromise) {
                            win.MathJax.typesetPromise().then(() => {
                                if (typeof fixDevanagariInMathJax === 'function') {
                                    fixDevanagariInMathJax(doc);
                                }
                            });
                        }
                    };
                    doc.head.appendChild(script);
                });

                // Imported content must also render when reopened, not just after typing.
                function typesetEditorMath() {
                    const win = editor.getWin();
                    if (win.MathJax && win.MathJax.typesetPromise) {
                        (win.MathJax.startup?.promise || Promise.resolve()).then(() => win.MathJax.typesetPromise()).then(() => {
                            if (typeof fixDevanagariInMathJax === 'function') {
                                fixDevanagariInMathJax(editor.getDoc());
                            }
                        }).catch(() => {});
                    }
                }
                editor.on('SetContent', typesetEditorMath);
                editor.on('change keyup', function () {
                    editor.save(); // Sync back to textarea
                    typesetEditorMath();
                });

                // Double click formula widget to trigger TeX editing modal
                editor.on('dblclick', function (e) {
                    const target = (e.target.nodeType===3?e.target.parentElement:e.target)?.closest('span.math-tex');
                    if (target) {
                        e.preventDefault();
                        e.stopPropagation();
                        openTexModal(editor.id, target);
                    }
                });

                // Add TeX modal trigger button
                editor.ui.registry.addButton('math_tex', {
                    text: 'TeX',
                    tooltip: 'Insert Mathematics TeX',
                    onAction: function () {
                        openTexModal(editor.id);
                    }
                });
            }
        });
    }
 };
 if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',start,{once:true});removers.push(()=>document.removeEventListener?.('DOMContentLoaded',start));}else start();
 return dispose;
}
function openTexModal(editorId, targetSpan = null) {
    activeEditorId = editorId;
    const editor=window.tinymce?.get(editorId);
    const body=editor?.getBody?.(),range=editor?.selection.getRng?.();
    if(body&&(!range||!body.contains(range.startContainer))){editor.selection.select(body,true);editor.selection.collapse(false);}
    activeTexBookmark=editor?.selection.getBookmark?.(2,true)||null;
    activeTexSpan = targetSpan || editor?.selection.getNode?.()?.closest?.('span.math-tex') || null;

    let initialText = '';
    let mode = 'inline';

    if (activeTexSpan) {
        const source=mathSource(activeTexSpan);
        if(!source){
            // Do not replace a legacy rendered-only equation with an empty one.
            editor?.notificationManager?.open({text:'This equation has no saved TeX source. Restore its original TeX using the HTML Source editor.',type:'warning'});
            activeTexSpan=null;return;
        }
        initialText = source.tex;
        mode = source.mode;
    } else {
        // Get text selection from active editor
        if (window.tinymce && window.tinymce.get(editorId)) {
            initialText = window.tinymce.get(editorId).selection.getContent({format: 'text'}).trim();
        }

        // Automatically strip LaTeX wrappers if selection already contains them
        if (initialText.startsWith('\\(') && initialText.endsWith('\\)')) {
            initialText = initialText.substring(2, initialText.length - 2).trim();
            mode = 'inline';
        } else if (initialText.startsWith('\\[') && initialText.endsWith('\\]')) {
            initialText = initialText.substring(2, initialText.length - 2).trim();
            mode = 'block';
        } else if (initialText.startsWith('$$') && initialText.endsWith('$$')) {
            initialText = initialText.substring(2, initialText.length - 2).trim();
            mode = 'block';
        } else if (initialText.startsWith('$') && initialText.endsWith('$')) {
            initialText = initialText.substring(1, initialText.length - 1).trim();
            mode = 'inline';
        }
    }

    document.getElementById('texInput').value = initialText;
    document.getElementById('texDisplayMode').value = mode;
    updateTexPreview();

    if (texModalObj) {
        texModalObj.show();
    }
}

function updateTexPreview() {
    let input = document.getElementById('texInput').value.trim();
    const preview = document.getElementById('texPreview');
    const mode = document.getElementById('texDisplayMode').value;

    if (input === '') {
        preview.innerHTML = '<span class="text-muted small">No TeX code entered yet</span>';
        return;
    }

    // Automatically wrap any Nepali / Devanagari text in \text{...} for seamless MathJax rendering
    const processedInput = fixNepaliInLatex(input);

    // Wrap according to selected mode
    if (mode === 'inline') {
        preview.textContent = '\\(' + processedInput + '\\)';
    } else {
        preview.textContent = '\\[' + processedInput + '\\]';
    }

    // Trigger MathJax render
    if (window.MathJax && window.MathJax.typesetPromise) {
        MathJax.typesetPromise([preview]).then(() => {
            if (typeof fixDevanagariInMathJax === 'function') {
                fixDevanagariInMathJax(preview);
            }
        });
    }
}

function insertTexIntoEditor() {
    let input = document.getElementById('texInput').value.trim();
    const mode = document.getElementById('texDisplayMode').value;

    if (input !== '' && activeEditorId !== '') {
        // Automatically wrap any Nepali / Devanagari text in \text{...} so valid LaTeX is saved
        input = fixNepaliInLatex(input);
        const escapedInput = escapeHtml(input);

        if (window.tinymce && window.tinymce.get(activeEditorId)) {
            const editor = window.tinymce.get(activeEditorId);
            // A toolbar dialog moves focus out of the iframe. Restore the
            // editor selection before inserting, including on a brand-new field.
            editor.focus?.();
            if(activeTexBookmark)editor.selection.moveToBookmark?.(activeTexBookmark);

            if (activeTexSpan) {
                // Update existing locked math span
                editor.getWin().MathJax?.typesetClear?.([activeTexSpan]);
                const update=()=>{
                    activeTexSpan.setAttribute('data-tex', input);
                    activeTexSpan.setAttribute('data-mode', mode);
                    if (mode === 'inline') {
                        activeTexSpan.textContent = '\\(' + input + '\\)';
                    } else {
                        activeTexSpan.textContent = '\\[' + input + '\\]';
                    }
                };
                if(editor.undoManager)editor.undoManager.transact(update);else update();
                editor.nodeChanged?.();editor.setDirty?.(true);
                editor.save();

                // Trigger MathJax typeset inside editor document
                const win = editor.getWin();
                if (win.MathJax && win.MathJax.typesetPromise) {
                    win.MathJax.typesetPromise().then(() => {
                        if (typeof fixDevanagariInMathJax === 'function') {
                            fixDevanagariInMathJax(editor.getDoc());
                        }
                    });
                }
            } else {
                // Insert a new locked math span
                let html = '';
                if (mode === 'inline') {
                    html = '<span class="math-tex" contenteditable="false" data-tex="' + escapedInput + '" data-mode="inline">\\(' + escapedInput + '\\)</span>';
                } else {
                    html = '<span class="math-tex" contenteditable="false" data-tex="' + escapedInput + '" data-mode="block">\\[' + escapedInput + '\\]</span>';
                }
                editor.insertContent(html);
                editor.save();

                // Trigger MathJax typeset inside editor document
                const win = editor.getWin();
                if (win.MathJax && win.MathJax.typesetPromise) {
                    win.MathJax.typesetPromise().then(() => {
                        if (typeof fixDevanagariInMathJax === 'function') {
                            fixDevanagariInMathJax(editor.getDoc());
                        }
                    });
                }
            }
        }
    }

    // Reset reference
    activeTexSpan = null;

    if (texModalObj) {
        texModalObj.hide();
    }
}

window.SchoolQuestionEditor={init};
})();
