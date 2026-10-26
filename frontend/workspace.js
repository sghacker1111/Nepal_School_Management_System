import {workspaceShell} from './app/workspace-shell.js';
import Alpine from '@alpinejs/csp';
import htmx from 'htmx.org';
import './app/editor-security.js';
import '../assets/js/dynamic-workspace.js';
import * as lifecycle from './app/page-lifecycle.js';
import * as dirtyForms from './app/dirty-forms.js';
import * as navigation from './app/navigation.js';
import * as forms from './app/forms.js';
import '../assets/js/routine-controls.js';
import '../assets/js/curriculum-delete.js';
import './pages/school.js';
import './pages/academics.js';
import './pages/administration.js';
import '../assets/js/attendance-controls.js';
import './pages/attendance.js';
import './pages/results.js';
import '../assets/js/question-editor.js';
import '../assets/js/exam-question-language.js';
import './pages/learning.js';
import './pages/questions.js';
import './pages/papers.js';
import './pages/curriculum.js';
import './pages/practice.js';
import './pages/accounting.js';

// CSP build: no new Function/eval required to operate the navigation.
Alpine.data('workspace', () => workspaceShell(window));
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
