// Installed before page-specific scripts so AJAX and dynamically created forms are protected.
(() => {
    if (window.erpRequestSecurityInstalled) return;
    window.erpRequestSecurityInstalled = true;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        const url = new URL(typeof input === 'string' ? input : input instanceof URL ? input.href : input.url, location.href);
        if (url.origin === location.origin) {
            const headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-CSRF-Token', window.CSRF_TOKEN || '');
            init = {...init, headers};
        }
        return nativeFetch(input, init);
    };
    if (window.jQuery) jQuery.ajaxPrefilter((options, original, xhr) => {
        if (new URL(options.url, location.href).origin === location.origin) xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN || '');
    });
    const addToken = form => {
        if ((form.getAttribute('method')||'get').toLowerCase() !== 'post' || new URL(form.getAttribute('action')||location.href, location.href).origin !== location.origin) return;
        let token = form.querySelector('[name="csrf_token"]');
        if (!token) { token = document.createElement('input'); token.type = 'hidden'; token.name = 'csrf_token'; form.append(token); }
        token.value = window.CSRF_TOKEN || '';
    };
    document.addEventListener('submit', event => addToken(event.target), true);
    const submit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        addToken(this);
        if(window.erpForms?.canHandleForm(this)){void window.erpForms.submitForm(this);return;}
        return submit.call(this);
    };
    window.postAction = url => {
        const target = new URL(url, location.href);
        if (target.origin !== location.origin) return;
        const form = document.createElement('form'); form.method = 'post'; form.action = target.href;
        (document.getElementById('main-content')||document.body).append(form); form.submit();
    };
})();
