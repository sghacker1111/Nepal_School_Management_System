# Class Management archival visibility

This focused release fixes the Class Management page without publishing the unrelated pending routine/report changes or changing the database schema.

- Default class and section lists exclude archived classes for every permitted role.
- Administrators can explicitly show archived classes and restore them with password confirmation. Duplicate grade aliases must be renamed before recovery.
- Class archival retains students, sections, subject mappings, examinations, marks, fees and history. The confirmation text now describes archival instead of claiming permanent deletion.
- Archive/recovery actions retain role, CSRF and school-scope enforcement. Global administrators and school administrators verify their own current password.

Verification: `python tests/class_management_http_test.py` passes 45 authenticated HTTP requests on a disposable schema-10 database, with linked educational records and the legitimate class unchanged. The base, routine/learning and teacher-result HTTP suites pass another 262 requests (307 total). Workflow, security, operations and accounting-schema checks pass, along with syntax checks for all 138 PHP files. The deployment guard confirms all 26 runtime-data exclusions and non-destructive serialized FTP. No schema files, configuration, databases or uploads are included in this release.
