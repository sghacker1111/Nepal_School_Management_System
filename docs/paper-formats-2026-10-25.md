# Paper headers and custom groups / sections

Both **Exam Paper Generator** and **Test Paper Generator** have a **Custom groups / sections** tab after class and subject selection. The existing chapter, topic, advanced grid and automatic/manual generators remain available.

1. Enter the paper title or choose First, Second, Third Terminal, Unit Test or Annual Examination. Link an existing exam when its name and mapping should be used.
2. Set the centered school name, choose **Group**, **Section** or **None**, and enter optional instruction lines. This changes the generated paper only; it does not rename the school or alter exam records.
3. In Custom groups / sections, set duration in minutes. Each block can have an optional title and several allocation rows. Each row specifies question type, one or several chapters, whole question count and total marks. Optional marks each automatically calculates the allocation total. Leave marks each blank for variable stored weights.
4. Add blocks and allocations as needed. Reorder blocks with Move up/down. A zero-count allocation is skipped, but every block must have an active allocation. Limits are 26 blocks, 50 active allocations, 200 questions and 1,000 marks per paper.
5. Generate and review the paper and answer key. Question counts and marks are checked against the selected bank. No repeated question, changed stored marks, incomplete output, foreign-school chapter or cross-bank fallback is accepted by the custom builder.

The printable header centers school and exam names, adds a rule, places Class / Time / Full Marks on one row, then prints Subject and instructions above another rule. A duration of 150 minutes appears as **02:30 Hours**. The answer key uses the same header and question ordering.

Group/section summaries use actual saved question marks: `(10 × 4 = 40)` for equal weights, or `(2 × 2 + 1 × 4 = 8)` for mixed weights. None removes the group headings and numbers every main question continuously. Custom groups may mix MCQs, very short, short, long and the existing additional question types. MCQ choices and correct options remain attached to their own question.

Structured questions retain their hanging bilingual alignment, selected language order, per-part marks and cognitive categories. The whole-question total is included in paper allocation but is not repeated beside the statement. Existing automatic/manual generation also uses the new header, instructions and heading choice; structured question totals in that older generator are preserved when its general marks controls are changed.

No database migration is required. Changes are verified with disposable SQLite and native MySQL fixtures, complete form HTTP flows, answer-key parity, header escaping, rejected allocations, frontend lifecycle tests and a guarded FTPS code/hash verification. Live browser/print-preview inspection is unavailable in this session.
