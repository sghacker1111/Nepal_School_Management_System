# School accounting and assessment completion design

Date: 2026-10-10
Status: approved by the user on 2026-10-10; implementation-plan review pending

## Intended outcome

Complete the existing Nepal School Management System, not a new application:

- Optional subjects must have usable marks inputs once the student selects that subject.
- Schools must be able to customize Internal Assessment component labels and full marks, with a confirmation warning and protection for previously entered scores.
- School administrators must be able to select the requested ERP, result, learning, paper-generator, question-view, question-add, or accounting package.
- Accounting and billing must operate against real school records and support fees, invoices, receipts, payments, dues, advances, subscriptions, expenses, journals, reconciliation, printing and exports.

The user's attached accounting specification remains the functional requirements source. This document defines the completion architecture and acceptance boundaries; it does not replace or silently reduce that specification.

Constraints: keep PHP and the current SQLite/MySQL database support; reuse school, student, class, section, academic-year, exam and subject IDs; preserve educational and financial history; enforce school isolation; do not create fictitious financial transactions in the real database. Git publication and deployment are separate from implementation and require a publication request.

Assumptions for review: one primary package per school, with Full ERP including every module; no invented student/parent login relationships; tax rates and fiscal periods are school-configured rather than assumed. These choices must remain visible in the settings and documentation.

## Current state and evidence

There are local, uncommitted assessment, package and accounting changes. They are a starting point, not verified completion. The production database was inspected read-only and still has workflow schema version 6. Only disposable databases were migrated to version 7 during this audit.

Verification performed on 2026-10-10:

- PHP syntax: 117 files passed. Accounting and school-date JavaScript syntax passed.
- All eight existing PHP suites passed: security, workflow, grading, lesson content, operations, exam enhancements, exam workflows and CDC integration.
- Existing HTTP suites passed 158 general requests and 33 exam requests.
- A disposable accounting probe passed 11 checks, including the Rs. 3,000 manual bill, invoice/payment replay protection, partial payment, overpayment as advance, advance allocation and balanced-ledger reconciliation.
- A copy of the original database migrated to version 7, retained the record counts checked by the upgrade test, passed SQLite integrity checking and produced a pre-upgrade backup. The old upgrade test then rejected version 7 because it still expects version 6; its expectation needs updating alongside explicit extension-migration tests.

Reproduced defects that remain unfixed:

1. Class-default IN editing hashes a display-only `practical_full` value into its revision token but compares it with the raw database row on save. Consequently an unchanged configuration is rejected.
2. The IN editor permits exam officers at page level, but the shared request guard rejects their POST to the subjects module.
3. Payment-method creation accepts the receivable control account as a cash/bank account. Using it records a payment without collecting cash and breaks receivable reconciliation.
4. Crediting an opening-due invoice uses current-period discount/contra-income instead of reversing its opening-equity counterpart. The probe changed current income by Rs. -100 when the expected change was zero.

Other identified completion areas include safe arithmetic bounds, subscription concurrency, historical/as-of reporting, admission-date/proration policy, real stream mapping, invoice/expense draft editing, fine policies, fee copying, attachments, complete filters, export/print verification and dedicated accounting tests. These require tests and implementation, not a claim that the existing prototype already meets them.

## Approach choices

Recommended: finish the native PHP subsystem incrementally, retain the additive schema and existing UI patterns, and separate calculation, posting and billing-generation responsibilities behind the existing accounting service facade. This preserves current work and ERP relationships while making the financial invariants independently testable.

Alternative: replace the entire local accounting prototype with a fresh native implementation. This offers a clean boundary but repeats work and increases the review surface; reserve it for a component that cannot be made reliable with targeted changes.

Alternative: integrate an external accounting platform. This adds external credentials, operational costs and synchronization dependencies, and does not meet the user's request for integrated operation in the existing database. It is not the selected approach.

## Architecture and data ownership

The ERP masters remain authoritative. Accounting tables store references and immutable billing snapshots, not duplicate student or class masters. Invoice snapshots preserve customer identity, class/section/year labels, item descriptions, quantities, rates, discounts, taxes and totals as issued. Changing a current student profile or fee rate must not alter historical documents.

Responsibilities are separated as follows:

- Assessment configuration handles component validation, optional-subject enrollment and safe revision checks. Existing marks and exam lifecycle rules remain authoritative.
- Package entitlements form one capability matrix used by navigation, page guards and mutation guards. Hiding a link is not authorization.
- Money calculation validates decimal input, computes rounded line totals and distributes discounts/tax exactly. Persisted money remains integer paisa; quantity uses thousandths and percentages use basis points. Arithmetic must reject unsupported bounds before PHP can overflow or convert to a float.
- Journal posting validates school-owned accounts, an open fiscal period, exact debit/credit equality and an immutable source reference within one transaction.
- Billing generation resolves effective fee rules and enrollment, produces a reviewable snapshot, then posts approved work with deterministic business keys.
- Reporting reads issued records and posted journals through school-scoped queries and explicit current-balance or historical-as-of semantics.

