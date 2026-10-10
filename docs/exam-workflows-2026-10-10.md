# Exam dates, symbols, mapping corrections and IN contributions

## Date controls

Exam creation/editing offers AD or BS entry for start/end dates. Only the selected input calendar is submitted; the other input shows the converted date. Issue dates can be automatic (today in Nepal time) or manual in AD/BS. Print calendar (BS, AD or both) and format (YYYY-MM-DD, DD-MM-YYYY or named month) apply to report/admit issue dates and admit exam dates. Exam defaults are saved; print-only overrides do not modify the exam. Report/admit DOB also follows the print choice.

Other existing date inputs, including attendance and teacher joining/permit dates, offer an AD/BS selector. Dates remain canonical AD in storage. Attendance also records the real converted BS date. Existing student DOB pairs remain available.

Conversion uses the month table already bundled in nepali-date-converter, derived from [subeshb1/Nepali-Date](https://github.com/subeshb1/Nepali-Date). Supported range is 2000–2090 BS. Impossible/out-of-range BS dates are rejected, never approximated by a year offset. Future table values should be reviewed when the calendar library is updated; this is not an assertion of official calendar certification.

## Exam-specific symbols

Open **Exam → Configure → Manage Exam Symbol Numbers**, then choose a class:

- Alphabetical mode assigns serial numbers from the first supplied number, preserving leading zeros.
- Manual mode edits individual symbols; blank cells leave existing values unchanged.
- Previous-exam mode copies the same student's earlier exam assignment.
- Explicit legacy-copy mode is offered where old student exam-roll fields contain data. These unscoped values are never automatically used on any exam's cards.

Symbols are unique per examination across classes. Replacement requires confirmation; duplicates roll back the whole operation. Locked/published exams must be reopened. Registration roll numbers are never modified. Old/new assignments are retained in exam_symbol_history.

## Admit-card selection and printing

Choose class, then **All** or **Choose students**. All hides the list and prints the class. Choose offers server-side search by name/symbol/roll with 10 results per page. Checkbox selections persist across filtering and pagination. **Generate Cards** previews only the requested students; **Print** prints that preview. No selection is an error, not an implicit request for every student. The student-profile shortcut requires choosing an exam and uses the same generator.

The logo is left of the school heading. A narrow-screen preview stacks cards; A4 printing keeps the four-card 2×2 layout. Print settings may depend on the printer/browser; use A4 and disable the browser's own headers/footers.

## Subject mappings and order

Open exams can add missing classes/subjects after marks exist. Auto-initialize includes visible electives, including inactive optional offerings, without certifying/activating those offerings or changing enrollment. Existing mappings are not overwritten.

Editing a mapping with marks requires confirmation and a current revision. The class/subject cannot be reassigned. New limits below existing marks/component scores are rejected. Entered scores remain unchanged; stored grades/GPA/remarks are recalculated, and old/new configurations are recorded in exam_scheme_history. Assessment profiles are not implicitly migrated by editing a limit. Locked/published exams still require reopening.

Marks entry, ledger and report cards share Nepali-first, English-second subject ordering.

## Earlier-exam contributions

**Marks Entry → Load Earlier Examination Marks (IN)** offers only earlier unit/terminal exams in the same school/year. Scheduled dates determine chronology; undated exams fall back to creation sequence. Select one or multiple sources, then click **Load Converted Marks**.

For a terminal's written-test contribution:

sum(source obtained TH + required IN) / sum(source effective full marks) × target field maximum

For example, (75 + 65)/(100 + 100) × 6 = 4.2. Different source maxima use the weighted denominator, not an unweighted average of percentages. Unit/theory-only exams exclude IN from both numerator and denominator. Missing source marks/subjects leave that student's contribution unchanged; missing marks are not zero.

Annual schemes with separate first/second-trimester fields load the selected chronological exams into their respective field maxima. Existing CDC component allocations remain unchanged.

Loading is read-only preview. Only the contribution fields are filled; TH and other IN fields stay unchanged. Review and submit **Save Marks** to persist. Success/error messages and accessible dismissible notifications are shared across signed-in roles; preview loading is labelled as review, not a saved result.

## Verification and deployment

- PHP syntax: 95 source/test files.
- Existing HTTP regression: 158 requests.
- New exam HTTP suite: 33 requests.
- New exam workflow checks: calendar boundaries, symbols, audits, conversions, roles and guarded updates.
- Existing grading, CDC (726 checks), security, operations, migration and content suites.
- Schema 6 upgrade checked on a disposable real-database copy and applied locally with an automatic backup. Existing record values, integrity and workbook mappings were verified unchanged.
- Browser verification used only synthetic data: BS switching/saving, serial symbols, pagination selection, logo placement, responsive cards and console checks.

Run php tests/exam_workflows_test.php and python tests/exam_http_test.py alongside the existing suites. MySQL execution and physical-printer output still require staging/device checks. Local edits are not deployed; pushing main triggers the existing FTP workflow.
