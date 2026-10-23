# Private MySQL migration guide

The application now requires a configured MySQL database for normal requests. SQLite appears only in isolated automated fixtures and the one-time offline converter. The web app never opens, creates, or falls back to a local SQLite file.

## Create a verified local package

Run the converter in a maintenance window so the source database is not being edited during the final snapshot. The command reads the SQLite source read-only and creates a new private folder outside the repository:

```powershell
python tools/export_mysql.py `
  --source 'D:\A WebSite\Nepal School Management System\database.sqlite' `
  --output 'C:\Users\SGHACKER\Downloads\School-ERP-MySQL-Migration\2026-10-17-final' `
  --verify-local-mysql
```

The output path must be new. `--verify-local-mysql` requires environment settings for a **new empty disposable loopback test database** whose name begins `erp_migration_test_`; the verification bridge rejects other hosts and target names. The conversion stops if the output exists, the MySQL target is not empty, the source changes, or any source/projected/target digest differs. A failed or partial MySQL target is retained for diagnosis and must not be reused.

The private package contains a consistent SQLite recovery snapshot, the MySQL SQL dump and gzip copy, controlled statement list, source/projected manifests, a concise migration report, and verification hashes/counts. It may contain school names, records and password hashes. Keep the whole package outside Git, FTP uploads and the web root. Uploads and other user files are not in the SQL package.

Local verification currently uses MySQL Community 8.0.45. This proves the package against that local server, not against the InfinityFree host version or production credentials. The configured MySQL80 Windows service is not needed by the migration helper; the helper launches and stops a separate loopback process with its own temporary data directory.

The final local gate passed 355/355 verification commands (233 PHP lint checks), with 61/61 UI tests and the production asset build passing. Native MySQL checks include real HTTP school creation, authentication/session timeout, tenant and teacher scope, exact class filters, AD/BS attendance, bilingual imports, practice, correction privacy, accounting and print responses. Two independent payment processes must produce exactly one payment and journal. Regressions also verify future fractional marks in legacy integer columns and source changes during final gzip packaging. The private report records the actual snapshot and SQL hashes; it must say `ready: true` before using an export.

## Import with phpMyAdmin

1. In the InfinityFree control panel, create a new empty database and note its exact database name, user and host. The free plan accepts database connections from the hosted PHP app and phpMyAdmin; a desktop/local app cannot connect directly to that hosted database endpoint.
2. Open that database in phpMyAdmin and use **Import** to load `school_erp_mysql.sql.gz` (or `school_erp_mysql.sql`). Never select an existing database with tables. The export does not issue `CREATE DATABASE`, `DROP`, `TRUNCATE`, or overwrite statements.
3. Confirm that phpMyAdmin reports success. If import stops part way, do not retry into that database: create a fresh empty destination and diagnose the error first.
4. On the host, create the private ignored file `config/database.local.php` from `config/database.local.example.php`. Fill only the host-provided MySQL values. Never commit, publish or share this file/password.
5. Keep an original SQLite backup and the verified package privately until the site is checked. Copy `uploads/` separately if logo/signature/document files must accompany the data.
6. Open the site, sign in with an existing account, and compare school, class, subject, student, teacher, exam, mark and accounting data. Verify representative report/admit/ledger prints and confirm the uploaded files separately.

This repository task does not import into InfinityFree or change live traffic. Live import, backup, credential setup and production verification need a separately approved maintenance window. Do not discard any live records that are not present in the local SQLite source snapshot.

## Preserved historical issue

The starting local database has 50 marks rows whose student relationships are missing. The export preserves those rows exactly, but does not invent students or repair the relationships. The report flags them as historical anomalies. New invalid FK writes are rejected after import.
