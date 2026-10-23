# InfinityFree MySQL Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Native execution is recommended here; obtain the owner's approval of this plan and execution choice before implementation.

**Goal:** Deliver a private, data-preserving phpMyAdmin import of the owner's SQLite database and make normal application operation use configured MySQL without SQLite fallback.

**Architecture:** Keep the latest PHP/Tailwind/Alpine/HTMX app in its existing isolated worktree. An offline converter snapshots the source read-only, adds current schema metadata without rewriting existing domain cells, and writes an independently verified MySQL SQL package. A shipped, secret-free bootstrap loads private MySQL settings; SQLite initialization is confined to explicit offline/test tools excluded from production.

**Tech Stack:** PHP 8.1+ / PDO MySQL; Python 3 standard library (`sqlite3`, `decimal`, `hashlib`, `unittest`, `gzip`); disposable local MySQL 8.4 LTS; existing npm UI build/tests. Record the actual local engine/version and do not call it a certification of InfinityFree's engine.

**Spec:** `docs/superpowers/specs/2026-10-17-infinityfree-mysql-migration-design.md` (approved 2026-10-17).

## Global Constraints

- "This task does not push, merge, import into hosting, or deploy."
- "Normal app requests use configured MySQL only."
- "Preserve every source table, row, primary key, school/owner reference, password hash, existing timestamp/date, account amount, mark, question HTML/TeX and Nepali text."
- "Any required rewrite of an existing domain value blocks completion and is reported for a separate decision."
- "Do not seed demo accounts, guess missing identities, renumber records, silently truncate values, skip failed rows, or deduplicate real data."
- "The 50 orphan marks remain stored, including their IDs and original values."
- "A rerun against existing tables must fail, not replace them."
- "The production package does not require PDO SQLite."
- "Do not remove a protective exclusion just to ship credentials."
- "Do not interpret 'unneeded files' as permission for recursive cleanup of the workspace or deletion of the owner's dirty checkout."
- Source: `D:\A WebSite\Nepal School Management System\database.sqlite`; baseline at design time: schema 7, 52 tables, 794 rows, 50 orphan marks. Reinspect at execution time.
- Work only in `C:\Users\SGHACKER\.codex\worktrees\accounting-completion\Nepal School Management System`. Preserve the original dirty checkout. Reuse this worktree; do not create another by default.
- Private packages go beneath `C:\Users\SGHACKER\Downloads\School-ERP-MySQL-Migration\<unique-timestamp>`, never into Git or the web root. Do not print row contents/password hashes/credentials in tool output.
- The current application schema version is 18. Do not advance the source database's version marker or normalize its data in place.

## Review Focus

1. A writer commits to SQLite WAL during export: capture a consistent snapshot, detect later source drift, and never call a stale export cutover-ready (Task 2).
2. Nepali HTML/TeX, backslashes, empty strings, NULL, integer money and explicit ID 0 survive strict MySQL modes without truncation or sequence reuse (Tasks 3–5).
3. Known orphan marks remain present, while import rejects new integrity violations; re-enabling checks alone is not validation (Task 5).
4. A deployed old `config/config.php`, HTTP-supplied test flags, or bad MySQL credentials cannot reactivate SQLite, leak secrets or cause reseeding (Task 1).
5. A nonempty target, a partial previous import or a private output path inside the app must fail before overwriting records or publishing a confidential export (Tasks 2, 5, 7).

## File responsibilities and shared interfaces

