import subprocess,unittest
from mysql_test_support import MysqlImportFixture,ROOT
class MySQLWorkflowTests(unittest.TestCase):
    def test_mathematics_area_targets_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/mathematics_catalog_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Mathematics area targets must pass on native MySQL: '+result.stdout[-2000:]+result.stderr[-2000:])
    def test_paper_format_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/paper_format_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Paper formats must pass on native MySQL: '+result.stdout[-2000:]+result.stderr[-2000:])
    def test_question_bulk_archive_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/question_bulk_archive_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Bulk archive must pass on native MySQL: '+result.stdout[-2000:]+result.stderr[-2000:])
            self.assertIn('Question bulk archive passed:',result.stdout)
    def test_structured_import_groups_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/question_parts_import_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Structured imports must pass on native MySQL: '+result.stdout[-2000:]+result.stderr[-2000:])
            self.assertIn('Structured import checks passed:',result.stdout)
    def test_structured_questions_and_cognitive_solver_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/question_parts_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Structured question behavior must pass on native MySQL: '+result.stdout[-2000:]+result.stderr[-2000:])
            self.assertIn('Structured question checks passed:',result.stdout)
    def test_school_offering_identity_and_snapshot_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/school_offerings_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'School offering identity and snapshot must pass on native MySQL: '+result.stdout[-2000:]+result.stderr[-2000:])
            self.assertIn('School offerings service passed:',result.stdout)
    def test_accounting_service_workflows_on_native_mysql(self):
        with MysqlImportFixture(clean_accounting=True) as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/accounting_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Accounting behavior must pass on native MySQL: '+result.stdout[-1000:]+result.stderr[-1000:])
            self.assertIn('Accounting passed:',result.stdout)
    def test_exam_mark_workflows_on_native_mysql(self):
        with MysqlImportFixture() as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/exam_workflows_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Exam and marks behavior must pass on native MySQL: '+result.stdout[-1000:]+result.stderr[-1000:])
            self.assertIn('Exam workflows passed',result.stdout)
    def test_teacher_result_and_class_scope_on_native_mysql(self):
        with MysqlImportFixture() as fixture:
            fixture.import_and_verify()
            env={**fixture.env,'ERP_TEST_MYSQL':'1'}
            result=subprocess.run([env['ERP_TEST_PHP'],'tests/teacher_results_test.php'],cwd=ROOT,env=env,capture_output=True,text=True,timeout=120)
            self.assertEqual(result.returncode,0,'Teacher result scope must pass on native MySQL: '+result.stdout[-1000:]+result.stderr[-1000:])
            self.assertIn('Teacher results passed',result.stdout)
if __name__=='__main__':unittest.main()
