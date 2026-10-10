# Accounting Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete reliable school accounting and billing in the existing PHP ERP, with exact money, immutable postings and reconciled reports.

**Architecture:** Preserve `SchoolAccounting` as the authorized facade and the additive `ac_*` tables. Extract bounded money arithmetic, transactional journal posting, fee resolution and report queries into focused PHP units. UI and CLI call the facade, never duplicate posting logic.

**Tech Stack:** PHP 8.1+ on the existing 64-bit runtime, PDO SQLite/MySQL/InnoDB, native ZipArchive/SimpleXML XLSX, browser print/PDF-save, Bootstrap/JavaScript and Python integration tests. No Java rewrite, external ledger or live payment-gateway dependency.

**Spec:** `docs/superpowers/specs/2026-10-10-school-accounting-design.md`; all accounting sections and acceptance items 4–17.

## Global Constraints

- "keep PHP and the current SQLite/MySQL database support"
- "reuse school, student, class, section, academic-year, exam and subject IDs"
- "preserve educational and financial history"
- "enforce school isolation"
- "do not create fictitious financial transactions in the real database"
- "Persisted money remains integer paisa; quantity uses thousandths and percentages use basis points."
- "Issued transactions are never destructively edited or deleted."
- "tax rates and fiscal periods are school-configured rather than assumed"
- "No read-only screen may create accounting configuration as an unnoticed side effect."
- "Git publication and deployment are separate from implementation and require a publication request."

## Review Focus

- Near-limit monetary products must be exact or reject before PHP produces floats/overflow (C2).
- A student profile/rate changed after issue must not rewrite an old bill or its allocation grouping (C4/C12).
- Cancellation or status change between preview and post must not bill an ineligible subscription/student (C7/C9).
- A reversal posted after the report cutoff must not erase the earlier collection from a historical report (C12).
- A malicious attachment or stale form must not expose files, overwrite another record or silently lose submitted bill rows (C10/C13).

## File map and interfaces

- Existing `includes/accounting_schema.php`: portable extension migrations and provisioning metadata.
- New `includes/accounting_money.php`: `acDecimalUnits`, `acAmount`, `acMoney`, `acWords`, `acCheckedAdd(int,int): int`, `acMulDiv(int,int,int): int` and `acRoundRatio(int,int): int`; no DB or authentication.
- New `includes/accounting_ledger.php`: `AccountingLedger(PDO $db,int $school,int $actor)`, `transaction(callable $work): mixed`, `post(string $type,int $source,string $date,string $description,array $lines,?int $reversal=null): int`. Lines are `[account_id,debit_minor,credit_minor,student_id|null]`. Posting is internal to authorized facade workflows, requires an active transaction, and validates scope/period/balance.
- New `includes/accounting_fees.php`: read-only `AccountingFeeEngine(PDO $db,int $school)`, `resolve(array $student,int $year,string $start,string $end,?int $exam,string $frequency,array $settings): array`; returns invoice-row inputs plus stable item/cycle business keys and rate provenance.
- New `includes/accounting_reports.php`: read-only `AccountingReports(PDO $db,int $school)`, `query(string $type,array $filters): array`; result is `['headers'=>array,'rows'=>array,'numeric_columns'=>array,'totals'=>array,'basis'=>string]`.
- Existing `includes/accounting_service.php`: actor/action authorization and business workflows. Existing public method signatures remain compatible unless an optional argument is explicitly added below.
- Existing `includes/accounting_ui.php`, `assets/js/accounting.js`, `assets/css/accounting.css`, `assets/js/school-dates.js`, and `modules/accounting/`: native pages, forms, search and print.
- New PHP suites: `tests/accounting_schema_test.php`, `tests/accounting_money_test.php`, `tests/accounting_test.php`, `tests/accounting_billing_test.php`, `tests/accounting_reports_test.php`, `tests/accounting_export_test.php`.
- New integration suites: `tests/accounting_http_test.py`, `tests/accounting_concurrency_test.py`, `tests/accounting_mysql_test.php` and `tests/accounting_worker.php`. Consume A1's test helpers; workers require an explicit disposable DB path/database and refuse normal production configuration.

