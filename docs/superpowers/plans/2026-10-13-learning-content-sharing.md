# Learning Content Sharing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make shared catalog content read-only to schools, restrict school content editing to its creator, and enforce Private/Public visibility across every learning reader without losing existing records.

**Architecture:** Extend the existing PHP application with additive ownership metadata, a shared policy/query service and a catalog-reference resolver. Keep the current IDs and local/shared scopes; controllers use the same access decisions for lists, mutations, imports, practice sessions and paper generation. Normalize application subject labels without rewriting CDC source snapshots or exam history.

**Tech Stack:** PHP/PDO, SQLite/MySQL, vanilla JavaScript, existing Bootstrap/TinyMCE/MathJax, PHP service tests and Python real-HTTP disposable fixtures.

**Spec:** `docs/superpowers/specs/2026-10-13-learning-content-sharing-design.md` (approved 2026-10-13).

**Status:** Tasks 1–7 completed locally on 2026-10-13. Full verification passed after the GPT-6.1 Sol review fixes. No live migration, push, merge or deployment was performed. See [the handoff report](../../learning-sharing-handoff-2026-10-13.md).

**Execution:** The user approved Native execution: implement sequentially in this session, then obtain one independent whole-branch review. No parallel implementer agents were needed because the tasks share policy and catalog interfaces. Astra was unavailable before reviewing; GPT-6.1 Sol completed the independent review with explicit user approval.

## Global Constraints

- Public means public inside the creating school only, never another school and never anonymous internet access.
- Teachers add, edit and publish only their own questions and notes. School administrators cannot overwrite teacher-owned content.
- Only super admin adds, renames or edits chapters/topics. Students remain view/practice-only within enrollment/elective scope.
- New school content defaults to Private; existing local content retains its existing school-only visibility. Unknown creators are not fabricated.
- Do not permanently delete educational content as part of this change. Preserve school/class/subject/question/topic IDs, marks, finance records and uploaded paths.
- Keep CDC source-workbook snapshots, subject codes, compulsory/optional type, credits and marks unchanged.
- Preserve subscriptions/packages, CSRF, preview read-only rules, rich-content sanitization and exact Class 1/10/11/12 isolation.
- Preserve the pending Kokila 20 pt/larger text/animation/louder-sound changes. Do not reset the original checkout or original database.
- No live migration, push, merge or FTP deployment is part of plan execution unless separately requested.

## Review Focus

1. A question becomes private after a student starts or completes practice: subsequent state/answer/review must not return stale private content (Task 6).
2. A school user imports an unknown or other-school chapter/topic: no implicit catalog creation or partial unintended mapping (Tasks 3–4).
3. Legacy content has no creator, or a creator loses their assignment: no invented ownership and no edit privilege based only on matching subject (Tasks 1–2).
4. Canonical labels collide or codes repeat between grades: no arbitrary subject selection, no subject deletion and no optional/compulsory confusion (Tasks 1 and 3).
5. A user changes archived content visibility or posts mixed GET/body actions: backend authorization remains atomic and cannot leak rows/counts (Tasks 2 and 7).

## File Structure and Test Conventions

New focused files:

- `includes/subject_names.php`: canonical application subject names and label-only migration.
- `includes/learning_schema.php`: schema-15 additive learning migration and compatibility backfill.
- `includes/learning_catalog.php`: validated local/global catalog choices and references.
- `includes/learning_content.php`: actor, read/create/edit/publish/archive decisions and readable queries.
- `includes/learning_content_service.php`: transactional question/note writes and audit records.
- `tests/learning_sharing_fixture.php`: extend existing disposable fixture with owners, co-teacher, unrelated teacher and both schools.
- New service/HTTP tests named in each task below; reuse `tests/test_support.php` and `tests/http_support.py` rather than introducing a second runner.

