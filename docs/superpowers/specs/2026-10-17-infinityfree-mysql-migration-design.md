# SQLite to InfinityFree MySQL migration

Date: 2026-10-17, Asia/Kathmandu
Status: Written specification approved by the owner on 2026-10-17 ("approve it"); implementation-plan approval is still required.

## Intent and boundaries

Prepare the existing school application locally to use MySQL on InfinityFree
instead of its normal local `database.sqlite` storage. Deliver a private SQL file
the owner can import with phpMyAdmin, preserve existing data and the recent
Tailwind/Alpine/HTMX modernization, and remove only demonstrably obsolete runtime
files. This task does not push, merge, import into hosting, or deploy.

The owner's "yes do it" is treated as approval to prepare a migration into a
**new empty database**, not permission to overwrite or merge any live database.
Live-site data is outside the source snapshot and is not claimed to be included.
If the intended destination is nonempty, cutover stops: reconciling that database
is a separate task requiring its own backup and record-matching decisions.

## Verified starting point

- Source: `D:\A WebSite\Nepal School Management System\database.sqlite`.
- Read-only inspection: schema version 7, 52 application tables, 794 rows;
  `PRAGMA quick_check` returns `ok`; no stored views or triggers.
- There are no student or exam records, and 50 existing marks reference missing
  students. Conversion must preserve those marks and disclose the broken links.
- Source file SHA-256 at design time:
  `f560972fd6e846d13063d71ed582b2f221ae2230bd5d881242d18d5a4ec403c3`.
  Re-read and snapshot the actual source at conversion time; this hash is not a
  license to overwrite later user changes with an earlier file.
- Latest application code is in the existing linked worktree at
  `C:\Users\SGHACKER\.codex\worktrees\accounting-completion\Nepal School Management System`,
  based on commit `57499fc`; its target schema is version 18.
- The original checkout has unrelated local edits. Do not reset, clean, replace,
  or mass-delete it to make the two checkouts match.
- Existing `database.sql` is an older starter schema with seed rows. It is not an
  export of the owner's records and must not be imported over an existing system.
- PDO MySQL is already a supported connection path, but configuration defaults
  to SQLite and most historical regression fixtures exercise SQLite.
- No local MySQL/MariaDB server or Docker command was found on PATH during the
  preceding inspection. A real isolated MySQL-compatible test runtime must be
  located or provisioned locally before claiming MySQL import verification.

## Approaches considered

1. **Recommended: MySQL for normal operation, offline conversion boundary.**
   Convert a consistent read-only SQLite snapshot, validate it on disposable
   local MySQL, and provide the resulting private SQL export. Normal app requests
   use configured MySQL only. SQLite remains confined to explicit offline
   conversion and isolated test fixtures, not normal school storage.
2. **Retain dual-engine runtime selection.** This keeps local SQLite development
   convenient but leaves two runtime paths to configure and support. It does not
   meet the owner's request to stop using local SQLite for the application.

Direct local access to the hosted database is not an alternative on the free
plan: InfinityFree allows access from its hosted PHP and its phpMyAdmin, not
external desktop applications. Local MySQL is for staging/tests only; the
production configuration uses the host supplied by InfinityFree.

## 1. Private migration package

An offline CLI conversion tool takes an explicit source filename and a new output
directory. It is not a web upload, public download endpoint, or automatic action
on page load. It refuses to overwrite an existing export package.

Default deliverable location is a dated directory beneath
`C:\Users\SGHACKER\Downloads\School-ERP-MySQL-Migration`, outside both application
checkouts and their Git history. The package contains:

- A consistent SQLite snapshot retained for recovery.
- `school_erp_mysql.sql`, optionally also gzip-compressed for phpMyAdmin upload.
- A private manifest recording source/snapshot/export hashes, table and row
  counts, primary-key ranges, schema version, and canonical content digests.
- A migration report with upgrade additions, anomalies, verification results,
  and manual phpMyAdmin import/configuration instructions.

The SQL contains data and password hashes and is confidential. Only converter
code, templates without credentials, tests, and instructions enter source control.
Generated exports, private configuration, snapshots, and reports containing record
details are excluded from Git, web access, and later FTP uploads/deletions.

Use SQLite's consistent backup API from a read-only source connection, including
committed WAL contents; do not copy only the main file while a writer is active.
Run the conversion from the snapshot and verify its captured state. If the source
changes during the operation, preserve those changes, report the drift, and do
not present the earlier snapshot as the final cutover state. Final cutover needs
a maintenance window and a freshly verified export.

## 2. Data and schema conversion

Preserve every source table, row, primary key, school/owner reference, password
hash, existing timestamp/date, account amount, mark, question HTML/TeX and Nepali
text. Do not seed demo accounts, guess missing identities, renumber records,
silently truncate values, skip failed rows, or deduplicate real data.

Build a schema compatible with the latest app, adding missing tables, columns,
defaults and indexes on the private working copy/isolated target only. Audit the
legacy version-7-to-18 upgrade first: do not rerun unrelated name normalization
or catalog cleanup merely to change engines. Existing domain cell values stay
unchanged. New metadata gets conservative, documented defaults; version metadata
changes only after the schema is genuinely ready. Any required rewrite of an
existing domain value blocks completion and is reported for a separate decision.

Preserve integer money exactly, use adequate text types for bilingual rich HTML,
retain NULL distinctly from empty text/zero, use safe identifier/value quoting,
and preserve explicit zero IDs and next-ID sequence state. Preflight index,
collation, length, type, date and uniqueness compatibility. Reject unsupported
constructs or conversion collisions with an actionable report rather than lose
data. Use InnoDB and a supported utf8mb4 collation, not an engine-specific collation
unavailable on the destination. SQL must not require privileged global settings,
`CREATE DATABASE`, `CREATE USER`, definers, or server filesystem access.

