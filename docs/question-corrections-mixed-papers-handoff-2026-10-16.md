# Question corrections and mixed exam papers

Implemented and verified on `codex/question-corrections-mixed-papers`; release authorized after verification.

## Acceptance checklist

- [x] Normal English/Nepali/imported-bold reading weights; TeX notation preserved.
- [x] Shared content remains read-only; own content keeps Edit/Delete/visibility controls.
- [x] Unassigned teacher exam reads/print filters denied; normal-bank browsing unchanged.
- [x] Shared correction request, duplicate handling, private reporter history.
- [x] Super-admin original/current comparison, actual correction and rejection with replies.
- [x] Requester unread response and explicit POST read; revoked access hides bodies.
- [x] Mixed chapter paper, exact counts/marks, MCQs first, matching key and groups.
- [x] Language changes retain selected IDs; narrow-screen/keyboard-labelled controls.
- [x] Full automated suite and consistent-copy migration pass; original hash unchanged.
- [x] Independent whole-branch review and any required fixes verified.

The user separately authorized push/merge/deploy after verification. Browser checks use OS-temporary synthetic schools and questions, not production or the original local database.

## Implemented safeguards

Final post-fix verification: **311/311 commands passed** — 215 tracked PHP syntax checks, 37 PHP test suites, 19 JavaScript suites, 36 dedicated HTTP suites, general HTTP integration (158 requests), deployment-safety checks (26 runtime exclusions), consistent-copy upgrade and two-process correction concurrency. `git diff --check` and the release-artifact/token scan passed. The original database SHA256 is unchanged: `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`.

The consistent-copy upgrade retained existing IDs/money and the original schema version. Its existing 50 orphan marks were retained, not fabricated or removed. The v17-specific preservation regression additionally compares every pre-existing table in the disposable fixture.

- Schema 18 adds `question_correction_requests` and two queue indexes. Nullable unique open keys prevent duplicate Pending reports per requester/question. All writes serialize with existing question edits and audit metadata, not private report text. Terminal replies are immutable. Corrected requires an actual canonical content change; archived questions can only be rejected.
- Requester access is original-account/original-school only. Losing a bank package or exam teaching assignment hides both snapshot and current body; active-school history retains only the requester's text and reply. View-only bank packages may report, but cannot mutate questions. Preview, inactive accounts, expired subscriptions, CSRF and identity forgery remain blocked.
- Exam teacher scope applies before list counts/view caching, direct reads and selector/catalog/template requests. Normal learning reads and student release remain unchanged.
- Mixed generation uses the existing exact solver, saved marks, count/time bounds and no normal-bank fallback. Both print surfaces share ordered actual-type groups; advanced quota rows remain visible in the audit.

## Browser evidence

The disposable browser fixture verified teacher submission; actual shared-answer editing through TinyMCE; changed-vs-original comparison; Corrected reply; teacher unread/read POST; a second normal-bank MCQ rejection with explanation; own controls; unassigned teacher empty lists/direct-filter denial; and a three-question **6.75-mark** bilingual mixed paper with Group A/B/C and matching answer key. Language switching retained IDs **1, 2, 3**. At 390px, page width was 375px and allocation fields had labels. The fixture's shutdown preservation checks passed.

Computed imported English/Nepali strong-text weights were both **400**, at **21.3333px / 29.3333px** (22:16 compensation). The standalone style regression passed and retained mathematical weight 700. Saved HTML was not rewritten by reading-style changes.

Proof screenshots (local task visualization directory): `correction-review-verified.png`, `mixed-paper-verified.png`, `mixed-paper-mobile.png`. These QA files are not deployed. Physical printing and authenticated production workflows are not certified by local preview tests.

## Implementation rulings

1. Use a real browser computed-style regression instead of a new source-regex JavaScript test for normal weight. Cost: browser QA is needed to establish cascade/MathJax behavior.
2. Test correction UI via rendered HTTP forms rather than another source-regex JavaScript test; these pages have no custom JavaScript behavior. Cost: visual/keyboard defects need browser QA.
3. Native helper/reviewer skill files were removed mid-session. Continue from the approved briefs/ledger with direct TDD, the full-suite runner and one independent reviewer rather than reinstalling a plugin. Cost: manual completion bookkeeping.

## Limits

SQLite is verified; MySQL staging is not. Group/Section presentation is generic and does not certify subject-specific CDC paper rules. Deployment preserves runtime database/uploads/private config; it is not a backup substitute. Live authenticated coverage requires a separate production session and is not inferred from a successful FTP job.

## Independent review

Astra identified an Important issue before hitting its usage limit: the old migration dispatch could re-run canonical subject-name cleanup during a schema-17 upgrade. A regression reproduced changed subject rows, then passed **73 checks** after the schema-17 fast path was restricted to correction table/index creation and version metadata. Every pre-existing table is compared in the new fixture regression.

GPT-6.1 Sol completed the whole-branch review as the available fallback, found **no remaining Critical, Important or Minor findings**, and independently ran the 73-check schema preservation test, 38 correction-service checks, 31 mixed-paper checks, 16 exam-scope checks, two-process duplicate test, 46-request correction HTTP suite and 10-request mixed HTTP suite. An additional assignment-revocation probe confirmed the snapshot/current body and raw JSON stay hidden while the requester's own report/reply remain readable.

Review boundaries accepted: MySQL runtime/concurrency requires staging; physical printing is not inferred from preview; production authenticated flows require a production session; generic Group/Section headings do not certify subject-specific CDC correctness. These limits do not alter the approved SQLite implementation or data-preserving release scope. No deferred minor findings.
