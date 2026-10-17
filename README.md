# Nepal School ERP

PHP school administration application using MySQL, locally built Tailwind CSS, Alpine.js, HTMX, and Nepali/English content.

## Supported workflows

- School-scoped classes, sections, subject assignments, teacher assignments, and enrollment.
- Student and teacher profiles, CSV import/export, and record-preserving archiving.
- Daily attendance and conflict-aware weekly routines.
- Exam configuration, marks entry, shared GPA/percentage calculations, ledgers, report cards, and admit cards.
- Question banks, curriculum topics, learning notes, specification grids, and paper generation.
- Separate Exam Questions, chapter-wise Exam Paper Grids, and quota-checked exam papers with printable answer keys.
- Shared-question correction requests, private requester replies, and mixed chapter-based MCQ/written exam papers.
- Staff and restricted student account management, academic years, school settings, and system administration.
- Interactive Practice MCQs with question selection, instant feedback, sounds, optional full screen and answer review.
- Searchable, role-aware navigation with mobile offcanvas and responsive forms.

See [INSTALL.md](INSTALL.md) for safe setup and [the MySQL migration guide](docs/mysql-migration.md) for private export/import steps and verification limits.

Source repository: [sghacker1111/Nepal_ERP](https://github.com/sghacker1111/Nepal_ERP). See [InfinityFree deployment setup](docs/infinityfree-deployment.md) for GitHub Actions secrets, the FTP destination, and first deployment steps.

## Learning content ownership and visibility

The super-admin shared catalog is read-only to school users. Only super admin adds or renames chapters/topics; school questions, imports and notes must select existing catalog entries. Nepali, English and Mathematics use canonical application names; CDC source snapshots and optional-subject identities remain unchanged.

Teachers and school admins create and edit their own content only. New school content is **Private**, readable by its creator, assigned class-subject teachers and school administrators. **Public means within the creating school**, including enrolled students in permitted subjects, never other schools or anonymous visitors. Shared public content is available through validated matching school classes/subjects. My content and archive filters expose creator controls; archive/restore keeps record IDs. Legacy local content with no known creator stays read-only; no author is invented.

Question Bank, Learning Hub, all paper generators and Practice MCQs enforce the same ownership/tenant policy. Exam Questions additionally require explicit student release and cannot enter the normal learning/practice pool. Practice sessions are revalidated on every request and cleared if content becomes private, archived, hidden or otherwise inaccessible. Subscriptions, CSRF, student enrollment/electives and school-preview read-only rules remain in force. Schema 15 established shared-content ownership; schema 16 adds the separate exam bank and grids. School data migration stays offline and requires a private verified MySQL export.

### Shared question corrections and exam access

Questions/MCQ prose in the banks, Learning Hub and paper views reads at normal weight without rewriting editor/import HTML. English and Nepali retain the established 22:16 reading-size compensation, and mathematical weights are excluded from prose normalization. Practice typography is unchanged.

Teachers may read, print, report and generate **exam-bank** questions only for exact, current teaching class-subject assignments, including shared/public exam items. Class-teacher status alone is not an exam-bank assignment. Normal Question Bank browsing remains unchanged. Shared content remains read-only; school staff still edit/delete/publish only their own eligible content.

On a readable shared question, teachers and school admins can use **Request correction** with Mistake found and Suggested correction (1–4,000 characters each). Viewing access is sufficient; question-creation packages are not required. **My correction requests** is private to the requester, including replies and unread counts. Losing subject/bank access hides the source and snapshot, but retains the active original-school requester's own report and reply. Changing schools denies old history.

System administrators use **Correction Questions**, with separate Question Bank / Exam Questions tabs, question-type/status/class/subject/school filters, and standard page sizes. Edit the source through its existing editor, reload the report, and mark **Corrected** only after an actual content change; or **Rejected** with an explanation. Responses require a reply, reject stale review state and become immutable. Duplicate Pending submissions reuse one request; other reporters retain separate history. Reply-read state changes only through a CSRF-protected POST. Schema **18** adds only the correction table/indexes and version metadata with the normal SQLite pre-upgrade backup; it does not rewrite school or question data. MySQL needs staging verification.

See [the correction/mixed-paper handoff](docs/question-corrections-mixed-papers-handoff-2026-10-16.md) for verification and release evidence.

### Safe chapter and topic deletion

Only super admin may delete or restore curriculum entries. In **Curriculum & Topics Manager**, Delete is allowed only when the selected chapter/topic has no **active** linked content. A blocked deletion shows the exact sections, record IDs, school, class, subject and owner for Question Bank items, exam questions/MCQs, notes and saved paper grids. Same-school links open the specific content record where supported. Cross-school private text is never exposed; the report identifies the creator/school responsible for removing or reassigning it. Up to 20 locations per section are shown, with the full count; content is never automatically deleted from this dialog. The Universal Subjects & Chapters catalog uses the same safeguard and reports errors instead of silently ignoring them.

Delete/archive the blocking content or remove its grid allocation, then retry. Already deleted/archived content does not block removal, but its records and references are retained. Deleting an unused chapter also removes its empty topics from active lists. **Deleted curriculum**, scoped by Class and Subject, restores the original IDs. Restore the chapter/topic before restoring its questions, notes or exam grids. A chapter restore also restores topics removed in that operation, not topics deleted separately. Adding an existing deleted name asks for restore rather than creating a duplicate. Historical duplicate IDs and legacy label-only notes are included in dependency checks. School users remain read-only, and all writes are CSRF-protected.

Schema **17** additively adds nullable deletion metadata to chapters/topics; existing records are unchanged and SQLite takes its normal pre-upgrade backup. No permanent catalog/content deletion or reference detachment is used by this flow. MySQL still requires staging verification.

Run the `learning_*_test.php` and `learning_*_http_test.py` suites along with the regressions below. `tests/learning_browser_fixture.py` starts a disposable local QA school; it never opens the original school database.

See [the verified learning-sharing handoff](docs/learning-sharing-handoff-2026-10-13.md) for the independent review fixes, retained-data evidence and outstanding staging/release limits.

## Exam Questions and Exam Paper Generator

System administrators enable **Exam Paper Generator System** (the existing `exam_generator` package key) or **Full ERP** in the school's purchased packages. New **Exam papers** navigation contains **Exam Questions**, **Exam Paper Grids** and **Exam Paper Generator**. The existing normal generator and normal grids remain separate and retain legacy package access. Teachers generate/print only for their assigned class subjects; school admins work within their school. Shared super-admin content/default grids are mapped to the exact corresponding class subject, never by a class-name substring.

1. Use **Exam Questions** for written-answer questions (Very Short, Short, Long, Reasoning and Difference Between), or **Multiple Choice Questions** for exam MCQs. These are separate sidebar pages with independent **Add**, **Import** and **Edit** views. Existing exam records appear automatically in the correct section; no records are copied or moved. List pages show 10 items by default, with 10/20/30/50/100-item choices and permission-aware pagination; filters are preserved in page links. Rich question bodies are loaded only for the selected page. The editor opens alone on its own page with Back/Cancel links, never alongside other questions. English reading text is 16 pt and Kokila Nepali is compensated at 22 pt, including imported small inline formatting, options and model answers. Both authoring sections retain difficulty, K/U/A/HA, decimal marks/time, shared bilingual rich editor, source/code, tables and TeX. Each import page offers only its own MCQ or written-question templates in Normal Text and HTML Source Excel/CSV formats. MCQ imports require four A–D options in English or Nepali and an A–D correct key; written imports have no option/key fields. Exam questions cover a whole chapter: add/edit/import and samples use **Class → Subject → Chapter**, with **no Topic selector or required Topic column**. All three metadata values must match every row; mismatches reject the whole file and imports never create catalog entries, including for super admin. Other invalid content rows show their errors; unexpected storage errors roll back the batch. Exam imports remain staff-only and never enter the normal Question Bank, Learning Hub or Practice MCQs. Only explicit per-question release permits enrolled students to view an exam question.
2. Leave school content **Private** for its creator, assigned subject teachers and school administrators, or share it with staff in that school. Students cannot see it by default. The author must explicitly tick **Allow enrolled students to view** with public/staff visibility; the confirmation explains that this reveals questions, options and model answers. Returning to Private revokes release. Students read only released questions for their enrollment/electives and never access grids or generation. Learning/question-view/question-add packages can read releases; generation requires exam-generator/full access.
3. In **Exam Paper Grids**, save a named blueprint with full marks and duration. Each allocation chooses one or several chapters, question type, count and total marks; optionally constrain difficulty, marks per item and K/U/A/HA counts. Exam grids do not constrain topics. Separate single-chapter rows give fixed chapter allocations; pooled rows can also have exact chapter count/mark targets. Whole-paper cognitive mark targets are optional and separate from MCQ count quotas. Creator-only edit, stale-revision protection, archive/restore and read-only shared defaults are enforced by the backend. Historical topic links and saved grid JSON remain stored; exam generation ignores old topic restrictions, and an explicit grid save normalizes to chapter-wide allocations.
4. **Exam Paper Generator** opens in **By chapter (simple)** mode. Select a class and subject, then choose **Written questions**, **Multiple Choice Questions only**, or **Written + MCQs**. Single-kind tables use Chapter, No. of Questions and Marks (the chapter's total). Mixed rows use Chapter, Question type, No. of Questions and Total marks, with Add/Remove controls; include at least one active MCQ row and one written row. Counts start at **0**; zero-count rows contribute neither questions nor marks. Exam questions cover the whole chapter, with no topic selection. Enter the paper duration and generate. Written-only mode pools non-MCQ types without changing saved marks. Up to 50 allocation rows, 200 questions and 1,000 marks are supported. Use **Advanced grid** for saved/default grids, difficulty and K/U/A/HA quotas; opening a saved grid selects Advanced automatically. An existing exam can optionally supply its title/date after the selected class subject is mapped to that exam. The solver randomizes eligible exam questions and requires exact counts/marks, uniqueness, all specified quotas and a fitting time budget. It never overwrites stored marks or prints partial papers. Shortages show an error. Choose **Group** or **Section** headings: MCQs appear first in A under main Question 1, then nonempty Very Short / Short / Long / Reasoning / Difference Between groups receive the next letters and numbering. Without MCQs, written questions start in A/main 1. Paper and answer key share identical grouping/numbering; advanced row names and quotas remain in Allocation audit.
5. The creator's **Delete** button on both exam-question lists asks for confirmation and performs recoverable deletion. Deleted items disappear from active lists, student release and paper generation. Use **Status → Deleted / archived** and **Restore** to bring back the same record and ID. Other users cannot delete or restore someone else's questions; shared super-admin content remains read-only to schools. No question content is permanently erased.
6. Exam question lists and generated papers support **Nepali + English** (default), **English only** and **Nepali only** viewing/printing. Both-language output pairs each Nepali statement/subpart with its English translation in parentheses, instead of printing two complete language blocks. Keep source paragraphs aligned; unlabeled question paragraphs after the statement receive (a), (b), (c). Options and model answers retain ordinary paragraphs. Language switching after generation retains the same selected questions and answer key. The shared equation editor recovers portable TeX from historical imported math wrappers, so double-clicking a saved equation opens its source rather than a blank box. See [bilingual layout and equation editing](docs/exam-bilingual-layout-2026-10-16.md).

The bundled reference is the visually checked **CDC Optional Mathematics Grades 9-10, 2082** grid: 75 marks, 180 minutes, with an 11-question MCQ allocation (K3/U3/A3/HA2). [Official CDC source](https://giwmscdnone.gov.np/media/pdf_upload/Specification%20Grid%20and%20Model%20Question-G9-Opt.%20Math-web_nrbp00y.pdf). Other subject defaults are super-admin saved grids. Source/edition and review warnings remain visible when loading saved reference copies. Composite questions, mixed-domain subparts, cross-content areas and accessibility alternatives need manual review; whole-question labels do not certify CDC compliance. No model-question bodies are imported from the source.

Generation is bounded to 200 items, 50 allocation rows and 2,000 **matching** eligible questions, with an exact-search limit. Narrow chapter/type/difficulty/mark pools if the limit is reached. Unrelated questions do not consume that matching-pool limit. Schema 16 preserves existing question IDs/content as `learning`, defaults student release off, and adds a separate `exam_paper_grids` table. See [the exam-paper handoff](docs/exam-papers-handoff-2026-10-14.md) for verification and release limits.

## Quick local start

Requires PHP 8.1+ with PDO MySQL, DOM, fileinfo and mbstring. SQLite is limited to isolated tests and offline conversion; normal requests require configured MySQL.

Configure `config/database.local.php` from the credential-free example or set `ERP_DB_*` values for a disposable MySQL server. Start the local PHP server with:

```powershell
php -S 127.0.0.1:8000 router.php
```

A fresh MySQL database has a **disabled** administrator, not a shared password. Set a private `ERP_ADMIN_PASSWORD` environment value (at least 12 characters), then run `php config/setup_admin.php`. Remove that environment value afterward. Existing administrator passwords are never reset by setup.

## School setup order

1. System administrator: create a school administrator assigned to the correct school.
2. School administrator: choose the academic year and enable the school's classes.
3. Add teachers; assign class teachers and subject teachers.
4. Review class-subject mappings, compulsory/elective status, credits and assessment components.
5. Enroll students in that year, class and section, selecting their offered electives.
6. Create an exam, initialize/configure subjects and assign exam-specific symbol numbers. Open exams can add forgotten mappings after marks; corrections affecting saved marks require confirmation.
7. Review marks, lock, then publish. Reopen explicitly withdraws publication for corrections.

Archiving students or teachers preserves their records. Exams with marks cannot be deleted. Class/elective enrollment cannot change after marks exist; retain the enrollment for historical reports.

## Student learning and Practice MCQs

In **Administration → User accounts**, a school administrator can select **Student (learning only)** and link an existing student record. The system administrator can do the same after selecting a school. Each record has at most one login. Passwords are hashed; disabling the account, enrollment or school subscription immediately revokes access. Students can access Learning Hub, Practice MCQs and explicitly released Exam Questions, subject to their school package, enrolled class and selected electives. A question-view-only student lands on the released exam-question section instead of an unavailable Learning Hub. Staff roles keep their existing access rules.

Use **Learning → Practice MCQs** to filter by class, subject, chapter, topic or question text, then select individual questions or a random set of 1–100 MCQs. Only valid four-option questions from the school's bank and matching shared catalog are offered. Answers are graded by the server and cannot be changed after submission. Red/green feedback includes the correct answer, with optional boom/chime sounds, mute, full screen and keyboard keys 1–4. A completed set shows its score and answer review. Practice is separate from official exam marks; sessions resume in the same login for up to one hour, but practice history is not stored permanently.

Every new practice shuffles its questions, including manually selected sets. **Back to class & subject selection** is available both above and beneath the completed answer review.

Kokila/Nepali text and question editors default to **20 pt**. Practice compensates for Kokila's smaller visible glyphs using a **22:16 size ratio**: 16 pt English corresponds to 22 pt Nepali. Questions and options each scale from their own English size, including fullscreen/mobile; feedback and review use the same compensation. Practice-only font rules prevent nested rich-text formatting from shrinking Nepali prose without changing saved content. Bold rich-text emphasis also has a subtle underline so it remains distinguishable with static font faces. Landscape fullscreen fills the largest available **16:9** board, while portrait/mobile layouts stack and scroll. Short question/feedback animations respect reduced-motion preferences. Both answer sounds have louder, smoothly faded levels; mute still persists between visits, and audible volume also depends on the device's volume setting.

Schema 14 adds only a nullable student-record account link and a unique index. Existing accounts and school records are not relinked, reset or removed. SQLite keeps the existing automatic pre-upgrade backup. Test MySQL upgrades separately on staging.

## Question Bank imports

**Learning → Question Bank** uses paginated bilingual cards with All questions, Multiple Choice Questions and Written questions tabs. Add, Edit and Import open their own pages using the shared rich editor; new questions select an existing Topic. Class, subject, chapter, topic, difficulty, cognitive level, source, text and language filters are supported. **Nepali + English** is the default; paired statement/subpart layout, single-language views and print controls match the exam bank. Existing creator-only edits, school visibility, recoverable Delete/Restore and shared-catalog restrictions remain unchanged.

In **Import Questions**, MCQs and Other Questions have separate forms and separate Excel/CSV samples in **Normal Text** and **HTML Source**. Select an existing **Class → Subject → Chapter → Topic** in the relevant form first. Samples use exactly those four entries. Each nonblank spreadsheet row must fill in all four metadata columns and match the selection. A missing or mismatched value rejects the whole file before any saves; Class 1 never matches Class 10/11/12. Only assigned/writable class subjects appear. Ambiguous subject names require a unique subject code. Headers include difficulty, source, cognitive level, decimal marks, required minutes, bilingual questions and model answers; only MCQ samples contain four bilingual options and Correct Option. Older clients keep their legacy import endpoint/template support.

**Exam Questions** and exam **Multiple Choice Questions** use the same validation with only **Class → Subject → Chapter**, not Topic. No account, including super admin, can create chapters or topics through either bank's import. Add missing catalog entries in **Curriculum & Topics Manager** first. Catalog mismatches reject the whole batch; other invalid question content is reported using row-level errors. Existing questions and catalog records are unchanged.

Normal mode escapes HTML tags and keeps line breaks. HTML Source preserves supported paragraphs, bold/italic, lists, tables, images, subscript/superscript and LaTeX in questions, all four options and model answers. Use `\( ... \)` / `\[ ... \]` or `$ ... $` / `$$ ... $$` for maths. Scripts, event handlers, unsafe URLs and unsupported styling are removed; imported content is editable using the editor's Source/code button. Content headers may include `HTML` or `Source`; if a file has both plain and source columns, the selected mode chooses the matching columns. MCQs require four nonempty options and a correct-option key A–D; invalid rows are reported, never assigned a guessed answer. Empty/failed batches do not leave partial questions or curriculum changes.

### Topic-wise unit/test papers

**Learning → Paper generator** now opens the **Test Paper Generator**. Select a class and subject, then add allocation rows: **Chapter | Topic (optional) | Question type | No. of questions | Total marks**. A blank Topic uses the entire selected chapter; count **0** skips the row. Add multiple rows to mix chapters, topics and question types. Generation randomizes the normal Question Bank only, enforces exact saved marks and counts across all rows, and never repeats questions or fills shortages from the exam bank. Up to 50 rows, 200 questions and 1,000 marks are supported. Older untimed learning questions remain eligible but contribute no time estimate. Language controls support both languages or one language without rerandomizing an existing preview; paper and answer key print separately. The existing automatic/manual/OCR/grid generator remains available through its link. See [chapter exams and topic tests](docs/chapter-exams-topic-tests-2026-10-16.md).

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
php tests/exam_templates_test.php
php tests/exam_bank_test.php
php tests/exam_paper_test.php
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
php tests/routine_profiles_test.php
php tests/mcq_practice_test.php
php tests/question_import_content_test.php
node tests/mcq_practice_ui_test.js
node tests/accounting_preview_test.js
node tests/date_controls_test.js
node tests/attendance_controls_test.js
node tests/content_safety_test.js
node tests/question_editor_test.js
node tests/learning_readability_test.js
node tests/paper_chapters_test.js
node tests/exam_grid_editor_test.js
python tests/http_test.py
python tests/exam_http_test.py
python tests/assessment_http_test.py
python tests/packages_http_test.py
python tests/accounting_http_test.py
python tests/subscription_teaching_http_test.py
python tests/teacher_results_http_test.py
python tests/attendance_filters_http_test.py
python tests/routine_learning_http_test.py
python tests/routine_profiles_http_test.py
python tests/progress_print_http_test.py
python tests/class_isolation_http_test.py
python tests/mcq_practice_http_test.py
python tests/question_source_http_test.py
python tests/exam_templates_http_test.py
python tests/exam_questions_http_test.py
python tests/exam_paper_http_test.py
python tests/deployment_test.py
```

The HTTP suite drives real PHP requests and database persistence. `tests/upgrade_test.php` accepts only a disposable existing-database copy in the OS temporary directory through `ERP_DB_PATH`.

## Subscriptions and teaching permissions

System administrators use **School Management → Edit** to tick multiple purchased packages, activate/deactivate the account and choose yearly, custom-date or no-expiry access. Package access is combined; an explicitly empty selection does not restore Full ERP. Dated subscriptions include the expiry day in Nepal time, then block school accounts until renewed. Start/expiry dates accept AD or BS. **Renew for one year** extends a current term without interrupting it, or starts a new year today for an expired term. Removing access never deletes school records.

Schema 9 adds subscription fields without assigning invented expiry dates to existing schools. Legacy schools retain their existing package and no expiry until the administrator configures a dated subscription.

Teachers can manage specification grids only for active subjects assigned to them in the selected class. Chapters/topics are now a read-only catalog for school users; only super admin maintains that catalog. Subject selectors retain distinct subject identities, remove repeated section mappings and show codes when names collide. These permissions are enforced on submitted requests as well as in the interface. Locked/published exams show saved marks read-only; only school/system administrators may reopen marks. After reopening, a subject teacher can save only their assigned subjects and sections. The active teacher assigned as **Class Teacher** in Classes may review and correct all configured subjects and sections in that supervised class, including subjects they do not teach; this never grants chapter/topic ownership or extends marks-entry/ledger authority to another class.

Use **Marks & results → Ledger Grid** or the **Full-class ledger / Subject ledger** links in Marks Entry to view and print. All teachers may view and print all classes, sections and subjects within their own school. Select **All Subjects** for the full-class ledger or a single subject. Broader ledger viewing never grants extra marks-entry permissions: subject teachers edit only their assigned subjects/sections; class teachers correct their supervised class; locks still apply. **Grade Only** (default) shows GRADE (TH), GRADE (IN), FINAL GRADE and GRADE POINT, with only total CH below each subject. **Marks Only** shows TH, PR and TOTAL, with only total FM below each subject, and an aggregate percentage/division. Subject summaries refer only to the selected subject, never the overall report card. Dedicated print CSS specifies **A4 landscape**, including a named ledger page; printers that override CSS must be set to Landscape in their dialog. Print layouts include the actual school name, exam/class/section, rolls and exam-specific symbols. Missing marks, nonapplicable components and untaken optional subjects remain distinct.

## Attendance and student filters

Attendance supports **Daily, Monthly, Yearly and Date range** views in AD or BS. BS periods use real calendar month lengths, not AD month names or fixed day offsets (supported BS years: 2000–2090). Monthly selectors show the twelve requested Nepali or English months. Year/range totals cover the full selected period; editable pages show at most 10 students and 32 dates to avoid truncated PHP forms. Browse dates/students or search a name, roll, student ID or EMIS number. Future dates and archived students are view-only, blanks are not inferred absences and never delete saved history, and correcting a saved status requires the actor's current password. Attendance editing remains limited to school/system administrators and the assigned class teacher; principals may view it. Arbitrary ranges are limited to 366 days.

Student Management uses exact class and section IDs and exact statuses on the server. Class 1 does not match Class 10. Free-text search is limited to names (English/Nepali), rolls, student IDs and EMIS numbers, not class names, gender or statuses. Search wildcards are treated literally, and every filter remains school-scoped.

Subject Management also uses an exact school-owned class ID on the server, rather than a partial DataTables text search. Selecting Class 1 excludes Classes 10, 11 and 12. Cross-section isolation is covered by real HTTP checks for subjects, students, curriculum selectors, attendance, results, ledgers, marks entry, report/admit cards and question lists.

## Super-admin exam defaults

Use **System → Default exams** to edit First, Second and Third / Final term templates. Choose a universal class to customize that template's TH/IN full/pass marks and credit hours. Templates are revisioned and may be deactivated for future use; earlier revisions remain in history. Initially, subject defaults come from the system's universal catalog. These are configurable school-management defaults, not certification of every CDC offering.

In **Exams → Create New Exam**, select **Manual** (customize a copy) or **Automatic** (locked defaults), then choose a template. Both modes copy subject schemes and retain a template snapshot. Schools choose their academic year and exam dates at creation. Manual exams may be renamed and configured under the usual marks/lock safeguards. Automatic exams cannot change names, types, result systems, subject limits, credits or IN allocation, including through forged POST requests; marks, symbols, print and lock/reopen operations keep their existing permissions. Additional class subjects may be initialized only from the saved snapshot, never from a later-edited template. Settings are not retroactively changed when an administrator edits a default. School-specific subjects without a system default require Manual mode or an administrator-supplied default; automatic creation fails atomically instead of silently dropping them.

Schema 12 adds template/history tables and nullable template references/snapshots. Existing exams remain Manual with unchanged IDs, marks and schemes. The migration retains the SQLite pre-upgrade backup and idempotency safeguards. MySQL schema is supported statically but still requires staging verification.

## Weekly routine and class-subject selectors

Routine settings use Monday–Friday; Saturday and Sunday are holidays. Administrators select a class, edit subject teachers and **total periods per week**, then generate or modify daily cells. Weekly periods are independent of TH/IN grading credit hours; unsaved values suggest rounded credits. The same value is editable in Class Subject Mapping. First periods require the class teacher and their assigned subject every school day, so their subjects need at least five weekly periods. Generation checks teacher collisions and capacity; infeasible schedules roll back entirely. Existing routines are archived before replacement. Section requirements are displayed separately, but the timetable is class-wide and uses the largest per-subject requirement.

Weekly routines now support named profiles (e.g. summer/winter). Load a profile to preview it; only administrators may save or activate it, with CSRF and current-password confirmation. Manual mode edits every day's subjects and teachers; automatic mode uses weekly requirements. Each day has its own 1–10 period count, individual start/end times, short break and tiffin/long break. Breaks must fit in gaps between lessons. Teacher labels use initials, with full-name tooltips and a printed legend. Saving a draft does not change the active timetable: manual and automatic drafts may overlap another class, and manual drafts may contain fewer than the required weekly periods. Activation requires each subject's full weekly allocation and checks time-overlap conflicts across classes, preserving the previous slots and layout on failure. A validation error retains valid manual selections and clock entries in the editor without changing the active preview. Profile revisions prevent stale edits and prior versions remain in history.

Class names are compared by normalized grade identity (`7`, `Class 7`, `Grade 7`, `कक्षा ७`), case and whitespace. Subject creation, mapping and rename check names and codes within each class, including hidden/optional mappings. Catalog changes are serialized per school. Teachers cannot create classes or subjects. In Class Management, a school administrator can remove a legacy class named exactly `7` from active lists when that school contains teacher `sgsunil`; password and explicit confirmation are required. This archives the class and retains linked records, rather than permanently deleting educational data. Archived classes can be reviewed; rename an invalid duplicate before restoring it.

Question Bank, all paper generators, Learning Hub and curriculum selectors use school-scoped class mappings. Assigned optional subjects appear when the catalogue subject is not hidden. Staff can browse eligible shared/school-public content across their school, but teachers create and edit only their own content for assigned teaching classes/subjects. Private reads still require the creator, assigned subject co-teacher or school-administrator permission. Repeated section mappings are removed; distinct subjects retain their IDs and code/type labels. Choose a class before a subject; changing class resets subject. Question Bank supports class, subject, category, cognitive level and text/chapter search. Quick Add Class/Subject is removed, including crafted requests; administrators use management pages.

The universal CDC catalogue has a class-first filter and include-hidden/inactive option. Visibility uses POST with an explicit target state and a redirect, preventing refresh/back from toggling it again. Existing school copies retain their own settings.

Individual and bulk progress cards default to **GPA, BS-only dates, BS manual date entry and YYYY-MM-DD**, independently of the saved exam's presentation settings. Issue dates and birth dates follow the selected print calendar; explicit AD/both and other date-format choices still work. The GPA title is **GRADE SHEET**; the percentage title is **MARKS SHEET**. Shared signatures print image/signing space, content-width rule, name, then post. Configure the coordinator name in School Settings. Empty result classes show no results table.

Percentage report cards use six columns: S.N., Subject, Full Marks, Obtained Marks, Total Obtained and Remarks. Subject entries have TH/PR rows, with a dash for nonapplicable PR; thematic continuous assessments use one overall-assessment row without inventing TH/PR marks. Shared serial/total/remarks cells avoid duplicated totals, and missing required marks remain Incomplete. Individual and bulk cards share the same renderer and show a division key, final total percentage and division instead of the GPA grading key/credit-hour note/abbreviation legend. Divisions are 80–100% Distinction, 60–<80% First, 45–<60% Second and 35–<45% Third; below 35% or a failed required component is Failed. Stored exam schemes and GPA calculations are unchanged.

Schema 11 adds nullable routine times and school/class-scoped profile, layout and history tables. Pre-upgrade SQLite backups and idempotent migration remain enabled; records and money are preserved. Legacy routines retain their slots, with times shown only after a timed profile is activated.

See [security review and hosting requirements](docs/security-review-2026-10-11.md). These checks reduce risk; they are not a guarantee against all attacks or a production penetration test.

## Deployment boundary

See [exam workflow controls](docs/exam-workflows-2026-10-10.md) for BS/AD dates, exam symbols, searchable admit-card printing, confirmed mapping corrections and converted previous-exam IN contributions.

Nothing deploys automatically from local edits, but **pushing this repository's main branch triggers the existing FTP deployment workflow**. Back up production, review the diff and test a staging copy before publishing. Do not run development servers on public interfaces.

## License

Proprietary software for educational institutions.
