# School ERP review and security release — 23 October 2026

## Repository comparison and scope

The initially clean local `main` exactly matched `sghacker1111/Nepal_ERP/main` at `c22f17381d1dc27ffd0b2d8ba2f42b426ca44586`. Its `nepal-erp` remote is the requested repository; `origin` still identifies the older School Management System repository. Releases for this work use `nepal-erp`, not that older destination.

The initial tracked inventory covered 541 files, including 233 PHP files, 77 module controllers, 89 shared PHP helpers and 85 frontend source files. A machine-readable path/size/hash/line-count/authorization/input/query inventory is retained locally in `scratch/security-review/source-inventory.json`. Inventory and automated scans cover the tracked source tree; manual review focused on request boundaries and workflow services. This is not a claim of independent penetration testing or proof that every possible defect has been eliminated.

| Area | Workflow and security boundaries reviewed |
| --- | --- |
| Entry points/frontend | Finite clean/legacy routes, native print/download/JSON responses, 70 page adapters, inert page metadata, navigation, CSRF, pending saves, dirty forms, dynamic callbacks and local assets |
| Authentication/users | Login limits, strict cookie sessions, live account roles/status, self-password changes, administrator resets, student account links and system-admin school preview |
| Schools/packages | School ID/reference validation, purchased package combinations, active/expired/disabled subscriptions and immediate entitlement revocation |
| Students/teachers | School/class/year/section records, CSV import/export, teacher assignments, enrollment/electives, archive/history preservation and signatures |
| Classes/attendance | Compulsory/elective mappings, exact teacher class-subject scope, weekly routines, AD/BS periods and password-confirmed attendance corrections |
| Exams/results | Automatic/manual templates, subject configuration, marks/components, lock/publish/reopen, symbols, GPA/percentage calculations and report/admit-card outputs |
| Learning/question banks | Private/school-public/shared content, creator writes, catalog mapping, normal versus exam bank separation, explicit student releases, correction request privacy and imports |
| Paper generation/practice | Chapter/mixed/grid quotas, eligible question scope, exact counts/marks, answer keys, student electives and practice access revalidation |
| Accounting | School-scoped ledgers, finance roles/actions, invoice/payment journals, minor-unit arithmetic, idempotent requests, concurrent payment replay and data retention |
| Deployment/storage | MySQL-only web runtime, isolated SQLite tests, private-file denial, FTP secrets/destination validation, upload/database/config exclusions and read-only release verification |

## Fixed application issues

1. **Old authenticated sessions survived password resets.** Sessions now carry a fingerprint of password hash, username, role, school and linked student. Protected requests reject stale fingerprints. A self-password change rotates the current session and CSRF token while revoking other logins. Existing pre-release sessions must sign in once again. No database migration or school-record rewrite is required.
2. **Sensitive password confirmation had no common brute-force limit.** Password verification, self-password changes, academic-year activation, class recovery, routine changes and attendance corrections share ten failed attempts per account over fifteen minutes. Attendance failures persist outside the attendance transaction, so rollback cannot erase the limit. Array/oversized passwords are rejected without type errors; new self-service passwords respect the bcrypt byte limit.
3. **The common XLSX reader could inflate unbounded archives or allocate huge sparse worksheet dimensions.** Before inflation it now caps compressed input at 5 MB, total expanded ZIP entries at 32 MB and entries at 1,000. Parsing rejects DTD/entities, NUL/UTF-16 XML, invalid/duplicate positions, rows beyond 10,001 and columns beyond 128. CSV cells/rows are bounded and UTF-8 checked. Exam imports retain their stricter 2,000-data-row/64-column policy. Existing bilingual/TeX templates and valid imports retain their semantics.
4. **Some handled database failures exposed technical messages.** Student/teacher archive errors and class-subject AJAX errors now use the shared public error handler. The AJAX mode is determined before its database operation, including failure paths.
5. **TinyMCE 6 had known upstream XSS advisories.** At the owner's explicit request its existing MIT release is retained. DOMPurify 3.4.17 now filters HTML before editor parsing/paste, including the OCR editor. SVG/MathML/active embeds, protected comments and attacker-provided `data-mce-*` attributes are excluded; the media plugin is disabled. Server sanitization remains authoritative. Bilingual prose, tables, formatting and portable TeX are tested. **This hardening does not close the upstream advisory or make `npm audit` clean.** A maintained editor replacement or licensed upgrade remains outstanding.
6. **MathJax could create active links from TeX after HTML sanitization.** The local `ui/safe` extension now loads in page and question-editor iframe renderers, with formula-generated URLs/IDs disabled. Actual rendering tests cover fractions and malicious direct/obfuscated TeX links. Stored question text is not rewritten.
7. **OCR loaded PDF.js 3.4.120 from a CDN.** It is replaced by the Apache-2.0 PDF.js 6.4.299 compatibility build, a matched local worker, character maps, fonts and WASM resources. Local-data requests force `isEvalSupported=false` and XFA off. Tests parse, extract text and rasterize an actual synthetic PDF using the packaged engine/worker, and check loader cleanup and safe options. Module/WASM MIME types are declared for Apache.
8. **Editor initialization could invent an unsaved draft.** Dirty tracking no longer takes an uninitialized editor's empty content as its baseline. Initial exam-catalog loading retains the saved chapter while fetching its options. Tests distinguish hydration from real edits and still protect changed content.
9. **One configuration-isolation test copied ignored private settings.** Its disposable copy now excludes local settings, so it can reliably verify missing/malformed/unavailable MySQL configurations without copying live credentials.

