# Single-Page Navigation and Clean URLs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make ordinary navigation, filtering, paging and saving update the existing school app without document refresh, with extension-free URLs and unchanged MySQL data.

**Architecture:** Retain existing PHP/PDO services and authorization. Introduce an explicit route registry, full/fragment response contract, scoped page lifecycles and HTMX navigation/form handling. Migrate and test every interactive controller before enabling global enhancement; dedicated print, file and authentication boundaries stay explicit.

**Tech Stack:** Existing PHP/PDO + MySQL, Tailwind CSS 4, Alpine CSP 3, HTMX 2, existing TinyMCE/MathJax/DataTables, npm build tooling only on developer/CI machines.

**Spec:** `docs/superpowers/specs/2026-10-16-single-page-clean-urls-design.md` (approved by owner in chat).

## Global Constraints

- "Continue using PHP, the existing MySQL database, Tailwind CSS, Alpine.js and HTMX on InfinityFree free hosting."
- "Do not add React, a second API backend, Node hosting, queues or sockets."
- "Store no rendered school data, form values or financial details in localStorage/sessionStorage history snapshots."
- "Do not enable arbitrary script execution or eval to make legacy inline scripts work."
- "Never auto-retry a mutation after an uncertain network result."
- "No schema or data conversion is needed for this navigation change."
- "Do not reseed, normalize or import base schema SQL."
- "Work only in the linked migration worktree, not the owner's dirty original checkout."
- "Do not merge/push main or deploy this change until separately authorized and verified."
- "Local SQLite evidence alone is not MySQL certification."

Worktree: `C:/Users/SGHACKER/.codex/worktrees/accounting-completion/Nepal School Management System`.
Branch: `codex/tailwind-alpine-htmx-migration`; baseline application commit `dbcfa45`, spec commit `072cf18`.
No implementation tasks below are complete merely because the earlier frontend foundation exists.

## Review Focus

1. Relative/encoded paths, query arrays and subdirectory installs must resolve identically without bypassing controller-specific permissions (Tasks 1–2).
2. A pending mutation followed by double-click, network loss, or navigation must not duplicate a payment or falsely report failure/success (Tasks 5, 7, 11).
3. Back/Forward cancellation and late GET responses must preserve dirty work, URL/content agreement and the active school context (Tasks 5–6).
4. Repeated editor/practice/routine visits must not leak listeners, tables, audio or fullscreen state; original Nepali/TeX content must survive (Tasks 4, 8–10).
5. Revoked access or a 200-status login/hosting challenge must never become trusted app content or reuse a previous school's data (Tasks 3, 6, 12).

## File boundaries and interfaces

Create focused files, not a general-purpose client framework:

- `includes/routes.php`: finite registry, canonical URL conversion and trusted resolved-controller identity.
- `app.php`: resolve and dispatch clean paths, never include a client-named file.
- `includes/app_response.php`: full/fragment metadata, safe redirect/error responses.
- `frontend/app/page-lifecycle.js`: page/asset registry, scoped cleanup and mounting.
- `frontend/app/dirty-forms.js`: baseline/dirty/pending state and discard decisions.
- `frontend/app/navigation.js`: HTMX page navigation, history, race handling and shell metadata.
- `frontend/app/forms.js`: native validation/editor synchronization, multipart submission and mutation outcomes.
- `frontend/pages/{school,academics,administration,attendance,results,learning,questions,papers,curriculum,practice,accounting}.js`: named page adapters, no global eval.
- `docs/single-page-route-coverage.md`: route inventory, lifecycle ownership, exemptions and verification evidence.

Modify shared rendering/security/routing/build files and the existing templates/controllers listed in each task. Tests use existing disposable fixture helpers; never change production configuration to run them.

### Route coverage inventory

There are 77 current `modules/**/*.php` files plus root `index.php`. Task 1 must compare the registry against that complete inventory, not a curated subset.

