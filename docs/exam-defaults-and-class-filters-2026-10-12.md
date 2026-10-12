# Exact class filters and exam defaults

Implemented in the isolated accounting-completion worktree. The dirty original checkout and its database were not modified. Git publication and the existing non-destructive FTP deployment were approved on 2026-10-12; final release evidence is recorded in the release handoff.

## Class filtering

Subject Management previously used DataTables substring matching on class names, allowing `Class 1` to match `Class 10`, `Class 11` and `Class 12`. The dropdown now submits the class record ID and the server applies `cs.class_id = ?`, retaining the hidden-subject selection. Other class selectors were inspected; their SQL/JavaScript identity comparisons are exact. Regression fixtures contain distinct pupils, subjects and questions in all four classes and verify output isolation in Students, Subjects, curriculum selectors, attendance, results, ledgers, marks entry, report cards, admit cards and Question Bank. No educational records were removed.

## Exam defaults

The system administrator manages three revisioned templates: First Term, Second Term and Third / Final Term. Templates include name, type, IN inclusion, result system, print calendar/format and class-subject TH/IN marks/credits. Initial schemes come from the universal catalog; administrator edits are copied to future exams. Old revisions remain in template history.

Schools select Automatic or Manual and a template. Both modes create a school-owned exam in a chosen visible school academic year (active by default) and retain the template revision/snapshot. Schools select their exam dates. Foreign-school and hidden academic years are rejected. Manual mode permits overrides and later edits under existing safeguards. Automatic names/types/result schemes/mappings/IN allocations remain read-only at the UI, request guard and service layers. Setting changes cannot be enabled by a forged mode field. Marks entry, symbols and lock/reopen remain operational with existing teacher/class permissions. Missing system schemes abort automatic creation; Manual mode permits school-only subjects. Subject initialization only adds missing rows and never replaces existing schemes or entered marks.

Template edits/deactivation do not rewrite existing exams. Stale administrator or creation forms are rejected. Schema 11 to 12 retains every existing exam ID and setting and classifies legacy exams as Manual; repeated migrations do not duplicate the defaults. SQLite backups remain enabled. MySQL uses MEDIUMTEXT for whole-catalog snapshots and is statically reviewed, not dynamically tested here.

## Verification

Targeted tests cover exact class isolation, template roles/CSRF, class-specific overrides, ambiguous-code handling with stable curriculum identities, automatic forgery rejection, snapshots, failed creation rollback, manual edits, inactive templates, academic-year ownership/visibility, marks/symbol operation, lock/reopen and a genuine schema-11-shape upgrade. No production database was used for tests.

Final local verification passed: 19 PHP suites, 4 JavaScript suites, 13 HTTP suites (765 requests), 151 PHP syntax checks, deployment-safety checks (26 runtime exclusions), and `git diff --check`. A consistent disposable copy of the original schema-6 database upgraded successfully to schema 12, retaining all IDs, money and 50 existing orphan marks without inventing missing students/exams; backup and idempotency checks passed. The original database SHA256 remained `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.

Browser checks on a synthetic localhost database confirmed the Manual/Automatic selector, read-only automatic fields, editable academic-year/date controls, the system-admin editor and exact Class 1 subject settings. Screenshots are saved as `automatic-exam-settings.jpg` and `super-admin-exam-defaults.jpg` in the task's visualization folder. Production MySQL behavior and deployment remain unverified for this batch.

## Release integration

On 2026-10-12 the user approved Git publication and FTP deployment. The current remote main (PR #6, `9e7f727`) merged without application-code conflicts. Its archive/recovery test was updated from schema 10 to schema 12 while retaining linked-record and identity checks. The combined release passed 19 PHP suites, 4 JavaScript suites, 14 HTTP suites (810 requests), all 151 PHP syntax checks and the 26-exclusion deployment guard. The consistent-copy schema-6-to-12 upgrade, automatic backup and original database hash were verified again. Local Python cache files remain untracked and excluded from FTP; no database, upload, environment or runtime configuration is in the release diff.