Modify existing schema/CDC/catalog/guards/learning pages at their named functions/handlers. Keep non-learning tenant validation unchanged. Reuse the existing linked worktree at `C:\Users\SGHACKER\.codex\worktrees\accounting-completion\Nepal School Management System`; verify its Git directory/common-directory relationship at execution start. Snapshot dirty-file hashes and database hash before edits. Use explicit path staging per task, never `git add .`.

Run commands below from that worktree. PHP executable is `C:\php\php.exe`; Python is `C:\ProgramData\anaconda3\python.exe`. Set `$env:ERP_TEST_PHP='C:\php\php.exe'` before HTTP tests. Every test uses OS-temp databases; use only a read-only consistent copy for upgrade tests involving the original file.

Common types: `Actor = array{id:int,role:string,school_id:int,read_only:bool}`; `Scope = array{school_id:int,class_id:int,subject_id:int}`. `purpose` accepts only `bank`, `study`, `practice` or `paper`. `kind` accepts only `questions` or `learning_notes`. All new service IDs are positive integers; visibility accepts only `private` or `public`.

---

### Task 1: Canonical labels and record-preserving schema upgrade

**Files:** Create `includes/subject_names.php`, `includes/learning_schema.php`, the scope-identity portion of `includes/learning_catalog.php`, `tests/learning_schema_test.php`, `tests/subject_names_test.php`; modify `includes/schema.php`, `includes/accounting_schema.php:migrateSchoolExtensions`, `includes/curriculum.php:seedUniversalCurriculum`, `includes/cdc_2083.php:importCdc2083Scope`, `includes/question_import.php:questionImportSubjectKey`, `includes/catalog_validation.php:validateUniqueClassSubject`, `modules/subjects/add.php`, `modules/subjects/edit.php`, `modules/super_admin/subjects.php`, `includes/xlsx_helper.php` sample labels, and `tests/upgrade_test.php`.

**Interfaces:**
- Produces `subjectCanonicalName(string $name):string`, `migrateCanonicalSubjectNames(PDO $db):void`, and `migrateLearningContent(PDO $db):void`.
- Produces `learningCatalogScope(PDO $db,int $school,int $class,int $subject):array` in `includes/learning_catalog.php`, returning `local:Scope` and `shared:?Scope`. Match validated source identity, otherwise exact canonical class plus unambiguous code/type/canonical subject. Reject multiple shared matches rather than choosing the first. This identity-only helper depends on no actor policy, avoiding a Task 2/3 dependency cycle.
- Names normalize case/whitespace only for exact Nepali/English/Mathematics or their exact Compulsory-prefixed aliases; other subject names remain unchanged. Leave Optional Mathematics distinct.
- Schema version becomes 15. Questions add `created_by_user_id`, `visibility`, `archived_at`, `updated_at`, `catalog_chapter_id`, `catalog_topic_id`. Notes add these plus `school_id`, nullable `class_id`, `subject_id`, local `chapter_id`, local `topic_id`.
- `visibility` is `VARCHAR(10) NOT NULL DEFAULT 'private'`; added identity fields are INTEGER, `archived_at`/`updated_at` are nullable TIMESTAMP. Notes have `school_id INTEGER NOT NULL DEFAULT 0`; other new identity fields are nullable. On first migration only, existing questions become public in their original scopes, existing notes become school-zero public. Existing creator values remain NULL. Backfill note scope only on a unique canonical match. Add school/scope/visibility/archive and creator indexes.

- [x] **Step 1: Write failing tests** `legacy_upgrade_retains_ids_and_content`, `ambiguous_note_not_assigned`, `canonical_names_keep_optional_and_duplicate_ids`, `repeat_upgrade_preserves_new_privacy`.
  ```php
  erpEqual(subjectCanonicalName(' Compulsory English '),'English','Canonical label');
  erpEqual(subjectCanonicalName('Optional Mathematics'),'Optional Mathematics','Optional stays distinct');
  // Snapshot all existing question/note columns and IDs before migration.
  migrateLearningContent($db);
  erpEqual($db->query('SELECT created_by_user_id FROM questions WHERE id='.$legacyId)->fetchColumn(),null,'No guessed author');
  erpEqual($db->query('SELECT visibility FROM questions WHERE id='.$legacyId)->fetchColumn(),'public','Legacy school visibility');
  ```