| Group / public prefix | Controllers (all `.php`) | Lifecycle owner / special response kinds |
| --- | --- | --- |
| root `/` | root `index` | school / page |
| students `/students` | index, add, edit, view, import_export, admit_card | school; import/export mixed; admit card print |
| teachers `/teachers` | index, add, edit, import_export | school; import/export mixed |
| classes `/classes` | index, add, edit, subjects, routine | academics |
| subjects `/subjects` | index, add, edit, internal | academics |
| attendance `/attendance` | index | attendance |
| exams `/exams` | index, add, edit, view, symbols, admit_cards | academics; admit-card selector page + rendered print |
| results `/results` | index, ledger, marks_entry, print_bulk, report_card | results; report card print; bulk selector page + rendered print |
| reports `/reports` | index | results |
| learning `/learning` | index, curriculum, questions, practice_mcqs, paper_generator, specification_grids, question_grid, ocr_scanner, question_corrections, exam_questions, exam_mcqs, exam_paper_generator, exam_specification_grids | learning/questions/papers/curriculum/practice; catalog/template/scan modes explicit |
| accounting `/accounting` | index, fees, create_bill, invoices, invoice, generate, payments, student, subscriptions, expenses, journals, reports, settings, credit, receipt, search | accounting; search JSON; invoice/receipt print actions explicit |
| settings `/settings` | users, school, years, cdc | administration; student lookup JSON mode |
| super_admin `/admin` | index, schools, users, subjects, exam_templates, question_corrections, switch_school | administration; school switch context boundary |
| auth `/account` | login, logout, profile, change_password, verify_password | administration for profile/password; login/logout auth; verify_password JSON |

Default public mapping: prefix alone for `index`, otherwise prefix + `/` + filename stem with `_` replaced by `-`.
Explicit overrides: auth login `/login`, logout `/logout`; learning questions `/learning/question-bank`, exam_questions `/exam-questions`, exam_mcqs `/exam-mcqs`, exam_paper_generator `/exam-paper-generator`, exam_specification_grids `/exam-paper-grids`. Other entries follow the default, including `/results/marks-entry` and `/settings/users`.
Query parameters retain their existing semantics; IDs do not become new path slugs.

Each registry entry has `id`, `path`, `controller`, `kind`, `pageId`, `enhanced` fields. `kind` is `page|mixed|json|print|auth`; `mixed` selects an explicit mode by existing action/query values, never by arbitrary content sniffing. `enhanced=false` until its owner task passes. Auth/profile/password forms can mount in the shell, but login/logout/context recovery stays a boundary.

---

### Task 1: Trusted route registry and complete coverage

**Files:** Create `includes/routes.php`, `tests/routes_test.php`, `docs/single-page-route-coverage.md`. Modify `includes/request_guard.php`, `includes/auth.php`.

**Interfaces:** Produce `schoolRoutes(): array`, `schoolResolveRoute(string $path,string $basePath=''): ?array`, `schoolRouteUrl(string $controller,array $query=[]): string`, `schoolCanonicalUrl(string $url): ?string`, `schoolControllerPath(): string`. Resolved identity is set only by the server dispatcher; direct legacy requests use the registered SCRIPT_NAME identity. URL helpers preserve APP_URL's origin/base path and reject foreign/unregistered destinations.

- [ ] **1. Write failing route tests:** assert each inventory controller has exactly one unique canonical mapping; `/students` resolves `modules/students/index.php`, `/results/marks-entry` resolves marks entry, root resolves `index.php`. Assert query arrays round-trip; `/erp/students` works with `/erp`; traversal, double-encoded traversal, encoded separators, foreign origins and unknown controllers fail closed. Assert forged headers/query cannot change `schoolControllerPath()`.
  Pin examples: `erpEqual(schoolResolveRoute('/students')['controller'],'modules/students/index.php','students route'); erpEqual(schoolResolveRoute('/erp/students','/erp')['controller'],'modules/students/index.php','subdirectory route'); erpEqual(schoolResolveRoute('/../includes/auth.php'),null,'traversal denied');`.
- [ ] **2. Run red:** `php tests/routes_test.php`; expect missing registry/helper failure before production changes.
- [ ] **3. Implement the registry/helpers** with the exact mapping rules above; replace all guard/preview uses of SCRIPT_NAME identity with `schoolControllerPath()` without changing their role rules. Populate coverage rows for every controller and mixed mode.
- [ ] **4. Run green:** `php tests/routes_test.php`, `php tests/security_test.php`, `php tests/subscription_teaching_test.php`, `php tests/exam_teacher_scope_test.php`. Expect all pass and no missing/duplicate route entries.
- [ ] **5. Commit:** `git add includes/routes.php includes/request_guard.php includes/auth.php tests/routes_test.php docs/single-page-route-coverage.md`; `git commit -m "Add trusted clean route registry and controller identity"`.

### Task 2: Canonical HTTP routing and backward-compatible URLs

