# Question imports and bilingual answers

Question Bank, Exam Questions and MCQs share the same current CSV and Excel sample format. Older Question Bank download links also return this format. Download a fresh sample after selecting the existing Class, Subject and Chapter; Question Bank also requires Topic.

- For a normal question, use Question (English/Nepali), Answer (English/Nepali), Marks and Cognitive Level. Leave Question Group, Statement and Subquestion Label blank.
- For a statement with parts, give every part the same Question Group, such as Q1. Each row contains one part's Question, Subquestion Label, Marks, Cognitive Level and Subquestion Answer in English and/or Nepali. The total is computed from all parts.
- The Answer columns can also hold an introduction or complete model answer for a grouped question. Supply that value once, or repeat it identically within the group. Use Subquestion Answer for the different solutions to a, b, c, or custom labels. Both sets of answers are retained in the existing model-answer fields for editing, saved views and answer keys.
- MCQs have four bilingual option pairs, Correct Option A–D, and the Answer columns for explanations. A statement-based MCQ uses one prompt row per group.
- Repeat the catalog, Type, Difficulty and whole-question Required Minutes on each row. Source is Auto, Shared or School as allowed by the signed-in account. Visibility is selected in the import form, while ownership and student release remain controlled by the application.
- Part Display is `both`, `marks` or `cognitive`. Mathematics Rule defaults to `strict` (K first, HA last); `cdc` permits CDC/PABSON combinations. KU remains an independent category.

Normal Text keeps line breaks and literal angle brackets. HTML Source supports sanitized paragraphs, tables, formatting and portable TeX in questions, statements, answers and part answers. Its sample headings identify HTML Source columns. Answer columns are optional; blank answers remain blank. A part answer on a free-form question is rejected with guidance instead of being silently discarded.

The importer validates the entire catalog selection before saving anything. Invalid subquestion data rejects the entire group. Other valid groups can still import, with row numbers and reasons shown for skipped groups. Files are limited to 5 MB, 2,000 data rows, 64 columns and the first worksheet. Legacy simple Question Bank files remain accepted through the older upload endpoint.