- [x] **Step 2: Run** `C:\php\php.exe tests/learning_schema_test.php` and `C:\php\php.exe tests/subject_names_test.php`; confirm failure on missing migration/helper, not an unrelated fixture error.
- [x] **Step 3: Implement** the interfaces and version-15 wiring. Normalize only `subjects.name_en`; do not mutate CDC JSON/reference payloads or exams. Canonicalize names on seed/import/add/edit paths and duplicate validation; retain both IDs on legacy collisions and use codes/type to disambiguate. Call the new migration once before marking version 15. Test old schemas too, retaining SQLite pre-upgrade backups.
- [x] **Step 4: Run** new tests plus `C:\php\php.exe tests/cdc_2083_test.php`, `C:\php\php.exe tests/accounting_schema_test.php`, and `C:\ProgramData\anaconda3\python.exe tests/upgrade_copy_test.py --source 'D:\A WebSite\Nepal School Management System\database.sqlite'`; require retained records/money, integrity `ok`, unchanged source hash. Report MySQL staging separately if unavailable.
- [x] **Step 5: Commit** only Task 1 source/tests: `feat: add learning ownership schema and canonical subject names`.

### Task 2: Authoritative content access policy and readable queries

**Files:** Create `includes/learning_content.php`, `tests/learning_sharing_fixture.php`, `tests/learning_content_test.php`; modify `includes/teaching_scope.php` only where adding read/write selection separation.

**Interfaces:**
- Consumes Task 1 metadata and `learningCatalogScope(...)`, existing `teacherOwnsSubject(...)` and `studentLearningRecord(...)`.
- Produces `learningActor(PDO $db,int $userId,int $school=0,bool $readOnly=false):array`; validates active account/role and same-school context.
- Produces `learningCanView(PDO $db,array $actor,array $record,string $purpose='study'):bool`, `learningCanCreate(PDO $db,array $actor,int $class,int $subject):bool`, `learningCanEdit(PDO $db,array $actor,array $record):bool`, `learningCanPublish(PDO $db,array $actor,array $record):bool`.
- Produces `learningContentList(PDO $db,array $actor,string $kind,array $filters,string $purpose='study'):array` and `learningContentRecord(PDO $db,array $actor,string $kind,int $id,string $purpose='study',bool $includeArchived=false):array`.
- List filters are optional `class_id`, `subject_id`, `topic_id`, `catalog_topic_id` positive integers; `chapter` and `search` strings; `type` an existing question type; `source` one of `all`, `shared`, `school`, `mine`; and `archived` bool (manageable own/global records only). They never supply a trusted school or creator. All unreadable rows are excluded before counts and random selection.
- Produces `learningReadClasses(PDO $db,array $actor):array`, `learningReadSubjects(PDO $db,array $actor,int $class):array`; leave existing teaching-only selectors for creation. Records returned have `can_edit`, `can_publish`, `source_scope` computed by the service, never trusted from POST.

- [x] **Step 1: Write failing tests** for the entire approved access matrix, NULL legacy creators, other-school direct IDs, disabled creators, and assignment removal. Fixture creates school A teacher A, same-subject teacher B, unrelated teacher C, school admins A/B, students and super admin.
  ```php
  erpEqual(learningCanEdit($db,$teacherA,$ownedQuestion),true,'Creator edit');
  erpEqual(learningCanEdit($db,$teacherB,$ownedQuestion),false,'Co-teacher cannot edit');
  erpEqual(learningCanEdit($db,$schoolAdminA,$ownedQuestion),false,'Admin cannot overwrite creator');
  erpEqual(learningCanView($db,$studentA,$privateQuestion),false,'Private excluded from student');
  erpEqual(learningCanView($db,$studentB,$schoolPublicQuestion),false,'Public is not cross-school');
  ```
