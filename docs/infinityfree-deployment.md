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

The workflow uses the `FTP_SERVER_DIR` secret for both validation and FTP transfer. It trims surrounding whitespace, normalizes folder separators and adds a missing trailing slash before validating the exact destination. It accepts an explicit website folder ending in `htdocs/` and refuses a filesystem root, URL or parent traversal. Only that validated folder is passed to FTP. It uses explicit FTPS on port 21, serializes transfers, and leaves destructive clean-slate mode disabled. The old website's folder is not a fallback destination.

## Prepare the hosted MySQL application

1. Retain a recovery backup of the existing site's database, uploads and private configuration before replacing the hosted application.
2. Import the privately verified `school_erp_mysql.sql.gz` into a **new empty MySQL database** using phpMyAdmin; see [the migration guide](mysql-migration.md). Confirm the import result before activation.
3. Create `<your-new-website-folder>/htdocs/config/database.local.php` manually from `config/database.local.example.php`, using the imported database's exact MySQL hostname, database, username and password. FTP deployment deliberately excludes this private file.
4. Keep any live records that differ from the local SQLite snapshot; this code deployment does not merge databases. Transfer/preserve `uploads/` separately.

## Deploy and verify

In **Actions → Validate and deploy Nepal ERP to InfinityFree → Run workflow**, select **main**, tick **deploy**, then run. Validation must succeed before the FTP job starts. After the first verified deployment, set `FTP_DEPLOY_ENABLED=true` if automatic deployments on later `main` pushes are wanted.

The workflow transfers application files and compiled frontend assets. Databases, private configuration, uploads, backups, SQL exports, test fixtures, build sources and offline tools are excluded from both upload and synchronization deletion. It never imports SQL or configures a database account.

`npm run build` also creates `assets/vendor/tinymce-6.8.6/` and `assets/vendor/mathjax-3.2.2/`, including editor plugins/skins and math fonts/extensions. They are generated from the pinned package lock, not committed or loaded from a CDN. Both directories must be uploaded alongside `assets/dist/` when deploying manually. The Actions FTP step includes them automatically. Uploading only PHP files or only `assets/dist/` is not a complete deployment. These libraries do not require Node.js on InfinityFree.

After uploading, Actions connects over certificate-verified FTPS to check every packaged editor/math file and the question controllers for existence and exact size, then downloads 12 critical code files to compare SHA-256 hashes. This check is read-only and never downloads database settings, SQL, uploads or school records. Missing files or incorrect content fail the release even if the synchronization step reported success. It verifies the configured FTP directory, not whether that directory is assigned to the public domain; authenticated browser checks are still required.

The pinned TinyMCE 6 runtime retains the MIT license used by the existing CDN integration. It has known upstream security advisories; self-hosting is a reliability change, not a claim that these advisories are resolved. Continue to enforce server-side rich-content sanitization. Moving to a newer TinyMCE major requires a separate compatibility and licensing review.

After FTP success, verify the site login, school data, teacher/student permissions, exam marks, accounting totals, printing and uploaded files. FTPS success alone is not application verification. Preserve the recovery backup and avoid overwriting records created after the cutover.

References: [InfinityFree FTP hostname guidance](https://forum.infinityfree.com/t/ftp-connection-not-possible/117054/5), [FTP Deploy Action documentation](https://github.com/SamKirkland/FTP-Deploy-Action/blob/master/README.md).
