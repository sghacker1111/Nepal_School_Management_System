import {trackForms} from './dirty-forms.js';
// Only bundled adapters and these fixed asset IDs can initialize an app page.
const pages = new Map();
const loads = new WeakMap();
const assets = Object.freeze({
    'attendance-css': '/assets/css/attendance.css',
    'ledger-css': '/assets/css/result-ledger.css',
    'exam-paper-css': '/assets/css/exam-paper.css',
    'question-language-css': '/assets/css/exam-question-language.css',
    'practice-css': '/assets/css/mcq-practice.css',
    'accounting-css': '/assets/css/accounting.css',
    tinymce: 'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js',
    mathjax: 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js',
    tesseract: 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
    pdfjs: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.4.120/pdf.min.js',
});
let active = null;

export function registerPage(id, init) {
    if (!/^[a-z][a-z0-9-]*$/.test(id) || typeof init !== 'function') throw new Error('Invalid page adapter.');
    if (pages.has(id)) throw new Error('Duplicate page adapter: ' + id);
    pages.set(id, init);
}

export function readPageMetadata(root) {
    // Never read a descendant from imported question/notes HTML as configuration.
    const marker = root.firstElementChild;
    if (!marker?.hidden || !marker.hasAttribute('data-erp-config')) throw new Error('Missing trusted page metadata.');
    const data = JSON.parse(marker.getAttribute('data-erp-config'));
    if (!data || typeof data.pageId !== 'string' || typeof data.routeId !== 'string') throw new Error('Invalid page metadata.');
    return data;
}

async function loadAsset(id, doc) {
    let cache = loads.get(doc); if (!cache) { cache = new Map(); loads.set(doc, cache); }
    if (cache.has(id)) return cache.get(id);
    const win = doc.defaultView, path = assets[id];
    if ((id === 'tinymce' && win.tinymce) || (id === 'mathjax' && win.MathJax?.typesetPromise)
        || (id === 'tesseract' && win.Tesseract) || (id === 'pdfjs' && win.pdfjsLib)) return;
    const url = path.startsWith('/') ? new URL((win.APP_URL || win.location.origin).replace(/\/$/, '') + path).href : path;
    const css = path.endsWith('.css');
    const existing = [...doc.querySelectorAll(css ? 'link[rel="stylesheet"]' : 'script[src]')].find(node => (css ? node.href : node.src).split('?')[0] === url);
    if (existing && css) { cache.set(id, Promise.resolve()); return; }
    const promise = new Promise((resolve, reject) => {
        const node = existing || doc.createElement(css ? 'link' : 'script');
        const timer = win.setTimeout(() => { cache.delete(id); reject(new Error('Required asset timed out: ' + id)); }, 20000);
        node.addEventListener('load', async () => {
            win.clearTimeout(timer);
            try { if (id === 'mathjax') await win.MathJax?.startup?.promise; resolve(); } catch (error) { reject(error); }
        }, {once: true});
        node.addEventListener('error', () => { win.clearTimeout(timer); cache.delete(id); if (!existing) node.remove(); reject(new Error('Required asset unavailable: ' + id)); }, {once: true});
        if (!existing) {
            node.dataset.erpAsset = id;
            if (css) { node.rel = 'stylesheet'; node.href = url; }
            else { node.src = url; node.async = true; }
            doc.head.append(node);
        }
    });
    cache.set(id, promise); return promise;
}

function disposeOwned(root) {
    const win = root.ownerDocument.defaultView;
    for (const editor of [...(win.tinymce?.editors || [])]) if (root.contains(editor.getElement?.())) editor.remove();
    const $ = win.jQuery;
    if ($?.fn?.dataTable) for (const table of root.querySelectorAll('table')) if ($.fn.dataTable.isDataTable(table)) $(table).DataTable().destroy();
    for (const type of ['Modal', 'Collapse', 'Toast', 'Tooltip', 'Popover']) {
        const component = win.ErpUI?.[type];
        if (component?.getInstance) for (const node of root.querySelectorAll('*')) component.getInstance(node)?.dispose();
    }
    win.MathJax?.typesetClear?.([root]);
}

export async function disposePage() {
    const old = active; if (!old) return;
    active = null; old.controller.abort(); old.hooks.clear();
    try { await old.dispose?.(); } finally { disposeOwned(old.root); }
}

export async function mountPage(root, metadata) {
    const init = pages.get(metadata?.pageId);
    if (!init) throw new Error('Missing page adapter: ' + metadata?.pageId);
    if (metadata.assets !== undefined && !Array.isArray(metadata.assets)) throw new Error('Invalid page assets.');
    for (const id of metadata.assets || []) if (!Object.hasOwn(assets, id)) throw new Error('Unknown page asset: ' + id);
    await disposePage();
    const controller = new root.ownerDocument.defaultView.AbortController();
    const entry = {root, controller, hooks: new Set(), dispose: null}; active = entry;
    const context = {signal: controller.signal, config: metadata,
        onBeforeSubmit(fn) { if (typeof fn !== 'function') throw new Error('Invalid submit hook.'); if (!controller.signal.aborted) entry.hooks.add(fn); }};
    try {
        await Promise.all((metadata.assets || []).map(id => loadAsset(id, root.ownerDocument)));
        if (active !== entry) return;
        const win = root.ownerDocument.defaultView;
        const shared = [win.initSchoolControls?.(root, context), win.initSchoolDates?.(root, context)];
        const dispose = await init(root, context);
        if(metadata.shell?.readOnly)root.querySelectorAll('form[method="post" i] input,form[method="post" i] select,form[method="post" i] textarea,form[method="post" i] button').forEach(field=>field.disabled=true);
        if (active === entry) shared.push(trackForms(root, context));
        const cleanup = async () => { for (const fn of [dispose, ...shared].reverse()) if (typeof fn === 'function') await fn(); };
        if (active !== entry) await cleanup(); else entry.dispose = cleanup;
    } catch (error) { if (active === entry) await disposePage(); throw error; }
}

export async function preparePageSubmit(form) {
    const current = active;
    if (!current || !current.root.contains(form)) return;
    for (const hook of current.hooks) { if (current.controller.signal.aborted) return; await hook(form); }
}