- [x] **Step 2: Run** `C:\php\php.exe tests/learning_content_test.php`; confirm a policy-related failure.
- [x] **Step 3: Implement** predicates and prepared queries. Read access uses canonical class/subject mappings, active/hidden rules, privacy and actor identity. Global private is super-admin-only. Local NULL-owner records are read-only. School edits require creator identity and permitted creation scope, including current teacher assignment; preview forbids writes. `includeArchived` reveals only records the caller could manage, not private archives of another creator. No client-controlled creator/school transfer.
- [x] **Step 4: Run** new policy tests and `C:\php\php.exe tests/subscription_teaching_test.php`, `C:\php\php.exe tests/packages_test.php`, `C:\php\php.exe tests/security_test.php`; require exact matrix outcomes and unchanged unrelated result/finance roles.
- [x] **Step 5: Commit** policy, fixture and tests: `feat: enforce creator-only learning content permissions`.

### Task 3: Read-only school curriculum with shared references

**Files:** Extend `includes/learning_catalog.php`; create `tests/learning_catalog_test.php`, `tests/learning_catalog_http_test.py`; modify `includes/curriculum_catalog.php`, `modules/learning/curriculum.php`, `includes/request_guard.php`, `includes/school_validation.php`, `includes/teaching_scope.php`.

**Interfaces:**
- Consumes Task 1 canonical naming and `learningCatalogScope(...)`, Task 2 actor/read scope, and existing `classNameKey()`.
- Produces `learningCatalogChapters(PDO $db,array $actor,int $class,int $subject):array` and `learningCatalogTopics(PDO $db,array $actor,int $class,int $subject,int $chapter):array`. Choice rows have `id`, `scope` (`shared`/`local`), names, parent identity and historical `alias_ids`.
- Produces `learningCatalogSelection(PDO $db,array $actor,int $class,int $subject,array $input,bool $allowCreate=false):array`, returning validated `chapter`, `topic_id`, `chapter_id`, `catalog_chapter_id`, `catalog_topic_id`, `topic`.
- Produces `learningCanManageCatalog(array $actor):bool`; super-admin role only and never read-only preview.

- [x] **Step 1: Write failing tests** for new super-admin topics readable in both schools, duplicate aliases, class 1/10 separation, optional/compulsory matching, foreign topic IDs, and school POST/import attempts to create/rename chapters. Verify `schoolRecord()` still rejects foreign result/accounting references.
  ```php
  erpEqual(learningCanManageCatalog($teacherA),false,'Teacher catalog is read-only');
  erpEqual(learningCanManageCatalog($schoolAdminA),false,'School admin catalog is read-only');
  erpReject(fn()=>learningCatalogSelection($db,$teacherA,$classA,$subjectA,['chapter'=>'Missing chapter'],false),'No implicit chapter creation');
  ```
- [x] **Step 2: Run** `C:\php\php.exe tests/learning_catalog_test.php` and `C:\ProgramData\anaconda3\python.exe tests/learning_catalog_http_test.py`; confirm missing resolver or incorrect school-write authorization causes failure.
- [x] **Step 3: Implement** shared/local choice resolution. Match source identity where valid, otherwise exact class plus unambiguous code/type/canonical subject. For shared selections write catalog-reference fields, never a global topic ID into the local `topic_id`. Guard every catalog mutation, remove school add/custom/edit controls, and retain super-admin additions. Global rename synchronizes linked question/note labels in one transaction. Generic school validation stays strict; only validated catalog-reference fields use the dedicated resolver.
- [x] **Step 4: Run** new tests, `C:\ProgramData\anaconda3\python.exe tests/curriculum_catalog_http_test.py`, `C:\ProgramData\anaconda3\python.exe tests/class_isolation_http_test.py`, `node tests/question_topics_test.js`; update prior school-catalog-write expectations to the approved deny rule, never remove coverage.
- [x] **Step 5: Commit** shared resolver/routes/tests: `feat: expose shared curriculum as read-only to schools`.

