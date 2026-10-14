# Shared question editor and readable practice

Branch `codex/shared-editor-practice-display`, based on released main `907b4e22554b61f12992ed7e018e2c509dabae43`. No new push/deployment has been requested for these changes.

## Requirements and completion checklist

- [x] Exam question/answer English and Nepali fields use the same full Question Bank rich editor and toolbar.
- [x] MCQ options use that same editor in both sections, not a separate reduced configuration.
- [x] Retain font/size, colour, alignment, lists, tables, TeX, source/code and fullscreen controls, with sufficient writing space beneath wrapped toolbars.
- [x] Preserve English 18 px / Nepali Kokila 20 pt authoring defaults, portable TeX saving and existing content permissions.
- [x] Enlarge practice questions/options, match Nepali bold weight and dark colour to English, and prevent imported small inline fonts from shrinking practice text without changing saved content.
- [x] Fullscreen landscape fills the largest available 16:9 board after its compact toolbar; phone/portrait layouts stay fluid, stacked and scrollable.
- [x] Keep sounds, answer locking, server grading, review Back, keyboard choices and reduced-motion safeguards working.
- [x] Run fresh full regressions and verify unchanged original database.
- [x] Final independent read-only review and important formatting fixes.
- [x] Final change set verified for local commit; preserve the linked worktree and original checkout.
- [ ] User integration choice: local merge, push/PR, or keep this branch.

## Implementation

`includes/question_editor.php` provides the common editor script and TeX dialog; `assets/js/question-editor.js` supplies one configuration to both authoring pages. Toolbar wrapping makes every control available in narrow bilingual columns. Editor height is increased to 420 px so wrapped toolbar rows do not consume the writing area. TeX is saved as clean delimiters rather than MathJax-generated markup; formula input is escaped before insertion. Nepali exam snake-case field names now receive the same Kokila handling as the normal bank's camel-case fields.

Practice styles are isolated to `.mcq-practice`: English questions are responsive 1.65-2 rem, English choices 1.3 rem, Nepali practice text 28 pt (26 pt on phones), with question weight 800 and choice weight 600. Fullscreen uses larger responsive English text and 34 pt Nepali, a compact header and a fitted 16:9 board; smaller portrait screens use stacked content rather than a tiny letterbox. Long questions/feedback remain scrollable. Fullscreen geometry is cleared on exit and recalculated on resize. No official marks or existing questions are rewritten.

The independent review identified an overly broad formatting reset. It is corrected: explicit Nepali classes/language markers retain their own font/size and nested text inherits those markers; semantic strong/b content remains emphasized. Because static Kokila faces map both 800 and 900 to the same bold face, a subtle underline makes authored emphasis distinguishable without weakening the requested bold English/Nepali question parity. A real-browser computed-style fixture first failed these regressions and now passes all 13 checks. The reviewer found no remaining Critical, Important or Minor issues in the correction.

Affected CSS and scripts use file-version query strings to avoid retaining the old display/editor after release. The practice stylesheet is loaded in the normal page head after shared CSS. The older generic Kokila 20 pt default stays unchanged elsewhere.

## Verification evidence

Fresh complete regression run passed: **179 PHP syntax checks, 28 PHP suites, 11 JavaScript suites, 27 dedicated HTTP suites, 158 general HTTP requests, 26 deployment-safety checks, consistent-copy schema upgrade, all asset JS syntax, and unchanged original database hash**. Successful HTTP fixtures require no PHP warning/deprecation/fatal errors.

Targeted failing regressions were observed before the main changes: missing shared editor; old fullscreen sizing; insufficient practice typography. Shared editor tests verify full toolbar controls, Nepali naming parity and safe formula insertion. Practice tests cover fullstage sizing/exit cleanup alongside existing audio/mute, duplicate-click locks, choices, review/back and reduced motion. Real HTTP suites verify exam authoring (40 requests), HTML/normal import and bilingual TeX (31), and practice/session permissions (70).

Actual browser QA uses a disposable OS-temp database with `ERP_EDITOR_PRACTICE_QA`, keeping the user's real local login separate. Findings:

- Both English and Nepali Exam Questions fields show the full menu/toolbar, including TeX, source and fullscreen; Nepali displays Kokila 20 pt.
- Both editor containers measure 420 px tall. HTML Source edits and TeX insertion were saved through the actual Question Bank form and reopened with clean HTML/TeX, not generated MathJax tags. An Exam Questions source edit likewise persisted and reopened with its strong formatting and fraction delimiters intact. All writes used synthetic records.
- Synthetic small-inline-font practice text renders at English 32 px, Nepali 37.33 px, both weight 800 and colour `rgb(24,48,68)`; options render at English 20.8 px / Nepali 37.33 px, weight 600.
- Actual fullscreen at 1920 x 1080 produces a 1717.33 x 966 panel, ratio 1.77777, with all four options visible.
- At a 390 px viewport, client and scroll width both measure 375 px; options are stacked, Nepali is 34.67 px/weight 800, and no horizontal overflow is present.

Screenshots saved and visually inspected in the task's visualizations directory: `shared-exam-editor-2026-10-14.jpg`, `practice-readable-2026-10-14.jpg`, `practice-fullscreen-2026-10-14.jpg`, `practice-mobile-2026-10-14.jpg`. These are local synthetic proofs, not authenticated production tests.

To repeat the browser-only display regression, run a loopback static server from the checkout (`python -m http.server 8001 --bind 127.0.0.1`), open `/tests/mcq_practice_display_test.html`, and check its PASSED status. The normal ERP router deliberately blocks the tests directory. This uses the real shared/practice CSS and browser computed styles, including bold emphasis, legacy small inline text and mixed/nested language markers. Stop the temporary static server afterward; do not expose it publicly.

## Safety and remaining limits

Original database SHA256: `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`. No migration changes; no production questions, accounts, packages or databases have been modified. Databases/uploads/private config remain excluded from releases. MySQL staging and authenticated production testing are not covered. Do not imply this follow-up is deployed until a separately authorized FTP run succeeds.

The in-app browser console recorded unattributed iframe `MutationObserver.observe()` errors despite successful authoring, TeX and reopen checks. A read-only diagnostic verified the served files match the worktree, the shared editor makes no observer calls, the two existing application observers target the main document, and the exact configured CDN library sources contain no MutationObserver identifier. There is not enough source/stack evidence to assign a cause; no speculative patch was made. A clean-console native-browser run is not claimed.
