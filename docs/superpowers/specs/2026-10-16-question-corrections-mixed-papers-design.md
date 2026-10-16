# Question corrections, exam access and mixed papers

Date: 2026-10-16 (Asia/Katmandu)

Status: specification and Native implementation plan approved on 2026-10-16; implementation, 311-command verification, browser acceptance and independent review complete. User separately authorized push and deployment after verification.

Baseline: release `c7ca3d6e85828838062cdf38b307c145e33fe2b8`. Verification/release evidence is recorded in `docs/question-corrections-mixed-papers-handoff-2026-10-16.md`.

## Purpose and agreed scope

Teachers and school administrators need readable, normal-weight questions, a safe way to report mistakes in shared super-admin content, and mixed MCQ/written exam papers. School users must never obtain editing rights over the shared source. Exam questions must be visible and printable by teachers only for their assigned class-subjects.

The approved approach is one shared correction service with separate Question Bank and Exam Questions tabs. Separate services were considered but duplicate the same authorization, request and response logic. Editable school copies of shared questions are not part of this work.

Normal Question Bank browsing remains unchanged. The new teacher read restriction applies specifically to the exam bank, including exam MCQs. Existing packages, school privacy, creator-only mutations, chapter-only exam content, topic-aware learning content and explicit exam student release remain in force.

## 1. Normal-weight question display

Remove forced bold weights from question and option prose in Question Bank and Exam Questions, their view/print output, and the Learning Hub question display that reuses this presentation. English and Nepali prose uses weight 400, including nested imported bold text in these reading surfaces. Keep the established English/Nepali size compensation and bilingual paragraph layout.

This is a display rule: do not rewrite stored HTML, imports, questions, answers or editor source. Headings, question numbers, option labels and interface controls can remain emphasized. Do not override MathJax's internal mathematical weights; vector/bold mathematical notation must not change meaning. Practice MCQs retains its existing interactive typography.

## 2. Ownership and teacher exam access

- Super-admin shared questions remain read-only to school administrators and teachers in both banks. Reporting an error never grants edit, delete, publish or release permission.
- Teachers and school administrators can add, edit, recoverably delete/restore and change visibility of their own eligible content, subject to existing packages and current subject assignments. School administrators do not gain ownership of a teacher's questions.
- Existing student visibility rules are preserved: normal school-public Question Bank content is available within that school; exam content additionally needs the creator's explicit student-release setting. Private content is not student-visible. No migration changes existing visibility settings.
- Teachers may read exam content only where they currently teach the exact local class-subject. Shared question IDs are mapped through the existing validated shared/local subject identity, not name substrings. A class-teacher assignment alone does not widen this exam-question permission.
- Enforce this for selector lists, paginated results/counts, direct record links, language views, print views, correction submissions, grids and generation. A public/shared exam question does not bypass the teacher scope check. Revoked assignments take effect on the next server request.
- School administrators retain school-wide eligible exam access. Super admin retains system-catalog administration; school-preview mode stays read-only. Students retain only explicitly released enrolled-class/elective access.

Use a dedicated exam-purpose scope policy shared by list and record authorization. Do not narrow the generic learning read policy, since normal Question Bank browsing was explicitly left unchanged.

## 3. Correction requests

### School-user flow

Show **Request correction** on an active, currently readable shared super-admin question or MCQ that the school user cannot edit. Allow school administrators and teachers, not students, principals, exam officers or finance users. Teacher exam requests must meet the new assignment restriction.

The form shows the selected question, its bank/type, class, subject and chapter, plus Topic for normal Question Bank content. Require two plain-text fields: **Mistake found** and **Suggested correction**, each 1–4,000 characters. The question ID determines bank, scope and ownership on the server; posted identity fields cannot retarget the request.

Submission creates a Pending request and shows a matching success notification. A second pending request from the same school account for the same question returns the existing request instead of duplicating it. Different reporters may submit their own reports. After a terminal decision the reporter can submit a new request; old history is retained.

Provide **My correction requests** with bank/type/status filters, pagination and an unread-response badge. Only the original requester can read their request history and reply; a school administrator cannot browse another teacher's private reports. Other schools never see it. If access to the original question is lost, keep the requester's own report/status/reply readable within their original active school account but hide the question body/snapshot and original-content link.

Reporting is available when the school has viewing access to the relevant bank; a separate question-creation package is not required. Subscription expiry, inactive accounts and read-only preview still block writes. Replies are in-app only; no email, SMS or external messaging is introduced.

### Super-admin review

Add **Correction Questions** to the system sidebar. Use two tabs, **Question Bank** and **Exam Questions**, with **All / MCQs / Written** type filters, individual written-type filters, status, class, subject and school filters. Default to Pending requests. Paginate using the established page-size choices.

A detail page shows reporter/school, mistake, suggested correction, original submission snapshot, current shared question and an indication when its content has changed since reporting. Read sanitized rich snapshots; render report/reply text escaped.

The administrator can open the existing shared-question editor, save an actual correction, return to the report, then choose:

