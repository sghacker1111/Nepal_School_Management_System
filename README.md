# Nepal School ERP

PHP school administration application with SQLite or MySQL, Bootstrap, and Nepali/English content.

## Supported workflows

- School-scoped classes, sections, subject assignments, teacher assignments, and enrollment.
- Student and teacher profiles, CSV import/export, and record-preserving archiving.
- Daily attendance and conflict-aware weekly routines.
- Exam configuration, marks entry, shared GPA/percentage calculations, ledgers, report cards, and admit cards.
- Question banks, curriculum topics, learning notes, specification grids, and paper generation.
- Staff account management, academic years, school settings, and system administration.
- Searchable, role-aware navigation with mobile offcanvas and responsive forms.

See [INSTALL.md](INSTALL.md) for safe setup, migration, and production precautions, and [the repair report](docs/repair-report-2026-10-09.md) for verified coverage and remaining limits.

## Quick local start

Requires PHP 8.1+ with PDO SQLite, DOM, fileinfo and mbstring. This revision was tested with PHP 8.5.1 and SQLite. MySQL needs its PDO driver and a separate staging verification.

For an existing database, back it up before running. Start the local server with:

```powershell
php -S 127.0.0.1:8000 router.php
```

A fresh database has a **disabled** administrator, not a shared password. Set a private `ERP_ADMIN_PASSWORD` environment value (at least 12 characters), then run `php config/setup_admin.php`. Remove that environment value afterward. Existing administrator passwords are never reset by setup.

## School setup order

1. System administrator: create a school administrator assigned to the correct school.
2. School administrator: choose the academic year and enable the school's classes.
3. Add teachers; assign class teachers and subject teachers.
4. Review class-subject mappings, compulsory/elective status, credits and assessment components.
5. Enroll students in that year, class and section, selecting their offered electives.
6. Create an exam, initialize/configure subjects and assign exam-specific symbol numbers. Open exams can add forgotten mappings after marks; corrections affecting saved marks require confirmation.
7. Review marks, lock, then publish. Reopen explicitly withdraws publication for corrections.

Archiving students or teachers preserves their records. Exams with marks cannot be deleted. Class/elective enrollment cannot change after marks exist; retain the enrollment for historical reports.

## CDC and assessment scope

The supplied `CDC_Nepal_2083_School_ERP_Grading_Master.xlsx` is integrated as the versioned data package in `config/data/cdc_2083.json`. Schema upgrade 4 stores all 76 class-subject source rows, current grade bands, legacy references, assessment components, policies and audit notes in the database. The importer reuses existing class/subject IDs, updates supported class-specific credits and marks, and leaves saved exam schemes and student marks unchanged. SQLite is backed up automatically before the upgrade.

Run `php config/import_cdc_2083.php` to apply and report the idempotent import; normal startup also applies it once. Administrators can use **CDC 2083 grading** in the sidebar to inspect each class and confirm uncertain school offerings. Grades 1–3 use achievement percentage with unspecified TH/IN splits. Grades 4–8 use the workbook's 50/50 scheme; annual exams have four internal contribution fields. Overall GPA uses unrounded weighted section totals before two-decimal display rounding.

The workbook itself is **not a complete national catalogue**: Class 11 is a six-subject example; Class 12 is illustrative; elective II has a 202/206 code conflict. These entries retain source flags and require school confirmation. Existing extra courses remain available, rather than being deleted or mislabeled as official. This is not a certified implementation of every CDC/NEB stream, attendance/certificate policy or historical cohort. Review school-specific electives, exceptions and approval/effective dates before issuing official reports. See `docs/cdc-2083-integration.md`.

The shared grade bands are A+ 90+, A 80+, B+ 70+, B 60+, C+ 50+, C 40+, D 35+, otherwise NG. Independent component pass thresholds come from the saved exam scheme. Missing required marks remain incomplete. Grade 11–12 enrollment supports three elective slots; official subject-group combination validation is not yet automated. Primary continuous assessment is not a full competency-report implementation.

## Verification

All tests below create temporary databases:

```powershell
php tests/security_test.php
php tests/workflow_test.php
php tests/grading_test.php
php tests/content_test.php
php tests/operations_test.php
php tests/exam_enhancements_test.php
php tests/exam_workflows_test.php
php tests/cdc_2083_test.php
python tests/http_test.py
python tests/exam_http_test.py
```

The HTTP suite drives real PHP requests and database persistence. `tests/upgrade_test.php` accepts only a disposable existing-database copy in the OS temporary directory through `ERP_DB_PATH`.

## Deployment boundary

See [exam workflow controls](docs/exam-workflows-2026-10-10.md) for BS/AD dates, exam symbols, searchable admit-card printing, confirmed mapping corrections and converted previous-exam IN contributions.

Nothing deploys automatically from local edits, but **pushing this repository's main branch triggers the existing FTP deployment workflow**. Back up production, review the diff and test a staging copy before publishing. Do not run development servers on public interfaces.

## License

Proprietary software for educational institutions.