**Files:** Create `app.php`, `tests/routes_http_test.py`. Modify `.htaccess`, `router.php`, `includes/functions.php`, `includes/header.php`, `includes/accounting_ui.php`, all inventoried module templates and their URL-producing scripts.

**Interfaces:** Consume Task 1 helpers. `app.php` resolves a registered route before loading the controller; static files retain existing behavior. Server-generated links/actions/redirects use `schoolRouteUrl`; JavaScript receives trusted canonical endpoint URLs in page configuration, not guessed relative `.php` paths.

- [ ] **1. Write failing HTTP tests:** clean and legacy student/exam pages have equivalent scoped content; safe legacy document GET navigates to its clean URL; direct legacy POST saves exactly once without GET conversion. Verify HEAD preserves headers with no response body, JSON/template downloads are not redirected to HTML, multipart bodies survive, and private/unknown/traversal paths stay denied. Include a second local server mounted under `/erp`.
  Pin examples: `assert response.geturl().endswith('/students'); assert before_count + 1 == after_count; assert payload_bytes == downloaded_bytes; assert canonical_denied.code == legacy_denied.code == 403` using disposable fixtures and a response opener that exposes redirects.
- [ ] **2. Run red:** `python tests/routes_http_test.py`; expect missing clean paths/incorrect canonical links.
- [ ] **3. Implement dispatch, Apache/local rules and URL generation.** Do not redirect HTMX/JSON/file requests solely to canonicalize them. Audit `Location`, `window.location`, `fetch`, jQuery URLs, form actions and download links. Preserve parameters and GET mutation-confirmation semantics. Do not use a catch-all filesystem include or indiscriminate HTML/text replacement.
- [ ] **4. Run green:** `python tests/routes_http_test.py`, `python tests/class_isolation_http_test.py`, `python tests/exam_teacher_scope_http_test.py`, `python tests/deployment_test.py`. Expect clean and legacy routes to preserve guards, streams and bodies.
- [ ] **5. Commit only this task's files** after `git diff --check`; message `Route clean URLs without breaking legacy actions`.

### Task 3: Full-document / app-fragment response contract

**Files:** Create `includes/app_response.php`, `tests/app_response_test.php`, `tests/app_response_http_test.py`. Modify `includes/header.php`, `includes/functions.php`, `includes/request_guard.php`, `includes/auth.php`, `includes/http_security.php`, `includes/exam_question_page.php`.

**Interfaces:** Produce `schoolIsAppRequest(): bool`, `schoolPageConfig(string $pageId,array $config=[]): string`, `schoolAppRedirect(string $url): never`, `schoolAppError(string $message,int $status): never`. `schoolPageConfig` emits escaped inert `data-erp-config` JSON attributes; never executable script. App fragments carry `X-ERP-Fragment: app-page`, `text/html; charset=UTF-8`, private/no-store and Vary headers. Metadata keys: `routeId,pageId,title,canonicalUrl,activePage,contextVersion,csrfToken,assets`. Context version identifies user/role/school/preview state, not credentials. `schoolIsAppRequest` requires the app target `main-content`, not merely arbitrary HX headers. `schoolAppRedirect` returns 204 with `HX-Location` JSON specifying the validated canonical `path`, `target:'#main-content'`, `swap:'innerHTML'`; direct native requests keep ordinary redirects.

- [ ] **1. Write failing tests:** full entry includes one shell; partial entry omits shell but includes valid metadata and CSRF fields. Assert malicious titles/config cannot escape attributes; partial 403/404/419/422 errors are marked safe; exception details are absent. Successful POST redirects use `204` + same-origin `HX-Location` for a subsequent GET, never repost. Auth expiry uses `401` + approved clean login `HX-Redirect` and no stale content. Existing `question-list` responses still work.
  Pin examples: `assert headers['X-ERP-Fragment'] == 'app-page'; assert 'top-navbar' not in fragment; assert post_response.code == 204; assert json.loads(post_response.headers['HX-Location'])['target'] == '#main-content'; assert mutation_count == 1`.
- [ ] **2. Run red:** `php tests/app_response_test.php`; `python tests/app_response_http_test.py`.
- [ ] **3. Implement rendering/redirect/error helpers** through the existing controllers, including direct `header('Location: ...')` paths. Keep existing domain statuses and JSON/file contracts. Include permission-aware navigation metadata and explicit legacy mutation confirmation fragments; do not turn headers into authority.
- [ ] **4. Run green:** both new tests, `python tests/dynamic_workspace_http_test.py`, `python tests/question_corrections_http_test.py`, `python tests/assessment_http_test.py`. Expect no accidental mutation or inaccessible source leak.
- [ ] **5. Commit:** message `Add authenticated app fragment and redirect contract`.

