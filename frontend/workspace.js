import Alpine from '@alpinejs/csp';
import htmx from 'htmx.org';
import '../assets/js/dynamic-workspace.js';

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
window.erpDynamic.install(htmx);
Alpine.start();
