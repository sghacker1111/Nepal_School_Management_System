# School classes and teaching subjects

System admin owns class and subject definitions, CDC settings, codes, marks, credit hours, assessment defaults and compulsory school assignments. School admins can select which additional catalog subjects their school teaches and assign teachers, sections and weekly periods. Other school users can read their school's active offerings with their existing role and teaching restrictions.

## Use the screens

- **System admin → Subjects & Chapters Catalog**: add/edit universal subjects and map them to a class. The new **School assignment default** is separate from whether an individual student selects an elective. Choose **Compulsory for all schools** or **Schools choose whether to teach**. Mapping rows can also switch this default. Saving synchronizes current schools transactionally.
- **System admin → Schools → Classes & subjects**: review a school's assigned subjects first, hide/allow an individual subject, show/hide a class, assign teachers, or synchronize the latest catalog. A hidden compulsory subject is an exception for this school only; later global saves retain the exception.
- **School admin → Subjects**, or **Classes → Subjects**: assigned subjects appear first; remaining available catalog subjects appear below. Select **Assign to teach** / **Unassign** for school-choice offerings. Use **Assign teachers** to select school teachers, section and weekly periods. Compulsory or admin-blocked subjects cannot be removed or re-enabled by a school admin.
- **Class teacher setup and sections** remain school operations. School users cannot create or rename catalog classes or change their levels. CDC class/subject records cannot be permanently deleted.

## Existing schools and records

No schema-version change or database import is required. The feature uses existing school copies, `class_subjects.is_active`, and small, namespaced `app_meta` records for identity links, defaults and exceptions. Ordinary page reads never synchronize or migrate school records.

After installing, system admin should select **Apply latest catalog to all schools** once. This copies any missing catalog records, links existing exact identities, applies active compulsory defaults, and keeps existing optional teaching selections and explicit admin exceptions. It does not delete or renumber records, reset passwords, rewrite students or marks, or alter saved exam schemes. Newly created schools get compulsory defaults; additional optional subjects begin unassigned. The publication workflow preserves hosted databases, private config and uploads.

Older provisioning sometimes reused one school subject ID across grades with identical names/codes. Synchronization preserves those IDs and stores a source link per class mapping; it does not silently duplicate those subjects. An identity edit conflicting with an existing cross-grade alias fails and rolls back the whole save with a review message. Resolve ambiguous legacy catalog identities before retrying; historical records remain recoverable.

## Workflow scope

Only active assigned class subjects appear in school question banks, exam questions, MCQs, practice, papers, curriculum selectors, timetables, optional enrollment, future exam initialization, fee subject selectors and open marks/result workflows. Forged/stale requests also check assignment on the server. Teacher ownership remains an additional restriction. Removing an optional offering revokes access even if its teacher IDs remain stored.

Existing locked/published exams retain their subject snapshot for historical results. New locks record which exam mappings were excluded by the current offerings, without deleting any scheme or marks. Publishing uses that locked selection. Reopening withdraws the result and uses current offerings again. Old locked exams with no exclusion metadata retain all original schemes.

## Verification

The dedicated service regression covers idempotent synchronization, legacy shared IDs across grades, per-school defaults, global hiding and record preservation. HTTP coverage exercises school-admin denial on direct catalog routes, all-school propagation, elective choices, teacher assignment, per-school compulsory exceptions, stale marks forms, historical reports and tenant isolation. Native MySQL runs the same HTTP assertions on disposable fixtures. Final release verification is recorded with the release commit and deployment run.

Release checks used patched PHP 8.3.36. The security gate passed 200 commands; the native MySQL suite passed 36 tests, including offering identity/snapshot coverage; the frontend suite passed 70 tests and 35 synthetic pages mounted/disposed successfully. Offering-specific checks passed 24 service assertions and 82 HTTP requests. The broad runner executed 371 commands; its single obsolete hidden-catalog expectation was updated and passed on a recorded rerun, leaving no unresolved failures. FTP package verification passed five tests and checks 42 critical deployed hashes. Local raw verification/recheck evidence remains under ignored `scratch/ui-migration/`.
