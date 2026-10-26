import {readPageMetadata, mountPage, disposePage} from './page-lifecycle.js';
import {confirmNavigation, hasUnsavedChanges} from './dirty-forms.js';
import {renderFeatureSearch} from './workspace-shell.js';
let app = null;
const fileKeys = ['print', 'download', 'export', 'template', 'student_lookup'];
const nativeActions = ['download_template', 'template', 'export', 'catalog', 'import_catalog'];

export function appRequest(htmx, doc, url, {signal} = {}) {
    return new Promise((resolve, reject) => {
        const source = doc.createElement('span'); source.hidden = true; source.dataset.erpTransport = ''; doc.body.append(source);
        const abort = () => htmx.trigger(source, 'htmx:abort'); signal?.addEventListener('abort', abort, {once: true});
        let result;
        const done = () => { signal?.removeEventListener('abort', abort); source.remove(); };
        Promise.resolve(htmx.ajax('GET', url, {source, target: '#main-content', swap: 'none',
            headers: {'HX-Request': 'true', 'HX-Target': 'main-content', Accept: 'text/html'},
            handler: (element, info) => { const xhr = info.xhr; result = {status: xhr.status, text: xhr.responseText, header: name => xhr.getResponseHeader(name)}; },
        })).then(() => { done(); result ? resolve(result) : reject(new Error('The page request did not complete.')); }, error => { done(); reject(error); });
    });
}

function sameOrigin(value, win) {
    try { const url = new URL(value, win.location.href); return /^https?:$/.test(url.protocol) && url.origin === win.location.origin ? url : null; } catch { return null; }
}

export function isEnhancedURL(value) {
    if (!app) return false;
    const url = sameOrigin(value, app.win); if (!url) return false;
    if (fileKeys.some(key => url.searchParams.has(key)) || nativeActions.includes(url.searchParams.get('action'))) return false;
    return app.routes.some(route => route.enhanced && [route.url,route.legacyUrl].filter(Boolean).some(path=>new URL(path, app.win.location.href).pathname === url.pathname));
}

export function recoverNavigation(message, url) {
    if (!app) return;
    const {doc, win} = app; doc.getElementById('erpRequestError')?.remove();
    const banner = doc.createElement('div'); banner.id = 'erpRequestError'; banner.className = 'erp-request-error'; banner.setAttribute('role', 'alert');
    banner.textContent = message + ' ';
    const link = doc.createElement('a'); link.href = sameOrigin(url, win)?.href || win.location.href; link.textContent = 'Open the full page'; banner.append(link); doc.body.append(banner);
}

export function cancelNavigation() {
    if(!app)return;app.sequence++;app.abort?.abort();app.root.removeAttribute('aria-busy');app.doc.getElementById('erpProgress')?.classList.remove('htmx-request');
}

function updateShell(metadata, state) {
    const {doc, win, root} = state;
    doc.title = metadata.title || 'School ERP'; win.CSRF_TOKEN = metadata.csrfToken || '';
    const token = doc.querySelector('meta[name="csrf-token"]'); if (token) token.content = win.CSRF_TOKEN;
    root.dataset.routeId = metadata.routeId; root.dataset.pageId = metadata.pageId;
    root.querySelectorAll('[name="csrf_token"]').forEach(field => field.value = win.CSRF_TOKEN);
    if (metadata.navigation) {
        const nav = doc.getElementById('sidebar'); nav?.querySelectorAll('.nav-group:not(#navigationFeatures)').forEach(node => node.remove());
        for (const [name, items] of Object.entries(metadata.navigation)) {
            if (!Array.isArray(items) || !nav) continue;
            const section = doc.createElement('section'); section.className = 'nav-group';
            const heading = doc.createElement('h2'); heading.textContent = name; const list = doc.createElement('ul'); list.className = 'nav flex-column';
            for (const item of items) {
                const url = sameOrigin(item.url, win); if (!url) continue;
                const row = doc.createElement('li'); row.className = 'nav-item'; const link = doc.createElement('a'); link.href = url.href; link.className = 'nav-link';
                if (item.key === metadata.activePage) { link.classList.add('active'); link.setAttribute('aria-current', 'page'); }
                if (/^[a-z0-9-]+$/.test(item.icon || '')) { const icon = doc.createElement('i'); icon.className = 'fa-solid fa-' + item.icon; icon.setAttribute('aria-hidden', 'true'); link.append(icon); }
                const label = doc.createElement('span'); label.textContent = item.label; link.append(label); row.append(link); list.append(row);
            }
            section.append(heading, list); nav.insertBefore(section, doc.getElementById('navigationFeatures'));
        }
    }
    renderFeatureSearch(doc, metadata.searchFeatures);
    if (metadata.shell) {
        const school = doc.querySelector('.workspace-heading strong'); if (school) school.textContent = metadata.shell.school;
        const year = doc.querySelector('.year-chip'); if (year) { year.hidden = !metadata.shell.year; year.textContent = metadata.shell.year ? `${metadata.shell.year.year_bs} BS · ${metadata.shell.year.year_ad} AD` : ''; }
        const name = doc.getElementById('erpAccountName'); if (name) name.textContent = metadata.shell.fullName;
    }
    const search = doc.getElementById('navigationSearch'); if (search) { search.value = ''; search.dispatchEvent(new win.Event('input', {bubbles: true})); }
    win.dispatchEvent(new win.Event('erp-navigated'));
}

