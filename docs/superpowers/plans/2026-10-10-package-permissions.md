# Package Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all requested school packages and finance roles consistent across navigation, direct routes and server-side writes.

**Architecture:** Keep one school package, with Full ERP including every module. Use a single capability matrix and preserve separate role checks and permission approvals. Fix labels and stale entitlement behavior without replacing authentication.

**Tech Stack:** Existing PHP authentication/session/CSRF guards, Bootstrap, PDO and the disposable test harness from the assessment plan.

**Spec:** `docs/superpowers/specs/2026-10-10-school-accounting-design.md`, package behavior and acceptance items 3 and 8.

## Global Constraints

- "one primary package per school, with Full ERP including every module"
- "enforce school isolation"
- "Question View cannot add/edit questions."
- "Question Add includes the viewing needed to manage permitted questions."
- "Finance-only roles do not gain educational-record administration simply because accounting references students."
- "Existing session/CSRF protections remain in force."

## Review Focus

- A package changed by the super admin must affect an already logged-in school user on the next request (Task P1).
- A failed entitlement database lookup must not grant Full ERP (Task P1).
- A learning subpage must obey its feature entitlement even when its parent navigation is unavailable (Task P1).
- An auditor or super-admin school-preview GET must not create finance configuration (Task P2; accounting C1 implements provisioning).
- A foreign/inactive account or crafted role selection must not acquire finance privileges (Task P2).

## File map

`includes/packages.php` owns names/capabilities; `includes/auth.php` resolves current school entitlements; `includes/request_guard.php` enforces route and write access. `includes/header.php` owns visibility only. The school/user settings pages render values from those shared definitions. New tests use `tests/test_support.php` and `tests/http_support.py` from A1.

## Task P1: Package matrix, direct-route enforcement and labels

**Files:** Modify `includes/packages.php`, `includes/auth.php`, `includes/request_guard.php`, `includes/header.php`, `modules/super_admin/schools.php`, `modules/learning/index.php`; create `tests/packages_test.php`, `tests/packages_http_test.py`. Adjust only an affected learning subpage if its explicit guard disagrees with the matrix.

**Interfaces:** Preserve `schoolPackages(): array`, `schoolPackageAllows(string $package,string $module): bool`, `Auth::getSubscriptionType()`, `Auth::hasAccess($module)` and `Auth::requireAccess($module)`.

- [ ] Write the matrix test for `full`, `result_system`, `learning_system`, `paper_generator`, `question_view`, `question_add`, `accounting`, and legacy `exam_generator`. Assert `schoolPackageAllows('accounting','accounting') === true`, `schoolPackageAllows('accounting','results') === false`, unknown packages deny feature access, and Full ERP permits accounting. HTTP tests assert Question View POST is 403, paper generation is usable in its package, and every new package has its exact visible label in the school list.
- [ ] Add a logged-in downgrade test: change `full` to `result_system`, request accounting without relogin, expect 403. Add a lookup-failure test expecting denial, never fallback to `full`.
- [ ] Run `php tests/packages_test.php` and `python tests/packages_http_test.py`; expect the missing-label/fail-open cases to fail before their fixes.
- [ ] Complete the shared matrix and exact labels from `schoolPackages()`. Resolve school entitlements freshly per request, retain the global-super-admin exception, fail closed on invalid/missing school data, and align sidebar/landing links with direct-page guards. Do not let permission approvals grant a feature outside its package.
- [ ] Run those suites and `php tests/security_test.php`; expect all checks to pass, including CSRF and direct-route restrictions.
- [ ] Commit only the task files as `fix: enforce school package capabilities consistently`.

## Task P2: Finance role boundaries and read-only access

**Files:** Modify `includes/user_management.php`, `modules/settings/users.php`, `includes/request_guard.php`, `includes/header.php`, `includes/accounting_ui.php`; extend `tests/packages_test.php`, `tests/packages_http_test.py`. Consume `includes/accounting_schema.php` / `includes/accounting_service.php` provisioning contract from C1 before the finance read-only tests can pass.

**Interfaces:** Preserve `saveSchoolUser(PDO,int,string,int,array): int`, `schoolRoleCanWrite(string $role,string $module,string $file): bool`, and `SchoolAccounting::can(string $action): bool`. Admin allows all reviewed finance operations; accountant allows catalog/fee/bill/payment/draft-expense/subscription work but not settings, approval, refund, reversal, fiscal-period or opening-balance actions; cashier allows collection and advance allocation; auditor allows view only.

- [ ] Write role-matrix tests asserting `schoolRoleCanWrite('auditor','accounting','payments.php') === false`, a cashier cannot issue a bill or approve an expense, and an accountant cannot refund or reopen a period. HTTP tests expect 403 for finance-role student/settings administration and missing CSRF, while permitted financial POSTs succeed. Snapshot accounting tables around auditor and school-preview GETs; expect exact equality.
- [ ] Run package suites; document the constructor's current write side effect as the failing read-only reproduction, then complete C1 before rerunning that case.
- [ ] Complete role choices, allowed navigation and service/action restrictions. Validate active actor and school ownership in finance services as well as the HTTP guard. Retain last-school-admin/self-demotion protections and display actions only when permitted. Do not grant access through a crafted POST role or a hidden button.
- [ ] Run package suites, `php tests/operations_test.php`, and `python tests/http_test.py`; expect all existing user safeguards and new finance boundaries to pass.
- [ ] Commit only the task files as `fix: protect finance roles and read-only accounting views`.

## Handoff

Execute P1 after assessment, then accounting C1, then P2, then the remaining accounting tasks. No external gateway, extra identity system or package-combination feature is introduced.