### Task 4: Scoped page lifecycle and trusted asset loading

**Files:** Create `frontend/app/page-lifecycle.js`, `tests/page_lifecycle_test.mjs`. Modify `frontend/workspace.js`, `tools/build-assets.mjs`, `assets/js/main.js`, `assets/js/school-dates.js`, `assets/js/question-editor.js`, `assets/js/notifications.js`, `assets/js/request-security.js`.

**Interfaces:** Export `registerPage(id,init)`, `mountPage(root,metadata): Promise<void>`, `disposePage(): Promise<void>`, `preparePageSubmit(form): Promise<void>`. Initializer: `(root:HTMLElement,context:PageContext)=>void|Dispose|Promise<Dispose>`. `Dispose` is `()=>void|Promise<void>`. PageContext has `signal:AbortSignal`, `config:object`, `onBeforeSubmit(fn)`; submit hook returns void/Promise<void>. Page/asset IDs come from a fixed bundled registry; server metadata cannot supply arbitrary script URLs. Registry rejects duplicate IDs and missing adapters.

- [ ] **1. Write failing tests:** mount/dispose/mount binds each handler once and aborts obsolete async callbacks; submit hooks run once; data controls initialize on live nodes. Reject foreign/unknown asset IDs and rich-text `<script>`/metadata lookalikes; no eval, Function constructor or script execution from fragments. TinyMCE, observers, dialogs and tables dispose only page-owned instances.
  Pin examples: `assert.equal(handlerCalls,1); assert.equal(disposeCalls,1); assert.equal(previousContext.signal.aborted,true); assert.equal(untrustedScriptRuns,0); await assert.rejects(mountPage(root,unknownAssetMetadata))`.
- [ ] **2. Run red:** `node --test tests/page_lifecycle_test.mjs`.
- [ ] **3. Implement registry and scoped shared initialization.** Expose `initSchoolDates(root,context)` and editor initialization/disposal hooks; retain existing compatibility entry points for dedicated standalone documents. Keep global request/notification listeners single-instance. Deduplicate trusted CSS/library loads and await required libraries before page mount.
- [ ] **4. Run green:** new test, `npm run build`, `npm run test:ui`, `node tests/date_controls_test.js`, `node tests/shared_question_editor_test.js`, `node tests/content_safety_test.js`.
- [ ] **5. Commit:** message `Introduce scoped page initialization and cleanup`.

### Task 5: Dirty-form and pending-mutation state

**Files:** Create `frontend/app/dirty-forms.js`, `tests/dirty_forms_test.mjs`. Modify page lifecycle submit hooks and `frontend/workspace.js` integration only.

**Interfaces:** Export `trackForms(root,context): Dispose`, `hasUnsavedChanges(): bool`, `confirmNavigation(): Promise<bool>`, `setMutationPending(form,pending): void`, `markSaved(form): void`. States: `clean,dirty,pending,uncertain`. Baselines include selected files, check/radio state and editor-synchronized values; never persist values in browser storage. `confirmNavigation` refuses while a mutation is pending/uncertain and otherwise asks before discard.

- [ ] **1. Write failing tests:** input/edit/grid changes become dirty; reverting to baseline becomes clean; valid save alone clears state; validation failure retains values; file selection is tracked. Pending/uncertain payments block navigation and duplicate submit. Discard cancellation preserves both DOM and URL; reload/close gets beforeunload protection where supported.
  Pin examples: `assert.equal(hasUnsavedChanges(),true); assert.equal(await confirmNavigation(),false); assert.equal(input.value,'unsaved'); assert.equal(location.href,previousURL); assert.equal(sendCount,1)`; simulate both a rejected discard prompt and a pending mutation.
- [ ] **2. Run red:** `node --test tests/dirty_forms_test.mjs`.
- [ ] **3. Implement state/guards** using scoped listeners and injected editor/grid change events. Clear only on confirmed success or explicit discard; provide an explicit status-check recovery for uncertain mutations rather than a retry button.
- [ ] **4. Run green:** new tests and lifecycle tests; verify no localStorage/sessionStorage writes contain content or form values.
- [ ] **5. Commit:** message `Protect unsaved forms and uncertain mutations`.