### Task 4: Question ownership, publishing, archive and import UI

**Files:** Create `includes/learning_content_service.php`, `tests/learning_questions_http_test.py`; modify `modules/learning/questions.php`, `modules/learning/ocr_scanner.php`, `includes/question_import.php`, `includes/request_guard.php`, `tests/question_import_http_test.py`, `tests/question_source_http_test.py`, `tests/question_editor_test.js`.

**Interfaces:**
- Consumes Tasks 1–3 policy/catalog interfaces and existing `questionImportRowContent(...)` sanitization.
- Produces `learningSaveQuestion(PDO $db,array $actor,array $input,?int $id=null):int`, `learningSetVisibility(PDO $db,array $actor,string $kind,int $id,string $visibility):void`, `learningSetArchived(PDO $db,array $actor,string $kind,int $id,bool $archived):void`.
- All writes validate current stored ownership inside the transaction; creator/school are authenticated fields. New school records are private; new global catalog records are public unless super admin explicitly selects private. Enum/ID arrays and forged scope are rejected.
- Question inputs keep existing `class_id`, `subject_id`, `chapter`, `topic_id`, content/type/marks/cognitive fields, with optional `catalog_chapter_id`, `catalog_topic_id`, `visibility`. Accept existing add/edit actions and new POST actions `set_visibility`, `archive`, `restore`, each using `question_id`; reject multiple dispatched actions. Archiving does not change visibility. Changing visibility while archived is rejected until the creator restores the record. Publish confirmations explain school-only scope.

- [x] **Step 1: Write failing HTTP tests** `teacher_can_manage_own_only`, `co_teacher_and_admin_cannot_overwrite`, `unknown_owner_is_read_only`, `publish_is_same_school_only`, `archive_restore_retains_id`, `mixed_actions_cannot_change_scope`, `source_import_missing_topic_creates_nothing`. POST a forged owner/global school ID and assert no row/count/audit-success changes.
  ```python
  app.login('co_teacher')
  before=app.snapshot('questions')
  app.request('/modules/learning/questions.php', owner_edit_payload, status=403)
  assert app.snapshot('questions')==before
  # Owner can publish; foreign school's list/search/count never contains its title.
  ```
- [x] **Step 2: Run** `C:\ProgramData\anaconda3\python.exe tests/learning_questions_http_test.py`; confirm the current controller incorrectly permits editing or lacks publishing.
- [x] **Step 3: Implement** services, audit logging and source/My content filters. Read other creators/shared content without edit controls; owner forms have Private/Public badges and Publish within this school. Replace deletion with reversible archive/restore. Remove or reject legacy make-universal transfers; do not rewrite school ID alone. Manual, CSV/XLSX and scanner saves all use the same service/catalog validation. Preserve HTML/LaTeX and existing batch transactions, row notices and filter redirects. Super-admin imports may create missing chapters; school imports may not.
- [x] **Step 4: Run** new HTTP tests, original import/source tests (including the actual renamed 80-MCQ workbook in a temporary DB), `node tests/question_editor_test.js`, `C:\php\php.exe tests/question_import_content_test.php`. All 80 rows still import under super admin; school missing-catalog rows fail clearly and do not add chapters/topics.
- [x] **Step 5: Commit** question/service/import/scanner changes and tests: `feat: add school question ownership and private publishing controls`.

### Task 5: Tenant-owned notes and unified Learning Hub

**Files:** Create `tests/learning_notes_http_test.py`; modify `includes/learning_content_service.php`, `modules/learning/index.php`, `includes/request_guard.php`, `includes/teaching_scope.php` and `tests/routine_learning_http_test.py`.