Each PHP suite obtains `$db` and `$f` from `erpTestFixture()`, constructs the school-1 administrator facade, and creates one synthetic open period covering `2026-01-01` through `2026-12-31`. Tests use distinct submission keys except when intentionally testing replay; no external gateway/network payment is performed.

## Task C1: Safe extension migration and write-free viewing

**Files:** Modify `includes/accounting_schema.php`, `includes/database.php`, `includes/schema.php`, `includes/accounting_service.php`, `tests/upgrade_test.php`; create `tests/accounting_schema_test.php`.

**Interfaces:** Produce `SCHOOL_SCHEMA_VERSION = 8`, preserve `migrateSchoolExtensions(PDO): void`, and add `provisionSchoolAccounting(PDO $db,int $school): void`. Provisioning is idempotent, called by migration/new-school provisioning, not the finance facade constructor.

- [ ] Write migration tests for fresh DB, version 6, and synthetic version 7 with existing invoices/journals. Assert `SCHOOL_SCHEMA_VERSION === 8`, repeated migration preserves IDs/counts/all monetary values, a pre-upgrade SQLite backup exists, and auditor/preview construction leaves a before/after table snapshot equal. Assert newer unsupported schema versions reject without downgrade writes.
- [ ] Run `php tests/accounting_schema_test.php`; reproduce the constructor write and old-version expectation failures before changes.
- [ ] Implement version 8 after the current version-7 extension. Add nullable `students.admission_date`, `withdrawal_date`, `academic_stream`; `ac_accounts.cash_bank_kind` (`cash`/`bank`/null); invoice `revision`, `snapshot_json`, `opening_reference` (unique per school when non-null); expense `revision`; credit `receivable_minor`, `refund_minor`, `tax_minor`, `request_hash`; subscription `billing_calendar`, `anchor_day`. Add school-scoped `ac_charge_posts` (unique business key), `ac_generation_previews` (kind/token/actor/input/payload/fingerprint/expiry), `ac_credit_items`, `ac_credit_refunds`, `ac_fine_policies`, `ac_fine_posts`, `ac_expense_attachments` and append-only `ac_period_close_snapshots`. Use `dbColumns` and driver-specific index checks; backfill metadata only from persisted records. Never fabricate admission dates or modify old journal amounts.
- [ ] Move the existing COA/method initialization into provisioning, mark actual cash/bank controls, add the refund-payable liability control, and make construction a read-only actor/settings lookup. Ensure new-school provisioning invokes accounting provisioning. Avoid rerunning the version-6 CDC migration on version 8; update the backup/version guards consistently.
- [ ] Run schema tests, `php tests/cdc_2083_test.php`, and the updated upgrade test against a consistent OS-temp copy; expect schema 8, intact records, backup, integrity and repeat migration. Resume P2's read-only/role tests here.
- [ ] Commit only C1 files as `fix: make accounting migrations additive and read-only views safe`.

## Task C2: Exact bounded money shared by server and preview

**Files:** Create `includes/accounting_money.php`, `tests/accounting_money_test.php`; modify `includes/accounting_service.php`, `assets/js/accounting.js`.

**Interfaces:** Produce the money helpers above and `AC_MAX_MINOR = 1000000000000`. Inputs are decimal strings/integers, never binary floats/scientific notation; `acMulDiv($a,$b,$denominator)` computes non-negative multiplication/division with half-up rounding without an overflowing intermediate. Every amount/sum/result is bounded by the same limit; signed reporting sums allow its negative counterpart.

- [ ] Write assertions: `acDecimalUnits('2500.00') === 250000`, `acMulDiv(1999,2500,1000) === 4998`, `acMulDiv(1000000000000,1000000000000,1000000000000) === 1000000000000`; reject `1e3`, `-1`, a float input, excess decimals, zero denominator and `acCheckedAdd(AC_MAX_MINOR,1)`. Assert three Rs. 0.01 lines with a Rs. 0.01 bill discount total exactly 2 paisa; include synthetic applicable tax and fractional quantities.
- [ ] Run `php tests/accounting_money_test.php`; require the large-product/overflow reproduction to fail before changing arithmetic.
- [ ] Extract helpers and implement checked integer quotient/remainder multiplication and addition; do not add a BCMath dependency or use floating-point intermediate ratios. Replace unchecked money products/totals in invoice, fee, credit and report paths. Mirror validation/bounds in the JavaScript BigInt preview while retaining server authority.
- [ ] Run money tests, `node --check assets/js/accounting.js` and a browser preview/server comparison for the Rs. 3,000 example and rounding cases; expect identical paisa totals.
- [ ] Commit `fix: bound accounting arithmetic without floating point`.

