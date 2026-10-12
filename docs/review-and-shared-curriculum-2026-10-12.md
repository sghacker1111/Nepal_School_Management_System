# Review fixes and shared curriculum — 12 October 2026

## Scope and status

Implemented in `codex/astra-review-fixes`, based on `dd607761913763686606ffb43b6b6bdf2248d8bf`.
This batch is local; it has not been pushed, merged, or deployed. The original checkout and its database were not modified.

The earlier GPT-6 Astra review supplied reproduced accounting, marks-entry, and permission findings. The final Astra re-review was attempted but could not complete because of the account usage limit. The implementation and verification below were completed by the primary agent, not certified by a completed final Astra review.

## Approved shared chapter/topic catalog

- Question Bank, Curriculum & Topics Manager, and the universal subject catalog read persistent chapter IDs from the same service.
- Schema 13 additively imports the installation's existing bundled chapter names and legacy question chapter names. Existing school fallback lists are preserved as school-owned rows.
- This reuses the installed catalog; it is not a new independent verification of CDC curriculum content.
- Each chapter has an Add Topic button below it. Topics support English/Nepali names, editing, sorting, and save feedback.
- Duplicate additions reuse existing IDs. Renames retain historical duplicate chapter IDs, their topic links, and matching question chapter names.
- Referenced chapters/topics cannot be deleted. Migration does not replace questions, student records, marks, invoices, or payments.
- Teacher subject restrictions and cross-school protections remain enforced on the server.

## Review findings addressed

| Area | Repair |
| --- | --- |
| Fee assignments | Correct positional PDO bindings so assignments actually save. |
| Recurring fees | Use admission/withdrawal dates and effective-date rate/scholarship segments. |
| Billing approval | Bind an expiring preview token to actor, school, filters, and calculated amounts; reject stale previews. |
| Duplicate billing | Track each student/cycle/fee item; repeat runs issue only newly assigned items. |
| Posting safety | Commit fee batches atomically; commit subscription invoice and cycle advancement together. |
| Subscriptions | Recheck cancellation under the posting lock and preserve month-end anchors. |
| Advances | Reject backdated use/refunds/reversals that overdraw a date or later committed balance. |
| Receipts | Restore administrator reversal routing while preserving role and CSRF checks. |
| Reports | Include credit-note refunds, include custom cash/bank accounts, and avoid allocation arithmetic overflow. |
| Marks entry | Limit page size to PHP input capacity, save up to 10 students per page, search within class, and reject incomplete submissions without partial writes. |
| Learning permissions | Reject conflicting URL/body actions and independently validate URL scope. |

## Verification

All automated mutations use disposable OS-temp SQLite databases.

- 20 standalone PHP suites.
- 16 HTTP suites: 886 requests, including 320 marks saved for 40 students across eight subjects.
- Deployment safety suite: 26 runtime exclusions, non-destructive FTP settings, serialized deployment.
- Four JavaScript suites and syntax checks for 153 PHP files.
- Browser verification: save a bilingual topic beneath a Class 9 Mathematics chapter, observe success feedback, and find that topic in Question Bank.
- Consistent read-only copy upgrade from schema 6 to 13: integrity, backup, idempotency, retained IDs and money checked. Existing orphan marks are retained, not deleted or fabricated.
- Original database SHA-256 remained `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.

## Limits and release notes

- No MySQL runtime or production data was exercised. Run host-specific checks and take a production backup before deploying a schema change.
- This is a bounded bug-fix review, not a guarantee that the whole application is vulnerability-free or an independent financial audit.
- Collection summaries continue to exclude currently reversed payments; they are not reconstructed historical as-of statements. Use posting-date journal reports for dated ledger activity.
- The in-app browser reported an iframe `MutationObserver` console error on Question Bank. Temporary diagnostics did not attribute it to the application's observer or main-page error handler; chapter/topic selection worked. Its source remains unconfirmed, and it is not claimed as fixed. Diagnostic instrumentation was removed.
- Preserve the original dirty checkout. Do not copy its SQLite database, uploads, configuration, or backups into Git or replace them during deployment.

## Reproduce

On Windows, set `ERP_TEST_PHP` to the PHP executable and run each `tests/*_test.php` except `upgrade_test.php` directly. Run each `tests/*http_test.py`, `tests/deployment_test.py`, and `tests/*_test.js` with Python or Node respectively.

`upgrade_test.php` deliberately refuses normal databases; run it through `tests/upgrade_copy_test.py --source <existing SQLite database>` to test a consistent disposable copy.
