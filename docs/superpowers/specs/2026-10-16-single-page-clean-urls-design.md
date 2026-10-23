# Single-page navigation and clean URLs

Date: 2026-10-16
Status: Approved by owner in chat; implementation plan awaiting review.
Base: `dbcfa45` on `codex/tailwind-alpine-htmx-migration`.

## 1. Agreed outcome

Make the existing Nepal School Management System feel like a single-page app:
ordinary navigation, filtering, pagination and saving update the working area
without reloading the whole document. Keep the header/sidebar available, display
clean URLs without `.php`, and preserve direct links, bookmarks and browser history.
Continue using PHP, the existing MySQL database, Tailwind CSS, Alpine.js and HTMX
on InfinityFree free hosting. The owner confirmed production already uses MySQL.

The owner approved this approach and subsequently approved this written spec in
chat. The next checkpoint is implementation-plan review and execution-method
selection. No production publishing is authorized here.

## 2. Scope and boundaries

Cover existing interactive pages in Students, Teachers, Classes/Sections, Subjects,
Attendance, Exams, Results, Reports, Learning/Question Bank, Exam Questions/MCQs,
paper generators, Accounting/Billing, Settings and Super Administration. The same
role, subscription, school, teacher-subject and content-ownership rules must apply.

Normal same-origin links, GET filters and mutations should use partial updates
after their page controllers have been migrated and verified. Merely intercepting
links while breaking page scripts does not satisfy this scope. Completion requires
an inventory with every interactive route assigned and tested.

Keep intentional document boundaries: initial entry, explicit Refresh, authentication
recovery, external destinations, downloads and dedicated print/new-window documents.
Print selection/forms inside the app remain dynamic; opening the final printable
document or file is not an app-navigation refresh. A host security challenge may
require a user-triggered full-page recovery. Never weaken that protection.

This is online progressive enhancement, not offline storage, an installable PWA,
or a promise of real-time multi-user updates. Cross-user live synchronization is
outside scope. Do not add React, a second API backend, Node hosting, queues or sockets.

## 3. Selected architecture

Retain PHP/PDO domain services and extend the current HTMX foundation. A separate
JavaScript/API rewrite was considered but rejected because it would duplicate
authorization and validation and expand migration risk without serving the chosen
hosting constraint. Clean URLs alone would not meet the no-refresh requirement.

Use four explicit interfaces:

1. A route registry maps a public path to an existing controller and response kind.
2. A response layer emits either a full document or a validated app fragment.
3. A navigation layer controls partial requests, history, focus and error recovery.
4. Per-page lifecycle modules initialize and dispose editors, tables and handlers.

The shell persists between ordinary requests. Server-provided metadata updates the
page title, active navigation, context labels and permission-dependent menu state.
Do not trust role or permission values supplied by the browser. On logout, expiry
or school-context change, discard old sensitive content and rebuild authorized
context; a full authentication boundary is acceptable.

## 4. Clean URLs and compatibility

Use an explicit allowlist, not filesystem-derived user input. Representative routes:

| Public URL | Existing controller |
| --- | --- |
| `/` | `index.php` |
| `/students` | `modules/students/index.php` |
| `/students/add` | `modules/students/add.php` |
| `/students/edit?id=123` | `modules/students/edit.php` |
| `/exams` | `modules/exams/index.php` |
| `/exams/view?id=123` | `modules/exams/view.php` |
| `/results/marks-entry` | `modules/results/marks_entry.php` |
| `/learning/question-bank` | `modules/learning/questions.php` |
| `/exam-questions` | `modules/learning/exam_questions.php` |
| `/exam-mcqs` | `modules/learning/exam_mcqs.php` |
| `/accounting/payments` | `modules/accounting/payments.php` |
| `/settings/users` | `modules/settings/users.php` |

Keep record IDs and filters as validated query parameters initially; changing their
storage or inventing record slugs is unnecessary. Paths are relative to APP_URL so
subdirectory installations remain supported. The browser URL changes between
sections, but shows no controller extension. This is not concealment of public code
or an authorization control.

Add a PHP front controller and shared registry. Apache rules and the local PHP router
use the same mapping. Real public assets/uploads bypass page routing; private files,
build sources, database files, traversal, unknown paths and unregistered controllers
remain blocked. Avoid rewrite loops and preserve query strings and request bodies.

Generate canonical URLs on the server for links, form actions, redirects and AJAX
endpoints. Legacy `.php` entry points remain compatible. Redirect only safe ordinary
GET navigation to canonical paths; do not convert POST to GET, redirect file streams
or JSON requests into pages, or lose upload bodies. Direct legacy POST requests must
receive exactly the same guards and service validation as canonical requests.

Guards currently depend on SCRIPT_NAME to determine module/file permissions. Expose
the resolved controller identity through a trusted server-side helper and migrate
all such consumers together. A request must never gain the front controller's
identity instead of the target's restrictions. No client header or query parameter
may override the selected controller.

## 5. Dynamic response and navigation contract

Normal entry returns the complete shell, page styles and required controllers.
App requests run the same authentication/authorization/controller logic and return
the working-area fragment plus explicit, escaped metadata. Require an app-specific
response marker and expected content type before replacing content. HTMX headers
select rendering only; they never authorize access.

Keep the current narrower `questionBankPanel` contract working during migration,
then integrate it under the page lifecycle so panel updates do not destroy the shell
or duplicate event handlers. Do not globally enable boosting before route coverage
and script lifecycle are ready.

Use same-origin requests, loading feedback, request cancellation/latest-response
rules and correct URL/history updates. Preserve modified clicks, new-tab links,
hash anchors and downloads. Revisit/Back/Forward fetch authorized content without
reloading the shell during normal navigation. Store no rendered school data, form
values or financial details in localStorage/sessionStorage history snapshots.

