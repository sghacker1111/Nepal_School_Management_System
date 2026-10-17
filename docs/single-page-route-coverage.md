# Single-page route coverage

All 78 existing controllers are explicitly registered. Enhanced routes have bundled scoped adapters and automated coverage. Interactive browser validation remains a release gate, deferred until all code changes are complete at the user request.

| Canonical path | Controller | Kind | Lifecycle / verification |
| --- | --- | --- | --- |
| `/` | `index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/accounting/create-bill` | `modules/accounting/create_bill.php` | page | Pending adapter and HTTP verification |
| `/accounting/credit` | `modules/accounting/credit.php` | mixed | Pending adapter and HTTP verification |
| `/accounting/expenses` | `modules/accounting/expenses.php` | page | Pending adapter and HTTP verification |
| `/accounting/fees` | `modules/accounting/fees.php` | page | Pending adapter and HTTP verification |
| `/accounting/generate` | `modules/accounting/generate.php` | page | Pending adapter and HTTP verification |
| `/accounting` | `modules/accounting/index.php` | page | Pending adapter and HTTP verification |
| `/accounting/invoice` | `modules/accounting/invoice.php` | mixed | Pending adapter and HTTP verification |
| `/accounting/invoices` | `modules/accounting/invoices.php` | page | Pending adapter and HTTP verification |
| `/accounting/journals` | `modules/accounting/journals.php` | page | Pending adapter and HTTP verification |
| `/accounting/payments` | `modules/accounting/payments.php` | page | Pending adapter and HTTP verification |
| `/accounting/receipt` | `modules/accounting/receipt.php` | mixed | Pending adapter and HTTP verification |
| `/accounting/reports` | `modules/accounting/reports.php` | page | Pending adapter and HTTP verification |
| `/accounting/search` | `modules/accounting/search.php` | json | Native boundary/document; not boosted |
| `/accounting/settings` | `modules/accounting/settings.php` | page | Pending adapter and HTTP verification |
| `/accounting/student` | `modules/accounting/student.php` | page | Pending adapter and HTTP verification |
| `/accounting/subscriptions` | `modules/accounting/subscriptions.php` | page | Pending adapter and HTTP verification |
| `/attendance` | `modules/attendance/index.php` | page | Pending adapter and HTTP verification |
| `/account/change-password` | `modules/auth/change_password.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/login` | `modules/auth/login.php` | auth | Native boundary/document; not boosted |
| `/logout` | `modules/auth/logout.php` | auth | Native boundary/document; not boosted |
| `/account/profile` | `modules/auth/profile.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/account/verify-password` | `modules/auth/verify_password.php` | json | Native boundary/document; not boosted |
| `/classes/add` | `modules/classes/add.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/classes/edit` | `modules/classes/edit.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/classes` | `modules/classes/index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/classes/routine` | `modules/classes/routine.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/classes/subjects` | `modules/classes/subjects.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/exams/add` | `modules/exams/add.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/exams/admit-cards` | `modules/exams/admit_cards.php` | mixed | Pending adapter and HTTP verification |
| `/exams/edit` | `modules/exams/edit.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/exams` | `modules/exams/index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/exams/symbols` | `modules/exams/symbols.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/exams/view` | `modules/exams/view.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/learning/curriculum` | `modules/learning/curriculum.php` | mixed | Pending adapter and HTTP verification |
| `/exam-mcqs` | `modules/learning/exam_mcqs.php` | mixed | Pending adapter and HTTP verification |
| `/exam-paper-generator` | `modules/learning/exam_paper_generator.php` | mixed | Pending adapter and HTTP verification |
| `/exam-questions` | `modules/learning/exam_questions.php` | mixed | Pending adapter and HTTP verification |
| `/exam-paper-grids` | `modules/learning/exam_specification_grids.php` | mixed | Pending adapter and HTTP verification |
| `/learning` | `modules/learning/index.php` | mixed | Pending adapter and HTTP verification |
| `/learning/ocr-scanner` | `modules/learning/ocr_scanner.php` | mixed | Pending adapter and HTTP verification |
| `/learning/paper-generator` | `modules/learning/paper_generator.php` | mixed | Pending adapter and HTTP verification |
| `/learning/practice-mcqs` | `modules/learning/practice_mcqs.php` | mixed | Pending adapter and HTTP verification |
| `/learning/question-corrections` | `modules/learning/question_corrections.php` | page | Pending adapter and HTTP verification |
| `/learning/question-grid` | `modules/learning/question_grid.php` | page | Pending adapter and HTTP verification |
| `/learning/question-bank` | `modules/learning/questions.php` | mixed | Pending adapter and HTTP verification |
| `/learning/specification-grids` | `modules/learning/specification_grids.php` | mixed | Pending adapter and HTTP verification |
| `/reports` | `modules/reports/index.php` | page | Pending adapter and HTTP verification |
| `/results` | `modules/results/index.php` | page | Pending adapter and HTTP verification |
| `/results/ledger` | `modules/results/ledger.php` | page | Pending adapter and HTTP verification |
| `/results/marks-entry` | `modules/results/marks_entry.php` | page | Pending adapter and HTTP verification |
| `/results/print-bulk` | `modules/results/print_bulk.php` | mixed | Pending adapter and HTTP verification |
| `/results/report-card` | `modules/results/report_card.php` | print | Native boundary/document; not boosted |
| `/settings/cdc` | `modules/settings/cdc.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/settings/school` | `modules/settings/school.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/settings/users` | `modules/settings/users.php` | mixed | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/settings/years` | `modules/settings/years.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/students/add` | `modules/students/add.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/students/admit-card` | `modules/students/admit_card.php` | print | Native boundary/document; not boosted |
| `/students/edit` | `modules/students/edit.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/students/import-export` | `modules/students/import_export.php` | mixed | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/students` | `modules/students/index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/students/view` | `modules/students/view.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/subjects/add` | `modules/subjects/add.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/subjects/edit` | `modules/subjects/edit.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/subjects` | `modules/subjects/index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/subjects/internal` | `modules/subjects/internal.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/admin/exam-templates` | `modules/super_admin/exam_templates.php` | mixed | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/admin` | `modules/super_admin/index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/admin/question-corrections` | `modules/super_admin/question_corrections.php` | page | Pending adapter and HTTP verification |
| `/admin/schools` | `modules/super_admin/schools.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/admin/subjects` | `modules/super_admin/subjects.php` | mixed | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/admin/switch-school` | `modules/super_admin/switch_school.php` | auth | Native boundary/document; not boosted |
| `/admin/users` | `modules/super_admin/users.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/teachers/add` | `modules/teachers/add.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/teachers/edit` | `modules/teachers/edit.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/teachers/import-export` | `modules/teachers/import_export.php` | mixed | Scoped adapter; automated HTTP/DOM coverage; browser pending |
| `/teachers` | `modules/teachers/index.php` | page | Scoped adapter; automated HTTP/DOM coverage; browser pending |
