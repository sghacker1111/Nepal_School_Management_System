import {readPageMetadata} from './page-lifecycle.js';

export function filterNavigation(root, search) {
    const words = search.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
    let matches = 0;
    root.querySelectorAll('#sidebar .nav-group').forEach(group => {
        let visible = 0;
        group.querySelectorAll('.nav-item').forEach(item => {
            const text = (item.textContent + ' ' + (item.dataset.searchKeywords || '')).toLocaleLowerCase();
            item.hidden = words.length ? !words.every(word => text.includes(word)) : item.hasAttribute('data-search-only');
            if (!item.hidden) visible++;
        });
        group.hidden = visible === 0; matches += visible;
        const accounting = group.querySelector('#accountingNav');
        if (accounting) accounting.classList.toggle('show', words.length ? visible > 0 : accounting.dataset.active === 'true');
    });
    const empty = root.querySelector('#navigationSearchEmpty');
    if (empty) empty.hidden = !words.length || matches > 0;
}

export function renderFeatureSearch(doc, items = []) {
    const list = doc.getElementById('navigationFeatureList');
    if (!list) return;
    list.replaceChildren();
    for (const item of items) {
        let url; try { url = new URL(item.url, doc.defaultView.location.href); } catch { continue; }
        if (url.origin !== doc.defaultView.location.origin || !/^https?:$/.test(url.protocol)) continue;
        const row = doc.createElement('li'); row.className = 'nav-item'; row.dataset.searchOnly = ''; row.hidden = true;
        row.dataset.searchKeywords = item.keywords || '';
        const link = doc.createElement('a'); link.className = 'nav-link'; link.href = url.href; link.textContent = item.label;
        row.append(link); list.append(row);
    }
}

export function workspaceShell(win = window) {
    return {
        navigationOpen: false, sidebarCollapsed: false, mobile: win.innerWidth < 768,
        accountOpen: false, schoolsOpen: false, search: '',
        init() {
            try { this.sidebarCollapsed = win.localStorage.getItem('erp.sidebarCollapsed') === 'true'; } catch {}
            this.onResize = () => { this.mobile = win.innerWidth < 768; if (!this.mobile) this.navigationOpen = false; };
            win.addEventListener('resize', this.onResize);
            try { renderFeatureSearch(win.document, readPageMetadata(win.document.getElementById('main-content')).searchFeatures); } catch {}
        },
        destroy() { win.removeEventListener('resize', this.onResize); },
        get navigationVisible() { return this.mobile ? this.navigationOpen : !this.sidebarCollapsed; },
        get navigationLabel() { return this.navigationVisible ? 'Hide menus' : 'Show menus'; },
        toggleNavigation() {
            if (this.mobile) {
                this.navigationOpen = !this.navigationOpen;
                if (this.navigationOpen) { this.navigationTrigger = win.document.activeElement; this.$nextTick(() => this.$root.querySelector('#navigationSearch')?.focus()); }
            } else {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                try { win.localStorage.setItem('erp.sidebarCollapsed', String(this.sidebarCollapsed)); } catch {}
            }
        },
        closeNavigation() { this.navigationOpen = false; this.navigationTrigger?.focus(); },
        toggleAccount() { this.accountOpen = !this.accountOpen; this.schoolsOpen = false; },
        closeAccount() { this.accountOpen = false; },
        toggleSchools() { this.schoolsOpen = !this.schoolsOpen; this.accountOpen = false; },
        closeSchools() { this.schoolsOpen = false; },
        closeAll() { if (this.navigationOpen) this.closeNavigation(); this.accountOpen = this.schoolsOpen = false; },
        filterNavigation() { filterNavigation(this.$root, this.search); },
    };
}