Catalog and control-account initialization belongs to authorized school provisioning/migration, not a constructor invoked by an auditor or read-only preview GET. No read-only screen may create accounting configuration as an unnoticed side effect.

Schema changes are additive and versioned. Capture a consistent backup before migrating an existing database; verify counts, ownership, integrity and idempotency on copies first. Rollback is restoration of the verified backup with its matching application version, not deletion of financial history. Do not activate global SQLite foreign-key enforcement until existing legacy orphan records have an explicit preservation/remediation policy.

## Assessment and package behavior

An offered elective and a student's selected elective are different. Marks entry shows a clear selection control for an unselected elective, not an unexplained N/A. Selecting it requires confirmation, validates available elective slots and school/class ownership, and persists enrollment and marks atomically. Do not enroll every student in every optional subject automatically.

Class defaults and exam-specific IN configurations have separate scopes. Class changes affect future mappings. An exam-specific change retains stable component keys and existing values, rejects deletion of a component with saved marks, and warns before recalculating grades. Component maxima must sum exactly to IN full marks. Published or locked exams retain their existing protection and reopen workflow; warnings are not permission to bypass locks. Exam officers may edit an eligible exam's configuration but may not change school-wide class defaults.

Package access is checked on every direct route and POST, including learning subpages. Question View cannot add/edit questions. Question Add includes the viewing needed to manage permitted questions. Finance-only roles do not gain educational-record administration simply because accounting references students. Full ERP includes accounting. Legacy packages retain documented compatibility.

## Fee setup and recurring billing

The catalog supports unlimited categorized fees, products and services, active/inactive status, income-account mapping, frequencies, configurable applicable tax, discount eligibility and fine eligibility. Fee assignments support class, section, academic year, enrolled subject/exam and individual-student overrides. Grade 11/12 stream selection must use an actual persisted ERP field or an additive, reviewed mapping; never silently match nonexistent columns.

Student overrides take precedence over section rules, then class rules. A rate revision retains its effective dates. Prorated billing divides the month into non-overlapping effective-rate segments rather than using the latest rate for the entire month. Enrollment uses an explicit school-maintained admission/effective date; database import/creation time is not silently treated as admission. Inactive students and dates outside enrollment are excluded according to a visible policy.

Copying fees across classes/years is a preview-and-confirm operation with an affected-student count. Selected-student generation is supported. Monthly, quarterly, annual, one-time and exam billing use deterministic school/student/item/cycle keys. Repeating a job, refreshing a page or two simultaneous cashiers must not duplicate charges. Adding a missing fee to an already billed month can generate only the missing charge without rebilling existing items.

Generation requires a reviewed preview whose fingerprint covers students, item/rate snapshots, discounts, dates and totals. If the source changes, require a fresh preview. Record successes, skips and failed students/cycles so a retry does not duplicate successful postings. Provide an authenticated UI preview and a CLI job with explicit school/user context; installing an unattended scheduler is a separate deployment step.

## Billing, payments and corrections

Manual billing provides searchable student/customer selection and dynamic item rows with catalog or custom particulars, quantity, rate, line discount, bill discount and applicable tax. The live preview and server calculation must agree exactly. Preserve form input after validation errors. Drafts are editable with revision protection; issuing freezes the document, assigns its unique number and posts its journal atomically.

The requested example is an acceptance case:

| Particular | Quantity | Rate | Amount |
| --- | ---: | ---: | ---: |
| Monthly Tuition Fee | 1 | Rs. 2,500.00 | Rs. 2,500.00 |
| Examination Fee | 1 | Rs. 500.00 | Rs. 500.00 |
| Subtotal / total with no discount or tax | | | Rs. 3,000.00 |

One payment can settle several invoices. Partial payments leave explicit balances. Excess student payments require an explicit choice and post to an advance liability, not income. Available advance can later settle an invoice without collecting cash again. Bank/digital payments require externally verified references; recording eSewa/Khalti/ConnectIPS/Fonepay is not a live gateway integration.

Cash/bank methods, expense payouts and refunds must use designated active cash/bank asset accounts, never receivable or another arbitrary asset/control account. Server-side checks apply even if a malicious request bypasses the dropdown.

Issued transactions are never destructively edited or deleted. Corrections use approved, numbered credit notes, linked reversal journals and refunds with reasons, actor, timestamps and before/after audit data. Opening dues post against opening equity and use a unique verified import reference; correcting them reverses that same counterpart and must not alter current earned income. Previously paid invoices need a reviewed credit/refund workflow, not only an advance refund button.

Scholarships and exemptions can be fixed, percentage-based and date-limited. Approval rules control manual discounts. Late-fine policies define grace days, fixed/percentage basis, repeat behavior, caps and waivers; repeated evaluation cannot duplicate a fine.

## Subscriptions, expenses and fiscal periods