## Task C3: Transactional journals, payment accounts and collection

**Files:** Create `includes/accounting_ledger.php`, `tests/accounting_test.php`; modify `includes/accounting_service.php`, `modules/accounting/settings.php`, `modules/accounting/payments.php`, `modules/accounting/student.php`.

**Interfaces:** Consume C1/C2. Produce the ledger contract above, `SchoolAccounting::cashBankAccount(int $id): array`, and private `createInvoiceInside(array $input,bool $issue): int` for reuse within a single ledger transaction. Preserve `recordPayment(array): int`, `applyAdvance(array): int`, `reversePayment(int,string,string,bool): void`, `refundAdvance(array): int`.

- [ ] Write `test_receivable_is_not_payment_account`: `erpReject(fn()=> $ac->saveMethod(['name'=>'Invalid','account_id'=>$ac->control('receivable')]), 'Receivable method denied')`. For an issued Rs. 3,000 bill, pay Rs. 2,000 then Rs. 1,500 allocating Rs. 1,000; assert due `0`, advance `50000`, and `reconcile()['ok'] === true`. Replaying each request returns its old ID; changed-key data, over-allocation, foreign account/invoice/student, inactive actor and unverified bank reference reject without writes.
- [ ] Run `php tests/accounting_test.php`; confirm invalid-control acceptance fails the test before implementing the fix.
- [ ] Centralize locking/atomic journal posting; retain one per-school transaction lock and source/number uniqueness on SQLite/MySQL. Require designated cash/bank assets in method creation, payment, expense payout and refund paths; do not trust UI restrictions. Validate each journal line and open fiscal period. Reversals retain original rows, write linked opposite journals and reject refund/reversal beyond available balances. Normalize/hash validated replay inputs instead of transport-only POST fields.
- [ ] Run accounting/schema/money suites and `php tests/operations_test.php`; expect exact-balanced journals, no failed-action side effects and both receivable/advance subledgers reconciled.
- [ ] Commit `fix: enforce balanced collections and valid cash-bank accounts`.

## Task C4: Editable drafts and immutable issued invoices

**Files:** Modify `includes/accounting_service.php`, `modules/accounting/create_bill.php`, `modules/accounting/invoice.php`, `modules/accounting/invoices.php`, `assets/js/accounting.js`; extend `tests/accounting_test.php`; create `tests/accounting_http_test.py`.

**Interfaces:** Add `invoiceRevision(array $invoice): string` and `SchoolAccounting::updateInvoiceDraft(int $id,array $input,string $revision): void`; consume `previewInvoice(array): array`, `createInvoice(array,bool): int`, `issueInvoice(int): void`.

- [ ] Write `test_draft_update_and_frozen_issue`: Rs. 3,000 draft changed to Rs. 3,100 stays unposted; assert `erpEqual((int)$ac->record('ac_invoices',$draft)['total_minor'],310000,'Updated draft total')`, old revision rejects, issuing creates exactly one numbered journal, and editing an issued bill rejects. After issue, change student name/class and catalog rate, then assert the rendered bill still shows its original identity/class/year/item/rate. Test two customers with equal names do not gain each other's student invoices.
- [ ] Run accounting and new HTTP suites; expect absent draft update/historical label behavior to fail before changes.
- [ ] Implement revisioned draft updates, canonical idempotency, school/customer validation and issue-time immutable snapshots. Retain entered rows/contacts/calendar after errors. Add explicit validated preview, draft edit and issue actions; render the stored snapshot on issued documents. No issue number or earned-income journal is created for a draft.
- [ ] Run accounting/HTTP suites and verify draft→edit→preview→issue→print in the disposable browser; expect the requested tuition/exam bill subtotal and total Rs. 3,000.00 and no duplicate issue on refresh.
- [ ] Commit `feat: add revision-safe bill drafts and issue snapshots`.

## Task C5: Correct opening dues, credit notes and paid-bill refunds

