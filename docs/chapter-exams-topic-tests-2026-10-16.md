# Chapter-wide exams and topic-wise learning papers

## Workflows

- Exam Questions and exam Multiple Choice Questions select Class, Subject and Chapter only. Topic does not appear in authoring, imports, filters, simple generation or advanced exam grids. All three metadata columns must match the selected existing entries before an upload can save anything. Chapters without topics are supported. Exam templates omit the Topic header.
- Normal Question Bank keeps Topic: new authoring and both MCQ/written import forms select Class, Subject, Chapter and Topic. Excel/CSV templates are separate for MCQs and written questions, in Normal Text and HTML Source modes. The modern templates include difficulty, source, cognitive level, decimal marks, time and bilingual content. Imports never create curriculum entries.
- Question Bank now shares the paginated card layout, isolated add/edit/import pages and rich editor with exam authoring. Both banks support Nepali + English (default), English only or Nepali only. Matching paragraphs/subparts display Nepali (English). Decimal answer options are never treated as numbered labels.
- Learning Paper generator opens a row-based Test Paper Generator. Each row selects a chapter, optional topic, question type, count and total marks. Blank topic includes the chapter; count zero skips a row. Mixed rows use the exact bounded solver, enforce the saved question marks, exclude repeat IDs and fail explicitly on shortages. The exam bank cannot enter this pool.
- Generated test papers and answer keys have independent print actions. Changing print language retains the same selected question IDs. The previous automatic/manual/OCR/grid generator is still linked and its preview gains the same language controls.
- Learning Hub questions/answers and the older Question Paper Grid view use the same paired language rendering. Their existing content permissions and notes workflows are retained. The older grid's section totals now sum actual saved question weights, including mixed/decimal marks, rather than assuming every item has the first item's marks.

## Preservation and compatibility

No schema change or production data rewrite is needed. Historical exam topic IDs remain stored. Edits that keep the same class/subject/chapter preserve those references; changing scope does not carry old references into another chapter. Old saved grid JSON is not changed by preview/generation, but exam generation ignores its obsolete topic restrictions. An explicit grid save stores chapter-wide allocations.

Legacy normal-bank API and import/template routes remain available for existing clients. Existing topic-less learning records remain readable and editable; modern new-question forms and imports require an existing topic. Learning questions can keep an unspecified duration for backward compatibility; they contribute zero to the generated time estimate. Exam questions still require valid positive timing. Generation is bounded to 50 rows, 200 questions, 1,000 marks, 2,000 matching candidates and the solver's time/search limits.

Ownership, teacher assignments, tenant isolation, packages, archive/restore, school-preview restrictions and CSRF checks remain enforced by the shared services. Public school content stays within that school. Exam imports stay staff-only until explicitly released through the existing authoring permission.

## Verification and release limits

Automated service, UI and HTTP tests cover topic-free exam sample round trips, strict topic-wise learning metadata, mismatch rollback, exact mixed allocations, no bank crossing, isolated editor pages, bilingual rendering and unchanged official school/accounting records. Browser QA uses a disposable school database, including a chapter with no topics and a normal bank with two distinct topics. It verifies actual HTML Source XLSX uploads and language switching without rerandomization. Generated sample workbook headers/numeric fields are inspected separately.

The original school database, uploads and private configuration are not modified by this work. Local checks do not establish authenticated production behavior or physical printer output. MySQL staging verification remains outstanding. This change is local until separately committed/pushed/deployed.