Product/service subscriptions support monthly, quarterly, annual and one-time cycles, start/end dates, pause, resume, cancel and reviewed renewal. Store the selected BS/AD billing calendar and original cycle anchor. Cycle posting, state validation and next-date advancement occur under the same locking/transaction strategy, so a concurrent cancellation cannot race with billing. Paused, ended, cancelled or ineligible subscriptions are not charged.

Expenses support categorized editable drafts, payee/reference, safe private attachments, approval, payment and linked reversal. Approval posts expense/payable; payment settles payable from cash/bank. Reject duplicate approval/payment and unauthorized attachments. No public path or arbitrary server file reading is permitted.

Fiscal periods are distinct from academic years, cannot overlap and control posting dates. Closed periods reject postings until an authorized, audited reopen. Year closing/carry-forward preserves outstanding receivables and advances without inventing a second revenue transaction. Reports retain access to closed-year history.

## Reports, printing and usability

Dashboard totals separately identify billed fees, collected cash/bank, current dues, advances, refunds, earned income, expenses and net result. Labels distinguish gross billing from net credits and current balances from period flows. Relevant filters include school, academic year, fiscal period, class, section, date range, item/category, method and cashier.

Reports cover collection, fee/category/method/cashier/class summaries, student accounts, due aging, cash and bank books, trial balance, income/expense, balance sheet, receivables and reconciliation. Historical reports exclude future bills and correctly account for payments/credits/reversals as of the requested date. Subledgers reconcile with their control accounts.

Invoices, receipts, credit notes and statements have school/logo/address/PAN where configured, unique numbers, customer/student details, dated item rows, totals, amount in words and visible AD/BS dates. Support A4, A5, 80 mm and compact print layouts without clipped headers or totals. Browser Print / Save as PDF is the native download path and must be labeled clearly; no server-generated PDF download is claimed unless such an endpoint is implemented and verified. Reports export genuine XLSX with numeric money cells and safe CSV, with totals matching the screen and protection against spreadsheet formula injection.

Reuse the ERP shell and notifications. Successful actions show an accurate success message; failures explain the specific correctable issue in one sentence without exposing SQL, file paths or stack traces. Tables use pagination/search, keyboard-accessible controls, mobile layouts and meaningful empty states. Avoid unbounded N+1 queries for invoice balances and dashboards.

## Security and acceptance evidence

School admins control configuration, fiscal periods and sensitive approvals. Accountants prepare bills and operational records within policy. Cashiers collect/apply permitted funds only. Auditors and school previews are read-only. Existing session/CSRF protections remain in force. Validate every supplied record ID at service level for its school and permitted relationship. Direct URL, AJAX, CLI and crafted POST paths must obey the same invariants.

The implementation plan must turn the following into automated regression checks, with a failing reproduction before each fix:

1. Optional enrollment and marks save together; unconfirmed, foreign, full-slot and hidden-subject choices fail safely.
2. IN default/exam saves, unchanged revision tokens, concurrent edits, component sum validation, saved-score preservation and locked-exam denial.
3. Every package/role combination, including question-view mutation denial and read-only GETs producing no writes.
4. Decimal parsing, fractional quantity, rounding, tax/discount allocation, maximum bounds and client/server preview agreement.
5. Draft create/edit/issue, immutable snapshots, unique numbers, replay with identical data and rejection of changed replay data.
6. Partial/multi-invoice payments, over-allocation rejection, advance receive/apply/refund, reversal and verified digital references.
7. The reproduced invalid receivable payment-account case and opening-credit income case.
8. Tenant isolation for every catalog, invoice, payment, expense, subscription and report reference.
9. Historical fee revisions, precedence, enrollment, inactive students, BS-month lengths, mid-month proration, fee copying and missing-item-only billing.
10. Reviewed generation fingerprint, retries, duplicate/concurrent submissions and subscription cancel/post races.
11. Scholarships, approval thresholds, fines, caps, repeat prevention and waivers.
12. Expense edit/approve/pay/reverse, attachment validation and balanced payable settlement.
13. Closed-period denial, authorized reopening, opening imports and carry-forward without duplicate income.
14. Historical report cutoff, credit/reversal treatment, control/subledger reconciliation and balanced trial/income/balance reports.
15. CSV/XLSX validity, formula safety, numeric totals, AD/BS dates and all print sizes/PDF-save layouts.
16. Version-6-to-extension upgrade on a consistent database copy, backups, retained IDs/counts, integrity and repeat migration.
17. Existing PHP and HTTP suites, PHP/JavaScript syntax, browser workflows and MySQL verification before any claim of MySQL readiness.

Acceptance is not merely syntax success. Financial invariants, real HTTP workflows, printed/exported artifacts and migration preservation must have fresh evidence. Unavailable environments or genuinely unfinished features must be named explicitly in the handoff.

## Review checkpoint

The user approved this design on 2026-10-10. The written plans specify regression-first work, file boundaries and verification commands. Implementation resumes only after those plans are reviewed and their execution method is selected. This document has not authorized publication or migration of the original school database.
