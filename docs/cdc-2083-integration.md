# CDC workbook integration — 9 October 2026

Source: user-supplied `CDC_Nepal_2083_School_ERP_Grading_Master.xlsx`, 21 worksheets. The workbook identifies `Grade Sheet New System.docx` for roster/assessment examples and `LetterGrading083_k5pneb6.pdf` for the current percentage bands. CDC's publication page is https://moecdc.gov.np/content/647/letter-/ . Publication was verified; the remote PDF retrieval timed out. Workbook tab/row provenance and SHA-256 are retained in `config/data/cdc_2083.json` and database metadata. Text in the workbook was treated as source data, not executable instructions.

## Applied data

- All 76 class-subject reference rows are stored in `cdc_reference`; each row is linked to a class mapping for both the universal catalog and each existing school.
- Grades 1–3: five subjects and 26 total example credits. TH/IN credits and marks remain SQL NULL because the source does not specify them. Achievement percentage is entered directly; it is not represented as an invented 100-mark theory exam in the catalog. The exam snapshot uses a 0–100 input scale with `THEMATIC_CONTINUOUS` semantics.
- Grades 4–8: seven example subjects, 32 total example credits, separate 50/50 TH/IN marks and specified section credits. Local/mother tongue/Sanskrit is one local alternative, not three compulsory subjects.
- Grades 9–10: five supported compulsory rows and two elective example rows. Textual numeric codes retain leading zeros. The optional-II code conflict is not silently resolved.
- Grades 11–12: six example rows per class are retained for review. Existing school offerings are preserved; newly introduced uncertain mappings are inactive until school confirmation. Class 12 values are not certified defaults.
- Eight current percentage bands, eight final subject GP intervals, 17 assessment records, 32 policies, 19 audit records, rule parameters, descriptors and isolated 2079 legacy references are stored. Worked marks are reference/test examples only; none are inserted as real student marks.

## Computation and screens

Section percentages are graded independently. Theory must reach 35% and internal 40% for CDC-tagged schemes, even if a lower pass threshold is entered. Subject GP is credit weighted. Overall GPA sums unrounded weighted values and rounds the final display to two decimals. The Grade 11 worked example yields 3.64, not the source's inconsistent printed 3.63. Final GP-to-letter intervals are Word-derived examples, not separately prescribed by the supplied PDF Annex 1. Ordinary rounding is explicit and must be confirmed for official board-issued reports.

Annual internal entry uses four scaled contributions: grades 4–8 = 4 + 36 + 5 + 5; grades 9–12 = 3 + 16 + 3 + 3. Trimester uses three: 4 + 36 + 10 or 3 + 16 + 6. Enter contribution marks (raw score / raw maximum * contribution maximum), not raw trimester marks. Legacy saved exams keep their prior component input behavior. Unsupported extra-credit GPA policy, stream exclusivity, certificate issuance, attendance exceptions and historical improvement eligibility are not fabricated.

**Administration → CDC 2083 grading** shows source values, current mapping status, grading bands, contribution maxima and all source warnings. Course confirmation is scoped to the signed-in school (or universal catalog for a system administrator), protected by role and CSRF checks, and logged. It does not rewrite saved exam configurations or student subject selections.

## Safety and verification

Schema 4 is additive. SQLite upgrade takes a recoverable snapshot in `.backups/`; MySQL installations should make their normal database backup before deployment. Import is transactional and version guarded. A second run does not duplicate classes, subjects or mappings. New schools inherit the source-aware catalog.

Verification: all PHP files lint; existing security/content/operations/workflow/grading checks pass; 726 dedicated CDC assertions pass; 125 real HTTP checks pass, including class initialization, four-component annual marks, Grades 1–3 achievement reports, contribution limits, school confirmation/CSRF, existing saved-scheme preservation and duplicate prevention. A disposable copy of the original database passes upgrade/integrity checks and retains all 50 pre-existing orphan marks without fabricating students or exams.

Commands:

```text
php config/import_cdc_2083.php
php tests/cdc_2083_test.php
python tests/http_test.py
```

## Local database application

Applied to the project's `database.sqlite` after a successful disposable-copy upgrade test. Backup: `.backups/school-before-upgrade-20261009-154633-b7ec79.sqlite`. The database now has 76 source-reference rows and 152 linked workbook mappings (universal catalog plus the one school), with 32 review-flagged mappings across those two scopes. The 16 existing universal classes retain their IDs; 16 separate school-owned classes are provisioned for tenant isolation, not duplicate classes inside a school. Original marks, user accounts, attendance, students and exam records were compared against the backup and are unchanged. The 50 pre-existing orphan marks remain retained and are not fabricated into student results.

Browser verification used a separate temporary fixture database on port 8099 with test accounts; it did not contain or modify the original school's records. That temporary preview was stopped after verification. The versioned data package and migration code are published through Git; the local database, backups and uploads are excluded. Hosted installations apply the migration on normal startup using their own database and require their own post-deployment verification.