The import file uses ordinary CREATE/INSERT statements with bounded statement
sizes and no DROP, TRUNCATE, REPLACE, or merge/upsert-overwrite behavior. Import
requires a newly created empty database selected in phpMyAdmin. A rerun against
existing tables must fail, not replace them. MySQL DDL is not transactionally
reversible; a failed import is not declared safe merely because INSERTs use a
transaction. Retry in a new empty test database after reviewing the error.

### Existing broken references

The 50 orphan marks remain stored, including their IDs and original values.
Do not create fake students or assign the marks to other students. During the
one-time import, foreign-key checks can be disabled for the import session to
load the captured legacy state, then restored to their prior setting. Retain
constraints for future writes; never globally disable integrity checking.

Re-enabling MySQL checks does not retrospectively validate those rows. Explicitly
compare every declared relationship before/after and flag the known source
violations as unresolved. No new or changed broken relationships are acceptable.
The handoff distinguishes a faithful conversion from a fully repaired historical
database. Repairing those marks requires a true source/backup, not migration code.

## 3. Application configuration and runtime

Normal operation requires validated MySQL settings: host, port, database, user,
password and utf8mb4 encoding. Support a private, ignored configuration file for
InfinityFree alongside environment overrides for local testing. Supply only a
credential-free example. No credential is requested in chat, committed to Git,
included in a URL, or written to browser responses/logs.

Audit entry points and shared connection code so there is one configuration
authority. A missing setting, missing PDO MySQL driver, wrong credential,
unreachable server, or incomplete schema gives an actionable safe error and fails
closed. It must never create/open `database.sqlite`, select a different database,
reset accounts, or import a base schema to hide a connection failure.

Normal web startup verifies readiness without silently running historical
data-rewriting upgrades. Setup/upgrades remain explicit offline/administrative
operations. The delivered export is already ready for the expected schema version.
SQLite adapters/schema fixtures, if needed for retained automated tests, live
behind an explicit isolated-test boundary; a request parameter/header cannot
enable them. The production package does not require PDO SQLite.

The current FTP workflow excludes `config/config.php` and private local files.
Document and test the one-time public-bootstrap/private-settings cutover so a
later deploy neither relies on a stale SQLite default nor overwrites existing
hosting secrets. Do not remove a protective exclusion just to ship credentials.

## 4. Cleanup

Inventory file references and the runtime/deployment manifest. Remove or relocate
only SQLite-specific production bootstrap/seed paths made obsolete by this
change, confirmed unused duplicate artifacts, and generated scratch/build caches
that can be reproduced. Update installation/start/migration docs accordingly.

Keep the original database, private snapshots, uploads, user documents, password
configuration, active features, maintained build sources and useful regression
fixtures. Database exports and retained backups are not application runtime files.
Do not interpret "unneeded files" as permission for recursive cleanup of the
workspace or deletion of the owner's dirty checkout. The handoff lists any
removed paths and their recovery method; ambiguous files stay in place.

## 5. Verification and completion criteria

1. Regression tests reproduce unsafe defaults, missing configuration, export
   quoting/Unicode/NULL/decimal issues and nonempty-target behavior before fixes.
2. Run a real MySQL-compatible local import of the generated file into an empty
   disposable database; run upgrade/readiness twice to prove idempotence.
3. Compare all original rows/columns, IDs, content hashes, password hashes, dates,
   monetary values, sequence state and relationships. Only documented schema
   metadata/default additions may differ; no original records disappear.
4. Test synthetic users/records separately for sign-in/roles, school isolation,
   exact class filters, bilingual questions/imports, exams/marks/results,
   attendance and accounting payment idempotency/reconciliation. Do not mutate
   the imported owner's records for these tests.
5. Run the retained PHP/JavaScript/HTTP regressions, UI build, deployment/private
   path tests and diff/secret checks. Keep local MySQL results separate from any
   SQLite fixture coverage. Report blocked tools or engine mismatches explicitly.
6. Confirm normal MySQL operation leaves no SQLite file, and connection failures
   cannot create one. Confirm sensitive exports/private config cannot ship.
7. Deliver the SQL/package location, verification report and phpMyAdmin steps.
   State that uploads are separate files and are not embedded in the SQL.

This local task does not certify the hosting server, perform production import,
or resolve the previously pending interactive navigation acceptance. Before any
later deployment, retain a live MySQL/uploads/config backup, verify the actual
InfinityFree engine/import, configure the hosted PHP connection, and check normal
staff/student workflows and print layouts. Preserve any live data not in this
snapshot. Rollback must not discard school work entered after cutover.

## References

- [InfinityFree: external MySQL access restriction](https://forum.infinityfree.com/t/connecting-to-mysql-from-an-external-application/49339).
- Existing schema/upgrade boundaries: `includes/database.php`,
  `includes/schema.php`, `includes/accounting_schema.php`,
  `includes/learning_schema.php` and `tests/upgrade_copy_test.py`.
- Existing safe-release boundaries: `.github/workflows/deploy.yml` and
  `docs/frontend-modernization.md`.

## Design checklist

- [x] Inspect current database, runtime paths, schema gap and deployment exclusions.
- [x] Interpret approval conservatively: local preparation, empty target, no live overwrite.
- [x] Document alternatives, preservation, conversion, configuration, cleanup and tests.
- [x] Self-review for placeholders, conflicting promises and scope ambiguity.
- [x] Owner reviews and approves this written specification.
- [ ] Write an implementation plan and obtain its approval/execution choice.
- [ ] Implement, verify and deliver the private import package locally.
