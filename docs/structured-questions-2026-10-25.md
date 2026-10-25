# Structured bilingual questions

Question Bank and Exam Questions share **Statement + subquestions** authoring. New written questions start in this mode. Existing free-form questions remain unchanged until their author selects it and saves a reviewed conversion draft. MCQs retain their existing editor.

1. Choose class, subject and chapter (and a topic for a new Question Bank item).
2. Enter the statement in Nepali and English. Either language can be omitted without copying it into the other language.
3. Each separate subquestion has two rich editors, a custom label, positive decimal marks and one independent cognitive category: **K**, **U**, **A**, **HA** or **KU**. KU is a separate category and contributes no marks to K or U.
4. Add, remove or reorder subquestions. Labels remain attached to their parts; edit the labels to change their printed sequence. Equations use the existing TeX dialog; formatted paragraphs, tables and diagrams remain inside their own subquestion.
5. Choose the saved annotation style: **marks + category** `(2KU)`, **marks only** `(2)`, or **category only** `(KU)`. Preview, then save.

The statement prints Nepali first and English below it. Each labeled subquestion does the same, with its annotation at the end of the last English paragraph. Nepali-only output places the annotation at the end of Nepali. Both banks, question views, learning views, correction snapshots, generated papers and print-language variants use the same renderer. No extra translation parentheses are added to structured content.

Total marks are calculated from subquestions using integer hundredths on the server. A forged total is ignored. There can be 1–40 parts and at most 1,000 total marks, with two decimal places. Each stored language document is limited to 60,000 bytes to fit existing MySQL TEXT storage.

For Mathematics and Optional Mathematics, structured questions need at least two parts: the first **K**, the last **HA**. With three parts the middle category is U, A or KU. KU does not substitute for K. Other subjects support the same fields and categories with freely chosen ordering. Existing free-form mathematics questions are not rewritten or rejected on read.

Advanced paper grids have an independent KU target. Whole-paper cognitive mark targets sum each subquestion into its own category; the sum must match full marks. Per-row category **question counts** select questions entirely within a single category. For structured questions containing several categories, leave those counts blank and use whole-paper cognitive mark targets. No question marks are split or counted twice.

The feature needs no schema import, new column, bulk rewrite or setup click. Validated structure travels with the two rich-text fields in fixed, sanitized data attributes. Existing IDs, permissions, creator ownership and bank separation remain intact. Archive/restore and correction conflict fingerprints retain the annotations. Plain legacy content keeps its original paragraph renderer.

Verification covers service roundtrips, independent KU allocation, exact paper generation, all three suffix styles, English/Nepali isolation, custom labels, content sanitization, mathematical ordering, forged totals and tenant isolation. HTTP coverage runs on disposable SQLite and native MySQL fixtures. Frontend checks include dynamic row/editor ownership, draft preservation, previews and navigation cleanup. Actual local browser checks exercised TinyMCE editing, saving and reopening the bilingual question with automatic totals and portable TeX.