### Task 6: No-refresh navigation, history and accessible shell updates

**Files:** Create `frontend/app/navigation.js`, `tests/app_navigation_test.mjs`. Modify `assets/js/dynamic-workspace.js`, `frontend/workspace.js`, `includes/header.php`, `frontend/app.css`.

**Interfaces:** Export `installNavigation(htmx,dependencies): Dispose`, `navigate(url,{history='push',focus='heading'}={}): Promise<void>`. Dependencies consume lifecycle and dirty guards from Tasks 4–5. Only registered/enhanced routes are intercepted. Requests target `#main-content`; the same main/shell DOM survives. Panel updates retain their narrower target. History state stores URL/scroll/request identity only, not HTML or private values.

- [ ] **1. Write failing tests:** ordinary internal clicks preserve shell/document; external/modified/new-tab/hash/download links retain native behavior. Rapid A→B navigation discards late A. Back/Forward refetches without document refresh; canceling dirty Back restores URL and draft. A changed context version rejects stale responses and clears old context. Unmarked 200 login/challenge HTML is never swapped; timeout exposes a safe full-page recovery link.
  Pin examples: `assert.strictEqual(document.querySelector('header'),originalHeader); assert.equal(location.pathname,'/exams'); assert.equal(root.dataset.routeId,'exams-index'); assert.equal(reloadCalls,0); assert.equal(privateCacheWrites.length,0)` after delayed responses and history events.
- [ ] **2. Run red:** `node --test tests/app_navigation_test.mjs`.
- [ ] **3. Implement HTMX navigation and History integration.** Turn off script/eval execution and history snapshots; use explicit popstate refetch with cancellation handling rather than the baseline `refreshOnHistoryMiss=true` full refresh. Synchronize server-authorized menus/context/title, active links, progress, live announcements, focus and scroll. Close mobile drawer after successful navigation; honor reduced motion.
- [ ] **4. Run green:** navigation/lifecycle/dirty tests, `npm run build`, `npm run test:ui`. Test Back/Forward across 3 routes and an existing question-list panel update.
- [ ] **5. Commit:** message `Navigate the persistent app shell without document refresh`.

### Task 7: Partial saving, upload and validation handling

**Files:** Create `frontend/app/forms.js`, `tests/app_forms_test.mjs`, `tests/app_forms_http_test.py`. Modify `assets/js/request-security.js`, `includes/app_response.php`, page lifecycle submit hooks.

**Interfaces:** Export `installForms(htmx): Dispose`, `submitForm(form,submitter): Promise<void>`. Consume `preparePageSubmit`, dirty state and response contract. Include submitter name/value, existing hidden tokens/revisions/idempotency keys and multipart files. Handle valid marked 422 responses as retained form content; 419/auth errors do not erase a draft or rerun a POST.

- [ ] **1. Write failing tests:** native invalid form sends nothing; editor sync precedes validation; selected submitter survives; multipart contains one file and CSRF. One click/double click produces one mutation. `204 HX-Location` causes only GET follow-up; 422 retains input; 500/network uncertainty never yields a success toast or automatic resend. Preserve password/action confirmations and legacy GET→POST confirmation flow.
  Pin examples: `assert.equal(sentMethods.join(','),'POST,GET'); assert.equal(sentFormData.get('csrf_token'),token); assert.equal(sentFormData.getAll('excel_file').length,1); assert.equal(input.value,'retained'); assert.equal(successToasts,0)` for the failed/uncertain case.
- [ ] **2. Run red:** `node --test tests/app_forms_test.mjs`; `python tests/app_forms_http_test.py`.
- [ ] **3. Implement partial forms and request states.** Replace direct `form.submit()` bypasses with the shared submit contract on enhanced pages. Keep original native fallback when JavaScript is unavailable. Do not abort pending POSTs on navigation. Do not globally boost JSON or file-response forms.
- [ ] **4. Run green:** new tests; `python tests/question_import_http_test.py`, `python tests/exam_question_import_http_test.py`, `python tests/accounting_http_test.py`, `python tests/assessment_http_test.py`. Expect existing domain rejection/retention semantics unchanged.
- [ ] **5. Commit:** message `Submit app forms dynamically with safe mutation recovery`.

### Task 8: School, academics and administration page adapters

