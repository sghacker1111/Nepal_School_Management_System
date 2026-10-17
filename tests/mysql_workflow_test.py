import subprocess,unittest
from mysql_test_support import MysqlImportFixture,ROOT
class MySQLWorkflowTests(unittest.TestCase):
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
