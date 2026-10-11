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
php tests/assessment_test.php
php tests/packages_test.php
php tests/accounting_schema_test.php
php tests/accounting_money_test.php
php tests/accounting_test.php
php tests/subscription_teaching_test.php
php tests/teacher_results_test.php
php tests/attendance_filters_test.php
php tests/routine_learning_test.php
node tests/accounting_preview_test.js
node tests/date_controls_test.js
node tests/attendance_controls_test.js
node tests/content_safety_test.js
python tests/http_test.py
python tests/exam_http_test.py
python tests/assessment_http_test.py
python tests/packages_http_test.py
python tests/accounting_http_test.py
python tests/subscription_teaching_http_test.py
python tests/teacher_results_http_test.py
python tests/attendance_filters_http_test.py
python tests/routine_learning_http_test.py
python tests/deployment_test.py
```

The HTTP suite drives real PHP requests and database persistence. `tests/upgrade_test.php` accepts only a disposable existing-database copy in the OS temporary directory through `ERP_DB_PATH`.

## Subscriptions and teaching permissions

System administrators use **School Management → Edit** to tick multiple purchased packages, activate/deactivate the account and choose yearly, custom-date or no-expiry access. Package access is combined; an explicitly empty selection does not restore Full ERP. Dated subscriptions include the expiry day in Nepal time, then block school accounts until renewed. Start/expiry dates accept AD or BS. **Renew for one year** extends a current term without interrupting it, or starts a new year today for an expired term. Removing access never deletes school records.

Schema 9 adds subscription fields without assigning invented expiry dates to existing schools. Legacy schools retain their existing package and no expiry until the administrator configures a dated subscription.

Teachers can manage chapters, topics and specification grids only for active subjects assigned to them in the selected class. Subject selectors remove duplicate section mappings and show the subject code. These permissions are enforced on submitted requests as well as in the interface. Locked/published exams show saved marks read-only; only school/system administrators may reopen marks. After reopening, a subject teacher can save only their assigned subjects and sections. The active teacher assigned as **Class Teacher** in Classes may review and correct all configured subjects and sections in that supervised class, including subjects they do not teach; this never grants chapter/topic ownership or extends marks-entry/ledger authority to another class.

Use **Marks & results → Ledger Grid** or the **Full-class ledger / Subject ledger** links in Marks Entry to view and print. All teachers may view and print all classes, sections and subjects within their own school. Select **All Subjects** for the full-class ledger or a single subject. Broader ledger viewing never grants extra marks-entry permissions: subject teachers edit only their assigned subjects/sections; class teachers correct their supervised class; locks still apply. Subject totals/GP/percentage refer only to the selected subject, never the overall report card. Dedicated print CSS specifies **A4 landscape**, including a named ledger page; printers that override CSS must be set to Landscape in their dialog. Print layouts include the actual school name, exam/class/section, rolls, exam-specific symbols, numeric TH/IN/total marks and grades; incomplete marks and untaken optional subjects remain distinct.

## Attendance and student filters

Attendance supports **Daily, Monthly, Yearly and Date range** views in AD or BS. BS periods use real calendar month lengths, not AD month names or fixed day offsets (supported BS years: 2000–2090). Monthly selectors show the twelve requested Nepali or English months. Year/range totals cover the full selected period; editable pages show at most 10 students and 32 dates to avoid truncated PHP forms. Browse dates/students or search a name, roll, student ID or EMIS number. Future dates and archived students are view-only, blanks are not inferred absences and never delete saved history, and correcting a saved status requires the actor's current password. Attendance editing remains limited to school/system administrators and the assigned class teacher; principals may view it. Arbitrary ranges are limited to 366 days.

Student Management uses exact class and section IDs and exact statuses on the server. Class 1 does not match Class 10. Free-text search is limited to names (English/Nepali), rolls, student IDs and EMIS numbers, not class names, gender or statuses. Search wildcards are treated literally, and every filter remains school-scoped.

## Weekly routine and class-subject selectors

Routine settings use Monday–Friday; Saturday and Sunday are holidays. Administrators select a class, edit subject teachers and **total periods per week**, then generate or modify daily cells. Weekly periods are independent of TH/IN grading credit hours; unsaved values suggest rounded credits. The same value is editable in Class Subject Mapping. First periods require the class teacher and their assigned subject every school day, so their subjects need at least five weekly periods. Generation checks teacher collisions and capacity; infeasible schedules roll back entirely. Existing routines are archived before replacement. Section requirements are displayed separately, but the timetable is class-wide and uses the largest per-subject requirement.

Question Bank, both paper generators, Learning Hub and curriculum selectors use school-scoped class mappings. Assigned optional subjects appear when the catalogue subject is not hidden. Teachers see only their teaching classes/subjects. Duplicate section/name entries are removed. Choose a class before a subject; changing class resets subject. Question Bank supports class, subject, category, cognitive level and text/chapter search. Quick Add Class/Subject is removed, including crafted requests; administrators use management pages.

The universal CDC catalogue has a class-first filter and include-hidden/inactive option. Visibility uses POST with an explicit target state and a redirect, preventing refresh/back from toggling it again. Existing school copies retain their own settings.

Bulk cards default to **BS only, YYYY-MM-DD**; explicit calendar/format choices still work. Shared signatures print image/signing space, content-width rule, name, then post. Configure the coordinator name in School Settings. Empty result classes show no results table.

Schema 10 adds nullable weekly-period/coordinator-name fields only. Pre-upgrade SQLite backups and idempotent migration remain enabled; records and money are preserved.

See [security review and hosting requirements](docs/security-review-2026-10-11.md). These checks reduce risk; they are not a guarantee against all attacks or a production penetration test.

## Deployment boundary

See [exam workflow controls](docs/exam-workflows-2026-10-10.md) for BS/AD dates, exam symbols, searchable admit-card printing, confirmed mapping corrections and converted previous-exam IN contributions.

Nothing deploys automatically from local edits, but **pushing this repository's main branch triggers the existing FTP deployment workflow**. Back up production, review the diff and test a staging copy before publishing. Do not run development servers on public interfaces.

## License

Proprietary software for educational institutions.