- `config/bootstrap.php`: shipped application-settings bootstrap; replaces runtime dependencies on the excluded legacy configuration filename.
- `config/database.local.example.php`: credential-free shape of the private, ignored `config/database.local.php` settings file.
- `includes/database_settings.php`: validate configuration and explicit isolated-test boundary; no connections or migrations.
- `includes/database_readiness.php`: check current schema from MySQL metadata, without DDL/seeding; consumes the structural manifest generated in Task 3.
- `includes/database.php`: keep `getDB(): PDO` / `Database::getInstance()` public interfaces; connect through validated settings and enforce readiness for normal MySQL use.
- `tools/mysql_migration/sqlite_runtime.php`: offline/test-only legacy SQLite adapter; never included in the hosting package.
- `tools/mysql_migration/snapshot.py`: consistent source backup, metadata inventory, original-row digest, drift and integrity report.
- `tools/mysql_schema_blueprint.php`: generate current structural schema in an owned disposable SQLite database, never using real records.
- `tools/mysql_migration/projection.py`: merge required schema additions into a working snapshot and preserve original cells/sequence state.
- `tools/mysql_migration/sql_writer.py`: encode the projected structure/rows as portable, bounded MySQL statements.
- `tools/mysql_migration/verify_mysql.php`: local-only PDO import/readback bridge; refuses nonempty or non-disposable target databases.
- `tools/mysql_migration/verify.py`: compare readback with captured original/projected manifests and classify known anomalies.
- `tools/export_mysql.py`: CLI orchestration, private output package and final report; no web endpoint.
- `tools/mysql_test_server.ps1`: local isolated server lifecycle only; no Windows service/global config changes.
- `config/data/mysql_schema.json`: versioned structural requirements only, generated from the latest schema; no school rows or secrets.
- `tests/mysql_*_test.py`, `tests/database_*_test.php` and `tests/mysql_workflow_fixture.php`: synthetic fixtures and migration/runtime verification.
- `docs/mysql-migration.md`: operator import, configuration, recovery and cleanup instructions.

Use dataclasses for `Snapshot` and `Projection`, with JSON serialization version `1`. Their manifests use `tables[table] = {columns, primary_key, row_count, rows_digest, sequence}` and `foreign_key_violations` with stable relationship/primary-key identity. Original/projected manifests are separate. Canonical numeric comparisons use `Decimal(str(value))`; text/bytes and NULL retain distinct tagged representations. Tables without a primary key use a sorted row multiset retaining duplicate multiplicity, never a deduplicating set.

## Task 1: Secure MySQL bootstrap and isolated-test compatibility

**Files:** Create `config/bootstrap.php`, `config/database.local.example.php`, `includes/database_settings.php`, `includes/database_readiness.php`, `tools/mysql_migration/sqlite_runtime.php`, `tests/database_settings_test.php`, `tests/database_runtime_http_test.py`; modify `config/config.php`, `includes/database.php`, the runtime bootstrap consumers in Appendix A, `tests/test_support.php`, `tests/http_fixture.php`, `tests/upgrade_test.php`, `tools/verify-release.py` and test fixture launchers identified by `rg -l 'ERP_DB_TYPE|ERP_DB_PATH' tests`.

**Interfaces:** `schoolDatabaseSettings(array $environment, ?array $privateSettings = null): array` returns driver/host/port/database/username/password/charset plus isolated-test settings. `openSchoolPdo(array $settings): PDO` opens exactly that database. `schoolAssertDatabaseReady(PDO $db): void` rejects old/incomplete MySQL schemas. Keep `getDB(): PDO` unchanged for consumers.

- [ ] **1. Write failing tests.** `test_missing_mysql_settings_fail_closed`: no configuration yields a safe error, not a SQLite filename. `test_env_overrides_private_settings` checks explicit environment precedence, including literal `"0"` values. `test_dsn_injection_and_invalid_port_rejected` rejects semicolons/control characters in connection identifiers and ports outside 1–65535. `test_no_secrets_in_error` checks a sentinel password never appears in response/log output. `test_legacy_config_is_not_runtime_authority` precreates an old excluded config and confirms shipped entrypoints still use the new bootstrap. `test_http_cannot_enable_sqlite` submits headers/query parameters named like test flags and asserts no SQLite file appears.

  Minimum positive assertions for the synthetic valid configuration:
  ```php
  $settings = schoolDatabaseSettings($environment, $privateSettings);
  if ($settings['driver'] !== 'mysql' || $settings['port'] !== 3306 || $settings['charset'] !== 'utf8mb4') throw new RuntimeException('Incorrect MySQL defaults');
  if ($settings['isolated_test'] !== false) throw new RuntimeException('Normal operation enabled test storage');
  ```
