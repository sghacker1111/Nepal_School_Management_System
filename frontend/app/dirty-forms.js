// Drafts live in memory only. A network failure is not proof a write failed.
const scopes = new Set();
const formStates = new WeakMap();

export function formSnapshot(form) {
    const win = form.ownerDocument.defaultView;
    return JSON.stringify([...form.elements].filter(field => !['submit', 'button', 'reset','fieldset','output'].includes(field.type) && field.name !== 'csrf_token' && !field.closest('.tox,[data-ignore-dirty]')).map((field,index) => {
        const key=field.name||'@'+(field.id||index);
        if (field.type === 'file') return [key, 'files', [...(field.files || [])].map(file => [file.name, file.size, file.type, file.lastModified])];
        if (field.type === 'checkbox' || field.type === 'radio') return [key, field.value, field.checked];
        if (field.multiple && field.options) return [key, [...field.selectedOptions].map(option => option.value)];
        const editor=win.tinymce?.get?.(field.id);
        // TinyMCE registers its instance before loading its initial document.
        // Its transient empty getContent() must not become the draft baseline.
        return [key, editor?.initialized ? (editor.getContent?.() ?? field.value) : field.value];
    }));
}
const snapshot=formSnapshot;

function register(form, scope) {
    if (form.method.toLowerCase() !== 'post' || formStates.has(form)) return;
    const state = {form, scope, baseline: snapshot(form), state: 'clean', interacted: false};
    formStates.set(form, state); scope.forms.add(state);
}

export function mutationState(form) {
    const state = formStates.get(form); if (!state) return 'clean';
    if (state.state === 'pending' || state.state === 'uncertain') return state.state;
    if (!state.scope.root.contains(form)) return 'clean';
    const editorsDirty = [...form.querySelectorAll('textarea[id]')].some(field => form.ownerDocument.defaultView.tinymce?.get?.(field.id)?.isDirty?.());
    if (!state.interacted && !editorsDirty && !state.scope.forced) return 'clean';
    return snapshot(form) === state.baseline && !state.scope.forced ? 'clean' : 'dirty';
}

export function trackForms(root, context = {}) {
    const win = root.ownerDocument.defaultView;
    const scope = {root, forms: new Set(), forced: false, win}; scopes.add(scope);
    const scan = () => root.querySelectorAll('form').forEach(form => register(form, scope)); scan();
    const observer = new win.MutationObserver(scan); observer.observe(root, {childList: true, subtree: true});
    const dirty = () => { scope.forced = true; };
    root.addEventListener('erp:dirty', dirty);
    const editorReady = event => {
        const field = event.target, state = formStates.get(field.form);
        if (!state || state.state !== 'clean') return;
        const original = JSON.parse(state.baseline), current = JSON.parse(snapshot(field.form));
        const key = field.name || '@' + field.id;
        const index = original.findIndex(row => row[0] === key), value = current.find(row => row[0] === key);
        // Normalize only this editor's initial HTML; retain all other edits.
        if (state.interacted && index >= 0 && original[index][1] !== field.value) return;
        if (index >= 0 && value) { original[index] = value; state.baseline = JSON.stringify(original); }
    };
    root.addEventListener('erp:editor-ready', editorReady);
    const interaction = event => {
        const form = event.target.closest?.('form');
        if (!form || event.target.closest('.tox,[data-ignore-dirty]')) return;
        register(form, scope); const state = formStates.get(form); if (!state) return;
        if (event.type === 'focusin') {
            // Rebase asynchronous defaults before the first user edit only.
            if (!state.interacted && state.state === 'clean') state.baseline = snapshot(form);
        } else if (event.type !== 'click' || event.target.closest('button[type="button"]')) state.interacted = true;
    };
    for (const type of ['focusin','input','change','click']) root.addEventListener(type, interaction, true);
    const unload = event => {
        if ([...scope.forms].some(({form}) => mutationState(form) !== 'clean') || scope.forced) { event.preventDefault(); event.returnValue = ''; }
    };
    win.addEventListener('beforeunload', unload);
    let disposed = false;
    const dispose = () => {
        if (disposed) return; disposed = true; observer.disconnect(); root.removeEventListener('erp:dirty', dirty); win.removeEventListener('beforeunload', unload);
        root.removeEventListener('erp:editor-ready', editorReady);
        for (const type of ['focusin','input','change','click']) root.removeEventListener(type, interaction, true);
        for (const {form} of scope.forms) formStates.delete(form); scopes.delete(scope);
    };
    context.signal?.addEventListener('abort', dispose, {once: true}); return dispose;
}

export function hasUnsavedChanges() {
    return [...scopes].some(scope => scope.forced || [...scope.forms].some(({form}) => mutationState(form) !== 'clean'));
}

export async function confirmNavigation() {
    for (const scope of scopes) for (const {form} of scope.forms) {
        if (['pending', 'uncertain'].includes(mutationState(form))) {
            scope.win.schoolNotify?.('A save is still pending or its outcome is unknown. Check the saved records before leaving.', 'warning'); return false;
        }
    }
    if (!hasUnsavedChanges()) return true;
    const win = [...scopes][0]?.win;
    return !!win?.confirm('You have unsaved changes. Discard them and leave this page?');
}

export function setMutationPending(form, pending) {
    if(!formStates.has(form))for(const scope of scopes)if(scope.root.contains(form)){register(form,scope);break;}
    const state = formStates.get(form); if (!state) throw new Error('Mutation form is not tracked.');
    if (pending && ['pending', 'uncertain'].includes(state.state)) throw new Error('A mutation is already ' + state.state + '.');
    if (pending) { state.state = 'pending'; state.interacted = true; }
    else if (state.state !== 'uncertain') state.state = snapshot(form) === state.baseline ? 'clean' : 'dirty';
}

export function setMutationUncertain(form, statusUrl) {
    const state = formStates.get(form); if (!state) throw new Error('Mutation form is not tracked.');
    state.state = 'uncertain';
    if (form.querySelector('[data-mutation-recovery]')) return;
    const win = form.ownerDocument.defaultView, url = new URL(statusUrl || win.location.href, win.location.href);
    if (url.origin !== win.location.origin || !/^https?:$/.test(url.protocol)) throw new Error('Invalid status-check URL.');
    const notice = form.ownerDocument.createElement('div'); notice.dataset.mutationRecovery = ''; notice.className = 'alert alert-warning'; notice.setAttribute('role', 'alert');
    notice.textContent = 'The connection ended before the save result was confirmed. Do not submit again. ';
    const link = form.ownerDocument.createElement('a'); link.href = url.href; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'Check saved records in a new tab'; notice.append(link); form.prepend(notice);
}

export function markSaved(form,baseline=snapshot(form)) {
    const state = formStates.get(form); if (!state) return;
    state.baseline = baseline; state.state = 'clean'; state.scope.forced = false; form.querySelector('[data-mutation-recovery]')?.remove();
}
