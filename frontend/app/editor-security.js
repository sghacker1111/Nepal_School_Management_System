import createDOMPurify from 'dompurify';

// This separate parser runs before the retained TinyMCE 6 parser. It reduces
// known attack paths; it does not replace upstream security updates.
export function createEditorSanitizer(win) {
const purifier = createDOMPurify(win);
purifier.addHook('uponSanitizeAttribute', (_node, attribute) => {
    const name = attribute.attrName.toLowerCase();
    if (name.startsWith('data-mce-') || (name === 'style' && /url\s*\(|expression\s*\(|@import|behavior\s*:|-moz-binding/i.test(attribute.attrValue))) {
        attribute.keepAttr = false;
    }
});

return function sanitizeEditorHtml(html) {
    return purifier.sanitize(String(html ?? ''), {
        USE_PROFILES: {html: true},
        ALLOW_DATA_ATTR: false,
        ADD_ATTR: ['data-tex', 'data-mode', 'contenteditable'],
        FORBID_TAGS: ['style', 'svg', 'math', 'iframe', 'object', 'embed', 'video', 'audio', 'source', 'template', 'form', 'input', 'button', 'textarea', 'select', 'option', 'meta', 'link', 'base'],
        FORBID_ATTR: ['id', 'name', 'srcdoc', 'formaction', 'xlink:href'],
    });
};
}

if (typeof window !== 'undefined') window.SchoolEditorSecurity = {sanitize: createEditorSanitizer(window)};
