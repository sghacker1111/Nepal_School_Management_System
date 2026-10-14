# Exam Questions and Exam Paper Generator handoff

Implemented natively on `codex/exam-questions-grids`, based on released `bc613b05b86c0ba93b8d33dcd8660418116a4df8`, in the existing linked worktree. This feature is not yet pushed, merged or deployed. The original checkout and its database have not been changed.

## Delivered behavior

- Independent Exam Questions with class/subject/chapter/topic, exact difficulty, cognitive level, marks, required minutes, safe bilingual rich text and LaTeX, text search and archive/restore.
- School-private/staff-only defaults; explicit author-controlled student release with a warning that questions, options and model answers become visible. Private revokes release. Students see only released enrollment/elective content, without authoring/generation controls; login respects question-view-only subscriptions.
- Independent, database-backed Exam Paper Grids with creator-only changes, shared super-admin defaults, stale-revision checks and recoverable archiving. Normal grids and questions are not mixed or relabeled.
- Chapter-wise and multi-chapter question rows, counts, exact full marks, fixed or variable stored marks, MCQ K/U/A/HA quotas, optional topic/difficulty constraints, chapter count/mark targets, whole-paper cognitive marks and estimated time budgets.
- Randomized bounded exact allocation, no repeated question IDs, no silent mark overrides, no partial paper when constraints fail. MCQs pool into Question 1; subjective sections continue numbering. Separate printable question and answer sheets, optional existing-exam link, bilingual maths, 20 pt Kokila, responsive options and a left-aligned school logo beside the heading.
- Package/role/assignment checks on real requests: Full ERP or Exam Paper Generator System enables generation; teachers operate on assigned class subjects. Existing `exam_generator` package identity and legacy access are retained. Student releases can be read through eligible learning/question-view/question-add packages; none grants student generation.
- Fixed the old normal generator's object-valued chapter dropdown, retained older string responses and exact selected names. Normal allocation labels no longer imply that arbitrary school configurations are official CDC grids.

The new exam bank currently uses manual authoring, including HTML Source/code editing. Existing normal Question Bank Excel/CSV imports continue to create learning questions, not exam questions. Historical questions are not copied or moved automatically.

## CDC provenance and limits

The bundled default is the visually reviewed Optional Mathematics Grades 9-10 external-assessment reference, edition 2082: full marks 75, 180 minutes, 11 one-mark MCQs with K3/U3/A3/HA2, 12 within-content-area questions totaling 40 marks, and four cross-content-area questions totaling 24 marks. Whole-paper cognitive targets are 12/18/30/15 marks. Content-area marks are retained as source metadata: Algebra 17, Trigonometry 17, Geometry 20, Vector 7, Statistics 7, Limits and continuity 7.

