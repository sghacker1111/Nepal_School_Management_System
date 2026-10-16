// Drafts live in memory only. A network failure is not proof a write failed.
const scopes = new Set();
const formStates = new WeakMap();

function snapshot(form) {
    const win = form.ownerDocument.defaultView;
    return JSON.stringify([...form.elements].filter(field => field.name && !['submit', 'button', 'reset'].includes(field.type) && field.name !== 'csrf_token').map(field => {
        if (field.type === 'file') return [field.name, 'files', [...(field.files || [])].map(file => [file.name, file.size, file.type, file.lastModified])];
        if (field.type === 'checkbox' || field.type === 'radio') return [field.name, field.value, field.checked];
        if (field.multiple && field.options) return [field.name, [...field.selectedOptions].map(option => option.value)];
        return [field.name, win.tinymce?.get?.(field.id)?.getContent?.() ?? field.value];
    }));
}

function register(form, scope) {
    if (form.method.toLowerCase() !== 'post' || formStates.has(form)) return;
    const state = {form, scope, baseline: snapshot(form), state: 'clean'};
    formStates.set(form, state); scope.forms.add(state);
}

export function mutationState(form) {
    const state = formStates.get(form); if (!state) return 'clean';
    if (state.state === 'pending' || state.state === 'uncertain') return state.state;
    return snapshot(form) === state.baseline && !state.scope.forced ? 'clean' : 'dirty';
}

export function trackForms(root, context = {}) {
    const win = root.ownerDocument.defaultView;
    const scope = {root, forms: new Set(), forced: false, win}; scopes.add(scope);
    const scan = () => root.querySelectorAll('form').forEach(form => register(form, scope)); scan();
    const observer = new win.MutationObserver(scan); observer.observe(root, {childList: true, subtree: true});
    const dirty = () => { scope.forced = true; };
    root.addEventListener('erp:dirty', dirty);
    const unload = event => {
        if ([...scope.forms].some(({form}) => mutationState(form) !== 'clean') || scope.forced) { event.preventDefault(); event.returnValue = ''; }
    };
    win.addEventListener('beforeunload', unload);
    let disposed = false;
    const dispose = () => {
        if (disposed) return; disposed = true; observer.disconnect(); root.removeEventListener('erp:dirty', dirty); win.removeEventListener('beforeunload', unload);
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
    const state = formStates.get(form); if (!state) throw new Error('Mutation form is not tracked.');
    if (pending && ['pending', 'uncertain'].includes(state.state)) throw new Error('A mutation is already ' + state.state + '.');
    if (pending) state.state = 'pending';
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

export function markSaved(form) {
    const state = formStates.get(form); if (!state) return;
    state.baseline = snapshot(form); state.state = 'clean'; state.scope.forced = false; form.querySelector('[data-mutation-recovery]')?.remove();
}