**Files:** Modify `includes/accounting_service.php`, `modules/accounting/invoice.php`, `modules/accounting/student.php`; create `modules/accounting/credit.php`; extend `tests/accounting_test.php`, `tests/accounting_http_test.py`.

**Interfaces:** Preserve `openingDue(array): int`, `creditInvoice(array): int`; add `refundCredit(array $input): int`. Credit input supports existing `amount` and optional per-invoice-line amounts. Credit notes persist total, tax, receivable-reduction and refund-payable portions separately. Refund input names credit ID, positive amount, designated cash/bank account, date, reason, confirmation and idempotency key.

- [ ] Write the reproduced opening-correction test: import a verified Rs. 100 opening due, credit Rs. 100, assert income before/after is identical, receivable falls by `10000` and the equity counterpart reverses. A fully paid Rs. 100 ordinary bill credited in full must leave AR `0`, create refund payable `10000`, then refund to cash exactly once; repeat key returns old ID. Assert partial credits' tax totals cannot exceed original line tax, and duplicate verified opening references under fresh form keys do not duplicate dues.
- [ ] Run accounting tests; expect current opening-credit and paid-credit behavior to fail before changes.
- [ ] Use immutable source/item snapshots to allocate credits with exact remaining-tax rounding; cap cumulative credits at the uncredited issued amount. Credit opening equity for opening corrections, ordinary contra-income/tax for fee corrections, AR only for still-due amounts, and refund-payable liability for paid portions. `invoiceBalance` subtracts only the receivable credit portion. Implement approved refunds against the credit's remaining payable balance, retaining linked journals/audit. Keep void limited to eligible unadjusted unpaid bills; no permanent deletion.
- [ ] Run accounting/HTTP suites; assert receivable, advances and refund-payable reconcile and print a uniquely numbered credit note with date/reason/item/tax detail.
- [ ] Commit `fix: preserve opening equity and audit credit refunds`.

## Task C6: Effective fee rules, enrollment and fee copying

**Files:** Create `includes/accounting_fees.php`, `tests/accounting_billing_test.php`; modify `includes/accounting_service.php`, `modules/accounting/fees.php`, `modules/students/add.php`, `modules/students/edit.php`, `includes/nepali_dates.php`, `assets/js/school-dates.js`.

**Interfaces:** Produce `AccountingFeeEngine::resolve` above, with one aggregated invoice input row per applicable item/cycle (`quantity='1'`, prorated combined `rate`, and segment `provenance`), `SchoolAccounting::previewFeeCopy(array $input): array` and `copyFeeRules(array $input): array`. Add private facade helpers `storePreview(string $kind,array $input,array $payload): array` and `verifyPreview(string $kind,string $token,string $fingerprint,array $currentPayload): array`; these persist a 30-minute actor/school-scoped preview and reject changed, foreign, expired or wrong-kind tokens. Copy APIs perform authorization before either helper. Use the existing fee/scholarship APIs; stream matching uses persisted `students.academic_stream`, not nonexistent class fields.

- [ ] Write `test_fee_precedence_and_rate_segments` (student over section over class), elective/compulsory subject eligibility and explicit optional-fee opt-in. With a 30-day test month, rates Rs. 3,000 for days 1–15 and Rs. 6,000 for days 16–30 must prorate to Rs. 4,500: `erpEqual($resolved[0]['rate'],'4500.00','Effective-rate segments')`. Inactive/withdrawn/out-of-year students are excluded. Missing admission date is displayed as unknown and treated by explicit assignment effective dates, never inferred from import time. Assert Grade 11 Science stream applies only to a student with that stored stream.
- [ ] Write copy-preview tests asserting counts, selected target class/year, changed-preview rejection, tenant denial and no changes to old issued bills; run `php tests/accounting_billing_test.php` to reproduce incorrect mid-month/stream resolution.
- [ ] Implement disjoint effective-date segments and checked daily proration, explicit admission/withdrawal and optional Grade 11/12 stream fields with AD/BS inputs, and school-validating section selectors. Fee copying uses the generation-preview contract and inserts new effective rules after confirmation; do not overwrite historical rates or create duplicate ERP masters.
- [ ] Run billing, accounting, CDC and general HTTP suites; expect exact segmented totals and preserved results/enrollment IDs. Verify affected-student counts and fee/year copy forms.
- [ ] Commit `feat: resolve effective school fees and preview fee copying`.

