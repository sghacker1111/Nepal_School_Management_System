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

After uploading, Actions connects over certificate-verified FTPS to check every packaged editor/math/PDF file and the question controllers for existence and exact size, then downloads 73 critical code files to compare SHA-256 hashes. This check is read-only and never downloads database settings, SQL, uploads or school records. Missing files or incorrect content fail the release even if the synchronization step reported success. It verifies the configured FTP directory, not whether that directory is assigned to the public domain; authenticated browser checks are still required.

The pinned TinyMCE 6 runtime retains the MIT license used by the existing CDN integration. It has known upstream security advisories; self-hosting is a reliability change, not a claim that these advisories are resolved. Continue to enforce server-side rich-content sanitization. Moving to a newer TinyMCE major requires a separate compatibility and licensing review.

After FTP success, verify the site login, school data, teacher/student permissions, exam marks, accounting totals, printing and uploaded files. FTPS success alone is not application verification. Preserve the recovery backup and avoid overwriting records created after the cutover.

References: [InfinityFree FTP hostname guidance](https://forum.infinityfree.com/t/ftp-connection-not-possible/117054/5), [FTP Deploy Action documentation](https://github.com/SamKirkland/FTP-Deploy-Action/blob/master/README.md).

## October 2026 PHP security requirement

The October 21 upstream release fixes CVE-2026-109618 and CVE-2026-109619 in the PHP engine. Minimum versions are PHP 8.2.35, 8.3.36, 8.4.27 and 8.5.12 in their respective branches. InfinityFree must install the engine patches and restart its PHP workers. Application changes, FTP uploads, `.user.ini` and runtime `ini_set()` cannot substitute for that upgrade. Sign in as system administrator and open Control Center to see the actual web runtime version. If it falls below the baseline, request host confirmation of **both** CVE backports or a patched runtime. A vendor backport cannot be inferred from the upstream version string. See the [official advisory](https://www.php.net/archive/2026.php#2026-10-21-5).

Validation now runs the application security gate using PHP 8.3.36 before deployment. That CI runtime is separate from InfinityFree. All existing sessions must sign in again once after this security release. Later password, username, role, school or student-link changes revoke older sessions on their next protected request.

PDF scanning ships the Apache-2.0 PDF.js 6.4.299 compatibility build, worker, character maps, fonts and WASM files in `assets/vendor/pdfjs-6.4.299/`, plus `assets/js/pdfjs-runtime.mjs`. Upload the entire generated directory. The scanner loads local PDF data with evaluation and XFA disabled.

At the owner request, TinyMCE stays on its existing MIT-licensed 6.8.6 release. DOMPurify 3.4.17 runs before editor parsing; embedded media is disabled, server HTML sanitization remains enforced, and MathJax safety filters reject formula-generated links. These are defense-in-depth changes: **npm audit still reports the retained TinyMCE high-severity advisories**. A maintained editor upgrade/replacement remains necessary for a clean dependency audit.

## School subject assignments (24 October 2026)

After deployment, sign in as system admin and open Subjects & Chapters Catalog. Use **Apply latest catalog to all schools** once to reconcile the current catalog. Subsequent admin saves synchronize automatically. Open **Schools → Classes & subjects** for individual class visibility and compulsory-subject exceptions; school admins use **Subjects** to select additional offerings and teachers. No schema migration or database replacement is needed. See [the feature guide](school-subject-offerings-2026-10-24.md) for scope and record-preservation details.
