# Separate exam questions and exam paper grids

Requested 14 October 2026. User clarified that teachers generate/print through administrator-granted school access, and exam questions are staff-only unless their author explicitly permits student viewing.

## Implementation checklist

- [x] Fix the normal paper chapter dropdown to read catalog object names, retain old string compatibility, escape selected names as JavaScript data and test actual options.
- [x] Add schema-16 question bank identity (`learning` or `exam`), difficulty, required minutes and explicit student-release flag. Preserve existing IDs/content as learning questions, default all exam releases off.
- [x] Enforce separate bank/paper/view purposes centrally. Exam questions never appear in the normal bank, Learning Hub, Practice MCQs or normal papers. Released exam questions are a read-only student section within enrollment/electives; private content is never released. Creator-only writes remain.
- [x] Add Exam Questions navigation/controller with the existing safe bilingual content service/editor, class/subject/chapter/topic/difficulty filters, marks/time and release warning. Keep school chapter/topic catalog read-only. Existing normal-bank imports stay separate; exam-bank bulk import is not part of this implementation.
- [x] Add separate creator-owned exam grid table/controller, archive/restore and shared defaults. Configure chapter pools, question types, counts, exact total marks, K/U/A/HA MCQ counts, optional difficulty/topics, exam time and chapter target counts/marks.
- [x] Generate only from eligible Exam Questions. Meet all quotas/marks without duplicates or mark overrides; fail clearly on shortages instead of printing an incomplete paper. Respect per-question estimated time and provide separate print styles for question/answer sheets; physical printing is unverified.
- [x] Verify the bundled CDC source PDF visually; label source/edition and unresolved chapter mappings. Do not invent universal CDC quotas or copy copyrighted model questions into the bank.
- [x] Add PHP, client and real-HTTP tests; run existing regressions and copy-only upgrades. Browser/print-layout QA uses disposable accounts. Preserve original database/uploads/config. Commit locally; do not automatically push/deploy this new feature.

Use the existing linked worktree on `codex/exam-questions-grids`, based on released `bc613b0`. Implement natively with failing regressions first. No new reviewer/model has been requested for this feature.

Separate difficulty (Easy/Medium/Hard/Challenging) from cognitive classification (K/U/A/HA). Grid rows may pool one or several chapters; separate rows allow fixed per-chapter allocations while MCQs print together as one section. Decimal marks use hundredths, not floating-point equality. Variable-mark rows must satisfy both their requested count and total marks. Configurations that cannot be fulfilled do not silently relax constraints.

Access reuses purchased packages; the existing `exam_generator` key is given a clear Exam Paper Generator label and gains the new routes without revoking legacy access. Full ERP includes them. Question-view/add and learning packages may read explicitly released questions as appropriate; only the exam-generator/full entitlement generates exam papers. Students never access grids, generated papers or authoring/import endpoints.

CDC references researched: official CDC Optional Mathematics Grade 9/10 external-assessment grid, 2082, and CDC mathematics/English grid catalog pages. Verified reference defaults will record their exact source. A CDC reference is not certification that every stored question, composite question or school offering complies with all examination rules.
