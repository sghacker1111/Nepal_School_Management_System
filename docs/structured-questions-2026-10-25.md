# Structured bilingual questions

## Viewing, printing and recoverable deletion

- Language selectors now offer Nepali then English (default), English then Nepali, English only and Nepali only. Paper controls switch the same selected questions, options and answers without regenerating the paper.
- Each labeled subquestion uses one shared hanging gutter. Both language lines and wrapped lines start at the same horizontal position; the label appears once beside the first displayed language. Custom labels receive a wider gutter when needed.
- Structured question totals remain part of paper allocation and full-mark totals, but do not appear beside the statement. Only subquestion annotations are printed in the question body. Answer keys show a per-part marking guide; even cognitive-only question views include marks in the answer guide. Ordinary question/MCQ answers show question marks.
- Question Bank, Exam Questions and Exam MCQs support selection of up to 100 editable questions on the current page. Delete selected opens a warning and current-password confirmation. The server checks the shared password retry limit and every record's creator, school, bank, section and active state before archiving the entire selection in one transaction. A failed batch changes no question. Passwords never enter audit logs or rendered responses.
- Single-question deletion retains its confirmation warning without a password. Both deletion paths preserve the complete question and allow restoration from the Deleted / archived filter. Existing creator permissions still apply to restoration.

Question Bank and Exam Questions share **Statement + subquestions** authoring. New written questions start in this mode. Existing free-form questions remain unchanged until their author selects it and saves a reviewed conversion draft. MCQs can also use a bilingual statement with one labeled prompt and their existing four choices and answer key.

1. Choose class, subject and chapter (and a topic for a new Question Bank item).
2. Enter the statement in Nepali and English. Either language can be omitted without copying it into the other language.
3. Each separate subquestion has two rich editors, a custom label, positive decimal marks and one independent cognitive category: **K**, **U**, **A**, **HA** or **KU**. KU is a separate category and contributes no marks to K or U.
4. Add, remove or reorder subquestions. Labels remain attached to their parts; edit the labels to change their printed sequence. Equations use the existing TeX dialog; formatted paragraphs, tables and diagrams remain inside their own subquestion.
5. Choose the saved annotation style: **marks + category** `(2KU)`, **marks only** `(2)`, or **category only** `(KU)`. Preview, then save.

By default the statement prints Nepali first and English below it. Each subquestion label is inserted directly into its first displayed text paragraph, so it does not occupy a separate line. The translation follows on the next line, starting in the same text column. English then Nepali reverses each pair while keeping its annotation at the end of the last English paragraph. Single-language output puts the label and annotation in that language. Both banks, question views, learning views, previews, correction snapshots, generated papers and print-language variants use the same layout. No extra translation parentheses are added to structured content.

Total marks are calculated from subquestions using integer hundredths on the server. A forged total is ignored. There can be 1–40 parts and at most 1,000 total marks, with two decimal places. Each stored language document is limited to 60,000 bytes to fit existing MySQL TEXT storage.

For Mathematics and Optional Mathematics, structured written questions need at least two parts: the first **K**, the last **HA**. With three parts the middle category is U, A or KU. KU does not substitute for K. Other subjects support the same fields and categories with freely chosen ordering. A structured MCQ has one prompt with its own category. Existing free-form mathematics questions are not rewritten or rejected on read.

## Import multiple main questions

Both **Normal Text** and **HTML Source** samples are available as Excel and CSV in each import screen. The written sample has four main questions across six spreadsheet rows; one main question has three subquestions. The MCQ sample has two questions, including one statement/prompt example. Old one-row-per-question files remain supported.

The added columns are **Question Group**, **Subquestion Label**, **Statement (English)**, **Statement (Nepali)** and **Part Display**. HTML samples append **HTML Source** to the statement headings, just as they do for other rich content.

- Use Q1 on each row of the first main question, then Q2 on the next main question. A group key is a file-local reference, never an existing question ID. Use a unique group for each MCQ.
- Each row's existing Question, Marks and Cognitive Level fields describe that subquestion. All marks are summed once for the main question. KU remains independent. Blank labels receive a, b, c in row order.
- Repeat the selected Class, Subject, Chapter and (for Question Bank) Topic on every row. Also repeat Type, Difficulty and whole-question Required Minutes. Conflicting metadata rejects the group; catalog mismatches reject the entire batch before any save.
- Supply each group's statement, model answers and Part Display once or repeat the same values. Part Display accepts **both**, **marks** or **cognitive**. Groups may be interleaved without merging unrelated questions.
- In Normal Text, line breaks and literal angle brackets remain text. In HTML Source, paragraphs, supported tables, images and portable TeX are sanitized before saving. Each group supports up to 40 subquestion rows. One invalid part skips the whole group; the error names every affected spreadsheet row.
- Each MCQ group is one row with one prompt, four options A–D and a correct-option key. Blank new fields preserve the existing MCQ or written-question import behavior.

No database import or setup click is needed. Imported structured questions reopen in the same editor as manually created ones.

Advanced paper grids have an independent KU target. Whole-paper cognitive mark targets sum each subquestion into its own category; the sum must match full marks. Per-row category **question counts** select questions entirely within a single category. For structured questions containing several categories, leave those counts blank and use whole-paper cognitive mark targets. No question marks are split or counted twice.

The feature needs no schema import, new column, bulk rewrite or setup click. Validated structure travels with the two rich-text fields in fixed, sanitized data attributes. Existing IDs, permissions, creator ownership and bank separation remain intact. Archive/restore and correction conflict fingerprints retain the annotations. Plain legacy content keeps its original paragraph renderer.

Verification covers service roundtrips, independent KU allocation, exact paper generation, all three suffix styles, English/Nepali isolation, custom labels, content sanitization, mathematical ordering, forged totals and tenant isolation. Import checks download and upload all 16 combinations of bank, question kind, text mode and file format, plus atomic group rejection, conflict handling and unchanged school records. HTTP coverage runs on disposable SQLite and native MySQL fixtures. Frontend checks include dynamic row/editor ownership, inline labels, draft preservation, previews and navigation cleanup. The earlier authoring release had an actual local browser check; browser access was disabled for this label/import follow-up, so its layout verification uses rendered DOM paragraph checks and frontend tests.
