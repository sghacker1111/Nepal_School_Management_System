# Nepali practice font parity

Follow-up to the released shared-editor changes (`d88d731`). The user requested visibly comparable English/Nepali practice text, suggesting 22 pt Nepali for 16 pt English.

The previous fixed Nepali practice size did not scale with English. It also depended on generic font styles; nested code/preformatted prose could retain a smaller size and another font. Practice now owns its Nepali font rule and scales at 1.375 times the corresponding English size. Questions, options, feedback and review each use their own text context. Ordinary nested Nepali prose inherits its compensated size/font. MathJax nodes and subscript/superscript sizes are not reset. Authoring defaults and stored content remain unchanged.

## Verified display

Actual isolated browser practice measurements (pixels):

| Context | English question | Nepali question | English option | Nepali option |
|---|---:|---:|---:|---:|
| Normal desktop | 32 | 44 | 20.8 | 28.6 |
| Fullscreen 1920 × 1080 | 41.6 | 57.2 | 26.4 | 36.3 |
| Mobile viewport 390 × 844 | 24 | 33 | 19.2 | 26.4 |

Fullscreen retained its 1717.33 × 966 (16:9) board with all four options visible and no panel overflow for the sample. Mobile uses one option per row, with client/scroll width both 375 px and no horizontal overflow. Feedback and review measured 20 px English / 27.5 px Nepali. Normal/fullscreen/mobile screenshots were saved and visually inspected in this chat's visualizations directory with the `nepali-practice-parity-` prefix.

The real-browser display fixture (`tests/mcq_practice_display_test.html`) failed the ratio/nested-formatting checks before the change and passes all 17 checks afterward. The old Node source-text assertion requiring a fixed 28 pt was replaced by this behavior check. Full verification passed: 179 PHP syntax checks, 28 PHP suites, 11 JavaScript suites, 27 dedicated HTTP suites, 158 general HTTP requests, 26 deployment-safety checks, consistent-copy upgrade and unchanged original database.

This is a local fix, not a new deployment. The exact source HTML/cache state behind the user's production screenshot was not available in an authenticated browser session; that production cause is not claimed as confirmed. The isolated reproduction verifies both responsive size compensation and the nested-prose display defect. No database migration, permissions or content changes were needed.

Original database SHA256 remains `69B8C3AC52DC3C3D5ACC10D188E9FD64065CF7BD6BA0E2F4B48142D701E1E67C`. Original uncommitted files remain untouched on the preservation branch. The existing versioned practice stylesheet will receive a fresh URL when this fix is deployed.
