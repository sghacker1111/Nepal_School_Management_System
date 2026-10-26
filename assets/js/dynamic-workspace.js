/* Narrow panel compatibility plus the app's explicitly scoped GET transport. */
(() => {
    const sameOrigin = value => {
        try { const url = new URL(value, location.href); return /^https?:$/.test(url.protocol) && url.origin === location.origin; } catch { return false; }
    };
    const safeURL = value => sameOrigin(value) ? new URL(value, location.href).href : location.href;
    function recover(message, url) {
        document.getElementById('erpRequestError')?.remove();
        const banner = document.createElement('div'); banner.id = 'erpRequestError'; banner.className = 'erp-request-error'; banner.setAttribute('role', 'alert');
        const text = document.createElement('span'); text.textContent = message + ' ';
        const link = document.createElement('a'); link.href = safeURL(url); link.textContent = 'Open the full page';
        banner.append(text, link); document.body.append(banner);
    }
    function recoverRead(message, url) {
        if (!window.erpFormState?.hasUnsavedChanges()) { window.location.assign(safeURL(url)); return; }
        recover(message, url);
    }
    function install(htmx) {
        if (window.erpDynamicInstalled) return;
        window.erpDynamicInstalled = true;
        htmx.config.historyCacheSize = 0;
        htmx.config.refreshOnHistoryMiss = false;
        htmx.config.historyRestoreAsHxRequest = false;
        htmx.config.selfRequestsOnly = true;
        htmx.config.allowScriptTags = false;
        htmx.config.allowEval = false;
        htmx.config.includeIndicatorStyles = false;
        htmx.config.timeout = 20000;
        document.addEventListener('htmx:configRequest', event => {
            const detail = event.detail;
            if (!sameOrigin(detail.path) || String(detail.verb).toLowerCase() !== 'get') { event.preventDefault(); return; }
            detail.headers['X-CSRF-Token'] = window.CSRF_TOKEN || '';
        });
        document.addEventListener('htmx:beforeRequest', event => {
            const target = event.detail.target;
            const appTransport = target?.id === 'main-content' && event.detail.elt?.hasAttribute('data-erp-transport');
            if (target?.id !== 'questionBankPanel' && !appTransport) { event.preventDefault(); return; }
            if(target.id==='questionBankPanel'&&window.erpFormState?.hasUnsavedChanges()){
                event.preventDefault();recover('Finish or discard your unsaved changes before changing these filters.',event.detail.requestConfig?.path);return;
            }
            document.getElementById('erpRequestError')?.remove(); target.setAttribute('aria-busy', 'true');
        });
        document.addEventListener('htmx:beforeSwap', event => {
            const { xhr, target } = event.detail;
            if (target?.id !== 'questionBankPanel') return;
            const valid = xhr.status === 200 && xhr.getResponseHeader('X-ERP-Fragment') === 'question-list' && /^text\/html(?:;|$)/i.test(xhr.getResponseHeader('Content-Type') || '');
            let current;
            try{const marker=document.getElementById('main-content')?.firstElementChild;current=marker?.hasAttribute('data-erp-config')?JSON.parse(marker.getAttribute('data-erp-config')).contextVersion:null;}catch{}
            if(valid&&current&&xhr.getResponseHeader('X-ERP-Context')!==current){
                event.detail.shouldSwap=false;
                // An account/school switch in another tab invalidates the entire
                // private shell, even when this request targets only a list.
                document.getElementById('main-content').replaceChildren();document.querySelectorAll('#sidebar .nav-group').forEach(node=>node.remove());
                Promise.resolve(window.erpPages?.disposePage?.()).finally(()=>recover('Your account context changed. Open the full page to continue.',event.detail.requestConfig?.path));return;
            }
            if (!valid) {
                event.detail.shouldSwap = false; target.removeAttribute('aria-busy');
                recoverRead('The update could not be loaded. Your existing work has not changed.', event.detail.requestConfig?.path || xhr.responseURL);
            }
        });
        document.addEventListener('htmx:afterRequest', event => { if(event.detail.target?.id==='questionBankPanel')event.detail.target.removeAttribute('aria-busy'); });
        for (const name of ['htmx:sendError', 'htmx:timeout', 'htmx:swapError']) document.addEventListener(name, event => { if(event.detail.target?.id==='questionBankPanel')recoverRead('Connection interrupted. Try loading this page again.', event.detail.requestConfig?.path); });
        document.addEventListener('htmx:afterSwap', event => {
            if (event.detail.target?.id !== 'questionBankPanel') return;
            // outerHTML swaps can leave detail.target pointing to the removed
            // node. Initialize the live replacement, never the detached tree.
            const target = document.getElementById('questionBankPanel');
            if (!target) return;
            target.removeAttribute('aria-busy');
            target.querySelectorAll('label.form-label:not([for])').forEach((label, index) => {
                const control = label.parentElement.querySelector('input:not([type=hidden]),select,textarea');
                if (control) { if (!control.id) control.id = 'dynamic-field-' + index; label.htmlFor = control.id; }
            });
            if (window.MathJax?.typesetPromise) window.MathJax.typesetPromise([target]).catch(() => {});
            window.initExamQuestionLanguage?.(target);
            const notice = document.getElementById('erpUpdateStatus');
            if (notice) notice.textContent = target.querySelector('#examQuestionCount')?.textContent || 'Results updated.';
        });
    }
    window.erpDynamic = { install, sameOrigin, safeURL };
})();