## Task C7: Reviewed, idempotent recurring bill generation

**Files:** Modify `includes/accounting_service.php`, `modules/accounting/generate.php`, `config/accounting_job.php`; extend `tests/accounting_billing_test.php`, `tests/accounting_http_test.py`; create `tests/accounting_worker.php`, `tests/accounting_concurrency_test.py`.

**Interfaces:** Add `prepareRecurring(array $input): array` returning `token`, `fingerprint`, affected students, rows, totals and expiry; preserve `generateRecurring(array): array` returning `issued`, `skipped`, `errors` and `run_id`. Consumption requires `preview_token`, `preview_fingerprint`, confirmation and the original filters. Use C6's fee engine/preview helpers and C3's internal invoice creation; normalize each preview's source data independently of which charge keys are already posted so successful-item retries remain valid.

- [ ] Write `test_recurring_replay_and_missing_item`: test monthly/quarterly/annual/one-time/exam keys, selected-student/class/section/year filtering and actual BS month length. Post a month twice: `erpEqual(count($retry['issued']),0,'Replay creates no bills')` and one charge per school/student/item/cycle. Add a new item to the same month: only that item is issued. Change a rate or deactivate a student after preview: post rejects until re-preview. A partial failed run retry must skip successful items. Define a concurrency worker `php tests/accounting_worker.php --operation=generate --input=<temp-json-path>` that refuses a non-temp DB and uses only synthetic inputs.
- [ ] Run billing and concurrency suites; expect missing preview checks/business-key behavior to fail before changes.
- [ ] Persist bounded-expiry previews and deterministic fingerprints over normalized inputs, rates, eligible students and snapshots. At posting revalidate source data, then atomically check/insert charge keys with each invoice. Record per-student/cycle outcomes. Provide preview and confirmation through both UI and CLI; no unattended scheduler is installed.
- [ ] Run `php tests/accounting_billing_test.php`, `python tests/accounting_concurrency_test.py`, `python tests/accounting_http_test.py`; expect exactly one committed charge under simultaneous workers, distinct numbers and retry-safe run records.
- [ ] Commit `feat: make recurring school billing previewed and duplicate-safe`.

## Task C8: Scholarships, approval limits and late fines

**Files:** Modify `includes/accounting_service.php`, `includes/accounting_fees.php`, `modules/accounting/fees.php`, `modules/accounting/settings.php`, `modules/accounting/student.php`; extend `tests/accounting_billing_test.php`, `tests/accounting_http_test.py`.

**Interfaces:** Add `saveFinePolicy(array $input): int`, `previewFines(string $asOf,array $filters=[]): array` (token/fingerprint/rows/total_minor/expiry), `applyFines(array $input): array` (issued/skipped/errors), `waiveFine(array $input): int`. Consume credit/preview/journal contracts; fine input contains grace days, fixed/percentage amount, one-time/daily behavior, cap, scope, effective dates and income mapping.

- [ ] Write `test_fine_grace_cap_and_replay`, date-limited fixed/percentage/exemption tests and accountant discount-threshold denial. Synthetic policy: Rs. 10/day after 5 grace days, Rs. 100 cap; 8 overdue days yields `erpEqual($preview['total_minor'],3000,'Fine after grace')`, repeated evaluation yields no duplicate, and later totals never exceed Rs. 100. A partial payment reduces percentage-fine base according to the saved policy; an administrator's confirmed waiver posts a credit with a reason, not a deletion.
- [ ] Run billing/HTTP suites; expect absent fine-policy behavior to fail.
- [ ] Implement administrator-approved policy setup, eligible-fee base snapshots, deterministic fine business keys, preview/post and waiver workflows. Leave fines/tax zero/disabled until configured; no legal tax rate or school penalty is assumed. Scholarship and discount calculations use the same bounded money engine and visible policy.
- [ ] Run billing/accounting/HTTP suites; expect balanced fine/waiver postings, date eligibility and no repeated penalty duplication.
- [ ] Commit `feat: add approved scholarship and late-fine policies`.