**Files:** Create `frontend/pages/school.js`, `frontend/pages/academics.js`, `frontend/pages/administration.js`, `tests/school_pages_lifecycle_test.mjs`, `tests/school_app_http_test.py`. Modify root `index.php`, all files in `modules/students`, `modules/teachers`, `modules/classes`, `modules/subjects`, `modules/exams`, `modules/settings`, `modules/super_admin`, and auth profile/password templates, plus `assets/js/routine-controls.js`.

**Interfaces:** Register stable page IDs derived from registry group + stem, e.g. `students-index`, `classes-routine`, `settings-users`; page config contains canonical endpoints and scalar/structured server data. Adapters return disposal functions and mark their coverage rows ready only after tests.

- [ ] **1. Write failing tests:** visit list→edit→list three times with exactly one table/dialog/search handler. Assert exact-class filters, teacher mapping restrictions, routine profiles/day controls, symbols, exam template locks, package expiry and super-admin preview remain correct. A stale school-switch response cannot replace the current school; context changes explicitly rebuild the authorized shell. Synthetic imports retain counts/IDs and no base-schema writes occur.
  Pin examples: `assert.equal(activeDataTables,1); assert.equal(saveHandlerCalls,1); assert.deepEqual(visibleClassIds,[selectedClassId]); assert.equal(staleSchoolSwapApplied,false)`; HTTP assertions compare pre-existing students/users/exams by primary key before and after read-only visits.
- [ ] **2. Run red:** lifecycle test and `python tests/school_app_http_test.py`; new partial pages must initially show missing adapters/config.
- [ ] **3. Extract existing inline behavior into the three adapters** without copying domain services. Convert PHP values in executable script to inert page config. Preserve native print/file/auth modes, including students' standalone admit card and exam rendered admit cards. Password changes retain existing server rules and are not exercised against real accounts.
- [ ] **4. Run green:** new tests; `python tests/class_management_http_test.py`, `python tests/subscription_teaching_http_test.py`, `python tests/routine_profiles_http_test.py`, `python tests/exam_templates_http_test.py`, `python tests/exam_http_test.py`, `python tests/packages_http_test.py`. Browser-check routine editing without saving real data and user dialogs at desktop/390px.
- [ ] **5. Commit:** message `Migrate school academics and admin pages to app lifecycles`.

### Task 9: Attendance, marks, results and print selection adapters

**Files:** Create `frontend/pages/attendance.js`, `frontend/pages/results.js`, `tests/results_pages_lifecycle_test.mjs`, `tests/results_app_http_test.py`. Modify `modules/attendance/index.php`, `modules/results/*.php`, `modules/reports/index.php`, `assets/js/attendance-controls.js`.

**Interfaces:** Register `attendance-index`, `results-index`, `results-ledger`, `results-marks-entry`, `results-print-bulk`, `reports-index`. Keep final report-card documents in `print` mode. Marks/attendance adapters expose dirty-change events and before-submit hooks to Tasks 4–5.

- [ ] **1. Write failing tests:** repeated class/exam/subject filters have one handler, exact scope and fresh BS/AD date controls; empty result classes mount without table errors. Wrong-subject edits remain denied; class-teacher correction privileges and locked/reopened marks behave identically. Invalid marks retain the draft; previous-exam IN conversion stays unchanged; canceled navigation keeps marks. Ledger grade/marks mode and report/admit issue-date print selection retain values.
  Pin examples: `assert.equal(dateControlsForInput,1); assert.equal(markInput.value,'invalid draft'); assert.equal(duplicateSubmitCalls,0)`; HTTP: `assert 'locked' in error_text.lower(); assert app.snapshot('marks') == before_marks` for the locked action; preserve existing native status behavior and use marked app validation responses.
- [ ] **2. Run red:** new lifecycle/HTTP tests.
- [ ] **3. Extract and mount results/attendance controls**, table sorting/filtering and print preparation. Dispose elective/marks/date handlers before replacement; preserve GPA/percentage/Nepali display and final landscape/print CSS. Never make client-disabled controls the marks-lock authority.
- [ ] **4. Run green:** new tests; `python tests/attendance_filters_http_test.py`, `python tests/teacher_results_http_test.py`, `python tests/progress_print_http_test.py`, `python tests/assessment_http_test.py`. Browser-check repeated grid visits, dirty cancellation and print selection.
- [ ] **5. Commit:** message `Keep marks attendance and result workflows dynamic and scoped`.

