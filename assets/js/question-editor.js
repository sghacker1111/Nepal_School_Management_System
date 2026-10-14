/* Shared Question Bank / Exam Questions editor: formatting and portable TeX. */
(function(){
'use strict';
let activeEditorId='',texModalObj,activeTexSpan=null;
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

function init(selector){
 const start=()=>{

    // Initialize TeX Modal
    texModalObj = new bootstrap.Modal(document.getElementById('texModal'));

    // Clear activeTexSpan when modal hidden
    document.getElementById('texModal').addEventListener('hidden.bs.modal', function () {
        activeTexSpan = null;
    });

    // Event listeners for live MathJax TeX preview
    document.getElementById('texInput').addEventListener('input', updateTexPreview);
    document.getElementById('texDisplayMode').addEventListener('change', updateTexPreview);
    document.getElementById('btnInsertTex').addEventListener('click', insertTexIntoEditor);

    // Initialize TinyMCE editors
    if (window.tinymce) {
        window.tinymce.init({
            selector,
            height: 420,
            min_height: 380,
            toolbar_mode: 'wrap',
            font_family_formats: 'Arial=arial,helvetica,sans-serif; Kokila=Kokila,Noto Sans Devanagari,Kalimati,sans-serif; Courier New=courier new,courier; Georgia=georgia,palatino; Tahoma=tahoma,arial,helvetica,sans-serif; Times New Roman=times new roman,times; Trebuchet MS=trebuchet ms,geneva; Verdana=verdana,geneva',
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table wordcount',
            toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | table | math_tex | fullscreen code',
            branding: false,
            promotion: false,
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
                // When content is loaded, wrap LaTeX with span so it can render inside iframe
                editor.on('BeforeSetContent', function (e) {
                    e.content = convertLatexToSpan(e.content);
                });

                // When retrieving content, strip MathJax HTML markup and restore clean LaTeX tags
                editor.on('PostProcess', function (e) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = e.content;
                    const spans = tempDiv.querySelectorAll('span.math-tex');
                    spans.forEach(span => {
                        const tex = span.getAttribute('data-tex');
                        const mode = span.getAttribute('data-mode') || 'inline';
                        const replacement = (mode === 'inline') ? '\\(' + tex + '\\)' : '\\[' + tex + '\\]';
                        span.replaceWith(document.createTextNode(replacement));
                    });
                    e.content = tempDiv.innerHTML;
                });

                // Load MathJax inside the editor iframe dynamically and configure English/Nepali base font
                editor.on('init', function () {
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
                    script.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js';
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
                    const target = e.target.closest('span.math-tex');
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
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
}
function openTexModal(editorId, targetSpan = null) {
    activeEditorId = editorId;
    activeTexSpan = targetSpan;

    let initialText = '';
    let mode = 'inline';

    if (activeTexSpan) {
        initialText = activeTexSpan.getAttribute('data-tex') || '';
        mode = activeTexSpan.getAttribute('data-mode') || 'inline';
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

            if (activeTexSpan) {
                // Update existing locked math span
                activeTexSpan.setAttribute('data-tex', input);
                activeTexSpan.setAttribute('data-mode', mode);
                if (mode === 'inline') {
                    activeTexSpan.textContent = '\\(' + input + '\\)';
                } else {
                    activeTexSpan.textContent = '\\[' + input + '\\]';
                }
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
