# Learning content sharing — verified local handoff

Date: 13 October 2026, Asia/Katmandu.
Project: the existing PHP Nepal School Management System, not the separate Next.js ERP.
Branch: `codex/practice-readability`, in the existing accounting-completion linked worktree.
Release base: `7966643986213003c1b71dbae57bbc25d2944cfa`.
Status: approved feature implementation and review-fix pass complete locally. No push, merge, FTP deployment or live migration performed.

## Delivered behavior

- Super-admin public questions, MCQs, notes, chapters and topics are available read-only through eligible school class/subject mappings. Only super admin maintains chapters/topics. Hidden shared subjects no longer expose their catalog choices.
- School teachers and school administrators create/edit/publish only their own questions and notes. School content defaults to Private; its creator, assigned subject co-teachers and school administrators can read it. Publishing exposes it only within that school, with students restricted to their enrollment/electives. It never publishes to another school or anonymously.
- Question Bank, Learning Hub, practice and both paper generators share the backend visibility policy. Students cannot author content or use staff-only paper routes. Private/archive/hidden changes immediately invalidate affected saved practice, including completed answer review.
- Archive/restore retains content IDs. Unknown legacy creators are not guessed. Unresolved legacy notes remain available in super-admin review instead of being lost.
- Normal/HTML-source CSV and XLSX imports retain safe HTML, bilingual content and LaTeX; school users select existing chapters/topics rather than creating them through imports. Distinct canonical-equal subjects retain their IDs and are selected by unambiguous name or code.
- Nepali, English and Mathematics have canonical application labels without changing CDC source snapshots, credits, codes, types or examination history. Optional Mathematics remains distinct.
- Preserved Kokila 20 pt defaults, larger practice text/choices, feedback animation, louder faded sounds, mute, reduced-motion safeguards, random question order and review Back buttons.

## Independent review and fixes

Astra failed before starting its final review due to a usage limit. The user explicitly chose GPT-6.1 Sol. Sol completed a read-only whole-branch review of `7966643..95792fb`, reported no Critical findings, four Important findings and three Minor findings. The initial verdict required fixes before readiness; the corrected patch was author-tested, not independently re-reviewed.

The single review-fix pass addressed all four Important findings and promoted/fixed two Minor findings:

1. Imports no longer discard legacy same-name subject IDs: unique codes work and ambiguous names fail without writing rows. Regression: `tests/learning_import_collision_http_test.py`.
2. Super-admin legacy review now includes notes whose class/subject resolve but chapter/topic references do not. Regression: `tests/learning_note_preservation_http_test.py`.
3. Ordinary note title/body edits preserve historical local chapter/topic aliases instead of silently transferring them to same-name shared entries. Both the form and old label-only clients preserve references. Same preservation regression.
4. Add Note serializes chapter names as JavaScript data, fixing apostrophe/ampersand names such as `King's & Queens`. The test executes the emitted literal and checks its actual value.
5. Hidden shared subjects exclude chapters/topics as well as content. Promoted to Important because an administrator's hide action must apply consistently. Regression: `tests/learning_catalog_test.php`.
6. Learning selectors render code/type-disambiguated labels instead of identical canonical names. Promoted to Important because users must choose the intended subject ID. Import selector regression and client checks cover the changes.

Additional author regressions before review fixed stale source-ID/reused-code subject matches and rich-HTML rendering in the legacy paper generator. These are author checks, not a second independent review.

## Fresh verification after the fix pass

All checks passed using PHP 8.5.1, SQLite and disposable fixtures:

- Syntax checks for all 169 tracked PHP files and all asset JavaScript files.
- 26 PHP service suites, including security, schema, CDC, grading, accounting, attendance, routines, packages and permission policies.
- 8 JavaScript suites, including content safety, topic dropdown concurrency, editor scripts, practice feedback/audio behavior and readability.
- 25 dedicated real-HTTP suites, including both new review regressions, cross-school sharing, imports, practices, papers, class isolation, reports, accounting and roles.
- General HTTP integration: 158 requests.
- Deployment safety: 26 runtime exclusions and non-destructive workflow checks. This tested deployment configuration; it did not deploy.
- Read-only consistent-copy upgrade of the original schema-6 database to schema 15; IDs, stable learning content and money retained, SQLite integrity `ok`, original unchanged.
- Additional actual-workbook test: all 80 MCQs saved with bilingual options and answer keys from `1_Grade_10_Sets_80_MCQ_English_Numerals.xlsx`.

