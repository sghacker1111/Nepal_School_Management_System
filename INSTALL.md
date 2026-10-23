# Installation and database migration

## Requirements

- PHP 8.1+ with PDO MySQL, DOM, fileinfo and mbstring.
- A MySQL database already created by the database host or phpMyAdmin.
- HTTPS and a web server that blocks private source/configuration and SQL files; Apache can use the supplied `.htaccess`.
- Node.js only for local/CI asset builds, not for the PHP hosting runtime.

Normal application requests require MySQL. SQLite is retained only in explicit isolated regression fixtures and the offline migration tool; it is not a runtime fallback or fresh-install database.

## Configure MySQL

Use environment variables or copy `config/database.local.example.php` to the ignored, private `config/database.local.php` and fill it on the server:

```php
return [
    'driver' => 'mysql',
    'host' => 'your-host-provided-mysql-host',
    'port' => 3306,
    'database' => 'your_database_name',
    'username' => 'your_database_user',
    'password' => 'your_database_password',
];
```

Keep the private file outside Git and never paste its password into chat. Environment overrides are `ERP_DB_HOST`, `ERP_DB_PORT`, `ERP_DB_NAME`, `ERP_DB_USER` and `ERP_DB_PASS`; `ERP_DB_TYPE` must be `mysql`. A failed connection stops with a safe setup error and never creates or opens `database.sqlite`.

## Import a prepared school database

1. In phpMyAdmin, create a **new empty** database using `utf8mb4` where available, select it, and import the private `school_erp_mysql.sql` (or its `.gz` copy) from the migration package. Do not use the legacy starter SQL file, and never import over a database with tables.
2. Review phpMyAdmin's import result. A failed/partial import is not reusable: create another empty database and repeat only after resolving the reported issue.
3. Put the private MySQL settings beside the deployed application as described above. On InfinityFree, use the host, database and username displayed in its control panel; desktop/local applications cannot connect directly to the free hosted database endpoint.
4. Keep the original SQLite database, SQL package and snapshot as private backups. Uploaded signatures/logos/documents are separate files and are not embedded in SQL; preserve and transfer `uploads/` separately if those files are needed.
5. Sign in and verify school, staff, students, exams, marks, questions and accounting totals before normal use.

The SQL import is only ready after the offline tool has verified every table and row against a private local MySQL copy. Local verification does not certify InfinityFree's server engine. Test the actual host import and connection in a separately approved maintenance window before switching production traffic.

## Fresh installation

Create an empty MySQL database and import the current deployment schema package supplied for that installation. Configure the private connection, then run `php config/setup_admin.php` with a temporary private `ERP_ADMIN_PASSWORD` of at least 12 characters. Remove the temporary value afterward. The setup command refuses to reset an existing administrator password. There is no default password and no automatic schema/data seeding on web requests.

## Existing MySQL installation

Back up MySQL, uploads and private settings before a release. Apply documented additive migrations through the approved maintenance procedure, verify retained table counts and school workflows on a restored staging copy, and keep the backup until staff confirm results. Do not use a starter schema as an upgrade, and do not overwrite a nonempty destination.

## Web-server restrictions

Keep `.htaccess` active on Apache. It denies database dumps, gzip SQL files, private manifests, configuration, development/source directories and executable uploads. For Nginx or hosts that ignore `.htaccess`, configure equivalent rules. Confirm the database file, `.sql`, `.sql.gz`, config files, migration manifests, tests, docs, tools, Git metadata and backups return 404/403. The FTP release excludes databases, uploads and private settings and does not use clean-slate deletion.

## Everyday operation and troubleshooting

- Create and activate the academic year before enrollment; assign class/subject teachers before marks.
- Reopen a locked exam explicitly before correcting saved marks; review and lock it again afterward.
- If MySQL connection/schema readiness fails, check the host-provided values and complete import; do not switch the app to SQLite.
- Upload failures may require checking PHP upload limits and `uploads/` write access. Local server-path uploads are disabled.
- Blank pages should be diagnosed from private server logs; enable debug only in an isolated test environment.

## Verification and release

Run the UI build/tests and the PHP regression suite in the local development environment. Use only disposable MySQL instances for native MySQL checks. A push to `main` triggers the existing FTP workflow; review the precise diff and exclusions before publishing. Production deployment and live-data import are separate actions and are not part of a local database conversion.

See [the MySQL migration operator guide](docs/mysql-migration.md) and [frontend build notes](docs/frontend-modernization.md).
