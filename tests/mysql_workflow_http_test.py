"""Run existing HTTP assertions unchanged against separate native MySQL fixtures."""
import os
import subprocess
import sys
import unittest
from mysql_test_support import ROOT


class NativeMySQLHttpTests(unittest.TestCase):
    def test_school_and_learning_http_parity(self):
        suites = ('class_isolation_http_test.py', 'attendance_filters_http_test.py',
                  'teacher_results_http_test.py', 'progress_print_http_test.py',
                  'mcq_practice_http_test.py', 'learning_sharing_http_test.py',
                  'exam_question_import_http_test.py', 'question_source_http_test.py',
                  'exam_teacher_scope_http_test.py', 'question_corrections_http_test.py',
                  'accounting_http_test.py', 'single_page_coverage_http_test.py',
                  'learning_app_http_test.py','mysql_auth_preview_http.py')
        for suite in suites:
            with self.subTest(suite=suite):
                env = {**os.environ, 'ERP_HTTP_NATIVE_MYSQL': '1'}
                result = subprocess.run([sys.executable, 'tests/'+suite],
                                        cwd=ROOT, env=env, capture_output=True, text=True,
                                        encoding='utf-8', errors='replace', timeout=240)
                self.assertEqual(result.returncode, 0, suite+' native MySQL failure: '+result.stdout[-1800:]+result.stderr[-1800:])


if __name__ == '__main__':
    unittest.main()
