# Exam question languages and equation editing

Applies to **Exam Questions**, exam **Multiple Choice Questions**, the normal **Question Bank**, Learning Hub questions/answers and their generated question papers/answer keys, including the legacy grid view. Practice MCQs retains its existing interactive language layout.

## Question layout

Both languages is the default. The Language filter selects **Nepali + English**, **English only**, or **Nepali only**. Pagination, edit/back links, saves and restoration preserve the selection. The list's **Print this page** prints the current page in that language without model answers. Adjust the existing page-size filter to print more items at once.

For bilingual questions, write matching English and Nepali paragraphs:

1. Statement.
2. Part (a).
3. Part (b), and so on.

The view/print layout is Nepali statement (English statement), then a separate row for each matched part: (a) Nepali (English). A repeated part label is shown only once. Explicit labels are retained. Unlabeled question paragraphs after the statement receive (a), (b), (c) automatically; ordinary options and model-answer paragraphs are not automatically labeled. Standalone tables/images retain their rich content. Existing formatted paragraphs and line breaks from Excel/CSV work without rewriting stored content. Use cell line breaks in Normal Text, or paragraphs/line breaks in HTML Source mode.

This is presentation, not machine translation. Missing translations are not invented. A single-language view with no supplied content says that version is not provided. Unmatched labeled subparts are retained instead of being silently dropped or paired with a different label. Keep corresponding paragraphs and labels aligned in both source fields.

## Generated papers

Choose the language before generating or use **Print language** afterward. Switching language changes the same generated questions, MCQ options and answer key immediately, without rerunning random selection or changing stored marks. Only the selected language variant prints. Print preparation waits for fonts and MathJax rendering. Questions and answer keys remain separate print actions.

## Equation repair

Historical imports can retain `span.math-tex` while the HTML sanitizer removes its `data-tex` attribute. The portable TeX delimiters remain inside the span. The shared editor now reconstructs that source before rendering, preserves source attributes within TinyMCE, and reuses the source when an existing equation is double-clicked. Save output remains portable TeX and still passes the existing server sanitizer. Editing has an undo checkpoint.

The same equation-editor repair benefits normal Question Bank authoring because both use the shared editor. Decimal option values such as `0.5` and `०.५` are retained as numbers, not mistaken for subpart labels. If a legacy record contains only rendered glyphs with no retained source, the editor warns rather than replacing the formula with an empty one; recover the original TeX through HTML Source.

No schema migration, original-database rewrite, permission expansion or change to question ownership/sharing is required.