**Interfaces:**
- Consumes Tasks 2–4 policy, shared catalog, publishing/archive services.
- Produces `learningSaveNote(PDO $db,array $actor,array $input,?int $id=null):int` in the transactional service. Uses relational class/subject/local or shared catalog references plus preserved label fields; validates title/nonempty sanitized content.
- Note POSTs use `action_note` values `add`, `edit`, `set_visibility`, `archive`, `restore`; target `note_id`; relational `class_id`, `subject_id`, local/catalog chapter/topic fields; `title`, `content` and optional `visibility`. `class_level`/`subject_name` compatibility labels are derived from the validated scope, not trusted as permissions.

- [x] **Step 1: Write failing HTTP tests** for teacher/school-admin own-note creation, creator-only edits, same-school public visibility, co-teacher private view, student/foreign denial and a legacy ambiguous note left unassigned. Assert new super-admin chapter appears in Hub's selector, without the old static-list limitation.
  ```python
  app.login('teacher')
  app.request('/modules/learning/index.php', note_create_payload)
  note=app.db.execute('SELECT school_id,created_by_user_id,visibility FROM learning_notes ORDER BY id DESC LIMIT 1').fetchone()
  assert note==(1,app.fixture['ids']['teacher'],'private')
  ```
- [x] **Step 2: Run** `C:\ProgramData\anaconda3\python.exe tests/learning_notes_http_test.py`; confirm missing school-note permissions/fields cause failure.
- [x] **Step 3: Implement** note service and school authoring controls; route every note/question read through `learningContentList`, and replace static chapter-only lookup with catalog choices. Render both MCQ options and answers through safe-rich-HTML; support existing LaTeX delimiters consistently. Archived or private-ineligible content must not appear in title lists, counts or details. Students use current Learning Hub route, no new broad module access.
- [x] **Step 4: Run** new HTTP suite plus routine/learning HTTP, HTML-source HTTP and content-safety tests. Browser-check school creator form and student read-only notes, keeping the 20 pt Kokila baseline.
- [x] **Step 5: Commit** notes/Hub changes and tests: `feat: share school notes through the learning content policy`.

### Task 6: Practice and paper generation cannot bypass visibility

**Files:** Modify `includes/mcq_practice.php`, `modules/learning/practice_mcqs.php`, `modules/learning/paper_generator.php`, `modules/learning/question_grid.php`; create `tests/learning_consumers_http_test.py`; update `tests/mcq_practice_test.php`, `tests/mcq_practice_http_test.py`, and their fixture inserts to set intended public/private state explicitly.

**Interfaces:**
- Consumes Task 2 `learningContentList(...,'questions',filters,'practice'|'paper')` and readable record checks. Keep existing `practiceStart(...)`, `practiceValidateSession(...)`, `practicePublicState(...)` and `practiceTransition(...)` signatures.
- Produces `learningValidateQuestionIds(PDO $db,array $actor,array $ids,string $purpose):void`; verifies every snapshot ID is still readable, active and non-archived. Existing practice-validation call sites run this before state/feedback/review/answer/next output, including completed sessions.

- [x] **Step 1: Write failing tests** for private exclusion from student catalogs/search/count/random/chosen sessions, global shared availability in both generators, staff-only private paper selection, and stale session/review revocation.
  ```python
  # Start student practice with a public question, then make it private as owner.
  student_session=app.opener
  app.login('teacher')
  app.request('/modules/learning/questions.php',{'action':'set_visibility','question_id':question_id,'visibility':'private'})
  app.opener=student_session
  body=app.request('/modules/learning/practice_mcqs.php?action=state',status=422)
  assert private_question_text not in body
  # Response has a restart message, never the question text/options/explanation.
  ```