- [ ] **2. Run RED.** `php tests/database_settings_test.php` and `python tests/database_runtime_http_test.py`; expect failures for missing new configuration/readiness interfaces, not fixture syntax errors.
- [ ] **3. Implement configuration/connection.** Require MySQL by default, use `utf8mb4`, port `3306`, native prepares and PDO exception/associative-fetch options; initialize each MySQL session to UTC so imported SQLite timestamp text reads consistently, while PHP/BS user-facing dates retain Asia/Kathmandu behavior. Validate DSN components rather than concatenate unchecked values. The private file returns an array and contains no placeholder credentials at runtime. Copy non-database application settings into the shipped bootstrap; `config/config.php` becomes a compatibility shim in source, while entrypoints stop depending on that excluded filename. Keep the FTP protection for old/private configuration. A failed connection returns a generic actionable 503 and a sanitized diagnostic code, never the DSN/password/raw exception or a chained exception containing connection arguments. Do not connect simply to render login until its existing workflow calls `getDB()`.
- [ ] **4. Isolate SQLite and upgrades.** Move existing SQLite connection/seed/backup behavior into the offline adapter. It requires process environment `ERP_RUNTIME=test` and `ERP_TEST_SQLITE=1`, an explicit path inside an owned system-temporary directory, and PHP CLI or loopback CLI-server; request input cannot supply these. Production lacks the adapter and never falls back. Update fixture launchers to set these flags explicitly. Normal MySQL connects verify version 18 without calling `migrateSchoolSchema`/`migrateSchoolExtensions`; explicit migration tooling uses `openSchoolPdo` before readiness. Initial readiness checks require the version and core tables; Task 3 supplies the complete structural manifest before final delivery.
- [ ] **5. Run GREEN and commit.** Run both RED commands, `php tests/security_test.php`, `php tests/workflow_test.php` and `python tests/routes_http_test.py`. All must pass; schema mutation is confined to disposable SQLite fixtures. Stage only this task's enumerated paths and commit `Require explicit MySQL configuration for normal school operation`.

## Task 2: Consistent snapshot and private manifest

**Files:** Create `tools/mysql_migration/__init__.py`, `tools/mysql_migration/snapshot.py`, `tests/mysql_snapshot_test.py`; begin `tools/export_mysql.py` with snapshot/preflight-only orchestration. Modify `.gitignore` for private exports/settings.

**Interfaces:** `capture_snapshot(source: Path, output_dir: Path) -> Snapshot`; `inspect_database(path: Path) -> dict`; `source_matches(snapshot: Snapshot) -> bool`. `Snapshot` exposes `path`, `source_path`, `manifest`, `source_manifest`, `source_file_sha256` and `snapshot_sha256`.

- [ ] **1. Write failing unit tests.** `test_wal_commits_are_captured` holds a WAL writer open and verifies committed text is in the snapshot. `test_source_drift_is_reported` commits another row after capture and expects `source_matches` false. `test_private_path_policy` rejects an output under either application checkout, symlink/junction aliases into it, the source's directory, or an already existing output directory. `test_manifest_preserves_duplicate_rows` checks a no-PK table with two identical rows retains count 2 and a different digest from count 1. Confirm source reads use `mode=ro` and cannot create an absent source.

  ```python
  self.assertEqual(snapshot.manifest['tables']['events']['row_count'], 2)
  self.assertFalse(source_matches(snapshot))  # after the second writer commits
  self.assertEqual(original_digest_after, original_digest_before)
  ```
- [ ] **2. Run RED.** `python -m unittest discover -s tests -p mysql_snapshot_test.py -v`; expect missing snapshot implementation.
- [ ] **3. Implement snapshot/inventory.** Use the SQLite backup API, not filesystem copying. Enumerate all user tables, columns/defaults, primary/unique keys, indexes, foreign keys, sequences, views/triggers and supported schema version. Use bounded reads and canonical digests without printing values. Abort on unsupported views/triggers/generated constructs with a private report; preserve the input. Capture physical hashes and logical manifests; drift checks compare logical data/metadata even if the main SQLite file hash is unchanged because of WAL.
- [ ] **4. Run GREEN.** Repeat the unit command; assert the source's logical digest, schema version and existing rows are unchanged. Verify `git check-ignore` covers `config/database.local.php` and representative generated SQL/gzip/manifest/report filenames without hiding maintained `config/data` JSON or tracked schema fixtures.
- [ ] **5. Commit.** Stage the new converter/snapshot tests and ignore rules; commit `Capture private consistent snapshots for MySQL conversion`.

## Task 3: Current-schema projection without historical data rewrites

**Files:** Create `tools/mysql_schema_blueprint.php`, `tools/mysql_migration/projection.py`, `tests/mysql_projection_test.py`, `config/data/mysql_schema.json`; use existing schema helpers read-only as structural references, not as an unreviewed upgrade of real records. Extend `includes/database_readiness.php` to consume the manifest. Modify `config/migrate.php` and `config/migrate_multi_tenant.php` for explicit, CLI-only upgrade/readiness behavior.