## PHP engine requirement — hosting action remains necessary

The official October 21 advisory identifies **CVE-2026-109618** and **CVE-2026-109619**, which can permit unauthenticated remote code execution before application code. The fixed upstream versions are **8.2.35, 8.3.36, 8.4.27 and 8.5.12**. Only a patched interpreter, or a host-confirmed backport of both fixes, resolves that engine exposure. Application patches, `.htaccess`, `.user.ini` and late `ini_set()` calls cannot establish that the host is safe.

System administration now shows the actual web runtime's PHP version and whether its version meets this dated baseline. The status is restricted to super admin, not public users or school administrators. Unknown branches/vendor backports are reported as unconfirmed, not silently treated as patched. The display is a version check, not an exploitation test.

InfinityFree's PHP patch status could not be confirmed through anonymous requests. The owner did not know its exact runtime version, and no authenticated production administrator session was supplied. **After deployment, sign in as system administrator, open Control Center, and request InfinityFree confirmation/upgrade if the baseline is not met.** Do not regard successful FTP transfer as engine remediation. PHP 8.1 and older are unsupported upstream.

Local testing also found the shared CLI at PHP 8.5.1. A separate official PHP 8.3.36 build was downloaded into ignored scratch storage, verified against the vendor SHA-256 manifest and used for final regressions. The shared installation was not overwritten. CI now explicitly uses PHP 8.3.36 and runs the portable security gate before allowing deployment.

## Verification and release evidence

All test databases, credentials and records are synthetic and disposable. Production data, uploads and private configuration are not imported, migrated, reset or synchronized by this release.

- The native MySQL suite passed **35 tests**, including migration preservation, real MySQL workflows/HTTP parity and concurrent payment replay. The new session-security HTTP suite was also rerun explicitly on native MySQL under patched PHP 8.3.36.
- The portable security gate passed **195 commands** under PHP 8.3.36: production PHP syntax, domain policies, runtime failures, sessions, class isolation, attendance, packages, exact teacher scope, question authoring and route coverage.
- The full patched-PHP non-MySQL regression run passed **363 commands** with zero failures; PHP lint covered 237 production files. Frontend verification passed **70 tests**, the focused editor/PDF checks passed **4 tests**, and the FTP runtime verifier passed **5 tests**. The initial broad pass exposed the private-settings test-fixture issue above; its corrected test passed in the final run.
- Frontend verification includes real TinyMCE/MathJax behavior, bilingual editing and draft protection; PDF tests exercise the actual packaged parser and renderer. No operating-system print dialog or real scanner OCR accuracy claim is made.
- Anonymous production browser access reached the login page. Authenticated production school/accounting/exam transactions remain unverified; fixture results must not be represented as live school-data certification.
- The FTP verification now checks packaged editor/math/PDF files and compares **29 critical SHA-256 hashes**, including authentication, imports, runtime diagnostics and compiled code. It never reads private settings, school records or uploads.

## Remaining risks and operator checks

- Confirm and install the InfinityFree PHP engine patches, then restart the provider's workers. The application cannot perform that hosting operation over FTP.
- `npm audit` retains one high-severity dependency finding for TinyMCE 6, covering multiple advisories. The owner chose to retain its existing license; the new sanitizer and disabled attack surfaces reduce exposure but do not equal an upstream patch.
- Tesseract OCR/language loading still depends on its existing external resources. No confidential PDF is sent by this release's local PDF renderer, but externally loaded code remains a supply-chain dependency.
- MFA, a strict script CSP, infrastructure configuration, account recovery and a separate authorized authenticated production assessment remain future work. No claim is made that this review detects historical compromise.

## Primary references

- [PHP official October 21, 2026 advisory](https://www.php.net/archive/2026.php#2026-10-21-5)
- [Official Windows PHP distribution/hash manifest](https://windows.php.net/downloads/releases/releases.json)
- [TinyMCE upstream security advisories](https://github.com/tinymce/tinymce/security/advisories)
- [DOMPurify releases](https://github.com/cure53/DOMPurify/releases/tag/3.4.17)
- [MathJax 3 safe extension](https://docs.mathjax.org/en/v3.2/options/safe.html)
- [Mozilla PDF.js advisory and evaluation workaround](https://github.com/mozilla/pdf.js/security/advisories/GHSA-wgrm-67xf-hhpq)