### Task 10: Learning, both banks, papers, curriculum and practice adapters

**Files:** Create `frontend/pages/learning.js`, `questions.js`, `papers.js`, `curriculum.js`, `practice.js` in the same directory; create `tests/learning_pages_lifecycle_test.mjs`, `tests/learning_app_http_test.py`. Modify every `modules/learning/*.php`, `includes/exam_question_page.php`, `includes/exam_question_editor_form.php`, `includes/exam_question_import_form.php`, `includes/exam_question_filters.php`, `includes/question_editor.php`, and existing learning/editor/import/practice JS controllers.

**Interfaces:** Register all learning group page IDs, preserving shared Question Bank/exam-bank config separation. All editor initialization uses lifecycle submit/dispose hooks; practice cleanup stops only its own audio/session UI and exits its fullscreen if active. The existing `question-list` marker/target remains supported alongside `app-page`.

- [ ] **1. Write failing tests:** editor→list→editor retains one TinyMCE/TeX dialog and editable imported math source; fragment requests cannot inject scripts via rich questions. Revisit banks/import pages and assert exact class/subject/chapter/topic selectors and canonical endpoints. Practice→away→practice stops prior sound/timers and retains readable Nepali text. Paper grids, mixed MCQ-first groups, language selection, correction privacy and print/key actions stay correct. Archived items and unassigned teacher subjects remain inaccessible.
  Pin examples: `assert.deepEqual(liveEditors.map(editor=>editor.id).sort(),expectedEditorIds.sort()); assert.equal(texInput.value,'\\frac{1}{2}'); assert.equal(oldAudioStopped,true); assert.equal(oldTimers.size,0); assert.equal(untrustedScriptRuns,0)` for the bilingual editor/practice fixture; HTTP compares unchanged stored question HTML/IDs after navigation.
- [ ] **2. Run red:** new lifecycle/HTTP tests; run existing editor tests to capture baseline behavior before extraction.
- [ ] **3. Extract inline controllers and adapt external scripts** to the page lifecycle; preserve HTML-source import sanitization, bank/topic distinctions, random practice and creator ownership. Load TinyMCE/MathJax only via fixed trusted asset IDs, with one global library instance and page-owned editor cleanup.
- [ ] **4. Run green:** new tests; `python tests/learning_sharing_http_test.py`, `python tests/exam_teacher_scope_http_test.py`, `python tests/topic_bank_workflows_http_test.py`, `python tests/exam_mixed_paper_http_test.py`, `python tests/exam_question_language_http_test.py`, `python tests/mcq_practice_http_test.py`, `python tests/question_corrections_http_test.py`. Browser-check source imports on synthetic records and repeat TeX editing/practice transitions.
- [ ] **5. Commit:** message `Migrate learning editors papers and practice to persistent navigation`.

### Task 11: Accounting and billing adapters with mutation safeguards

**Files:** Create `frontend/pages/accounting.js`, `tests/accounting_pages_lifecycle_test.mjs`, `tests/accounting_app_http_test.py`. Modify all `modules/accounting/*.php`, `includes/accounting_ui.php`, `assets/js/accounting.js`. Do not refactor financial calculations or schema.

**Interfaces:** Register all accounting page IDs except JSON-only search; distinguish invoice/receipt print requests from interactive views and POST actions. Consume existing `accountingRouteAction`, revision and idempotency fields; do not generate new keys on each retry of the same draft.

- [ ] **1. Write failing tests:** bill line add/remove/preview and student search bind once after three visits. A synthetic payment double-submit creates exactly one payment/journal set; uncertain POST does not retry or permit navigation into a second collection. Existing stale-revision rejection retains draft; cashier/auditor limitations remain enforced on clean and legacy routes. Receipt/credit/refund and reconciliation views show matching amounts after partial save; empty periods do not crash.
  Pin examples: `assert after_payment_count == before_payment_count + 1; assert after_journal_count == before_journal_count + 1; assert reconciliation['ok'] is True; assert other_school_rows == before_other_school_rows`; client test: `assert.equal(retryCount,0)` after an uncertain response.