**Interfaces:** `build_blueprint(php_binary: str, directory: Path) -> Path` runs the CLI helper with `--action blueprint --database <owned-temp-sqlite>`. `project_snapshot(snapshot: Snapshot, blueprint: Path, output: Path) -> Projection` returns `path`, `manifest`, `original_manifest`, `additions` and `known_anomalies`. The helper's `--action metadata --database <owned-temp-sqlite>` opens raw PDO without invoking application startup/upgrades and applies only the explicitly allowed metadata provisioning. The blueprint is a temporary latest-version schema; its seed rows are never copied into the owner's data. Projection processing is also in an owned system-temporary directory; the private export directory retains the raw recovery snapshot and final deliverables, not a normal application database.

- [ ] **1. Write failing tests.** Build a synthetic schema-7 copy with a `Compulsory Nepali` name, a stored password hash, private HTML/TeX, high sequence values and 50 orphan marks. Assert every original cell remains unchanged after projection except `app_meta.workflow_schema`, all original row IDs remain, the 50 marks remain, and no student/exam/invoice/journal is fabricated. Assert version 18 structural requirements are present and a second projection produces no new changes. Unknown extra tables/columns remain present or cause an explicit unsupported-schema error, never disappear.

  ```python
  self.assertEqual(after_original_columns['subjects'], before_original_columns['subjects'])
  self.assertEqual(after_original_columns['marks'], before_original_columns['marks'])
  self.assertEqual(projected_counts['marks'], 50)
  self.assertEqual(projected_counts['students'], 0)
  self.assertEqual(projected_counts['exams'], 0)
  self.assertEqual(projected_schema_version, '18')
  ```
- [ ] **2. Run RED.** `python -m unittest discover -s tests -p mysql_projection_test.py -v`; expect missing blueprint/projection functions.
- [ ] **3. Implement the projection.** Generate the latest schema only in an owned empty fixture using Task 1's test adapter. Compare its structure with the raw snapshot and add required tables/columns/indexes to a separate working database, never by calling legacy data-rewriting migrations on original rows. Preserve original declarations/data and record all added defaults. Legacy question/note visibility, if absent, gets a documented same-school legacy read scope; creator stays NULL, new item defaults remain private, `bank_kind` remains learning and exam student release stays off. Add only required per-existing-school accounting control/settings metadata and missing default exam-template metadata through the existing idempotent helpers; audit these as new metadata rows, not money or demo users. Do not run canonical-subject renames/shared-catalog relinking or guess missing references. Set schema version 18 only after full structure/preservation checks pass.
- [ ] **4. Run GREEN and verify readiness.** Run the unit command and `php tests/database_settings_test.php`. Generate a deterministic requirements-only `config/data/mysql_schema.json`; readiness checks its required tables/columns using one batched MySQL metadata query per connection, not 70 sequential per-table requests. Metadata changes do not substitute for row validation. CLI migrate checks/dry-run are explicit; on an unsupported older schema they direct the operator to the offline converter instead of silently normalizing it.
- [ ] **5. Commit.** Commit only projection/schema-manifest/readiness/CLI/test changes as `Project legacy school data into the current schema without rewrites`.

## Task 4: Portable SQL generation

**Files:** Create `tools/mysql_migration/sql_writer.py`, `tests/mysql_sql_writer_test.py`; extend `tools/export_mysql.py`.

**Interfaces:** `write_mysql_dump(projection: Projection, destination: Path, max_statement_bytes: int = 1048576) -> dict` returns keys `sha256`, `bytes`, `table_count`, `row_counts` and `schema_mappings`. It never connects to hosting. SQL settings are session-scoped and saved/restored.

- [ ] **1. Write failing tests.** Pin `test_nepali_tex_quotes_null_and_zero`, `test_large_rich_text_is_not_truncated`, `test_foreign_key_column_types_match`, `test_numeric_scale_is_not_silently_rounded`, `test_sequences_exceed_existing_max`, `test_indexed_text_or_collation_collision_is_reported` and `test_dump_contains_no_destructive_or_privileged_sql`. Include a no-PK duplicate table, composite keys, signed/unsigned-range edge values and explicit ID 0.

  ```python
  self.assertEqual(result['row_counts']['marks'], 50)
  self.assertEqual(result['row_counts']['duplicate_rows'], 2)
  self.assertNotIn('DROP TABLE', sql)
  self.assertNotIn('SET GLOBAL', sql)
  self.assertLessEqual(largest_statement_bytes, 1048576)
  ```