## Task C9: Calendar-aware subscriptions without cancel/post races

**Files:** Modify `includes/accounting_service.php`, `modules/accounting/subscriptions.php`, `config/accounting_job.php`; extend billing/HTTP/concurrency suites and the worker.

**Interfaces:** Preserve `saveSubscription(array): int`, `changeSubscription(int,string,string): void`; extend to `generateSubscriptions(string $through,?array $confirmation=null): array` and add `previewSubscriptions(string $through): array`. Confirmation supplies `preview_token`, `preview_fingerprint`, and `confirm`; generation without reviewed confirmation rejects. Use C6's preview helpers and C7's normalized fingerprint contract. Store `billing_calendar` and original `anchor_day`; update both UI and CLI callers.

- [ ] Write `test_subscription_anchor_and_cancellation`: AD Jan-31→Feb-last-day→Mar-31 anchor tests, actual BS-month equivalents, quarterly/annual/one-time cycles, pause/resume/end/cancel/renewal and inactive-student exclusions. For a 2026-01-31 monthly anchor after January/February cycles, assert `erpEqual($record['next_date'],'2026-03-31','Original monthly anchor')`. Run a cancellation and billing worker concurrently; assert at most one eligible pre-cancellation cycle and no cycle posted after effective cancellation. Replaying a cycle must not duplicate an invoice or advance next date twice.
- [ ] Run billing/concurrency suites; reproduce separate create/advance transaction and missing-calendar behavior.
- [ ] Put subscription state lock, eligibility recheck, invoice posting, cycle key and next-date/state advancement in one transaction. Clamp to each calendar's real month length while preserving original anchor. Render due-cycle preview, confirmation and traceable renewal; do not resume cancelled/expired records silently or post paused periods automatically.
- [ ] Run billing/HTTP/concurrency suites; expect correct anchors, exactly-once cycles and reconciled journals.
- [ ] Commit `fix: serialize calendar-aware subscription billing`.

## Task C10: Expense drafts, private attachments and payable settlement

**Files:** Modify `includes/accounting_service.php`, `modules/accounting/expenses.php`; create `includes/accounting_attachments.php`, `modules/accounting/attachment.php`; extend accounting/HTTP suites.

**Interfaces:** Add `updateExpenseDraft(int $id,array $input,string $revision): void`, `storeExpenseAttachment(int $expenseId,array $upload): int`, `expenseAttachment(int $id): array`; preserve expense create/approve/pay/reverse APIs. Attachment records return validated school-owned metadata; content is served only by the authenticated endpoint.

- [ ] Write `test_expense_lifecycle_and_private_upload`, stale draft revisions, approval/pay replay, closed periods and foreign expense/category/account references. An expense of Rs. 500 must post expense/payable `50000` at approval and payable/cash `50000` at payment; reversal leaves zero net impact and `erpCheck($ac->reconcile()['ok'],'Expense reversal reconciles')`. Uploads accept only validated PDF/PNG/JPEG up to the existing 5 MB limit; forged MIME, PHP/polyglot executable, traversal filename, foreign ID and direct public URL access reject without revealing paths.
- [ ] Run accounting/HTTP suites; expect absent draft/attachment paths to fail before changes.
- [ ] Implement revisioned expense edits and scoped expense categories. Store attachments under private random names outside public serving, validate MIME/content/size, audit metadata and authorize download by school/role. Reuse C3's designated cash/bank validator; retain originals and linked reversal journals. Orphan temp uploads are cleaned only by verified exact paths.
- [ ] Run accounting/HTTP/security suites; expect no leaked private files, correct payable balances and immutable approved history.
- [ ] Commit `feat: complete audited expenses and private attachments`.

## Task C11: Fiscal closing and opening-reference safeguards

**Files:** Modify `includes/accounting_service.php`, `modules/accounting/settings.php`, `modules/accounting/student.php`, `modules/accounting/journals.php`; extend accounting/billing suites.

**Interfaces:** Preserve `savePeriod(array): int`, `openingDue(array): int`; extend to `closePeriod(int $id,bool $close,string $reason,bool $confirm,string $previewToken=''): void` and add `previewPeriodClose(int $period): array`. Closing requires the stored actor/school preview token and rechecks its fingerprint; reopening still requires administrator/reason/confirmation but no close preview. Closing writes an immutable reconciliation/balance snapshot and locks the period; carry-forward reads prior ledger balances, never reissues old receivables as income.

