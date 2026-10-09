# Installation and upgrade

## Requirements

- PHP 8.1+; tested locally on PHP 8.5.1.
- PDO SQLite (default), or PDO MySQL with an existing imported `database.sql` schema.
- DOM, fileinfo, mbstring; writable PHP session directory.
- For production, HTTPS and Apache with rewrite/access-control support. Other servers must provide equivalent private-file and upload-execution restrictions.
- The UI and scanner use external CDN libraries. Offline operation and OCR accuracy are not guaranteed.

## Configuration

Set environment values in your hosting control panel or server service, not in committed files:

| Variable | Meaning |
| --- | --- |
| ERP_DB_TYPE | sqlite (default) or mysql |
| ERP_DB_PATH | Absolute SQLite filename; use a directory outside the public web root in production |
| ERP_DB_HOST | MySQL host |
| ERP_DB_NAME | MySQL database name |
| ERP_DB_USER | Dedicated MySQL user |
| ERP_DB_PASS | MySQL password |
| ERP_DEBUG | 1 only for isolated development; omitted/0 in production |

The PHP process needs write access only to uploads, the SQLite directory (including WAL and backups), and its session directory. Do not grant Everyone full control or use world-writable 777 permissions.

A MySQL connection failure does not fall back to SQLite.

## Fresh install

1. Configure the database. SQLite creates its schema automatically; import `database.sql` into an empty MySQL database only.
2. Set a private `ERP_ADMIN_PASSWORD` environment value, at least 12 characters.
3. Run `php config/setup_admin.php` from the project directory.
4. Remove `ERP_ADMIN_PASSWORD` from the environment.
5. Start `php -S 127.0.0.1:8000 router.php` for local development or configure the production server.
6. Sign in as `admin` with your private password.
7. In User management, create a school administrator for your school, then sign in with that account to configure records. System-school preview is read-only.

The setup command refuses to change an already configured account. There is no new-install default password.

## Existing installation upgrade

1. Put the site into a maintenance window. Save a recoverable database and uploads backup.
2. Work on a staging **copy** first. Set its `ERP_DB_PATH` or MySQL connection explicitly.
3. Run `php config/migrate.php`. The legacy `migrate_multi_tenant.php` now calls the same additive upgrade.
4. Confirm the retained student, mark, user, attendance and exam counts. Review school/class ownership.
5. Test sign-in, academic years, enrollment, marks entry, report cards and publication with school staff.
6. Repeat the approved upgrade in production during the maintenance window.

SQLite existing databases get a dated pre-upgrade snapshot in `.backups` beside the database. Automatic backups do not replace independent off-site backups. MySQL requires your own pre-upgrade backup. DDL and recovery behavior must be verified in MySQL staging; that engine was not available in this local repair run.

The migration adds missing columns, independent school mappings, indexes and curriculum starters without deleting records. Legacy records whose parents are already missing cannot be reconstructed by schema migration. Restore their true relationships from a known-good backup; never guess student identities or grades.

Do not import either base SQL schema over an existing installation.

## Web-server restrictions

Keep the supplied `.htaccess` active on Apache. It blocks private configuration/source directories, SQL/database files, dot directories and executable PHP uploads. Local development must use `router.php`, not a bare PHP server.

For Nginx or hosts that ignore `.htaccess`, configure equivalent restrictions before making the site public. Verify that database.sqlite, database.sql, config/, includes/, tests/, scratch/, docs/, .git/ and .backups/ are inaccessible and that uploaded files cannot execute.

Use HTTPS, production-safe error logging, limited database credentials and restricted upload directory permissions. Enable OPcache using your host's supported configuration.

## Everyday workflow notes

- CSV dates: YYYY-MM-DD preferred. Missing dates remain unknown; invalid rows are skipped.
- Teacher imports create disabled accounts. Set individual passwords and activate through Staff accounts.
- Archive is reversible through the profile/status control; saved marks are not erased.
- Existing exams retain their assessment scheme. Once any marks exist, configuration changes are blocked.
- To correct marks: Reopen marks, correct, review, lock and republish. Reopening withdraws publication.
- Results lists include historical academic years. Marks entry uses the current year's active students.
- Third elective is available only in Grades 11 and 12.
- Timetable generation can leave unplaced periods when available teachers/slots cannot satisfy the requests. Review the reported count.

## Troubleshooting

- Security token expired: reload the page before resubmitting. Check session storage if this repeats.
- Database connection failed: check the selected database and environment values; do not switch engines to hide the error.
- Upload failed: use PNG/JPEG images below 5 MB. Check PHP upload limits and directory permissions. Local server-path uploads are intentionally disabled.
- Blank page: inspect server logs. Set ERP_DEBUG=1 only in an isolated test environment.
- Missing old marks: check the selected exam/year and database integrity. Do not reseed or reimport a base schema.
- No active academic year: create and activate one using the school administrator account.

## Verification and release

Run the commands in README. Do not point test fixtures at a real database. Browser checks should use a temporary fixture database.

Before pushing main, remember that the configured GitHub workflow deploys via FTP. Review the deployment file list, keep secrets/databases/uploads out of source, back up production, and validate CDC configurations with the school. No production deployment was performed by this repair.
