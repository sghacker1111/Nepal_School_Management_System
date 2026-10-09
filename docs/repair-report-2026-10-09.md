# ERP repair and verification — 2026-10-09

## Implemented repairs

- Additive, versioned schema upgrades and independent school class/subject mappings; existing SQLite database backups; idempotent provisioning.
- Tenant and class/section/subject relationship validation; active teacher assignment; reliable class-specific enrollment dropdowns.
- Third elective slot for Grades 11–12, duplicate-choice rejection and per-exam elective snapshots.
- Shared GPA/percentage calculation across marks, report cards, ledger and bulk printing; fractional thresholds; component pass requirements; missing marks remain incomplete.
- Exam publication rejects incomplete assessments; published/locked marks are protected; reopening withdraws results; saved exam grading configuration and marks cannot be deleted through normal exam actions.
- Historical results remain available across academic-year switches and student archiving.
- Timetable generation honors requested weekly periods and avoids teacher conflicts across classes; manual conflicts roll back.
- Staff-account add/edit/role/status/password management; same-school boundaries; safeguards against disabling oneself or removing the last school administrator.
- No shared password for new administrators or teacher imports; login throttling, regenerated sessions, active-account rechecks, CSRF and server-side write permissions.
- CSV formula neutralization and explicit escaping; invalid dates rejected; missing birthdays no longer invented.
- Sanitized rich learning content, exact-grade shared question matching, corrected paper-generation filters.
- Searchable grouped sidebar, mobile navigation, accessible field labels, staff search/dialog and upload guidance.
- Database indexes and removal of repeated per-cell routine lookups; no benchmark or Core Web Vitals score is claimed.

## Verification

- 98 real HTTP integration requests passed using a fresh temporary SQLite database, without PHP warnings/deprecations/fatal errors. These include saved-scheme input limits, distinct blank-code subject rows and incomplete-result labels.
- Security, relationship migration, grading, rich-content, and operations tests passed.
- Browser checks: class-dependent enrollment selectors, staff-account screen and dialog, desktop 1366×900 and mobile 390×844, mobile navigation; no browser console errors in the checked screens.
- All application PHP files are syntax-checked and the changed JavaScript files are parsed by Node.
- Upgrade exercised against a disposable copy of the existing SQLite database: row counts retained, integrity check passed, migration idempotency and automatic backup verified.

## Existing data requires recovery

The original database inspected on this date contains **50 marks rows, zero students and zero exams**. All 50 marks therefore have missing parent records. The upgrade retains them; it cannot recover the missing identities or exam definitions.

The original database was not migrated or edited during testing. Its SHA256 was checked against the starting value:

`CF43413A896493BD4B4515E7FE93EE8C17FD170EEC560F9BDC2B970E7CB1526C`

Recovery requires a known-good database backup or the original student/exam records and an approved mapping. No records should be fabricated to make this check green.

## CDC review boundary

References consulted:

- [Letter Grading Directive 2078, fourth amendment 2081](https://www.moecdc.gov.np/content/22/letter-gradinging-directory--2078--fourth-amendment-2081-/).
- [CDC notice for Grade 4–5 assessment guidance 2083](https://moecdc.gov.np/content/650/student-assessment-in-basic-level-grades-4-5/).
- [Basic Education Curriculum 2078, Grades 4–5](https://moecdc.gov.np/content/203/basic-education-courses--2078--class-4-5--mandatory/).

The source pages establish relevant directives; the complete current attachments were not all retrievable. Consequently this implementation must not be marketed as fully certified CDC/NEB compliant. The starter mapping uses configurable defaults, not a substitute for school approval.

Before using results operationally, verify each class's actual offered subjects, local curriculum, elective group combinations, theory/internal weights and credit hours against the applicable school year/stream. Full continuous-assessment competency reports, Grade 11–12 group-combination enforcement and all technical/Sanskrit stream rules remain outside the verified implementation.

## Remaining deployment and product limits

- SQLite was exercised locally. MySQL/MariaDB, Apache/Nginx production configuration, concurrent multi-user writes and large-school load were not validated against a live server.
- CDN dependencies and OCR/AI-assisted document parsing require separate availability/accuracy checks.
- No public student/parent result portal, fee accounting or automated year-promotion/history model is claimed by this repair.
- Timetable generation is a conflict-aware heuristic, not an optimal solver. Unplaced periods are reported.
- No Git push, FTP deployment or changes to the production database were performed. The main-branch push workflow deploys live, so release requires a reviewed staging run and explicit deployment decision.
