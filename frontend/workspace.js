import Alpine from '@alpinejs/csp';
import htmx from 'htmx.org';
import '../assets/js/dynamic-workspace.js';
import * as lifecycle from './app/page-lifecycle.js';
import * as dirtyForms from './app/dirty-forms.js';
import * as navigation from './app/navigation.js';
import * as forms from './app/forms.js';

// CSP build: no new Function/eval required to operate the navigation.
Alpine.data('workspace', () => ({
    navigationOpen: false,
    accountOpen: false,
    schoolsOpen: false,
    search: '',
    get hasSearch() { return this.search.trim().length > 0; },
    toggleNavigation() {
        this.navigationOpen = !this.navigationOpen;
        if (this.navigationOpen) { this.navigationTrigger = document.activeElement; this.$nextTick(() => this.$root.querySelector('#navigationSearch')?.focus()); }
    },
    closeNavigation() { this.navigationOpen = false; this.navigationTrigger?.focus(); },
    toggleAccount() { this.accountOpen = !this.accountOpen; this.schoolsOpen = false; },
    closeAccount() { this.accountOpen = false; },
    toggleSchools() { this.schoolsOpen = !this.schoolsOpen; this.accountOpen = false; },
    closeSchools() { this.schoolsOpen = false; },
    closeAll() { if (this.navigationOpen) this.closeNavigation(); this.accountOpen = this.schoolsOpen = false; },
    filterNavigation() {
        const query = this.search.trim().toLocaleLowerCase();
        this.$root.querySelectorAll('.nav-group').forEach(group => {
            let visible = 0;
            group.querySelectorAll('.nav-item').forEach(item => {
                item.hidden = !item.textContent.toLocaleLowerCase().includes(query);
                if (!item.hidden) visible++;
            });
            group.hidden = visible === 0;
            const accounting = group.querySelector('#accountingNav');
            if (accounting) accounting.classList.toggle('show', query ? visible > 0 : accounting.dataset.active === 'true');
        });
    },
}));
window.Alpine = Alpine;
window.htmx = htmx;
window.erpPages = lifecycle;
window.erpFormState = dirtyForms;
window.erpNavigation = navigation;
window.erpForms = forms;
window.erpDynamic.install(htmx);
Alpine.start();
const startNavigation = () => {
    try {
        const dispose = navigation.installNavigation(htmx);
        forms.installForms(htmx);
        window.erpAppReady = dispose.ready || Promise.resolve();
        window.erpAppReady.catch(() => navigation.recoverNavigation('This page could not be initialized.', location.href));
    } catch { navigation.recoverNavigation('This page needs a full-page visit.', location.href); }
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startNavigation, {once:true}); else startNavigation();