On navigation, focus the new page heading and restore sensible scroll; on filtering,
retain useful input focus and announce result counts. Mobile navigation closes after
selection. Honor reduced-motion preferences and prevent whole-page width overflow.

## 6. Saving and unsaved changes

Convert ordinary forms to partial POST handling without altering their domain
services. Retain native constraint checks, server validation, CSRF, method rules,
role/school references, marks locks and financial atomicity. Successful mutations
return the next authorized view and a clear notification; validation errors retain
submitted values and focus the error summary/field. POST redirects need an explicit
partial-response contract, not a second accidental form submission.

Use multipart requests for imports and image uploads, respecting current file limits.
Synchronize rich editors into form fields before validation/submission. Preserve
password-confirmation and irreversible-action confirmations. GET confirmations for
legacy mutation links must still require an explicit CSRF-protected POST.

Disable duplicate submits while pending. Preserve existing financial idempotency and
revision checks. Never auto-retry a mutation after an uncertain network result; show
that its outcome is unknown and provide a safe status/reconciliation path. Navigation
must not abort an in-flight payment and encourage a second payment.

Track dirty forms, including editors, marks grids and routine editors. Confirm before
discarding changes on navigation, Back/Forward or school-context changes. Preserve
the standard browser warning on reload/close where supported. Clear dirty state only
after confirmed save, explicit discard or a verified unchanged reset.

## 7. Page lifecycle and asset safety

Inventory embedded scripts and existing DOMContentLoaded handlers. Move page behavior
into named initializer/disposer modules with a registry keyed by trusted page IDs.
Pass escaped server data through structured data blocks, not generated executable
code. Repeated visits must not redeclare global lexical variables, duplicate tables,
leave timers/listeners behind, or accumulate modal backdrops/observers.

Destroy TinyMCE instances, table instances, practice/audio/fullscreen state and other
page-owned resources before removal. Reinitialize Nepali BS/AD controls, MathJax,
imports, chapter/topic selectors, routines, symbols and printing controls after swaps.
Keep global security/notification listeners single-instance. Bind new fragments to
their actual live DOM nodes, not detached replacement targets.

Do not enable arbitrary script execution or eval to make legacy inline scripts work.
Question/answer/notes HTML is untrusted content and must never act as navigation
metadata or runnable scripts. Load required styles/assets with deduplication and
cache-busting; never fetch dependencies or destinations named by untrusted rich text.

## 8. Security and error behavior

Keep private/no-store headers, same-origin policy, escaped metadata, server checks
and file protections. A student or teacher must not access extra routes by using a
clean URL, forging HTMX headers, visiting history or following an old bookmark.
Retain distinctions between viewing, printing, editing, publishing and corrections.

Handle 401/expired session, 403, 404, 419, 422, 500, timeout, canceled navigation and
offline conditions explicitly. Expected permission/validation errors render safely
without an app reset. Do not show raw exceptions or pretend a failed save succeeded.
Login HTML or InfinityFree security-challenge HTML must not replace an app fragment.
Offer a same-origin full-page recovery; never bypass the challenge or retry unsafe
mutations. No production performance claim follows from choosing these libraries.

## 9. Data preservation and rollout

No schema or data conversion is needed for this navigation change. Preserve MySQL
tables, IDs, content, marks, password hashes, accounting amounts/audits and uploads.
Do not reseed, normalize or import base schema SQL. Work only in the linked migration
worktree, not the owner's dirty original checkout.

Build assets locally/CI and deploy static bundles/PHP only. Keep database files,
uploads, private configuration, backups and build sources excluded from FTP. Do not
merge/push main or deploy this change until separately authorized and verified.

Before production release: obtain a complete MySQL export plus uploads/config backup,
test a staging restore, verify route/role and important workflows against MySQL,
compare protected data, then perform a code-only release. Keep the previous code
release available for rollback. Do not restore an old database to roll back an
asset-only change and discard newer school work.

## 10. Acceptance and verification

- Every interactive route has a canonical mapping and declared page/JSON/file/auth
  kind; unknown/traversal/private routes fail closed. Apache/local routing agree.
- Direct links, old links, reload, query filters, POST, uploads and subdirectory
  installations work. The resolved module identity preserves all permission rules.
- Ordinary navigation, filters, paging and saves keep the same browser document
  and shell. Test Back/Forward, rapid requests, dirty-form cancel/discard and focus.
- Repeat visits to script-heavy pages without duplicate listeners/tables, broken
  editors, missing TeX, stale date controls, timer/audio leaks or console errors.
- Test all roles and two schools, including forged IDs/HTMX headers, revoked access,
  subscription expiry, super-admin preview, locked marks and private question content.
- Verify student/teacher/class/subject workflows, attendance ranges, symbols, marks,
  curriculum, both banks/imports, paper generation and print selection.
- Verify invoices, payment posting, receipts, refunds/credits and reconciliation;
  duplicate clicks/network interruptions must not duplicate financial transactions.
- Run the full regression suite, add route/fragment/history/form lifecycle coverage,
  test desktop and 390px behavior, and snapshot protected synthetic tables.
- Actual-host MySQL staging and printed-document layout checks are separate release
  gates. Local SQLite evidence alone is not MySQL certification.

The implementation plan must enumerate route coverage and verification checkpoints
before enabling global navigation. Remaining page exceptions must be reported,
not silently labeled a completed single-page migration.

## References

- [HTMX boosted navigation/forms](https://htmx.org/attributes/hx-boost/)
- [HTMX history, configuration and security](https://htmx.org/docs/)
- [Apache URL remapping](https://httpd.apache.org/docs/2.4/rewrite/remapping.html)
- Existing branch baseline: `docs/frontend-modernization.md`.
