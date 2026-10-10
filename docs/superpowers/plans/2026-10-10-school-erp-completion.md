# School ERP Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the approved assessment, school-package and accounting changes with reproducible evidence and no loss of school records.

**Architecture:** Preserve the PHP ERP and current local work. Execute three focused plans on one isolated feature branch, with shared disposable tests and tightly ordered schema/permission dependencies. No production-database migration, push or FTP deployment is performed during verification.

**Tech Stack:** Existing PHP/PDO SQLite/MySQL, Bootstrap/JavaScript, native XLSX/CSV exports and browser Print / Save as PDF; Python integration tests.

**Spec:** `docs/superpowers/specs/2026-10-10-school-accounting-design.md`, approved by the user on 2026-10-10.

## Global Constraints

- "keep PHP and the current SQLite/MySQL database support"
- "reuse school, student, class, section, academic-year, exam and subject IDs"
- "preserve educational and financial history"
- "enforce school isolation"
- "do not create fictitious financial transactions in the real database"
- "Git publication and deployment are separate from implementation and require a publication request."
- Preserve all unrelated workspace changes and original XLSX/DOCX/PDF attachments; do not stage databases, uploads, backups, credentials or generated print artifacts.

## Review Focus

- Local uncommitted prototype files must survive isolated-workspace preparation (preparation below).
- Default IN edits and exam-specific changes must not share an inconsistent revision contract (A1).
- Finance viewing must remain read-only at both constructor and HTTP levels (C1/P2).
- Monetary and cutoff invariants must be checked on both normal and correction paths (C2/C5/C12).
- A passing syntax check is not evidence of working exports, concurrent cashiers or MySQL (C13/C14).

## Three focused plans

1. [Assessment completion](<D:/A WebSite/Nepal School Management System/docs/superpowers/plans/2026-10-10-assessment-completion.md>) — A1–A2: revision-safe IN configuration, elective marks/enrollment, and reusable disposable test support.
2. [Package permissions](<D:/A WebSite/Nepal School Management System/docs/superpowers/plans/2026-10-10-package-permissions.md>) — P1–P2: one entitlement matrix, school package labels, direct-route checks, finance-role boundaries and read-only previews.
3. [Accounting completion](<D:/A WebSite/Nepal School Management System/docs/superpowers/plans/2026-10-10-accounting-completion.md>) — C1–C14: additive migration, exact money, posting/collection, editable bill drafts, opening corrections/refunds, effective fees, recurring billing, scholarships/fines, subscriptions, expenses, fiscal close, reports, print/exports and full verification.

Each task includes tests to write, the failing reproduction to observe, exact interfaces/files, verification commands and a scoped commit. Previously implemented behavior that already passes is retained and gains regression coverage; it is not rewritten merely to follow the test cycle.

## Execution preparation

Before A1, invoke the selected execution skill and `superpowers:using-git-worktrees`. Inspect actual branch/status and existing attachments before choosing a suitable worktree. If creating one, preserve the current local prototype by copying an explicit reviewed source-file manifest into the isolated checkout; do not assume Git worktree creation copies uncommitted work. Resolve and check every destination is inside that checkout. Keep the original checkout and unrelated files untouched.

The manifest consists of the currently changed PHP/JavaScript source files reported by `git status`, the new accounting CSS/JS/job/service/schema/UI/modules, `includes/assessment_service.php`, `includes/packages.php`, and `modules/subjects/internal.php`. Exclude the three source documents, `database.sqlite`, `.backups`, uploads, private test data and generated artifacts. If new overlapping user edits appear, compare them before any copy/integration.

Use a disposable DB/server for all mutation and browser tests; set `ERP_DB_PATH` explicitly. Do not open the original port-8099 app to verify code that would auto-migrate its database. Any existing-database upgrade test uses a consistent OS-temp snapshot obtained through SQLite's backup API, not an unsafe copy of a live WAL database. Capture counts and balances without exposing student details in logs.

## Required order and deliverables

- [ ] A1, then A2: verified assessment behavior and shared fixtures.
- [ ] P1: verified package matrix and school labels.
- [ ] C1: schema version 8/provisioning and write-free finance construction.
- [ ] P2: verified finance roles and no-write HTTP previews.
- [ ] C2–C5: exact money, valid payment accounts, drafts/issue snapshots, correct opening equity and credit/refund journals.
- [ ] C6–C11: configured fees, previewed recurring charges, approved discounts/fines, race-safe subscriptions, expenses/attachments and fiscal close.
- [ ] C12–C13: reconciled historical reports, searchable/paginated UI, accurate notifications and verified print/exports.
- [ ] C14: complete regression/concurrency/upgrade evidence, independent review and final handoff.

After each task, run its stated checks, record actual results and commit only the named source/test files in the feature branch. Stop for newly required authority or an architectural change outside the approved design, not for unchanged external state. No stage is accepted solely because an earlier test run passed.

## Completion checks

The new suites supplement, not replace, these existing checks:

```powershell
$env:PATH = 'C:\php;' + $env:PATH
$existingTests = @('security_test.php','workflow_test.php','grading_test.php','content_test.php','operations_test.php','exam_enhancements_test.php','exam_workflows_test.php','cdc_2083_test.php')
foreach ($testFile in $existingTests) {
    & 'C:\php\php.exe' ('tests/' + $testFile)
    if ($LASTEXITCODE -ne 0) { throw ('Failed: ' + $testFile) }
}
python tests/http_test.py
python tests/exam_http_test.py
```

Also execute every new task's PHP/HTTP/export/concurrency suite, syntax and whitespace checks, the copy-only upgrade test, and disposable browser print/keyboard/mobile workflows. Every nonzero exit or unexplained warning is investigated, not hidden. MySQL readiness requires an actual safe MySQL/InnoDB run; unavailable runtime checks are listed as outstanding. One fresh whole-branch reviewer is required after native implementation; the subagent-driven method instead supplies task-level reviewers plus final review.

Integrate verified code into the user's original checkout only after comparing it with the preserved baseline and any newer user changes. Keep that integration separate from activating migration on the original school database. Handoff reports completed features, remaining limitations, verified commands and commits. A later publication request includes a staged-content secret/data check and remote branch/hash confirmation before a push that could trigger FTP deployment.

## Plan review and execution choice

Status: awaiting user review; product-code implementation has not resumed after design approval.

Recommended **Native**: implement the tasks in this chat, then one fresh independent whole-branch review. The plans have tightly shared schema/service interfaces, and the user previously raised credit limits, so this minimizes repeated context while retaining final independent review.

Alternative **Subagent-driven**: a fresh implementer and reviewer for each task, followed by whole-branch review. This provides more independent checkpoints but incurs repeated contexts and more coordination.

The user should confirm that this plan captures the approved design and choose Native or Subagent-driven before implementation resumes. Approval of the earlier design did not select an execution method or approve these newly written plans.
