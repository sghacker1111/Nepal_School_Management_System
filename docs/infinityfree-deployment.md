# Nepal ERP: InfinityFree deployment

Repository: https://github.com/sghacker1111/Nepal_ERP

New application website: https://school.freedev.app/

The `Validate and deploy Nepal ERP to InfinityFree` workflow validates every push to `main`. FTP stays disabled until the repository variable `FTP_DEPLOY_ENABLED` is `true`, or an owner manually runs the workflow from `main` with **deploy** selected. The first publication validates the code only.

## One-time repository settings

Open **Settings → Secrets and variables → Actions → Secrets** and add these repository secrets (or environment secrets in `infinityfree-production`):

| Name | Value |
| --- | --- |
| `FTP_SERVER` | FTP hostname from the InfinityFree account, normally `ftpupload.net` for its TLS certificate |
| `FTP_USERNAME` | InfinityFree hosting FTP username |
| `FTP_PASSWORD` | InfinityFree FTP password; this is not the MySQL password |
| `FTP_SERVER_DIR` | Exact folder for the new website, for example `your-new-domain.example/htdocs/` or `htdocs/`, with a trailing slash |

GitHub secrets belong to each repository. A secret saved in the old repository is not automatically available in this repository, and GitHub does not allow reading its original value for copying. Enter it directly in GitHub, never in source files, commits, chat or workflow logs.

The repository variables are configured as:

| Name | Initial value |
| --- | --- |
| `FTP_DEPLOY_ENABLED` | `false` |

The workflow uses the `FTP_SERVER_DIR` secret for both validation and FTP transfer. It accepts an explicit website folder ending in `htdocs/` and refuses a filesystem root, URL or parent traversal. It uses explicit FTPS on port 21, serializes transfers, and leaves destructive clean-slate mode disabled. The old website's folder is not a fallback destination.

## Prepare the hosted MySQL application

1. Retain a recovery backup of the existing site's database, uploads and private configuration before replacing the hosted application.
2. Import the privately verified `school_erp_mysql.sql.gz` into a **new empty MySQL database** using phpMyAdmin; see [the migration guide](mysql-migration.md). Confirm the import result before activation.
3. Create `<your-new-website-folder>/htdocs/config/database.local.php` manually from `config/database.local.example.php`, using the imported database's exact MySQL hostname, database, username and password. FTP deployment deliberately excludes this private file.
4. Keep any live records that differ from the local SQLite snapshot; this code deployment does not merge databases. Transfer/preserve `uploads/` separately.

## Deploy and verify

In **Actions → Validate and deploy Nepal ERP to InfinityFree → Run workflow**, select **main**, tick **deploy**, then run. Validation must succeed before the FTP job starts. After the first verified deployment, set `FTP_DEPLOY_ENABLED=true` if automatic deployments on later `main` pushes are wanted.

The workflow transfers application files and compiled frontend assets. Databases, private configuration, uploads, backups, SQL exports, test fixtures, build sources and offline tools are excluded from both upload and synchronization deletion. It never imports SQL or configures a database account.

After FTP success, verify the site login, school data, teacher/student permissions, exam marks, accounting totals, printing and uploaded files. FTPS success alone is not application verification. Preserve the recovery backup and avoid overwriting records created after the cutover.

References: [InfinityFree FTP hostname guidance](https://forum.infinityfree.com/t/ftp-connection-not-possible/117054/5), [FTP Deploy Action documentation](https://github.com/SamKirkland/FTP-Deploy-Action/blob/master/README.md).
