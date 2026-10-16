/* Small native components for existing PHP forms. No Bootstrap/Popper runtime.
 * The public API preserves existing form controllers while Alpine owns the shell.
 */
(() => {
    const instances = new WeakMap();
    const openDialogs = [];
    const resolve = value => typeof value === 'string' ? document.querySelector(value) : value;
    const emit = (element, name, cancelable = false) => element.dispatchEvent(new CustomEvent(name, { bubbles: true, cancelable }));
    const focusable = element => [...element.querySelectorAll('a[href],button,input:not([type="hidden"]),select,textarea,[tabindex]')].filter(node => !node.disabled && !node.hidden && node.type !== 'hidden' && node.getAttribute('tabindex') !== '-1' && !node.closest('[hidden]') && getComputedStyle(node).display !== 'none');
    class Component {
        constructor(element, options = {}) {
            this.element = resolve(element); this.options = options;
            if (!this.element) throw new Error('UI component target was not found.');
            const map = instances.get(this.element) || new Map();
            map.set(this.constructor, this); instances.set(this.element, map);
        }
        static getInstance(element) { return instances.get(resolve(element))?.get(this) || null; }
        static getOrCreateInstance(element, options) { return this.getInstance(element) || new this(element, options); }
        dispose() { this.hide?.(); instances.get(this.element)?.delete(this.constructor); }
    }
    class Modal extends Component {
        constructor(element, options = {}) { super(element, options); this.isOpen = false; }
        show() {
            if (this.isOpen || !emit(this.element, 'show.erp.modal', true)) return;
            this.previous = document.activeElement; this.isOpen = true;
            this.element.classList.add('show'); this.element.removeAttribute('aria-hidden');
            this.element.setAttribute('aria-modal', 'true'); this.element.setAttribute('role', 'dialog');
            if (!this.element.hasAttribute('tabindex')) this.element.tabIndex = -1;
            document.body.classList.add('modal-open');
            this.backdrop = document.createElement('div'); this.backdrop.className = 'modal-backdrop';
            document.body.append(this.backdrop); openDialogs.push(this);
            const depth = openDialogs.length - 1;
            this.backdrop.style.zIndex = String(1070 + depth * 20); this.element.style.zIndex = String(1080 + depth * 20);
            (focusable(this.element)[0] || this.element).focus();
            emit(this.element, 'shown.erp.modal');
        }
        hide() {
            if (!this.isOpen || !emit(this.element, 'hide.erp.modal', true)) return;
            this.isOpen = false; this.element.classList.remove('show'); this.element.setAttribute('aria-hidden', 'true'); this.element.removeAttribute('aria-modal');
            this.backdrop?.remove(); const index = openDialogs.indexOf(this); if (index !== -1) openDialogs.splice(index, 1);
            if (!openDialogs.length) document.body.classList.remove('modal-open');
            if (this.previous?.isConnected) this.previous.focus();
            emit(this.element, 'hidden.erp.modal');
        }
        toggle() { this.isOpen ? this.hide() : this.show(); }
    }
    class Collapse extends Component {
        constructor(element, options = {}) { super(element, options); if (options.toggle !== false) this.toggle(); }
        show() {
            if (this.element.classList.contains('show') || !emit(this.element, 'show.erp.collapse', true)) return;
            const parent = this.element.getAttribute('data-ui-parent');
            if (parent) document.querySelector(parent)?.querySelectorAll('.collapse.show').forEach(node => { if (node !== this.element) Collapse.getOrCreateInstance(node, { toggle: false }).hide(); });
            this.element.classList.add('show'); this.update(true); emit(this.element, 'shown.erp.collapse');
        }
        hide() { if (!emit(this.element, 'hide.erp.collapse', true)) return; this.element.classList.remove('show'); this.update(false); emit(this.element, 'hidden.erp.collapse'); }
        toggle() { this.element.classList.contains('show') ? this.hide() : this.show(); }
        update(open) {
            document.querySelectorAll('[data-ui-toggle="collapse"]').forEach(button => {
                if (targetOf(button) === this.element) { button.setAttribute('aria-expanded', String(open)); button.classList.toggle('collapsed', !open); }
            });
        }
    }
    class Toast extends Component {
        show() { clearTimeout(this.timer); this.element.classList.add('show'); this.element.setAttribute('role', 'status'); if (this.options.autohide !== false) this.timer = setTimeout(() => this.hide(), this.options.delay || 5000); }
        hide() { clearTimeout(this.timer); this.element.classList.remove('show'); }
    }
    class Alert extends Component { close() { this.element.remove(); } }
    class Tooltip extends Component { constructor(element, options) { super(element, options); if (!this.element.title) this.element.title = this.element.dataset.uiTitle || ''; } }
    class Popover extends Tooltip { constructor(element, options) { super(element, options); this.element.title = this.element.dataset.uiContent || this.element.title; } }
    const targetOf = button => {
        const selector = button.getAttribute('data-ui-target') || button.getAttribute('href');
        if (!selector?.startsWith('#') || selector === '#') return null;
        try { return document.querySelector(selector); } catch { return null; }
    };
    const closeDropdowns = except => document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
        if (menu !== except) { menu.classList.remove('show'); menu.parentElement.querySelector('[data-ui-toggle="dropdown"]')?.setAttribute('aria-expanded', 'false'); }
    });
    document.addEventListener('click', event => {
        const dismiss = event.target.closest('[data-ui-dismiss]');
        if (dismiss) {
            const type = dismiss.dataset.uiDismiss;
            if (type === 'modal') Modal.getOrCreateInstance(dismiss.closest('.modal')).hide();
            if (type === 'alert') dismiss.closest('.alert')?.remove();
            if (type === 'toast') Toast.getOrCreateInstance(dismiss.closest('.toast')).hide();
            event.preventDefault(); return;
        }
        const button = event.target.closest('[data-ui-toggle]');
        if (button) {
            const target = targetOf(button);
            switch (button.dataset.uiToggle) {
                case 'modal': if (target) { event.preventDefault(); Modal.getOrCreateInstance(target).show(); } break;
                case 'collapse': if (target) { event.preventDefault(); Collapse.getOrCreateInstance(target, { toggle: false }).toggle(); } break;
                case 'dropdown': {
                    event.preventDefault(); const menu = button.parentElement.querySelector('.dropdown-menu');
                    if (menu) { closeDropdowns(menu); menu.classList.toggle('show'); button.setAttribute('aria-expanded', String(menu.classList.contains('show'))); }
                    break;
                }
                case 'tab': if (target) {
                    event.preventDefault(); const group = button.closest('[role="tablist"],.nav');
                    group?.querySelectorAll('[data-ui-toggle="tab"]').forEach(tab => { tab.classList.remove('active'); tab.setAttribute('aria-selected', 'false'); const panel = targetOf(tab); panel?.classList.remove('show', 'active'); });
                    button.classList.add('active'); button.setAttribute('aria-selected', 'true'); target.classList.add('show', 'active'); emit(button, 'shown.erp.tab');
                } break;
            }
        }
        if (!event.target.closest('.dropdown')) closeDropdowns();
        const current = openDialogs.at(-1);
        if (current && event.target === current.element && current.options.backdrop !== 'static') current.hide();
    });
    document.addEventListener('keydown', event => {
        const current = openDialogs.at(-1);
        if (event.key === 'Escape') { closeDropdowns(); if (current && current.options.keyboard !== false) current.hide(); }
        if (event.key === 'Tab' && current) {
            const nodes = focusable(current.element); const first = nodes[0], last = nodes.at(-1);
            if (!first) { event.preventDefault(); current.element.focus(); }
            else if (event.shiftKey && (document.activeElement === first || !current.element.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && (document.activeElement === last || !current.element.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
        }
    });
    document.addEventListener('focusin', event => { const current = openDialogs.at(-1); if (current && !current.element.contains(event.target)) (focusable(current.element)[0] || current.element).focus(); });
    window.ErpUI = { Modal, Collapse, Toast, Alert, Tooltip, Popover };
})();