Source: [official CDC specification grid and model question PDF](https://giwmscdnone.gov.np/media/pdf_upload/Specification%20Grid%20and%20Model%20Question-G9-Opt.%20Math-web_nrbp00y.pdf). A local research copy and inspected page image are excluded from Git under `.superpowers/sdd/2026-10-14-exam-papers/pdf/`.

Reference warnings and source links remain visible when opening saved reference copies. This is not national CDC/NEB certification. Map actual chapters and review composite questions, mixed-domain subparts, within/cross-content areas and accessibility alternatives manually. Whole-question cognitive labels cannot prove subpart-level compliance. Exact cognitive targets are editable; the source's permitted domain variation is not silently applied. No copyrighted model-question bodies are seeded. Other subjects use super-admin-created default grids, not fabricated universal quotas.

## Verification

The final combined run passed: **178 PHP syntax checks, 28 PHP suites, 10 JavaScript suites, 27 dedicated HTTP suites, 158 general HTTP requests, 26 deployment-safety checks, and the consistent-copy upgrade from schema 6 to 16**. All asset JavaScript syntax checks also passed. Test data is synthetic in OS-temporary SQLite databases; the original database is opened read-only for the consistent-copy upgrade.

Full-flow story: staff save eligible exam questions, load a scoped grid, generate a complete question/answer sheet, and students independently read only explicit releases.

| Boundary | Evidence |
| --- | --- |
| Authoring UI and catalog | Actual teacher browser showed assigned subject, named chapters/topics and private/release-off defaults. |
| Request to database | 40 Exam Questions HTTP requests verified persistent bank identity, metadata, scope, release/revocation and failed-edit retention. |
| Grid to allocation service | 31 PHP allocation checks and 36 exam-paper HTTP requests covered counts, marks, quotas, time, shared defaults and safe failures. |
| Data to rendered paper | Actual browser generated a complete four-mark synthetic paper; rich maths/Nepali rendered and desktop/mobile screenshots were inspected. |
| Student response | Actual browser showed only one released example, no staff-only questions or edit/generation controls; HTTP tests independently checked permissions. |

No PHP warnings/deprecations/fatal errors were present in successful HTTP test server logs. Disposable fixtures explicitly selected SQLite and an OS-temp database; a separate test session cookie prevented changes to the user's existing local browser login.

New targeted suites cover:

- `exam_bank_test.php`: bank separation, creator/subject permissions, release/revocation, required minutes and exact decimal validation.
- `exam_paper_test.php`: exact counts/marks/time, variable marks, overlapping pools, chapter/cognitive targets, topic aliases, shared defaults, reference eligibility, revision/archive rules and large unrelated-bank handling.
- `exam_questions_http_test.py`: real authoring/filter/catalog requests, separate consumers, malformed-input handling, failed-edit preservation, student/school isolation and question-view-only student login.
- `exam_paper_http_test.py`: real independent grid persistence, rich paper/key responses, shortages, unchanged records, creator/tenant/package/CSRF checks, stale revisions, archive/restore and POST scope/failed-grid-form preservation.
- `paper_chapters_test.js`: named catalog objects/legacy strings, escaped option text, exact selection and duplicate suppression.
- `exam_grid_editor_test.js`: saved CDC-source warnings, chapter/topic labels, quotas/targets, serialization and client validation.

Actual local browser checks confirmed a saved synthetic four-mark grid, complete generation, separate print controls, rendered MathJax and Nepali text, chapter/topic loading and private/release-off authoring defaults. The normal generator displayed chapter names, not object strings. The restricted student browser view showed one explicitly released question, none of the four staff-only examples, and no edit/grid/generator controls. The browser became unavailable after these observations; final console capture and another screenshot of the student page were not possible. Earlier desktop and 390 px mobile generation screenshots were saved and inspected. No physical printer or browser PDF-export dialog was exercised.

Desktop proof: `C:/Users/SGHACKER/.codex/visualizations/2026/07/21/019f8429-f98f-7932-bc5a-a38a5f0e09e7/exam-paper-desktop-2026-10-14.jpg`.
Mobile proof: `C:/Users/SGHACKER/.codex/visualizations/2026/07/21/019f8429-f98f-7932-bc5a-a38a5f0e09e7/exam-paper-mobile-2026-10-14.jpg`.

MySQL was not available for staging. Its additive DDL path is present, but SQLite results must not be treated as MySQL certification. No authenticated production test or new independent-model review was performed for this feature.

## Data and release safety

Schema 16 adds `bank_kind`, difficulty, nullable estimated minutes and default-off student release to questions, plus `exam_paper_grids`. Existing IDs/content remain learning questions. The existing pre-upgrade SQLite backup and idempotent migration remain active. Generation does not rewrite examinations, official marks, students or bank values.

Original database SHA256 (before/after): `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.

Safe release still excludes databases, uploads, backups, private configuration, tests, docs and scratch. A push/merge into `main` triggers the existing FTP workflow, so this feature remains local pending an explicit release request. Before releasing: back up the deployed runtime, verify MySQL on staging if that is the deployed database, rerun checks, merge/push through the configured workflow, then verify authenticated staff/student flows in production. Never replace a live database with a test database.

Allocation limits: at most 200 items, 50 rows and 2,000 matching eligible questions; bounded exact search reports a clear error rather than returning partial output. Narrow row filters for oversized/complex pools. Per-question minutes are planning estimates, not official marking rules.