- [ ] **2. Run RED.** `python -m unittest discover -s tests -p mysql_sql_writer_test.py -v`; expect writer missing, then assertion failures until mappings are implemented.
- [ ] **3. Implement schema/value encoding.** Introspect metadata rather than regex-replacing SQLite SQL. Map related integer/FK columns consistently, choose adequate text/decimal capacity from declarations plus observed values, preserve binary fields and reject unrepresentable values. Use InnoDB/`utf8mb4_bin`, verifying its presence during local MySQL tests; report trailing-space/uniqueness collisions rather than collapse them. Emit text as `CONVERT(X'hex' USING utf8mb4)` and binary as `X'hex'`, so SQL quoting cannot damage HTML/TeX or execute payloads. Preserve NULL and numeric types distinctly. Append `NO_AUTO_VALUE_ON_ZERO` to existing session SQL modes without disabling strict modes; set session UTF-8/UTC timestamp handling and import-only FK checks explicitly, restoring prior session settings afterward. Emit next-ID values at least as high as SQLite's recorded sequence plus one. Do not use DROP/TRUNCATE/REPLACE/upsert/CREATE DATABASE/DEFINER/SET GLOBAL. Stop with a private report if one row cannot fit the configured statement limit.
- [ ] **4. Run GREEN.** Run snapshot, projection and writer tests; statically assert every table's CREATE precedes its INSERTs, all source rows are emitted, statements are bounded and sessions restore settings. Only a subsequent real import proves compatibility.
- [ ] **5. Commit.** Commit writer/orchestrator/tests as `Export preserved school snapshots as portable MySQL SQL`.

## Task 5: Real isolated MySQL import and preservation verification

**Files:** Create `tools/mysql_test_server.ps1`, `tools/mysql_migration/verify_mysql.php`, `tools/mysql_migration/verify.py`, `tests/mysql_import_test.py`, `tests/mysql_test_server_test.py`.

**Interfaces:** The server helper accepts `-Action Start|Stop -StateDirectory <owned-temp-path>` and writes private state/connection settings. `verify_mysql.php --action import|inspect --package <path>` reads connection settings from process environment/private files. `verify_import(snapshot: Snapshot, projection: Projection, target_manifest: dict) -> dict` returns keys `preserved` (bool), `known_orphans` (int), `new_violations` (int), `engine` (version details) and private comparison details, without dumping rows to the terminal.

- [ ] **1. Write failing integration tests.** `test_native_mysql_round_trip` checks every original/projected value and retained sequence. `test_known_orphans_are_not_deleted_or_repaired` expects exactly the same 50 synthetic mark/student violations and zero new ones. `test_nonempty_target_refused_before_writes` places an unrelated sentinel table/row in a target and verifies both stay unchanged. `test_partial_import_target_is_not_reused` stops after DDL and confirms a retry refuses that database. `test_failure_does_not_open_sqlite` uses a wrong synthetic password and checks no local SQLite file exists.

  ```python
  self.assertTrue(report['preserved'])
  self.assertEqual(report['known_orphans'], 50)
  self.assertEqual(report['new_violations'], 0)
  self.assertEqual(nonempty_target_after, nonempty_target_before)
  ```
- [ ] **2. Provision only an isolated test runtime and run RED.** Reuse a suitable local MySQL binary if found; otherwise obtain a portable MySQL 8.4 LTS distribution from the official provider and record its exact version/source/hash. Bind to `127.0.0.1` on an available nonstandard port, create a unique OS-temporary data directory, launch helpers with `-WindowStyle Hidden`, and use private/random credentials without command-line password arguments. No Windows service or existing databases are modified. Run `python -m unittest discover -s tests -p mysql_import_test.py -v`; expect missing verification bridge or explicit connection-gate failure, not a misleading skip/pass.
- [ ] **3. Implement import/readback.** Require CLI, a loopback host and a test-owned database name beginning `erp_migration_test_`; reject all other targets in these tools. Check the target has no tables/views before any import statement. Verify the package SQL hash and generated statement set against its manifest before execution; do not accept unrelated SQL/USE/destructive instructions. Read and execute the generated bounded statements, fail on every storage warning/error, and never auto-delete partial targets. Read back canonical manifests via PDO with native prepares and fixed UTC session handling. Query relationships explicitly: MySQL does not retroactively validate rows when FK checks are re-enabled. Verify future invalid FK writes are rejected. Compare all original columns/rows plus projected defaults/new metadata separately; report known source anomalies without declaring historical integrity repaired.
- [ ] **4. Run GREEN.** Run snapshot/projection/writer/import/server tests; assert MySQL row digests, money, passwords, Unicode, zero IDs, timestamps and next sequences match. Run readiness twice and compare table/row digests before/after to prove no automatic writes. Server Stop terminates only its recorded owned process; validate absolute targets before any cleanup and retain private reports on failure.
- [ ] **5. Commit.** Commit local tooling/tests as `Verify MySQL imports against preserved source manifests`; never stage the data directory, credentials or generated packages.