- **Corrected**: requires a nonempty reply and an actual current content fingerprint different from the submitted snapshot. This avoids claiming a fix while leaving the reported content unchanged. It may acknowledge a correction made while the report was pending.
- **Rejected — question already correct / other reason**: leaves the question unchanged and requires an explanatory reply, such as why the original answer is correct.

The service never applies proposed correction text automatically. Reject decision/reply writes if the request revision or reviewed question fingerprint is stale; reload before resolving. Each terminal decision records the responding admin and time, clears its open-request key, and becomes immutable. Other pending reports on that question remain pending for deliberate review.

Requesters see the decision and reply as unread until they open that request. Marking a response read is a CSRF-protected POST. Admin edits to a question do not erase report history. Archived questions cannot accept new reports or be marked Corrected; a Pending report can still be rejected with an explanation. No correction request is permanently deleted in this scope.

### Data and boundaries

Add an additive schema-18 migration for `question_correction_requests`, storing request ID, question ID, bank/type at submission, requester user/school IDs, mistake, suggested correction, sanitized question snapshot and fingerprint, status, admin response/user/time, response-read time, revision, creation/update times and nullable unique open-request key. Question identity, creator and school come from authenticated lookups. Use the nullable open key to enforce one Pending request per reporter/question safely in SQLite and MySQL; clear it on resolution. Retain question/user references without cascading deletion.

Index the admin bank/status/type queue and requester school/user/status list. Use existing transaction and audit helpers for submit, respond and read actions. Keep correction-specific policy/service and schema in dedicated includes; thin staff/admin routes share that service. Navigation counts come from authorized request metadata only.

Update the central request guard deliberately: route-specific access maps and POST actions must allow eligible viewers to report without weakening existing question-creation guards. No GET mutates requests or read state. Preserve CSRF, single-action validation, school-preview protection, input limits and safe public error messages.

## 4. Mixed chapter-based exam generation

Keep **Written only** and **MCQs only**, and add **Written + MCQs**. In Mixed mode, allocation rows select **Chapter | Question type | No. of questions | Total marks**. Types are MCQ, Very Short, Short, Long, Reasoning and Difference Between. Multiple rows may use the same chapter to request different types. Count 0 skips a row. Topic selection is not introduced into exam generation.

Reuse the existing exact solver with a server-normalized mixed configuration. Require at least one active MCQ row and one active written row for Mixed mode. Preserve saved question marks, decimal calculations, random selection, no repeated IDs, duration limits, current pool/search bounds and explicit shortage errors; do not emit an incomplete paper or borrow from the normal Question Bank. Keep existing single-kind requests and advanced saved grids compatible.

Add **Heading style: Group / Section**, default Group. Render every MCQ first under **Group A — Multiple Choice Questions**, combining MCQs from selected chapters and showing their actual total marks. Render nonempty written-type groups in this order: Very Short, Short, Long, Reasoning, Difference Between, assigning the next letters B, C and so on when MCQs exist. Without MCQs the first written group starts at A. Section style substitutes the word Section without changing quotas or ordering.

Keep MCQs as numbered subitems within the first main question, then continue main numbering for written items using the existing convention. Written question subparts remain inside their parent question. The answer key follows exactly the same groups, letters and numbering. Paper/answer-key language switching preserves selected IDs. Apply the ordered heading presentation to chapter and advanced exam output; advanced row quotas/names remain visible in the allocation audit, not substituted for type-group headings.

Subject-specific CDC section rules are not inferred or certified here. They can be added after the user supplies individual subject rules.

## 5. Preservation, errors and verification

Develop against disposable fixtures, not the original school database. Schema 18 takes the existing SQLite pre-upgrade backup and creates only the request table/indexes and version metadata; it must not rewrite questions, marks, students, curriculum, accounting or visibility. Verify migration idempotency and a consistent-copy upgrade. MySQL staging remains a separate release limitation until exercised.

Regression coverage must include normal English/Nepali prose and imported bold display without changing stored HTML or mathematical notation; shared-question mutation forgery; own-content controls; exam teacher list/count/direct-link/print/generation restrictions; preserved normal-bank browsing; report permissions for view-only packages; forged targets; duplicate/concurrent submissions; stale decisions; escaped responses; unread/read handling; tenant privacy; revoked access; immutable history; migration preservation; and mixed exact counts/marks/ordering/answer-key parity.

Browser QA uses temporary school/admin/teacher accounts and synthetic questions. Verify submit, super-admin correction/rejection, requester reply, owner controls, forbidden subject access and mixed bilingual paper preview. Test narrow screens and keyboard-labelled forms. No live correction requests or student records are created as QA.

Run affected tests plus the full PHP/JavaScript/HTTP regressions before claiming completion. A later release must scan staged files and retain the existing data-preserving FTP exclusions. This approval does not authorize push/deployment; request release separately after implementation verification.

## Specification self-review

The scope matches the approved conversation: one correction service/two banks, creator-only mutations, exam-only teacher read restriction, normal-weight reading text, mixed chapter generation and ordered Group/Section print output. Existing normal student visibility and legacy question IDs are deliberately preserved. No open design placeholders, external messaging, automated correction application, role expansion or subject-specific exam rules are included.
