# Single-page route coverage

All 78 existing controllers are explicitly registered. Routing ready does not mean a page lifecycle is complete. No route is enhanced until its adapter and response-mode tests pass.

| Canonical path | Controller | Kind | Lifecycle / verification |
| --- | --- | --- | --- |
| `/` | `index.php` | page | Pending adapter and HTTP verification |
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
| `/accounting/search` | `modules/accounting/search.php` | json | Pending adapter and HTTP verification |
| `/accounting/settings` | `modules/accounting/settings.php` | page | Pending adapter and HTTP verification |
| `/accounting/student` | `modules/accounting/student.php` | page | Pending adapter and HTTP verification |
| `/accounting/subscriptions` | `modules/accounting/subscriptions.php` | page | Pending adapter and HTTP verification |
| `/attendance` | `modules/attendance/index.php` | page | Pending adapter and HTTP verification |
| `/account/change-password` | `modules/auth/change_password.php` | page | Pending adapter and HTTP verification |
| `/login` | `modules/auth/login.php` | auth | Pending adapter and HTTP verification |
| `/logout` | `modules/auth/logout.php` | auth | Pending adapter and HTTP verification |
| `/account/profile` | `modules/auth/profile.php` | page | Pending adapter and HTTP verification |
| `/account/verify-password` | `modules/auth/verify_password.php` | json | Pending adapter and HTTP verification |
| `/classes/add` | `modules/classes/add.php` | page | Pending adapter and HTTP verification |
| `/classes/edit` | `modules/classes/edit.php` | page | Pending adapter and HTTP verification |
| `/classes` | `modules/classes/index.php` | page | Pending adapter and HTTP verification |
| `/classes/routine` | `modules/classes/routine.php` | page | Pending adapter and HTTP verification |
| `/classes/subjects` | `modules/classes/subjects.php` | page | Pending adapter and HTTP verification |
| `/exams/add` | `modules/exams/add.php` | page | Pending adapter and HTTP verification |
| `/exams/admit-cards` | `modules/exams/admit_cards.php` | mixed | Pending adapter and HTTP verification |
| `/exams/edit` | `modules/exams/edit.php` | page | Pending adapter and HTTP verification |
| `/exams` | `modules/exams/index.php` | page | Pending adapter and HTTP verification |
| `/exams/symbols` | `modules/exams/symbols.php` | page | Pending adapter and HTTP verification |
| `/exams/view` | `modules/exams/view.php` | page | Pending adapter and HTTP verification |
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
| `/results/report-card` | `modules/results/report_card.php` | print | Pending adapter and HTTP verification |
| `/settings/cdc` | `modules/settings/cdc.php` | page | Pending adapter and HTTP verification |
| `/settings/school` | `modules/settings/school.php` | page | Pending adapter and HTTP verification |
| `/settings/users` | `modules/settings/users.php` | mixed | Pending adapter and HTTP verification |
| `/settings/years` | `modules/settings/years.php` | page | Pending adapter and HTTP verification |
| `/students/add` | `modules/students/add.php` | page | Pending adapter and HTTP verification |
| `/students/admit-card` | `modules/students/admit_card.php` | print | Pending adapter and HTTP verification |
| `/students/edit` | `modules/students/edit.php` | page | Pending adapter and HTTP verification |
| `/students/import-export` | `modules/students/import_export.php` | mixed | Pending adapter and HTTP verification |
| `/students` | `modules/students/index.php` | page | Pending adapter and HTTP verification |
| `/students/view` | `modules/students/view.php` | page | Pending adapter and HTTP verification |
| `/subjects/add` | `modules/subjects/add.php` | page | Pending adapter and HTTP verification |
| `/subjects/edit` | `modules/subjects/edit.php` | page | Pending adapter and HTTP verification |
| `/subjects` | `modules/subjects/index.php` | page | Pending adapter and HTTP verification |
| `/subjects/internal` | `modules/subjects/internal.php` | page | Pending adapter and HTTP verification |
| `/admin/exam-templates` | `modules/super_admin/exam_templates.php` | mixed | Pending adapter and HTTP verification |
| `/admin` | `modules/super_admin/index.php` | page | Pending adapter and HTTP verification |
| `/admin/question-corrections` | `modules/super_admin/question_corrections.php` | page | Pending adapter and HTTP verification |
| `/admin/schools` | `modules/super_admin/schools.php` | page | Pending adapter and HTTP verification |
| `/admin/subjects` | `modules/super_admin/subjects.php` | mixed | Pending adapter and HTTP verification |
| `/admin/switch-school` | `modules/super_admin/switch_school.php` | auth | Pending adapter and HTTP verification |
| `/admin/users` | `modules/super_admin/users.php` | page | Pending adapter and HTTP verification |
| `/teachers/add` | `modules/teachers/add.php` | page | Pending adapter and HTTP verification |
| `/teachers/edit` | `modules/teachers/edit.php` | page | Pending adapter and HTTP verification |
| `/teachers/import-export` | `modules/teachers/import_export.php` | mixed | Pending adapter and HTTP verification |
| `/teachers` | `modules/teachers/index.php` | page | Pending adapter and HTTP verification |

Mixed controllers retain their existing page, JSON, upload/template and print modes. The relevant module task must enumerate and test these modes before marking its row ready. Dedicated JSON, print and authentication routes are intentional response boundaries, not missing SPA adapters.