## Task 6: MySQL school-workflow parity and query fixes

**Files:** Create `tests/mysql_workflow_fixture.php`, `tests/mysql_workflow_http_test.py`, `tests/mysql_accounting_concurrency_test.py`. Modify only MySQL-reproduced portability sites in `includes/accounting_service.php`, `includes/accounting_ledger.php`, `includes/learning_content.php`, `includes/learning_content_service.php`, `includes/learning_catalog.php`, `includes/attendance_service.php`, `includes/student_search.php`, `includes/question_correction_service.php`, `modules/results/marks_entry.php`, `modules/results/ledger.php`, `modules/students/index.php` and their existing focused tests; if a failure is elsewhere, record its exact path/reproduction before expanding this list.

**Interfaces:** `mysql_workflow_fixture.php` consumes a fresh test-owned MySQL database and emits synthetic IDs plus a fixture base URL. It must never seed or log in as an owner from the real imported package. Existing PHP service signatures and authorization rules remain unchanged.

- [ ] **1. Write/run RED workflows on synthetic MySQL.** Cover login/expiry, super-admin preview read-only, school isolation, subject-teacher versus class-teacher rights, restricted students, exact Class 1 versus Classes 10/11/12, BS/AD attendance, TH/IN/optional marks, lock/reopen, symbol numbers, GPA/percentage and print-document responses. Add normal/HTML bilingual imports, both question banks, shared/private content, correction privacy and exam release. Fail on SQL errors or unauthorized writes; do not loosen assertions or grants to make MySQL pass.
- [ ] **2. Add accounting/concurrency RED tests.** Use the existing synthetic Rs. 3,000 invoice and payment replay pattern. Two independent PDO connections/processes submitting the same idempotency key must yield one payment/journal and balanced reconciliation; changed replay data and cross-school allocations must fail without writes. Test receipt/credit/refund dates and exact integer-paisa balances.

  ```python
  assert invoice_total_minor == 300000
  assert payment_count_for_key == 1
  assert journal_count_for_payment == 1
  assert reconciliation['ok'] is True
  assert other_school_after == other_school_before
  ```
- [ ] **3. Implement only reproduced portability fixes.** Keep `ATTR_EMULATE_PREPARES=false`. Correct duplicate named placeholders, integer LIMIT/OFFSET binding, strict GROUP BY behavior, reserved identifiers, date expressions and engine-specific insert/locking semantics at the failing sites. Never suppress strict SQL modes globally, change financial formulas, weaken tenant/role/exam locks or rewrite stored school data.
- [ ] **4. Run GREEN.** `python tests/mysql_workflow_http_test.py` and `python tests/mysql_accounting_concurrency_test.py` must pass on the local MySQL server. Repeat the corresponding existing SQLite/domain tests for each touched service. Confirm synthetic tests used a different database than the imported real-data preservation test.
- [ ] **5. Commit.** Commit fixture/reproduced query fixes/tests as `Verify school and accounting workflows on native MySQL`.

## Task 7: Safe cleanup, deployment boundaries and operator instructions

**Files:** Modify `.gitignore`, `.github/workflows/deploy.yml`, `.htaccess`, `router.php`, `tests/deployment_test.py`, `tests/database_runtime_http_test.py`, `README.md`, `INSTALL.md`, `docs/frontend-modernization.md`; create `docs/mysql-migration.md`. Relocate `database_sqlite.sql` to `tests/fixtures/database_sqlite.sql` and `database.sql` to `tests/fixtures/legacy_mysql.sql` only after all code references have been updated and the converter no longer needs either root path.