- [ ] **2. Run red:** new lifecycle/HTTP tests and retain before/after financial table snapshots.
- [ ] **3. Extract accounting controls into its adapter**, preserve transaction/idempotency service boundaries, request statuses and safe recovery links. Keep download/print response kinds explicit. No public payment gateways, live transfers or real financial actions are part of tests.
- [ ] **4. Run green:** new tests; `php tests/accounting_money_test.php`, `php tests/accounting_review_test.php`, `python tests/accounting_http_test.py`, `python tests/empty_dashboard_http_test.py`, `node tests/accounting_preview_test.js`. Assert protected totals and unrelated school records unchanged.
- [ ] **5. Commit:** message `Add dynamic accounting workflows without duplicate financial posts`.

### Task 12: Enable complete route coverage and prove parity

**Files:** Create `tests/single_page_coverage_http_test.py`, `tests/single_page_browser_fixture.py`, `tests/single_page_security_test.mjs`. Modify `includes/routes.php`, `frontend/workspace.js`, `package.json`, `tools/verify-release.py`, `tests/deployment_test.py`, `docs/single-page-route-coverage.md`, `docs/frontend-modernization.md`.

**Interfaces:** All registry entries have an implemented page adapter or explicit reviewed response-kind exemption. Build loads the fixed module registry; global enhancement becomes default only now. Extend `npm run test:ui` and the full regression runner to execute every new `.mjs` suite as well as PHP/HTTP tests.

- [ ] **1. Write failing coverage/security tests:** every current controller is mapped and every page route is ready; generated links/actions have no registered `.php` URLs. Test all roles/two schools against canonical and legacy routes, forged HTMX, subscription expiry and revoked assignments. A 200 login/challenge body, unexpected content type or stale context never enters the shell. JSON and file bytes are unaffected. JS-disabled navigation/forms still work.
  Pin examples: `assert registered_controllers == discovered_controllers; assert unready_page_routes == []; assert leaked_php_links == []; assert canonical_access == legacy_access`; client: `assert.equal(rejectedChallengeReplacedContent,false)`.
- [ ] **2. Run red:** `python tests/single_page_coverage_http_test.py`; `node --test tests/single_page_security_test.mjs`; expect coverage failures until every adapter is ready, not artificial exemptions.
- [ ] **3. Enable global enhancement** after reconciling every coverage row. Keep new-tab/download/print/auth exceptions explicit. Wire new tests into build/verification and ensure build/private sources and runtime data remain excluded from FTP.
- [ ] **4. Run automated green:** `npm ci --no-fund`, `npm run build`, `npm run test:ui`, `npm audit`, `python tools/verify-release.py --upgrade-source "D:/A WebSite/Nepal School Management System/database.sqlite"`, `git diff --check`. Require zero failures; record actual totals rather than repeating the old 315 count. The upgrade command operates on a consistent copy, never the source.
- [ ] **5. Run disposable browser acceptance:** log in as synthetic staff/student roles; use observed links/forms across every section; verify the same document/main-shell identity through normal navigation and save. Test Back/Forward, dirty cancellation, late response, TeX import/editor, BS/AD controls, marks lock, bill/payment/receipt, print selection and 390px drawer. Save screenshots and fixture preservation results. Do not claim real MySQL/physical printing from this evidence.
- [ ] **6. Commit verified coverage and handoff** with actual results, remaining deployment gates and rollback steps; message `Enable verified single-page navigation across the school app`.

## Release boundary (not an implementation task)

Finish code and report readiness; do not push/merge/deploy main under this approval.
Before any later authorized release, obtain the production MySQL/uploads/config
backup, test a MySQL staging restore and compare protected records, verify Apache
rewrites/host challenge recovery and printed documents, inspect staged assets for
secrets/runtime files, then use the serialized non-destructive FTP workflow. A local
commit is not a database backup. Any blocked production check must be reported as
unverified, not replaced by SQLite claims.

## Plan self-review and execution handoff

Spec coverage: routing/compatibility → Tasks 1–2; response/security metadata → 3;
page-script safety → 4 and 8–11; unsaved/pending state → 5; history/navigation → 6;
saving/uploads → 7; every feature group → 8–11; complete acceptance/release safety → 12.
The five Review Focus conditions each have concrete tests in their owning tasks.
All shared function names/config fields are defined above before dependent tasks.

Recommended execution: **Native**, implementing tasks sequentially in this session
because routing, response, lifecycle and navigation interfaces depend closely on
one another. An independent whole-branch review follows the final test run; no
per-task subagents are needed. Alternative: **Subagent-driven**, a new implementer
and reviewer per task plus final review, with more independent checkpoints/context cost.

Owner checkpoint: review this plan and confirm the execution method before coding.
