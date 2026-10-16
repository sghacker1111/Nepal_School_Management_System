# Question Corrections and Mixed Exam Papers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add normal-weight question reading, assignment-restricted teacher exam access, shared-content correction requests with admin replies, and mixed MCQ/written exam papers.

**Architecture:** Extend the existing shared learning policies without changing normal-bank browsing. Add one additive correction-request table and transactional service used by thin staff/admin routes. Normalize mixed chapter allocations into the existing exact solver and share one ordered exam paper/answer-key renderer.

**Tech Stack:** PHP 8.1+/PDO, SQLite/MySQL-compatible SQL, existing Bootstrap/TinyMCE/MathJax, vanilla JavaScript, PHP service tests, Node UI tests and Python real-HTTP fixtures. No new product dependencies.

**Spec:** `docs/superpowers/specs/2026-10-16-question-corrections-mixed-papers-design.md`, approved 2026-10-16.

**Status:** Approved for Native implementation on 2026-10-16. All six implementation tasks verified: 311/311 commands, disposable browser acceptance and independent review passed. The user separately authorized push, merge and data-preserving deployment.

## Global Constraints

- Normal Question Bank browsing remains unchanged. The new teacher read restriction applies specifically to the exam bank, including exam MCQs.
- Reporting an error never grants edit, delete, publish or release permission.
- No migration changes existing visibility settings. School administrators do not gain ownership of a teacher's questions.
- Require two plain-text fields: **Mistake found** and **Suggested correction**, each 1–4,000 characters.
- Replies are in-app only; no email, SMS or external messaging is introduced.
- No GET mutates requests or read state. Keep CSRF, subscription gates, preview read-only rules and audit records.
- Count 0 skips a row. Topic selection is not introduced into exam generation.
- Subject-specific CDC section rules are not inferred or certified here.
- This approval does not authorize push/deployment; request release separately after implementation verification.

## Review Focus

1. Simultaneous duplicate submissions must return one Pending request, not a 500 or two records (Task 3).
2. A reporter loses a teaching assignment or changes schools: their reply must not expose a now-forbidden question snapshot or another school's report (Tasks 2–4).
3. Unicode-only reports, pasted HTML and JSON option key reordering must not break length checks, render active content or falsely imply a correction (Tasks 3–4).
4. Old advanced grids may have mixed rows, repeated chapters and custom names: keep exact quotas and preserve IDs while ordering printed groups correctly (Task 5).
5. Imported inline bold CSS and MathJax vector notation coexist: prose becomes normal-weight without flattening maths or editing saved HTML (Task 1).

## Workspace, file boundaries and conventions

Execute in the existing linked worktree `C:\Users\SGHACKER\.codex\worktrees\accounting-completion\Nepal School Management System`, branch `codex/question-corrections-mixed-papers`, based on `c7ca3d6` plus design commits. At execution start use the worktree skill's read-only Git-directory checks; reuse this worktree instead of creating another. Do not touch the preserved dirty checkout at `D:\A WebSite\Nepal School Management System` or other worktrees.

