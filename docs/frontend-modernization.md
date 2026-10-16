# PHP / MySQL frontend modernization

## Scope and data preservation

The live site already uses MySQL (confirmed by the owner). This release does **not**
convert, recreate, seed, truncate, normalize, or replace that database. It adds no
schema migration. Existing PHP/PDO school services, record IDs, password hashes,
uploads, exam marks, question ownership, subscriptions and financial transactions
remain the source of truth. SQLite support is retained for isolated local tests.

Work is on `codex/tailwind-alpine-htmx-migration` in the linked worktree. The owner's
dirty original checkout is not a migration target and must not be overwritten.

## Implemented

- Locally compiled Tailwind 4 component/utility styling replaces Bootstrap CSS in
  the shared layout, login, report card and admit-card pages. Existing server
  template component names map to the new design system to preserve form semantics
  and print layouts rather than rewrite school workflows.
- Alpine's CSP build manages responsive navigation, section search and account/
  school menus without `eval` or a frontend application server.
- Native modal, tab, accordion, dropdown, toast and alert components replace the
  Bootstrap JavaScript runtime. School forms and the shared mathematical editor
  use `ErpUI`. Dialogs trap/restore keyboard focus and retain lifecycle events.
- HTMX progressively updates Question Bank, Exam Questions and MCQ filters/pages.
  The same PHP route and authorization run for complete and partial responses.
  Full links and GET forms still work without HTMX. Selected filters remain in
  the URL; dependent subject/chapter/topic controls are reset on parent changes.
- Partial responses carry `X-ERP-Fragment: question-list`, private/no-store
  caching and CSRF-protected mutation forms. Arbitrary HTML/login/hosting challenges
  do not replace content. Interrupted requests offer a same-origin full-page link.
  HTMX script execution, eval and sensitive localStorage history caching are off.
- Accounting's empty-payment chart now renders safely. Read-only status messages
  no longer falsely say that a record was saved.
- Icons, table dependencies and notification dependencies are self-hosted. Existing
  sortable ledgers still use isolated jQuery/DataTables; rich editors, MathJax and
  specialist date/print controllers retain their established behavior.

This is **not** a claim that every module has been rebuilt as an HTMX screen or that
every historical controller has been rewritten. Marks, payments and other mutations
deliberately retain their verified PHP POST/CSRF/redirect paths. Further module-level
UX changes can be delivered incrementally without replacing the data model.

## Build and verify

Node is needed **locally or in CI only**, never on InfinityFree:

```text
npm ci --no-fund
npm run build
npm run test:ui
python tools/verify-release.py
```

The lockfile pins the dependency graph. Commit the generated `assets/dist` files
and public fonts so a normal PHP checkout is runnable without npm on the host.
`tools/build-assets.mjs` generates a hash manifest to invalidate stale asset caches.
Do not edit compiled assets directly. `frontend`, `tools`, `node_modules` and package
metadata are blocked from HTTP and excluded from FTP publication.

The regression runner creates only temporary databases and writes logs to ignored
`scratch/ui-migration`. `--upgrade-source PATH` verifies a **consistent copy** of an
existing SQLite database; it never migrates the source in place. The browser fixture
is also disposable and asserts unchanged school/financial/question records on exit.

## InfinityFree constraints

- Build locally/CI and upload PHP/static files. No Node runtime, SSH worker,
  WebSocket service, Redis or queue daemon is required by this release.
- Serve HTMX from the same scheme, host and subdomain as PHP. InfinityFree's browser
  security challenge can interrupt AJAX after a long session; use the recovery link.
  Do not disable the host's security protection or move requests to another domain.
- This changes application assets, not free-host CPU, process or request quotas.
  Hosting-latency improvements are not certified by local functional tests.

Primary references: [local builds](https://forum.infinityfree.com/t/can-i-ssh-into-the-host-and-run-commands-like-npm-install/59811),
[hosting browser security](https://forum.infinityfree.com/t/browser-security-system-features-and-limitations/49353/6),
[HTMX documentation](https://htmx.org/docs/),
[Tailwind CLI](https://tailwindcss.com/docs/installation/tailwind-cli).

## Before production release

1. Export the complete live MySQL database through the hosting control panel's
   phpMyAdmin and back up uploads/private configuration. Keep backups outside the
   public web root. A source-code commit is not a database backup.
2. Restore the export into an isolated MySQL staging database using the existing
   application schema; do **not** import the repository's base SQL over live data.
3. Test this branch on staging, including role/school isolation, mark locking,
   BS/AD dates, existing questions and TeX, imports, report/admit/ledger printing,
   billing/receipts and reconciliation. Compare table/row counts and sampled IDs,
   marks, totals and password hashes before/after the deployment.
4. Confirm desktop and 390px navigation/forms plus HTMX filters, paging, back/forward,
   login-expiry and full-page recovery behavior on the actual host.
5. Deploy the reviewed code-only release after approval. Keep production
   `config/config.php`, `config/local.php`, uploads, backups and database files excluded.
   The FTP workflow remains main-only, serialized and non-destructive.
6. On failure, roll back application assets/PHP to the previous release. Since this
   change has no database migration, do not restore an old database and discard school
   work performed after the backup.

No authenticated production MySQL verification or deployment of this branch is
implied by local SQLite, JavaScript or browser test results.

## Local verification (2026-10-16)

- Full regression: **315/315 commands passed**, including 216 PHP syntax checks,
  PHP/JavaScript domain suites, HTTP workflows, deployment exclusions, correction
  concurrency and consistent-copy upgrade preservation.
- Frontend suite: **11 checks passed**, covering modal/accordion/tab behavior,
  vendor compatibility, generated hashes, safe dynamic responses, same-origin/GET
  restrictions, private history handling, post-swap initialization and notifications.
- `npm audit`: zero reported vulnerabilities in the installed lockfile graph.
- Disposable browser checks: teacher/school-admin login, account menu, dynamic
  class/subject/chapter filters, pagination, language selection, browser Back,
  user-account dialog without saving, accounting empty state, result table,
  ledger and 390px navigation/search. Fixture exit confirmed all protected tables
  unchanged. Physical printing and actual-host MySQL staging remain release gates.
- Original local database SHA-256 remained
  `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.
