# Learning content ownership, school publishing and shared curriculum

Date: 2026-10-13 (Asia/Katmandu)
Status: conversational design approved; written-specification review pending
Project: existing PHP Nepal School Management System, with SQLite and MySQL support

## 1. Intended outcome and confirmed decisions

Keep one super-admin learning catalog that eligible schools can use without modifying it. Separate that catalog from school-created questions, MCQs and study notes. School content has an individual creator and an explicit Private/Public setting. Public means public inside the creating school only, never another school and never anonymous internet access.

The user confirmed these rules:

- Teachers add, edit and publish only their own questions and notes. They cannot overwrite another teacher's content.
- School administrators create and manage their own content; they can view school content but cannot overwrite teacher-owned content.
- Students view permitted content and practice only; they never create, edit or publish content.
- Only super admin adds, renames or edits chapters/topics. School staff and students select/view existing chapters/topics.
- Shared super-admin content is readable across eligible schools, but only super admin edits it.
- Private school content is readable by its creator, active assigned class-subject teachers in that school, and school/system administrators. Students and unrelated teachers cannot read it.
- Display Nepali, English and Mathematics without the Compulsory prefix. Preserve optional-subject distinctions, IDs, codes, student selections, examinations and marks.

This is an architectural change because the same records feed Question Bank, curriculum selectors, Learning Hub, MCQ practice and two paper-generation flows. Visibility must be enforced by the backend across all these readers, not merely by hiding edit buttons.

The already implemented Kokila 20 pt, larger practice text, animations and louder-sound changes are separate pending work. Preserve those changes. This specification does not claim they have been committed, pushed or deployed.

## 2. Existing code and observed gaps

Inspected source baseline: commit 7966643986213003c1b71dbae57bbc25d2944cfa, plus pending readability edits in the existing linked worktree.

- `includes/schema.php` declares schema version 14. Questions have school/class/subject identity, bilingual content, a legacy chapter string and topic ID, but no creator or privacy field.
- `modules/learning/questions.php` creates school-zero shared questions for super admin and local questions for school users. Its existing mutation checks establish school/teaching scope, not individual ownership.
- `learning_notes` has class-level, subject-name, chapter/topic labels and content, but no school/class/subject owner IDs. Its current editor is super-admin-only.
- `includes/curriculum_catalog.php` resolves chapters/topics strictly inside one school. Existing rows and duplicate-ID aliases are important to preserve.
- `modules/learning/index.php` still uses the static CDC chapter list rather than the persisted shared catalog. Its notes lookup is label-based; public/private rules cannot currently be applied.
- `includes/mcq_practice.php`, `modules/learning/paper_generator.php` and `modules/learning/question_grid.php` have separate question queries. The latter generator currently selects only local questions.
- `includes/teaching_scope.php` currently uses one teaching-assignment restriction for both read selectors and mutations. Public viewing and creator-authorized editing need distinct decisions.
- `includes/request_guard.php` limits student routes and enforces subscriptions, packages, CSRF, preview read-only access and relationship validation. Retain these protections.

## 3. Chosen approach and alternatives

Extend the existing records with ownership/visibility and introduce a small shared learning-content policy/resolver. Reuse existing school, class, subject, question and catalog identities. Every consumer uses the same policy.

An alternative is copying all global content into each school. It is rejected because it duplicates content, loses authoritative updates and complicates ownership. A separate replacement learning subsystem is also unnecessary: the existing PHP pages and database can support these rules through additive changes.

## 4. Access matrix

All access requires an active account. School-user access also requires an active school subscription and the applicable module entitlement; super-admin global catalog maintenance does not require a school subscription. Hidden/inactive subject/class rules remain in effect. A student is limited to their enrolled class and selected optional subjects.

| Content | View/use | Edit, archive, restore or change visibility |
| --- | --- | --- |
| Published super-admin shared content | Eligible users through their class/subject mappings | Super admin only |
| Private/unpublished super-admin content | Super admin only | Super admin only |
| School-public questions/notes | Staff of that school; students in the permitted class/subjects | Active creator only |
| School-private questions/notes | Creator, school administrators, system administrator in an explicit school context, and active assigned class-subject teachers | Active creator only |
| Shared chapters/topics | Eligible users through matching school class/subject mappings | Super admin only |
| Preserved legacy local chapters/topics | Eligible users of the owning school | No school-user mutations; super-admin maintenance only in that school context |

Principal, exam-officer and finance roles gain no new creation rights. A role is not treated as a school administrator merely because it could previously use a learning page. Other teachers can read published school content but never mutate it.

Teacher creation still requires assignment to the selected school class/subject. A teacher who loses that assignment can retain read access to their own school record while active, but cannot edit/publish it until assigned again. Other teachers cannot take ownership. Students cannot access another class through forged parameters.

Shared content can appear read-only in school editors/selectors without becoming school-owned. Super-admin school-preview remains read-only. No automatic conversion of school content to global content is permitted.