Use `tests/test_support.php`, `tests/learning_sharing_fixture.php`, `tests/http_support.py` and `tests/learning_sharing_support.py`. Every mutation runs against an OS-temp fixture. Original database hash must remain `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.

Commands below run from the linked worktree: `php` means `C:\php\php.exe`; `python` means `C:\ProgramData\anaconda3\python.exe`. Set `$env:ERP_TEST_PHP='C:\php\php.exe'` for HTTP/copy-upgrade tests. Stage explicit task paths, not the whole workspace. Do not commit QA files, databases, uploads, credentials or private configuration.

New units:

- `includes/exam_content_policy.php`: exam-purpose teacher scope and selectors; depends on existing learning/catalog/teaching helpers.
- `includes/question_correction_schema.php`: additive schema-18 table/index creation only.
- `includes/question_correction_service.php`: correction policy, canonical snapshots/fingerprints, transactional state changes and authorized queries.
- `includes/question_correction_page.php`: shared request-list/detail/form controller; `includes/question_correction_views.php`: its escaped/sanitized presentation.
- `modules/learning/question_corrections.php` and `modules/super_admin/question_corrections.php`: role-specific entry points.
- `includes/exam_mixed_editor.php`, `assets/js/exam-mixed-editor.js`: mixed chapter/type allocation UI; `includes/exam_mixed_paper.php`: server normalization.
- `includes/exam_paper_groups.php`: pure ordered print model; `includes/exam_paper_output.php`: shared paper/key rendering extracted from the existing exam generator.

Types: `Actor = array{id:int,role:string,school_id:int,read_only:bool}`. `Scope = array{school_id:int,class_id:int,subject_id:int}`. Bank values `learning|exam`; request states `pending|corrected|rejected`; heading styles `Group|Section`. PHP service failures use `InvalidArgumentException`; HTTP guards return 403/404 for forbidden reads, 419 for CSRF, 422 for invalid input, and 409 for stale review state. Do not expose PDO errors.

## Task 1: Normal-weight bilingual reading

**Files:** Modify `assets/css/exam-question-bank.css`, `assets/css/exam-question-language.css`, and the question wrapper in `modules/learning/index.php`. Test `tests/exam_question_readability_test.js`; create `tests/question_normal_weight_test.js`.

**Interfaces:** Existing `.exam-bank-question`, `.exam-bank-rich`, `.exam-language-en`, `.exam-language-ne` reading surfaces consume saved rich content without mutation. No service or database interface changes.

- [x] **1. Write failing assertions:** update the old assertion that requires Nepali weight 700 to require normal prose weight 400. Add named cases `normalEnglishAndNepali`, `importedBoldRemainsStored`, `mathWeightIsNotOverridden`, `printAndHubParity`, and `practiceStylesUnchanged`. Assert the 22:16 size ratio and heading/option-label emphasis remain intact.

```js
assert.match(bankCss, /\.exam-bank-question\s*\{[^}]*font-weight:400/s);
assert.match(languageCss, /font-weight:400/);
assert.match(bankCss, /--exam-nepali-scale:1\.375/);
```

- [x] **2. Run red tests:** `node tests/question_normal_weight_test.js` and `node tests/exam_question_readability_test.js`; expect failure on the current 650/700 forced weights.
- [x] **3. Implement scoped reading CSS:** weight 400 on prose and imported prose descendants, including `b`, `strong` and inline weights; exclude `mjx-container` and its descendants. Remove the Learning Hub's question-wide `fw-bold` wrapper. Do not alter editor HTML, practice styles or mathematical source.
- [x] **4. Verify green:** run both tests plus `node tests/learning_readability_test.js` and `node tests/shared_question_editor_test.js`. Browser-computed weights, print preview and unchanged stored HTML are additionally verified in Task 6.
- [x] **5. Commit task paths:** `fix: render question prose at normal font weight`.

## Task 2: Enforce teacher scope for every exam read

**Files:** Create `includes/exam_content_policy.php`, `tests/exam_teacher_scope_test.php`, `tests/exam_teacher_scope_http_test.py`. Modify `includes/learning_content.php`, `includes/exam_question_page.php`, `includes/exam_paper_page.php`, and scope-dependent fixtures/assertions in existing `tests/exam_*_http_test.py` where the approved restriction changes expected results.

**Interfaces:** `examContentScopeAllowed(PDO $db,array $actor,array $scope):bool`; `examReadClasses(PDO $db,array $actor):array`; `examReadSubjects(PDO $db,array $actor,int $class):array`. Nonteachers delegate to existing readable scopes. Teachers require `teacherOwnsSubject()` for the exact local class/subject; shared records are resolved by `learningRecordScope()` first.

- [x] **1. Write failing service/HTTP cases:** public school and shared exam questions outside an assignment are excluded from lists and counts, cannot open by record ID or print filter, and cannot enter generation. Assigned/co-teachers can read. A class-teacher assignment alone does not grant access. Revocation denies the next request. The same normal-bank records remain readable under existing policy; school admins and released enrolled students keep access.

```php
erpEqual(learningCanView($db,$unrelatedTeacher,$publicExam,'exam_bank'),false,'Public exam is assignment-scoped');
erpEqual(learningCanView($db,$assignedTeacher,$publicExam,'exam_bank'),true,'Assigned teacher can read');
erpEqual(learningCanView($db,$unrelatedTeacher,$publicLearning,'bank'),true,'Normal browsing unchanged');
```

- [x] **2. Run red tests:** `php tests/exam_teacher_scope_test.php`; `python tests/exam_teacher_scope_http_test.py`. Expect current public exam records to leak to an unrelated teacher.
- [x] **3. Implement policy at both read boundaries:** use it in `learningCanView()` for `exam_*` purposes and inside `learningContentList()` before authorized counting/caching. Never reuse an unqualified public-view cache entry across differing scope decisions. Exam pages use dedicated selectors and reject unassigned scope before loading catalog/action/template data; test crafted `action=catalog`, `import_catalog` and template requests. Normal learning selectors stay broad. Existing exam grid/generator checks remain defense in depth.
- [x] **4. Verify green:** new tests plus `python tests/exam_questions_http_test.py`, `python tests/exam_question_pages_http_test.py`, `python tests/learning_consumers_http_test.py`. Preserve forged-edit/delete denial for shared items and creator controls.
- [x] **5. Commit task paths:** `fix: restrict teacher exam content to teaching assignments`.

## Task 3: Durable correction lifecycle and migration

**Files:** Create `includes/question_correction_schema.php`, `includes/question_correction_service.php`, `tests/question_correction_schema_test.php`, `tests/question_correction_service_test.php`, `tests/question_correction_concurrency_test.py`. Modify `includes/schema.php` version 17 → 18, `includes/accounting_schema.php` extension migration dispatch, and `tests/upgrade_copy_test.py` snapshot coverage for an existing correction table.

**Interfaces:**

- `migrateQuestionCorrections(PDO $db):void` — idempotent schema only; dispatch before the current version-8+ early return.
- `questionCorrectionSnapshot(array $question):array` and `questionCorrectionFingerprint(array $question):string` — canonical content snapshot and SHA256.
- `questionCorrectionCanSubmit(PDO $db,array $actor,array $question):bool` — active readable shared question, school-admin/teacher role, correct subscription, not preview; creation permission is not required.
- `questionCorrectionSubmit(PDO $db,array $actor,int $questionId,string $mistake,string $suggestion):int`.
- `questionCorrectionList(PDO $db,array $actor,array $filters,array &$pagination):array` and `questionCorrectionDetail(PDO $db,array $actor,int $id):array`.
- `questionCorrectionRespond(PDO $db,array $actor,int $id,int $revision,string $expectedQuestionHash,string $decision,string $reply):void`.
- `questionCorrectionMarkRead(PDO $db,array $actor,int $id):void`; `questionCorrectionCounts(PDO $db,array $actor):array` returning `{pending:int,unread:int}`.
- `QuestionCorrectionConflict extends InvalidArgumentException` identifies stale review state for the HTTP 409 response.

- [x] **1. Write schema/lifecycle tests first:** assert table creation, version 18, pre-upgrade backup, idempotency, nullable unique open key and no existing-row changes. Assert submission returns the same ID for the same Pending reporter/question but separate IDs for different reporters. Empty/4,001-character fields fail; 4,000-character Nepali succeeds. Deny own/local/private-unreadable/archived targets, forged IDs, students, inactive accounts and preview writes. View-only eligible packages can report.
- [x] **2. Add decision/privacy tests:** reject requires reply and changes no question fields; Corrected requires actual content change, active target, matching reviewed hash and Pending revision. Terminal decisions cannot be rewritten. Other pending reports remain pending. Read is idempotent and requester-only. Losing subject/package access hides current/snapshot bodies while retaining the original active-school reporter's own text/status/reply. Changing schools denies old history. Canonical option key ordering/decimal serialization/timestamp-only changes do not count as a correction. HTML in report/reply remains data, never executable.

```php
$id=questionCorrectionSubmit($db,$teacher,$sharedQuestionId,'Wrong correct option','Use option B');
erpEqual(questionCorrectionSubmit($db,$teacher,$sharedQuestionId,'Wrong key','Use B'),$id,'Pending duplicate reuses ID');
erpEqual(questionCorrectionDetail($db,$teacher,$id)['status'],'pending','Request starts Pending');
erpReject(fn()=>questionCorrectionSubmit($db,$teacher,$sharedQuestionId,str_repeat('क',4001),'Use B'),'Unicode limit enforced');
```

- [x] **3. Run red:** `php tests/question_correction_schema_test.php`; `php tests/question_correction_service_test.php`; `python tests/question_correction_concurrency_test.py`. Expect missing service/table, not unrelated fixture failures.
- [x] **4. Implement schema/service:** table fields `id,question_id,bank_kind,question_type,requester_user_id,requester_school_id,mistake,suggested_correction,snapshot_json,snapshot_hash,status,response_text,responded_by,responded_at,response_read_at,revision,created_at,updated_at,open_key`. Use nullable unique `open_key VARCHAR(64)` derived from reporter-school/user/question identity; clear on resolution. Use non-cascading question/user/school references, compatible primary keys and indexed admin/requester queues. Only update version after successful migration. Never backfill or change question visibility.
- [x] **5. Implement transactions/query boundaries:** revalidate current actor; derive bank/scope/type from the question, not POST. Fingerprint content/scope/type/questions/options/key/answers/marks/time/difficulty/cognitive data in fixed canonical order; exclude ownership, visibility, release, timestamps and archive metadata. Serialize correction writes through the existing `learningWrite()` app-meta row lock used by question edits, acquiring it before question/request reads. Submit atomically with unique-key replay recovery; only the duplicate-key condition may return an existing Pending ID. Respond uses revision compare-and-set and a current question read consistent with that transaction. Throw `QuestionCorrectionConflict` on stale review, handled as 409 in Task 4. List/detail never return hidden snapshot/current HTML. Audit state changes, not private report text.
- [x] **6. Verify green:** all three new tests; `php tests/workflow_test.php`; `python tests/upgrade_copy_test.py --source 'D:\A WebSite\Nepal School Management System\database.sqlite'`. For concurrent submissions use two processes against one OS-temp DB, not the original database. Assert all existing stable rows and original hash remain identical.
- [x] **7. Commit task paths:** `feat: add audited question correction request lifecycle`.

## Task 4: Correction forms, admin inbox and requester replies

**Files:** Create `modules/learning/question_corrections.php`, `modules/super_admin/question_corrections.php`, `includes/question_correction_page.php`, `includes/question_correction_views.php`, `tests/question_corrections_http_test.py`, `tests/question_corrections_ui_test.js`. Modify `includes/question_bank_actions.php`, `includes/header.php`, `includes/request_guard.php`, `includes/school_validation.php`, `includes/teaching_scope.php` only at correction-route handling points.

**Interfaces:** Staff wrapper calls shared page with `$correctionAdmin=false`; super-admin wrapper sets true. Service interfaces are Task 3. GET `view=list|request|detail`, `question_id` only for request view, `request_id` only for detail; POST `action=submit|respond|mark_read`. `submit` accepts question ID/mistake/suggestion; `respond` accepts request ID/revision/expected question hash/decision/reply. The controller does not accept actor/school/bank overrides.

- [x] **1. Write red HTTP/UI assertions:** both bank pages show Request correction only for eligible shared items; own Edit/Delete/Publish controls remain unchanged. Staff can submit and inspect only their reports. Super admin sees two tabs, type/status/class/subject/school filters and page sizes 10/20/30/50/100. Verify original/current comparison, edit link, required response, Pending badge and unread requester reply. GET/detail does not mark read; a labelled POST action does. Wrong-owner IDs, CSRF, mixed actions, posted identity overrides, student/finance access and preview writes are denied. Stale reviews return 409; invalid input 422. Tests inspect rendered text and the database, not merely a success toast.

```python
app.login('teacher')
app.request('/modules/learning/question_corrections.php', dict(action='submit', question_id=shared_id, mistake='Wrong key', suggestion='Use B'), csrf=False, status=419)
assert app.snapshot('question_correction_requests') == before_requests
```

- [x] **2. Run red:** `python tests/question_corrections_http_test.py`; `node tests/question_corrections_ui_test.js`; expect absent routes/controls.
- [x] **3. Implement thin routes and guard policy:** staff route requires active `teacher|school_admin` and a real school. Keep original-school history available independently of current bank package; submission separately validates current bank viewing permission. Add a narrow correction-route exception to module access mapping; do not bypass subscription, CSRF, role or generic question-creation checks. Shared global question IDs are validated by correction policy rather than local-only generic foreign-reference checks. Super-admin response route requires role and school context 0. Validate only one action and bounded scalar filters.
- [x] **4. Implement reusable views/navigation:** plain-text bounded report/reply fields; escaped output; sanitized rich snapshots; bilingual question rendering. Add **Correction Questions** to System navigation, **My correction requests** for eligible school roles, authorized Pending/unread counts and bank action links. Use POST/redirect/GET, existing success/error notifications, safe return URLs and existing editor links; no automatic proposed-text insertion. Use labelled forms and responsive table/detail layout without private data in tooltips/badges.
- [x] **5. Verify green:** new tests plus `python tests/review_regressions_http_test.py`, `python tests/packages_http_test.py`, `python tests/learning_sharing_http_test.py`, `python tests/exam_questions_http_test.py`; confirm view-only reporters still cannot add/edit questions.
- [x] **6. Commit task paths:** `feat: add question correction inbox and admin responses`.

## Task 5: Mixed allocations and ordered exam printing

**Files:** Create `includes/exam_mixed_paper.php`, `includes/exam_mixed_editor.php`, `assets/js/exam-mixed-editor.js`, `includes/exam_paper_groups.php`, `includes/exam_paper_output.php`, `tests/exam_mixed_paper_test.php`, `tests/exam_mixed_paper_http_test.py`, `tests/exam_mixed_editor_test.js`. Modify `includes/exam_paper_service.php`, `includes/exam_chapter_editor.php`, `modules/learning/exam_paper_generator.php`, and affected exam print assertions in existing HTTP tests.

**Interfaces:**

- `examMixedGridInput(PDO $db,array $actor,int $class,int $subject,array $input):array` consumes `{duration_minutes,rows:[{chapter_id,type,count,total_marks}]}` and returns the existing raw exact-grid shape.
- Existing `examChapterGridInput()` dispatches `generation_kind=mixed` to the normalizer after decoding `mixed_config_json` with `examGridInputJson()`; written/mcq payloads retain compatibility.
- `examPaperGroups(array $paper,string $headingStyle='Group'):array` returns ordered groups `{letter,label,type,marks_units,items:[{question,main_no,sub_no}]}`. MCQs share main number 1 and sequential subnumbers; written main numbers continue after it, or start at 1 without MCQs.
- `includes/exam_paper_output.php` consumes `$paper,$paperGroups,$questionLanguage,$school,$title,$className,$subjectName,$selectedExam`; both paper and key iterate the same groups/items. Existing language controls and print target IDs remain unchanged.

- [x] **1. Write failing generation/print cases:** combine chapter-A MCQ 1 mark, chapter-A Short 2.25 and chapter-B Long 3.50; assert three unique exam IDs and exact 675 units. Reject all-zero, only-MCQ or only-written Mixed inputs, unknown types, invalid chapters even in zero rows, fractional counts, >2-decimal marks, oversized JSON, >50 rows, >200 questions, >1,000 marks, timing failures and shortage. Ignore valid zero-count allocations. Assert no normal-bank fallback or stored-mark changes. Old advanced rows with repeated chapters retain their exact quotas.
- [x] **2. Add grouping assertions:** scrambled solver rows print MCQs first in Group A, then Very Short/Short/Long/Reasoning/Difference Between, omitting empty types. Section style changes headings only. Assert actual summed marks, letter/number/answer-key parity, original subparts and identical question IDs after language switching; no-MCQ output starts written Group A/main 1. Advanced row names/quotas remain in the allocation audit.

```php
erpEqual($paper['total_units'],675,'Mixed paper preserves 1 + 2.25 + 3.50');
erpEqual(count(array_unique(array_column($paper['questions'],'id'))),3,'No duplicate IDs');
erpEqual(array_column(examPaperGroups($paper),'type'),['MCQ','Short','Long'],'Type groups, not solver row order');
erpEqual(examPaperGroups($paper,'Section')[0]['label'],'Section A — Multiple Choice Questions','Heading style is explicit');
```

- [x] **3. Run red:** `php tests/exam_mixed_paper_test.php`; `python tests/exam_mixed_paper_http_test.py`; `node tests/exam_mixed_editor_test.js`; expect current mode validator to reject mixed.
- [x] **4. Implement server normalization and print model:** validate at most 50 rows, use existing decimal/count/catalog helpers, require both active kinds and normalize each selected chapter/type row into `EXAM_TYPES`. Reuse `examGeneratePaper()` without a new solver. Group a returned paper by actual question type, preserve each selected ID once, sum integer mark units and attach shared numbering. Do not change the saved advanced grid configuration during preview.
- [x] **5. Implement UI/output integration:** add the third Generate option and Group/Section selector, default Group. Move the common kind selector outside the single-kind editor; when mixed is selected, render the dedicated row editor with add/remove controls and default MCQ/Short rows at count 0. Keep duration/title/language/heading style and submitted rows after errors. Show live integer-unit totals; serialize `mixed_config_json` only in Mixed mode. Render the extracted common output for simple and advanced papers, preserve print/key controls and add row quota/name details to Allocation audit.
- [x] **6. Verify green:** new tests plus `php tests/exam_chapter_paper_test.php`, `php tests/exam_paper_test.php`, `python tests/exam_paper_http_test.py`, `python tests/exam_chapter_paper_http_test.py`, `python tests/exam_question_language_http_test.py`, `node tests/exam_chapter_editor_test.js`, `node tests/exam_question_language_ui_test.js`.
- [x] **7. Commit task paths:** `feat: generate mixed chapter papers with ordered exam groups`.

## Task 6: End-to-end verification, preservation and handoff

**Files:** Create `tests/question_corrections_browser_fixture.py` and `docs/question-corrections-mixed-papers-handoff-2026-10-16.md`; modify `README.md`, approved spec status and this plan's checkboxes as work is verified. Reuse test fixtures from Tasks 2–5; QA screenshots/logs go in ignored `.superpowers/sdd/2026-10-16-question-corrections/`.

**Interfaces:** Browser fixture extends `ERPHttpFixture` with synthetic admin/two-school teacher/shared questions and a deliberate stop marker, and asserts protected school/accounting/question snapshots at shutdown. Protect baseline question IDs outside the explicitly allowlisted synthetic records edited by the correction acceptance test. It never opens the original file for writing.

- [x] **1. Write an acceptance checklist tied to the spec:** normal font/TeX; shared read-only and own controls; forbidden exam subject read/print; correction submit/duplicate; admin correct/reject with required reply; requester unread/read; revoked permission hides snapshot; mixed bilingual paper/key exact group/order/count/marks; mobile/keyboard controls.
- [x] **2. Run the full suite:** lint every tracked PHP file; run every `tests/*_test.php` except standalone `upgrade_test.php`, every `tests/*_test.js`, every `tests/*_http_test.py`, `tests/http_test.py`, `tests/deployment_test.py` and consistent-copy `tests/upgrade_copy_test.py --source 'D:\A WebSite\Nepal School Management System\database.sqlite'`. Capture individual exit codes and fail the run on any nonzero; expected all pass with actual totals reported, not guessed. Expected-denial stderr is not a failing exit by itself.
- [x] **3. Browser QA using the computer-use skill:** start the disposable fixture and complete the acceptance checklist on desktop and narrow view. Verify computed prose weight 400 for English/Nepali/imported strong, MathJax vector rendering, and print-ready language/key output without editing original records. Save proof screenshots; stop the fixture, reset temporary viewport and close agent-created tabs. Do not claim physical printing or authenticated production coverage.
- [x] **4. Final branch review:** follow the execution method the user selects. Obtain a whole-branch independent review using an available model only when authorized by the selected workflow; no model override without a user/skill requirement. Address findings with red/green regressions, then rerun affected/full verification as risk requires.
- [x] **5. Verify preservation and document:** `git diff --check`; original database SHA256 matches the value above; no database/uploads/private config/QA files in tracked changes. Document actual test totals, migration 18/backup behavior, creator/teacher policies, correction statuses, print ordering, browser evidence and MySQL/production limits. Commit only the named docs/fixture changes as `docs: record correction and mixed-paper verification`.
- [x] **6. Hand off locally:** report verified completed behavior and any real limitations. Do not push, merge or deploy without the separate release request.

## Plan self-review and handoff

Coverage: spec 1 → Task 1; spec 2 → Task 2 plus owner regressions in Task 4; spec 3 → Tasks 3–4; spec 4 → Task 5; spec 5 → Task 6. Each Review Focus item has an explicit owning test. New service signatures, bank/status/heading enums and route fields match across tasks. No unrelated finance, curriculum, practice or student-release redesign is included.

Recommended execution: **Native**, sequentially in this session, because the shared authorization and correction-service interfaces make these tasks dependent. This reuses the current context; independent review happens once at the branch gate. The user must review this plan and select/confirm the execution approach before product changes begin.