function historyCommit(state, url, options) {
    const {win} = state;
    if (options.history !== 'none') {
        win.history.replaceState({...state.current, scroll: [win.scrollX, win.scrollY]}, '', state.current.url);
        state.current = {erp: true, index: options.history === 'replace' ? state.current.index : state.current.index + 1, url, scroll: [0, 0]};
        win.history[options.history === 'replace' ? 'replaceState' : 'pushState'](state.current, '', url);
    } else state.current = {erp: true, index: options.index ?? state.current.index, url, scroll: options.scroll || [0, 0]};
}

export async function applyPageResponse(response, url, options = {}) {
    if (!app) return false;
    const state = app, {win, doc, root} = state;
    if (options.requestId !== undefined && options.requestId !== state.sequence) return false;
    if (response.status === 401 && response.header('HX-Redirect')) {
        const login = sameOrigin(response.header('HX-Redirect'), win);
        if (login && login.pathname.endsWith('/login')) { await (state.deps.disposePage || disposePage)(); root.replaceChildren(); state.fullNavigate(login.href); return false; }
    }
    if (response.header('X-ERP-Fragment') !== 'app-page' || !/^text\/html(?:;|$)/i.test(response.header('Content-Type') || '')) throw new Error('The server did not return an app page.');
    if (![200, 403, 404, 419, 422, 500].includes(response.status)) throw new Error('The page could not be loaded.');
    const template = doc.createElement('template'); template.innerHTML = response.text;
    const metadata = readPageMetadata(template.content), canonical = sameOrigin(metadata.canonicalUrl || url, win);
    if (!canonical) throw new Error('Invalid canonical page URL.');
    if (metadata.contextVersion && metadata.contextVersion !== state.contextVersion) {
        await (state.deps.disposePage || disposePage)(); root.replaceChildren(); doc.querySelectorAll('#sidebar .nav-group').forEach(node => node.remove());
        const school = doc.querySelector('.workspace-heading strong'); if (school) school.textContent = '';
        state.fullNavigate(canonical.href); return false;
    }
    // Fragment scripts are never replayed. Required controllers are bundled.
    template.content.querySelectorAll('script:not([type="application/json"]),base,meta[http-equiv],iframe,object,embed').forEach(node => node.remove());
    for (const node of template.content.querySelectorAll('*')) for (const attr of [...node.attributes]) {
        if (/^on/i.test(attr.name) || (['href', 'src', 'action'].includes(attr.name) && /^\s*javascript:/i.test(attr.value))) node.removeAttribute(attr.name);
    }
    await (state.deps.disposePage || disposePage)();
    if (options.requestId !== undefined && options.requestId !== state.sequence) return false;
    root.replaceChildren(template.content); updateShell(metadata, state); historyCommit(state, canonical.href, {history: 'push', ...options});
    await (state.deps.mountPage || mountPage)(root, metadata); state.htmx.process?.(root);
    if (options.requestId !== undefined && options.requestId !== state.sequence) return false;
    if (options.focus !== 'retain') { const heading = root.querySelector('h1,h2'); if (heading) { heading.tabIndex = -1; heading.focus({preventScroll: true}); } }
    win.scrollTo(...(options.scroll || [0, 0]));
    const status = doc.getElementById('erpUpdateStatus'); if (status) status.textContent = metadata.title || 'Page updated.';
    return true;
}