- [ ] Write `test_period_close_preserves_subledgers`, overlapping periods, missing coverage, closed posting dates and unauthorized reopening. Close a period with AR Rs. 500 and advance Rs. 200; opening the next period must retain `erpEqual($ac->outstanding(),50000,'Carried receivable')` and advance `20000` without another invoice/revenue journal. Test confirmed/reasoned reopen/reclose creates audited snapshots and retains the first one. Independently verified opening references remain unique across different submission keys.
- [ ] Run accounting/billing suites; expect missing close snapshots/reference guard failures.
- [ ] Require a balanced/reconciled close preview, persist snapshot/hash/actor and reject stale confirmation; use prior posted balances for carry-forward. Add explicit verified source reference to opening imports, prevent duplicate imports, and provide reviewed credit corrections. Keep fiscal and academic-year selectors distinct.
- [ ] Run accounting/schema/billing suites; expect unchanged income on carry-forward, denied closed-period posts and append-only close history.
- [ ] Commit `feat: close fiscal periods with reconciled carry-forward snapshots`.

## Task C12: Historical reports, reconciliation and bounded queries

**Files:** Create `includes/accounting_reports.php`, `tests/accounting_reports_test.php`; modify `includes/accounting_service.php`, `modules/accounting/index.php`, `modules/accounting/reports.php`, `modules/accounting/student.php`, `modules/accounting/payments.php`.

**Interfaces:** Add optional cutoff to `invoiceBalance(int $id,?string $asOf=null): int`, `outstanding(?int $student=null,?string $asOf=null): int`, `advanceBalance(int $student,?string $asOf=null): int`, `reconcile(?string $asOf=null): array`; produce the report result contract above. Report types include existing trial/income/balance/ledger/cash/bank/collection/class_collection/category/dues/expenses/student/reconciliation plus method and cashier summaries.

- [ ] Write `test_historical_reversal_cutoff`: bill Rs. 100 on Oct 1, receive it Oct 5, reverse Oct 10. Assert `erpEqual($ac->invoiceBalance($invoice,'2026-10-07'),0,'Paid before later reversal')` and collection `10000`; at Oct 11 due is `10000` and net collected cash `0`. A bill dated Nov 1 is absent from Oct aging. Assert ordinary/paid/opening credits and refunds follow their posting dates; all AR/advance/refund-payable control differences are `0`. Include profile changes after issue and designated custom bank accounts.
- [ ] Add filters for academic year, fiscal period, class, section, student, item/category, method and cashier; assert combined-filter totals, prior-balance-plus-period-movement ledger balances, weekly/monthly/yearly grouping, balanced trial/balance sheet and correct current-vs-as-of labels. Instrument PDO statements in the test harness: `query('dues',['page'=>1,'per_page'=>25,'as_of'=>'2026-10-10'])` must use at most 20 statements with both 10 and 1000 synthetic invoices, return at most 25 data rows plus a totals row, and report totals across all matching records. Exports explicitly request the full filtered dataset rather than accidentally exporting just one page.
- [ ] Run `php tests/accounting_reports_test.php`; reproduce status-only reversal, future-dues and N+1 behaviors before changes.
- [ ] Move report queries into the read-only unit, aggregate allocations/credits/advances by date and school in batches, and derive historical reversal eligibility from linked journals rather than today's status alone. Include snapshot grouping, custom cash/bank kinds, separate billing/collection/advance/refund/income/expense dashboard totals and filter-preserving navigation. Reject unsupported filter/report combinations explicitly, never silently ignore them.
- [ ] Run reports/accounting/HTTP suites; expect reconciled totals and bounded queries with no full in-memory scan on paginated screens.
- [ ] Commit `fix: reconcile historical school finance reports`.

## Task C13: Usable forms, notifications, exports and print layouts

**Files:** Modify `includes/accounting_ui.php`, `includes/xlsx_helper.php`, `includes/nepali_dates.php`, `assets/js/school-dates.js`, `assets/js/accounting.js`, `assets/css/accounting.css`, and affected accounting pages; create `tests/accounting_export_test.php`; extend HTTP tests.