## 5. Data model and migration

### Questions and notes

Questions gain nullable `created_by_user_id`, validated `visibility` (`private` or `public`), `archived_at`, and `updated_at` fields. School zero identifies the shared catalog; positive school IDs identify tenant content. Creator/school identity is set by the authenticated server and is immutable through ordinary content forms/imports.

Notes gain the same ownership/visibility/archive fields, plus school, class and subject IDs and nullable local chapter/topic IDs. Keep the existing label columns as compatibility/source data; new notes use relational identity rather than matching by an editable subject name. Local chapter/topic IDs must belong to the note's school and selected class/subject.

Questions and notes gain nullable `catalog_chapter_id` and `catalog_topic_id` for selections from the shared school-zero catalog. Keep the existing question chapter text and legacy topic ID; do not replace local topic IDs with global IDs and then assume they belong to the local school. New relations must prove the exact matching class, subject and parent chapter.

Use indexes for school/class/subject/visibility/archive filtering and creator lookup. Use prepared queries and validate enum values. Preserve existing IDs and raw question/answer content.

### Compatibility rules

- Existing school-zero questions remain shared-public. Existing local questions remain public inside their original school, matching their existing visibility. Do not invent their creator.
- Existing notes originate from the former super-admin-only note editor, so retain them as shared-public with unknown creator. Resolve label-based class/subject identity only when there is one unambiguous match; preserve unmatched notes for super-admin review instead of discarding them or attaching them to an arbitrary class.
- Legacy shared content may be maintained by super admin. Legacy local records with unknown creators remain readable but read-only: this work adds no ownership-claim or reassignment workflow.
- New school questions/notes default to Private. Only their creator can deliberately publish them within the school. New super-admin catalog content defaults to shared-public, with an optional Private draft state visible only to super admin.
- Archived records are hidden from all normal readers, including practice and random generation; creator-authorized restoration is recoverable. Do not permanently delete educational content as part of this change.

Make the next schema migration additive, idempotent and repeatable. Keep the current automatic SQLite pre-upgrade backup. Test upgrades on consistent disposable copies, not the user's original database. MySQL upgrades require a staging check before claiming MySQL migration verification. An older application must not silently downgrade the new schema.

## 6. Shared chapters/topics and subject identity

### Shared catalog

Introduce a catalog resolver that combines matching global catalog entries with existing local entries for viewing. Match classes by an exact canonical grade/class identity, never substring search (Class 1 must not match Class 10/11/12). Match subjects using existing source IDs where valid, then unambiguous code/type/canonical-name identity. Optional Mathematics must never match Mathematics by a loose alias.

Question Bank, Curriculum & Topics Manager and Learning Hub must use this resolver. De-duplicate labels while retaining historical alias IDs and parent/scope information, so existing topic selections survive. Newly added super-admin chapters/topics become available to matching school class subjects without per-school copies.

School users cannot add chapters/topics through Curriculum Manager, custom text, question saves, Excel/CSV imports, scanner saves or forged requests. Imports select existing catalog labels; missing chapters/topics produce a row-specific error. A blank topic may remain blank where topics are optional. Super-admin imports retain their ability to add missing catalog entries.

School users may choose an existing legacy local chapter/topic but cannot rename it. Super-admin shared chapter renames update the canonical linked display and compatible question/note chapter labels transactionally. Reject duplicate names and invalid parents. Preserve all legacy question/topic IDs and refuse destructive removal of referenced entries.

Avoid weakening `schoolRecord()` globally to accept school-zero IDs. New catalog reference fields use a dedicated resolver that checks cross-scope matching explicitly; unrelated student/result/accounting reference validation remains tenant-strict.

### Subject-name cleanup

Use one canonical-name helper for application subject labels and legacy import aliases. Persist canonical application names for exact prefixed names Compulsory Nepali, Compulsory English and Compulsory Mathematics; apply the same rule to new seeds/catalog imports so the prefix is not reintroduced.

Keep CDC source-workbook snapshots, subject codes, compulsory/optional type, credits and marks unchanged. Do not delete or merge duplicate subject rows to remove the prefix. If normalization creates equal labels, keep their IDs and disambiguate distinct records using code/type; do not pick an arbitrary subject. Update compatible legacy note lookups through the canonical helper, not a broad string replacement of lesson text or historical exam snapshots.

## 7. Shared backend policy and affected flows

A focused policy/service module separates `canView`, `canCreate`, `canEdit`, `canPublish` and catalog-write permissions. It resolves eligible global/local content and centralizes prepared read predicates. Controllers never trust a posted school, creator, owner or claimed privacy privilege.

Enforce the policy in all of these flows:

1. Question Bank listing, filters, counts, details, manual CRUD, archive/restore, visibility changes and both normal/HTML imports.
2. Learning Hub notes CRUD and questions/answers reading; notes use the same safe-rich-HTML handling as questions.
3. Curriculum Manager chapter/topic APIs and form controls, plus Question Bank/scanner chapter-topic selection.
4. Practice MCQ catalog, chosen/random start, resume, answer, next, completion and review.
5. Both paper generators, including random selection, previews, answer keys and any detail/download endpoints.
6. Any additional question/note reader found by repository search during implementation. None may keep an unfiltered legacy query.

Separate teacher read selectors from write selectors. Reading school-public content may include other school classes/subjects; creating/editing remains limited to assigned teaching subjects and creator identity. Private content is filtered by the actual class-subject assignment, not a teacher-name comparison. Keep student enrollment/elective scope and all existing package checks.

Visibility changes must take effect immediately on every new request. A student's saved practice session cannot expose questions that have since become private/archived. Revalidate the underlying question IDs before returning session state, feedback or review; invalidate an affected session with a clear restart message rather than grading/revealing stale content. Never modify official examination marks.

Private questions can be selected for a paper only by an authorized staff reader. Paper-generation routes are not a new student/private-content endpoint; students remain denied those routes. Explicit printing by authorized staff is their controlled exam workflow, not automatic publishing to Learning Hub or Practice MCQs.

## 8. UI and error behavior

- Distinguish Shared catalog and This school in the Question Bank and notes area, with a Private/Public badge and a My content filter.
- Show read-only shared/other-creator records without edit/archive/publish controls. An authorized creator sees those controls only on their own records.
- School creation forms show a Private default and a Publish within this school action. Explain who can see each setting; never label school publishing as universal.
- Preserve the user's class/subject/chapter/topic filters after save or visibility changes. Counts and search results must include only readable, non-archived records.
- Curriculum Manager becomes view-only for school users. Super admin retains Add Chapter/Add Topic and protected editing. Remove school-user custom chapter/topic creation affordances from every entry point.
- Invalid/foreign content IDs return a generic not-found response without leaking another school's title, owner or counts. Forbidden edits to a visible record return a concise creator-only explanation. CSRF and expiry failures retain current clear notifications.
- Imports report invalid mapping/privacy rows accurately, with no guessed answer keys, creator assignments or false Saved successfully notices. Existing batch rollback behavior remains atomic.
- Publishing, unpublishing, creation, edits and archiving record actor, scope, target and action in the audit log. Log no passwords or full private lesson content in general error messages.

## 9. Acceptance tests

Use at least two schools, two assigned teachers for the same subject, an unrelated teacher, separate school admins, active enrolled students, and a super admin in disposable fixtures.

Required assertions:

1. A new shared chapter/topic/question/note appears in eligible schools and renders safely, without duplicate dropdown labels; school users cannot alter it.
2. School A public content is visible to School A eligible students/staff and never School B. Anonymous access remains blocked.
3. School-private content is visible to its creator, same-school subject co-teacher and administrators, not students or unrelated teachers. Direct-ID/search/count/random/chosen requests enforce the same rule.
4. Teacher A can create/edit/publish their own assigned-subject content. Teacher B and school admin cannot edit/publish/archive Teacher A's record. School admin can manage their own content.
5. Posted creator/school/global/private fields cannot forge access. Preview remains read-only, CSRF is required and package/subscription restrictions cannot be bypassed.
6. School users cannot create/rename chapters/topics by UI, direct POST, scanner save or XLSX/CSV import; existing selections and super-admin import additions work.
7. Publishing/unpublishing and assignment changes invalidate inappropriate access, including a previously started student practice and its completed review.
8. HTML/LaTeX and bilingual normal/HTML imports still render correctly. Questions remain randomized; Back buttons, larger Kokila text, audio/mute and reduced-motion behavior remain intact.
9. Nepali/English/Mathematics labels are canonical across selectors and new content without changing codes/types/IDs/credits. Optional Mathematics remains distinct; Class 1 remains isolated from Classes 10/11/12.
10. Schema-14 upgrade/repeat upgrade preserves questions, notes, chapters/topics, marks, student identities, finance records and uploaded paths. Unknown creators are not fabricated; backup and SQLite integrity checks succeed.

Run PHP syntax checks, JavaScript syntax/client tests, existing import/catalog/practice/role/package/class-isolation/print/accounting regression suites and new real-HTTP permission tests. Browser-check creator and reader screens, Private/Public transitions, source imports, student practice and mobile layout. No verification uses real accounts or production test mutations.

## 10. Delivery boundaries and review checkpoint

No live database migration, source push, merge or FTP deployment is performed by writing this specification. Implementation and any later release preserve database files, uploads and private server configuration. Source publication remains a separate explicitly requested step.

This specification is ready for the user's written-spec review. After approval, prepare a test-led implementation plan and have the user review it and select execution method. Product code changes for this permission feature begin only after that checkpoint. Existing pending font/audio work remains separate and preserved.