Original database: `D:\A WebSite\Nepal School Management System\database.sqlite`.
Unchanged SHA-256: `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.
The original checkout was not reset, synchronized, staged or edited by this implementation.

Author browser checks used temporary teacher/co-teacher/student accounts: private creation defaults, read-only shared content, separated global/local topic IDs, no school custom chapters, co-teacher private reads without edit controls, public-only student notes, no student author controls, 20 pt Kokila, rendered mathematics and 390 px practice without horizontal overflow. Red/green feedback and review Back controls rendered. Screenshots were saved outside the repository; the temporary QA server was stopped.

To rerun, set `ERP_TEST_PHP` to the local PHP executable. Run all `tests/*_test.php` except `upgrade_test.php`, all `tests/*_test.js`, all `tests/*_http_test.py`, then `tests/http_test.py`, `tests/deployment_test.py` and `tests/upgrade_copy_test.py --source <original-database>`. The upgrade test creates an OS-temporary copy; do not point the lower-level `upgrade_test.php` at production data. Also lint tracked PHP and check `assets/js/*.js` with Node.

## Deferred finding and review boundaries

- One Minor finding is deferred: creator mutation permissions repeat authorization queries per list row. Sol's synthetic benchmark was approximately 0.629 s for 1,000 own public rows versus 0.004 s for another owner's rows. A request-local optimization is a follow-up; no persistent authorization cache was introduced.
- MySQL and production migrations were not executed: no MySQL staging database was available. Static support and SQLite evidence are not MySQL certification. Validate a backed-up staging copy before release.
- The independent reviewer did not judge browser accessibility or actual device sound loudness. Author checks covered layout/visibility/fonts; client tests checked sound envelopes/mute. Audible loudness remains dependent on device volume, and assistive-technology/device QA is still needed.
- Production-scale latency was not measured beyond the synthetic benchmark. Do not treat these checks as a production performance guarantee.
- Unrelated existing accounting/grading behavior was not independently re-audited in this learning-feature review. Their regression suites passed; this is not a fresh audit or security guarantee for the entire platform.
- Live release actions were outside this approved implementation. The updated code is not yet on the live site. Pushing this repository's main branch triggers its FTP workflow, so release requires an explicit request, production backup and staging checks.

## Process rulings and costs

- Moved Question Bank catalog-read API wiring from Task 4 into Task 3 to prove chapter/topic parity early. Cost if wrong: a small API hunk would need reordering. Existing approved font work was preserved.
- Committed verified integration/readability before the whole-branch review so the reviewer had a stable Git range. Cost: an extra local review-fix commit; no external release.
- Promoted hidden-catalog eligibility from Minor to Important. Cost if wrong: narrower shared choices until an administrator unhides the subject.
- Promoted duplicate display labels from Minor to Important. Cost if wrong: extra code/type suffixes in selectors.
- Accepted SQLite-only migration evidence and disclosed unverified MySQL. Cost: MySQL staging remains required before release.
- Kept independent browser/device/accessibility judgment separate from author evidence. Cost: further device/assistive-technology QA may find issues.
- Declined a production-latency claim. Cost: larger installations may need query optimization.
- Kept unrelated accounting/grading review outside the feature review while rerunning regressions. Cost: undiscovered pre-existing issues remain possible.
- Kept push/merge/live migration/deployment outside this execution. Cost: the completed local changes are not live yet.

The originally selected implementation/review skills became unavailable during execution. The remaining fix pass continued with the same test-first, explicit-file editing, fresh verification, review ledger and safe-handoff process. No additional reviewer was substituted after Sol and no new deployment authority was inferred.