- [x] **Step 2: Run** `C:\ProgramData\anaconda3\python.exe tests/learning_consumers_http_test.py`; confirm the existing snapshot/query bypass reproduces.
- [x] **Step 3: Implement** centralized visible pools and validation. On revoked practice IDs clear the session and return a restart error, with no stale private payload. Keep answer-key secrecy before answering, random shuffle, single-answer/idempotent transitions and official-marks separation. Both generators use the same scoped pool and preserved requested type/count rules; students remain denied generator routes, and generated-paper outputs revalidate supplied IDs where present.
- [x] **Step 4: Run** consumer tests, PHP/HTTP/client MCQ tests and `node tests/learning_readability_test.js`. Browser-check owner unpublishing followed by student resume/review denial, larger mobile choices, mute, Back and reduced motion.
- [x] **Step 5: Commit** consumer changes/tests: `fix: enforce learning privacy in practice sessions and paper generation`.

### Task 7: Integration, source review and safe handoff

**Files:** Modify `README.md` and affected regression fixture expectations; create `tests/learning_sharing_http_test.py` as the end-to-end cross-role acceptance suite; modify `tests/upgrade_copy_test.py` to snapshot questions/notes/catalog stable columns; preserve `tests/learning_readability_test.js` and all pending readability source edits.

**Interfaces:** Consumes the completed Tasks 1–6 interfaces. Produces a documented role/visibility workflow and verified retained-data evidence, not a deployment.

- [x] **Step 1: Write acceptance assertions** covering both schools, public/private transitions, archived counts, creator/scope forgery, mixed actions, student enrollment/electives, global/local collisions, CSRF/subscription/package/preview restrictions, legacy NULL owners and all readers. Assert restored records keep IDs and every non-learning marks/money snapshot is unchanged.
- [x] **Step 2: Run** `C:\ProgramData\anaconda3\python.exe tests/learning_sharing_http_test.py`; record any uncovered failures rather than weakening permissions to pass old expectations.
- [x] **Step 3: Fix only confirmed integration gaps**, update README with creator-only editing and school-public meaning, and audit all PHP readers found by `rg -n 'FROM questions|JOIN questions|FROM learning_notes|INSERT INTO questions|INSERT INTO learning_notes' includes config modules --glob '*.php'`. Every runtime path must call the policy or be a justified protected migration/audit path.
- [x] **Step 4: Run full verification**: PHP lint for tracked PHP; `node --check` on changed JS; new suites plus security, workflow, grading, CDC, accounting, packages, subscription-teaching, class-isolation, curriculum, import, source-import, practice, routines, teacher-results and print regression tests. Run consistent-copy upgrade with retained content IDs/creator NULLs/money and SQLite backup/integrity checks; compare original database hash. Report MySQL tests as unrun if no staging database exists.
- [x] **Step 5: Browser verification** using only disposable accounts: creator vs co-teacher/admin edit controls, private vs published student views, shared topic selection, bilingual HTML maths, notes, practice, and responsive layout. Save screenshot proof. Keep user-created tabs/session data untouched.
- [x] **Step 6: Whole-branch independent review** under the requesting-code-review skill using the current available reviewer tools/model rules; fix findings and rerun their tests. Verify review actually completed before claiming it. Native implementation does not require fresh implementers per task.
- [x] **Step 7: Commit** verified source/tests/docs with explicit file paths, including the preserved readability work once its tests pass. Scan staged files for tokens and exclude databases/uploads/private config/generated artifacts. Hand off a concise completion report with current commit, test evidence and remaining release/MySQL limitations. Do not push/merge/deploy without a separate request.

## Self-Review Coverage and Handoff

Spec sections 1–4 map to Tasks 2–6; schema/migration section 5 maps to Task 1 and upgrade acceptance in Task 7; shared catalog and label section 6 maps to Tasks 1 and 3; backend/UI sections 7–8 map to Tasks 2–6; all section-9 tests and section-10 delivery limits map to Task 7. Each Review Focus condition has a named task/test above. The native execution approach and the user's pending readability edits are preserved.

This is the completed implementation checklist. Execution used failing tests first, fresh verification and narrow local commits. The handoff report records the whole-branch review, its fix pass, all process rulings, the one deferred performance finding and outstanding staging/release limits.