**Interfaces:** A production file manifest includes only PHP/static/runtime reference data; converter/tests/templates/private packages are not served. The public bootstrap ships; `config/config.php`, `config/database.local.php` and `config/*.local.php` stay protected/excluded. The compatibility wrapper may remain in source for old CLI callers, but no maintained runtime entrypoint relies on it.

- [ ] **1. Write failing safety tests.** Assert source/export `*.sql.gz`/private manifests/config are blocked/excluded, the new bootstrap and compiled assets still ship, all runtime references use the shipped bootstrap, and direct HTTP requests cannot enable the test adapter or read migration output. Keep all existing upload/database/backup exclusions and serialized non-destructive FTP checks.

  ```python
  assert '**/*.sql.gz' in patterns
  assert 'config/database.local.php' in patterns
  assert 'config/bootstrap.php' not in patterns
  assert 'assets/dist/**' not in patterns
  assert normal_runtime_sqlite_files == []
  ```
- [ ] **2. Run RED.** `python tests/deployment_test.py` and `python tests/database_runtime_http_test.py`; failures must identify missing new protections or old bootstrap/schema paths.
- [ ] **3. Implement focused cleanup and docs.** Use `rg` to prove obsolete root schema paths have no remaining runtime consumers before relocation. Preserve history with tracked moves; do not delete the owner's database/uploads/documents or any unrelated dirty files. Exclude seed SQL and offline migration/test tooling from hosting, while ensuring maintained structural JSON remains available internally and inaccessible over HTTP. Add phpMyAdmin steps: create/select a new empty database; import `school_erp_mysql.sql`/gzip; inspect errors/counts; create private settings using the host/user/database from InfinityFree; keep password out of chat/Git; verify normal login. Explain that SQL excludes uploaded files, a local app cannot directly use the free hosted MySQL endpoint, and the original SQLite is now a retained backup rather than normal runtime storage.
- [ ] **4. Run GREEN.** Repeat RED commands plus routes/security/deployment tests. Show a precise removed/relocated path list with Git recovery instructions. Keep ambiguous cleanup candidates unchanged. The production FTP job is not run by these checks.
- [ ] **5. Commit.** Commit cleanup/protection/docs changes as `Document MySQL cutover and remove obsolete runtime SQLite artifacts`.

## Task 8: Final source export, whole-branch verification and local handoff

**Files:** Finish `tools/export_mysql.py`, update `tools/verify-release.py` to discover `tests/mysql_*_test.py` without implicitly exporting real data, and write verification evidence to ignored/private output. Add a short data-free handoff to `docs/mysql-migration.md`.

**Interfaces:** `python tools/export_mysql.py --source <absolute-sqlite-path> --output <new-private-directory> --verify-local-mysql` orchestrates Tasks 2–5. Verification credentials come from a private settings file/environment, never command arguments or the report. A generated package is labelled ready only after full preservation verification and a no-drift source check.

- [ ] **1. Write the final orchestration test.** `tests/mysql_package_test.py` asserts raw snapshot, SQL, gzip, manifest and report are emitted to a new private directory; no data is printed; any drift/error yields nonzero exit and a not-ready report; reusing the directory or nonempty verification target fails without overwrites.

  ```python
  self.assertNotEqual(drift_result.returncode, 0)
  self.assertFalse(drift_report['ready'])
  self.assertNotIn(sentinel_password_hash, command_stdout)
  self.assertEqual(original_source_digest_after, original_source_digest_before)
  ```
