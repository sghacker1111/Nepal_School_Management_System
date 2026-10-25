# Class 9–10 compulsory mathematics areas and source grids

System admin can open **Exam Paper Grids** in the shared catalog and select **Install missing mathematics chapters and default grids**. Class 9 and Class 10 compulsory Mathematics must be active first. Setup adds missing chapters and nine shared presets. It preserves existing chapter IDs, questions, archived records, custom area mappings and edited presets. Repeating setup does not reset an existing default. No schema migration or database replacement is required; reading a page never installs data.

Select a class and compulsory Mathematics to edit its **Content areas and chapters**. Each chapter can belong to one area; an area can include several chapters. School accounts can read the shared mappings and copy a grid, but cannot change the shared catalog. Existing school subject assignments and teacher restrictions still apply.

## Included presets

| Source | Grade | Examinations | Source pages |
| --- | --- | --- | --- |
| CDC specification grid, 2078 | 9 and 10 | CDC reference | 1 |
| Owner-supplied full-grid PDF, 2083 | 9 | First Term, Mid Term, Second Term, Annual | 2, 4, 5, 6 |
| Owner-supplied full-grid PDF, 2083 | 10 | First Term, Mid Term, Pre-Qualifying | 8, 10, 11 |

The second PDF is attributed to PABSON by the owner; the application records that attribution without claiming independent publisher verification. Source filenames, SHA-256 checksums, page numbers, area allocations and review notes are kept in `config/data/mathematics_grids.json`. The original PDFs are not published with the application.

The seven areas are Sets, Arithmetic, Mensuration, Algebra, Geometry, Statistics and Probability, and Trigonometry. The setup adds the Class 9 catalog and Class 10 chapters, including separate Pyramid, Cone, Combined Solids, Cost Estimation and Exponential Equations chapters. Existing broader Area and Volume / Indices chapters remain available.

The CDC and Class 10 Pre-Qualifying allocations are 6, 13, 13, 15, 13, 11 and 4 marks respectively: 16 main questions, 49 subquestion items and 75 marks in three hours. Generation enforces each area's chapter pool, main-question count, marks, and supplied consistent cognitive mark/item targets. K, U, A, HA and KU remain independent categories. A missing eligible question produces an error instead of an incomplete paper.

## Source discrepancies and authoring

- Class 9 First Term area item counts add to 50 while the footer prints 49; its Mensuration row also has an inconsistent subtotal. Those item counts remain reference-only.
- Class 9 Mid/Second Term footers print K=11/U=13 while the area rows add to K=12/U=12. Those item counts remain reference-only.
- Class 9 Annual syllabus and grid headings use different years (2082/2083). The preset follows the grid heading, with a review note.
- Several term grids omit cognitive marks. The application enforces area totals and any consistent item targets, without inferring marks. Class 10's `C` column is interpreted as comprehension / U, with an explicit note; it is not KU.
- Printed term chapter pools are included, but syllabus subtopic coverage and cross-area questions still require academic review. The CDC's permitted cognitive-mark variation is documented in the preset note; the bundled allocation uses the printed exact targets.

Structured mathematics questions keep **Strict — K first, HA last** as the default. Select **CDC / PABSON** explicitly when authoring combinations needed by the source grids. For example, Arithmetic has three main questions but only two K and two HA items, so the strict rule cannot satisfy that source allocation for every main question. Each CDC-mode part still requires valid marks and a single independent cognitive category. The mode survives editing and normal/HTML imports using the optional **Mathematics Rule** column (`strict` or `cdc`).

Labels and marks annotations now use the English reading size, avoiding the larger font compensation needed by Kokila's Nepali glyphs. The common hanging gutter for both languages is retained in saved views, paper printing and Word export.

## Verification and activation

Disposable SQLite and native MySQL checks cover setup, permissions, preservation, idempotence and source allocation. HTTP tests exercise both question banks, strict/CDC authoring and shared area controls. The real PHP area/grid page is mounted and disposed with the shipped frontend adapters in an isolated DOM.

Deployment transfers code only. Run the setup action while signed in as system admin to install the presets into the hosted database. A successful FTP runtime check does not confirm that this action has run, nor replace authenticated browser and physical print review.
