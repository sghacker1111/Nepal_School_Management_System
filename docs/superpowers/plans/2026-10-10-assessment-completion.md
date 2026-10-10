# Assessment Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make elective marks entry and customized IN allocations reliable without changing CDC grading bands or losing entered scores.

**Architecture:** Keep the existing marks and exam-mapping services. Move IN-save validation out of the page into the assessment service, compare database-backed revisions, and allow only exam-scoped edits for exam officers. Add disposable PHP/HTTP regression fixtures reusable by the package and accounting plans.

**Tech Stack:** Existing PHP 8.1+, PDO SQLite/MySQL, Bootstrap, browser JavaScript and Python HTTP tests; no new application framework.

**Spec:** `docs/superpowers/specs/2026-10-10-school-accounting-design.md`, assessment behavior and acceptance items 1–2.

## Global Constraints

- "keep PHP and the current SQLite/MySQL database support"
- "reuse school, student, class, section, academic-year, exam and subject IDs"
- "preserve educational and financial history"
- "enforce school isolation"
- "do not create fictitious financial transactions in the real database"
- "Class changes affect future mappings."
- "Component maxima must sum exactly to IN full marks."
- "Published or locked exams retain their existing protection and reopen workflow"

## Review Focus

- A failed second student's marks must roll back the first student's elective selection as well as all marks (Task A2).
- Imported marks can exist before an elective slot is filled; show saved values without auto-enrolling unrelated subjects (Task A2).
- NaN/infinite inputs and decimal component maxima must not bypass sum/range validation (Task A1).
- Two administrators editing the same empty-mark mapping must not overwrite a newer configuration (Task A1).
- Grade 11/12's third elective slot and lower grades' two-slot limit must be respected (Task A2).

## File map and shared test interfaces

New `tests/test_support.php` owns disposable fixture setup and assertions only. `erpTestFixture(): array` returns `['db'=>PDO,'fixture'=>array,'directory'=>string]` using `tests/http_fixture.php` with a new OS-temp database; add synthetic cashier/auditor users in `fixture['ids']` and `fixture['otherStudent']` / `fixture['otherUser']` for tenant tests there, never in the original database. Export `erpCheck(bool $ok,string $label): void`, `erpEqual(mixed $actual,mixed $expected,string $label): void`, and `erpReject(callable $action,string $label): void` (accept only `InvalidArgumentException`). Cleanup validates an exact generated temp path before removal.

New `tests/http_support.py` owns `ERPHttpFixture` as a context manager launching a disposable PHP server. Provide `.fixture`, `.db`, `.login(username)`, `.request(path,data=None,status=200,csrf=True)` returning text, `.request_bytes(...)` returning response headers/body, and `.snapshot(table)` returning ordered rows. Reject PHP warnings/fatal output and terminate the child server on exit. Preserve the existing suites unchanged.

## Task A1: Safe IN configuration saves and scoped permissions

**Files:** Create `tests/test_support.php`, `tests/http_support.py`, `tests/assessment_test.php`, `tests/assessment_http_test.py`; modify `includes/assessment_service.php`, `includes/exam_mapping_service.php`, `includes/request_guard.php`, `modules/subjects/internal.php`.

**Interfaces:** Consume `schoolRecord`, `examMappingRevision(array): string`, `saveExamMapping(PDO,int,int,array,int,int,array): int`. Produce `assessmentRevision(array $row,bool $examScope): string` and `saveInternalAssessment(PDO $db,int $school,int $actor,string $role,int $mappingId,?int $examId,array $input): void`. For exam scope keep the raw `examMappingRevision` contract; class scope hashes only persisted configuration fields in canonical order. Input contains `revision`, `total`, `pass`, `components`, `confirm_assessment_change`.

- [ ] Write `test_class_defaults_revision_round_trip`, `test_exam_officer_exam_only`, `test_stale_empty_mapping`, and `test_component_guards`; assert a raw class row and its display projection have equal class revision tokens, exam-officer exam saves return 200 but default saves return 403, a stale revision rejects without writes, and `assessmentComponentsInput([],NAN)` rejects. Include exact 25-mark split `3+16+6`, a wrong `3+16+5`, duplicate keys and 121-character labels. Rename the `previous_exam` label and change its maximum to 8 within a `3+14+8` profile; assert earlier-exam contribution conversion uses that stored maximum while retaining the stable key.
- [ ] Run `php tests/assessment_test.php` and `python tests/assessment_http_test.py`; confirm the unchanged-default and exam-officer failures reproduced in the audit before changing product code.
- [ ] Implement the two service functions, database-check the actor/scope, keep class saves atomic under the row revision guard, and delegate exam edits to `saveExamMapping`. Keep raw configuration separate from display aliases. Require confirmation; reject used-key removal, scores over new maxima, missing IN, thematic continuous assessments, locked/published exams and stale revisions even when no marks exist. Narrow the subjects-module exception to `internal.php`; the service still rejects exam-officer class-default mutations.
- [ ] Run those two suites plus `php tests/exam_workflows_test.php` and `php tests/cdc_2083_test.php`; expect all assertions to pass and existing marks/CDC snapshots to remain unchanged except an explicitly tested grade recalculation.
- [ ] Stage only this task's files and commit `fix: make internal assessment edits revision-safe`.

## Task A2: Complete elective enrollment and usable marks inputs

**Files:** Modify `includes/assessment_service.php`, `includes/marks_service.php`, `modules/results/marks_entry.php`, `modules/classes/subjects.php`, `modules/subjects/edit.php`, `modules/exams/view.php`; extend `tests/assessment_test.php`, `tests/assessment_http_test.py`.

**Interfaces:** Consume A1's helpers and the existing `enrollOptionalForMarks(PDO,int,array &$student,array $scheme,array $input,array $class,int $user): void` and `saveExamMarks(PDO,int,int,string,array): void`. Preserve their signatures and current `optional_enrollment[student][subject]` / `confirm_optional_enrollment` POST contract.

- [ ] Write tests asserting that a selected elective has enabled TH/IN inputs with saved values, an offered unselected elective has a clearly labeled selection control, and checking/confirming it saves both enrollment and marks. Assert that unconfirmed/hidden/foreign/full-slot choices and an invalid second-student mark leave both students' enrollment and marks snapshots unchanged. For Class 11/12 fill slots I/II and assert a valid third choice fills III; reject that third-slot action in Class 9.
- [ ] Run the assessment suites; distinguish already-working prototype behavior from missing coverage. Do not break passing behavior to manufacture a failing test; require a failing reproduction before modifying any remaining defect.
- [ ] Complete only failing paths. Retain selected elective values, respect teacher subject/section scope, keep enrollment inside the marks transaction, show component labels/maxima from the saved exam profile, and ensure IN edit links lead to the correct class/exam scope. Do not enroll all students in every offered elective or clear saved scores.
- [ ] Run `php tests/assessment_test.php`, `python tests/assessment_http_test.py`, `php tests/operations_test.php`, `python tests/http_test.py` and `python tests/exam_http_test.py`; expect all to pass. Verify keyboard selection and input enabling in the disposable browser app.
- [ ] Stage only this task's files and commit `fix: complete optional-subject marks enrollment`.

## Handoff

Run this plan first. Tests are synthetic/disposable; the original database and source attachments are never test inputs for mutation. The coordinator plan specifies isolated-workspace preparation and final integration/review.