export async function navigate(value, options = {}) {
    if (!app || !isEnhancedURL(value)) return false;
    const state = app, url = sameOrigin(value, state.win), id = ++state.sequence;
    if (!await (state.deps.confirmNavigation || confirmNavigation)() || id !== state.sequence) return false;
    const startedClean = !(state.deps.hasUnsavedChanges || hasUnsavedChanges)();
    const panel=state.doc.getElementById('questionBankPanel');if(panel)state.htmx.trigger?.(panel,'htmx:abort');
    state.abort?.abort(); const controller = new state.win.AbortController(); state.abort = controller;
    state.root.setAttribute('aria-busy', 'true'); state.doc.getElementById('erpProgress')?.classList.add('htmx-request'); state.doc.getElementById('erpRequestError')?.remove();
    try {
        let target = url.href, result;
        for (let hop = 0; hop < 6; hop++) {
            result = await (state.deps.request || ((path, init) => appRequest(state.htmx, state.doc, path, init)))(target, {signal: controller.signal});
            if (id !== state.sequence) return false;
            if (result.status !== 204 || !result.header('HX-Location')) break;
            const next = sameOrigin(JSON.parse(result.header('HX-Location')).path, state.win);
            if (!next || !isEnhancedURL(next.href)) throw new Error('The redirected page needs a full-page visit.'); target = next.href;
        }
        return await applyPageResponse(result, target, {...options, requestId: id});
    } catch (error) {
        state.win.console?.warn('Page navigation failed:', error);
        if (id === state.sequence && !controller.signal.aborted) {
            // Only GET navigation may fall back to a normal page. Never replay a
            // save, and never discard a draft typed while the request was loading.
            if (startedClean && !(state.deps.hasUnsavedChanges || hasUnsavedChanges)()) {
                state.fullNavigate(url.href); return true;
            }
            recoverNavigation('The page could not be loaded. Your unsaved work is still here.', url.href);
        }
        return false;
    } finally {
        if (id === state.sequence) { state.root.removeAttribute('aria-busy'); state.doc.getElementById('erpProgress')?.classList.remove('htmx-request'); }
    }
}

export function installNavigation(htmx, dependencies = {}) {
    const win = dependencies.window || window, doc = win.document, root = doc.getElementById('main-content');
    if (!root) return () => {};
    const metadata = readPageMetadata(root);
    const state = {win, doc, root, htmx, deps: dependencies, routes: dependencies.routes || metadata.routes || [], contextVersion: metadata.contextVersion, sequence: 0,
        current: {erp: true, index: 0, url: win.location.href, scroll: [win.scrollX, win.scrollY]}, fullNavigate: dependencies.fullNavigate || (url => win.location.assign(url))}; app = state;
    win.history.replaceState(state.current, '', state.current.url);
    Object.assign(htmx.config, {historyCacheSize: 0, refreshOnHistoryMiss: false, historyRestoreAsHxRequest: false, selfRequestsOnly: true, allowScriptTags: false, allowEval: false, includeIndicatorStyles: false, timeout: 20000});
    const click = event => {
        const link = event.target.closest?.('a[href]');
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || !link
            || link.target && link.target !== '_self' || link.hasAttribute('download') || link.getAttribute('href').startsWith('#')
            || link.closest('[data-untrusted-content],.exam-bank-rich,.learning-note-content,[hx-target="#questionBankPanel"]') || !isEnhancedURL(link.href)) return;
        event.preventDefault(); void navigate(link.href);
    };
    const pop = async event => {
        if (!event.state?.erp) return;
        event.stopImmediatePropagation();
        if (state.restoring) { state.restoring = false; return; }
        const previous = state.current, requested = event.state;
        const ok = await navigate(win.location.href, {history: 'none', index: requested.index, scroll: requested.scroll});
        if (!ok && app === state && win.location.href !== previous.url) { state.restoring = true; win.history.go(previous.index - requested.index); }
    };
    const panelHistory = event => {
        const url = sameOrigin(event.detail?.path, win); if (!url) return;
        state.current = {erp: true, index: state.current.index + 1, url: url.href, scroll: [win.scrollX, win.scrollY]}; win.history.replaceState(state.current, '', url.href);
    };
    doc.addEventListener('click', click); win.addEventListener('popstate', pop, true); doc.addEventListener('htmx:pushedIntoHistory', panelHistory);
    const dispose = () => { state.abort?.abort(); state.sequence++; doc.removeEventListener('click', click); win.removeEventListener('popstate', pop, true); doc.removeEventListener('htmx:pushedIntoHistory', panelHistory); if (app === state) app = null; };
    dispose.ready = isEnhancedURL(win.location.href) ? (dependencies.mountPage || mountPage)(root, metadata)
        : metadata.modules?.some(id=>id.startsWith('shared-')) ? (dependencies.mountPage || mountPage)(root,{...metadata,pageId:'compatibility'}) : Promise.resolve(); return dispose;
}