- [ ] **2. Run RED/GREEN on synthetic input.** `python -m unittest discover -s tests -p mysql_package_test.py -v`; implement CLI orchestration, private permissions, sanitized output, complete report and explicit known-orphan warning until it passes.
- [ ] **3. Run the full non-production gate.** `npm ci --no-fund`, `npm run build`, `npm run test:ui`, and `python tools/verify-release.py --upgrade-source 'D:/A WebSite/Nepal School Management System/database.sqlite'`. The upgrade-source check uses a consistent copy only. Run all new Python unit/import/workflow/concurrency commands explicitly and require zero failures. Run PHP lint, `git diff --check`, secret scans and protected-path staging checks. Do not substitute prior 342/342 or SQLite-only results for this run. Record any unavailable browser/host check as unverified.
- [ ] **4. Export the actual owner data read-only.** Reinspect the source and its current logical/physical fingerprint, use a new private directory and fresh empty disposable MySQL target, run the CLI above, and verify every source row/column plus projected additions. At the current baseline the report must retain 50 marks and no invented students/exams; if the source now differs, use/report that new snapshot instead. Retain the original source and the private raw snapshot. Include SQL/gzip hashes, actual MySQL engine/version, table/row comparisons, known anomalies, configuration instructions and no-live-deployment status. Do not claim readiness if source drift or preservation checks fail.
- [ ] **5. Review, commit code only and hand off.** Use the approved execution approach's final review and the verification-before-completion/finishing skills; fix findings with regression tests. Any reviewer receives code and synthetic evidence, not confidential owner exports or production credentials. Commit only code/docs/tests and confirm the linked worktree is clean. Provide the private SQL/package path and operator instructions. No push/merge/FTP/live import occurs under this plan; those need later explicit authorization and a live recovery backup.

## Appendix A: Bootstrap-consumer manifest

The following files currently require the excluded `config/config.php` path and
must use `config/bootstrap.php` with the same relative depth. Change only the
configuration require, not unrelated controller code. Check for further relative
`__DIR__.'/config.php'` CLI references using `rg` before execution.

```text
app.php
index.php
includes/accounting_ui.php
includes/exam_question_page.php
includes/learning_test_paper_page.php
includes/question_correction_page.php
modules/attendance/index.php
modules/auth/change_password.php
modules/auth/login.php
modules/auth/logout.php
modules/auth/profile.php
modules/auth/verify_password.php
modules/classes/add.php
modules/classes/edit.php
modules/classes/index.php
modules/classes/routine.php
modules/classes/subjects.php
modules/exams/add.php
modules/exams/admit_cards.php
modules/exams/edit.php
modules/exams/index.php
modules/exams/symbols.php
modules/exams/view.php
modules/learning/curriculum.php
modules/learning/exam_paper_generator.php
modules/learning/exam_specification_grids.php
modules/learning/index.php
modules/learning/ocr_scanner.php
modules/learning/paper_generator.php
modules/learning/practice_mcqs.php
modules/learning/question_grid.php
modules/learning/questions.php
modules/learning/specification_grids.php
modules/reports/index.php
modules/results/index.php
modules/results/ledger.php
modules/results/marks_entry.php
modules/results/print_bulk.php
modules/results/report_card.php
modules/settings/cdc.php
modules/settings/school.php
modules/settings/users.php
modules/settings/years.php
modules/students/add.php
modules/students/admit_card.php
modules/students/edit.php
modules/students/import_export.php
modules/students/index.php
modules/students/view.php
modules/subjects/add.php
modules/subjects/edit.php
modules/subjects/index.php
modules/subjects/internal.php
modules/super_admin/exam_templates.php
modules/super_admin/index.php
modules/super_admin/schools.php
modules/super_admin/subjects.php
modules/super_admin/switch_school.php
modules/teachers/add.php
modules/teachers/edit.php
modules/teachers/import_export.php
modules/teachers/index.php
tests/cdc_2083_test.php
tests/http_fixture.php
tests/learning_sharing_fixture.php
tests/question_correction_worker.php
tests/schema7_fixture.php
tests/test_support.php
tests/upgrade_test.php
tests/workflow_test.php
```

Also change the local-relative require in `config/accounting_job.php`,
`config/import_cdc_2083.php`, `config/migrate.php`,
`config/migrate_multi_tenant.php`, `config/seed_cdc_curriculum.php` and
`config/setup_admin.php`. Fresh fixture bootstrap references in generated test
scripts must follow the same rule. A source scan at completion must leave only
the intentional compatibility shim/documented legacy references.

## References and plan review

- [PHP PDO prepare: native placeholder rules](https://www.php.net/PDO.prepare).
- [MySQL foreign-key constraints and non-retroactive check enabling](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html).
- Self-review: each spec section maps to Tasks 1–8; all five Review Focus cases
  have named tests; shared Snapshot/Projection interfaces are consistent; there
  are no delegated task prerequisites or production credentials in this plan.
- Owner's written-spec approval is recorded. This plan still requires review and
  approval plus an execution choice. Native is recommended because the eight
  tasks share one schema/configuration boundary and touch confidential exports;
  keep one implementer responsible for the migration state, then perform the
  approved final independent review.