**Interfaces:** Preserve `XlsxHelper::generateXlsx(array,array,$sheetName,array $numericColumns=[])`; extend CSV compatibly to `generateCsv(array $headers,array $rows,array $numericColumns=[]): string`. Only strict numeric values in those trusted numeric columns bypass text-formula escaping; existing two-argument calls retain text safety. Add `acPage(array $filters,int $defaultSize=25): array` returning validated page/size/offset (size capped at 100); extend `acHandle(callable,string,array $args=[],string $success='Saved successfully.'): void` with explicit safe error responses. Print uses stored snapshots and existing AD/BS date helpers, not a new calendar implementation.

- [ ] Write tests that a bad bill line returns its submitted rows and one specific error, successful draft/issue/payment/refund each displays the matching success message, and unexpected failures reveal no SQL/path/trace. Student/catalog search is debounced, bounded and keyboard-selectable; a slow older search cannot overwrite newer results. Tables paginate/filter predictably with empty states.
- [ ] Write `test_numeric_exports_and_bs_dates`: unzip generated XLSX and assert numeric money-cell totals equal `3000.00`; Nepali labels survive; CSV neutralizes `=`, `+`, `@`, leading-tab formulas while signed money in declared numeric columns remains numeric. Reject invalid dates and assert `erpEqual(schoolAdToBs('2026-10-10'),'2083-06-24','BS document date')` in invoice, receipt, credit and statement views.
- [ ] Run export/HTTP tests; reproduce missing retention/keyboard/pagination behavior before changes.
- [ ] Complete reusable form retention/accessibility, section/year/cashier selectors, accurate action notices, pagination and safe exports. Apply shared A4/A5/80-mm/compact print controls to invoices, receipts, credits and statements. Label browser Print / Save as PDF honestly; do not claim a nonexistent server PDF endpoint or configured external notification delivery.
- [ ] Run `php tests/accounting_export_test.php`, HTTP suites and JS syntax. In a disposable browser app inspect desktop/mobile/keyboard flows; print/save representative multi-page Nepali documents in all four sizes and render/inspect output with the PDF skill. Verify logo/header position, repeated headings, no clipped long names or totals and totals matching source. Keep generated artifacts out of commits.
- [ ] Commit `feat: finish accessible accounting UI and verified exports`.

## Task C14: Cross-driver concurrency and complete handoff

**Files:** Create `tests/accounting_mysql_test.php`; extend concurrency/HTTP suites; modify `README.md`; create `docs/accounting-verification.md`.

**Interfaces:** MySQL tests require an explicitly named disposable test database via existing `ERP_DB_TYPE/HOST/NAME/USER/PASS`, verify its safety before seeding and never read credentials into logs. Run the same facade invariants on SQLite and MySQL; workers use explicit temporary input files. No production credentials/environment change is part of this task.

- [ ] Add `test_two_cashiers_last_balance` and simultaneous receipt-number, invoice-number, billing retry, subscription cancellation and closing/posting tests. Assert `allocation_sum === 10000` when two workers attempt the last Rs. 100 due, no duplicate business key/number, and no journal outside an allowed period. Check rollback on a forced mid-transaction failure, tenant isolation on every new endpoint and auditor/preview no-write behavior.
- [ ] Run all new suites and all existing suites; run PHP syntax for every tracked/source PHP file, JS syntax and `git diff --check`. Run MySQL/InnoDB tests only with a safe local/staging instance; if unavailable, mark MySQL runtime verification as outstanding instead of claiming readiness or connecting to production.
- [ ] Document actual commands/counts, schema/backup/restore boundary, school setup order, fiscal/admission policies, job preview/post usage, package roles, external gateway/notification limits and native PDF-save behavior. Include a consistent-copy upgrade test retaining students/marks/exams/users/finance balances.
- [ ] Perform the chosen execution method's final independent code review, turn actionable findings into failing regressions, fix and rerun relevant/full checks, then record remaining limitations. Integrate verified source back into the user's workspace without overwriting unrelated changes or attachments.
- [ ] Commit verified task files as `test: verify accounting workflows and deployment boundaries`. Do not push or deploy until the user requests publication; verify remote access/staged secrets/branch hash at that later step.
