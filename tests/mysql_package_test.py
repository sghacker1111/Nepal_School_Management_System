import hashlib,json,os,subprocess,tempfile,unittest,uuid
from pathlib import Path
from mysql_test_support import MysqlImportFixture,ROOT

class MySQLPackageTests(unittest.TestCase):
    def test_verified_private_export_is_complete_and_source_read_only(self):
        with MysqlImportFixture(clean_accounting=True) as fixture, tempfile.TemporaryDirectory(prefix='erp-package-out-') as parent:
            source_hash=hashlib.sha256(fixture.source.read_bytes()).hexdigest()
            output=Path(parent)/('school-'+uuid.uuid4().hex)
            result=subprocess.run([os.environ.get('ERP_TEST_PYTHON','python'),str(ROOT/'tools/export_mysql.py'),'--source',str(fixture.source),'--output',str(output),'--verify-local-mysql'],cwd=ROOT,env=fixture.env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=120)
            self.assertEqual(result.returncode,0,'Verified private export failed: '+result.stderr[-500:])
            self.assertTrue((output/'school_erp_mysql.sql').is_file());self.assertTrue((output/'school_erp_mysql.sql.gz').is_file())
            manifest=json.loads((output/'migration-manifest.json').read_text(encoding='utf-8'))
            report=json.loads((output/'migration-report.json').read_text(encoding='utf-8'))
            self.assertTrue(manifest['ready']);self.assertTrue(report['ready'])
            self.assertEqual(report['known_orphans'],50);self.assertEqual(report['new_violations'],0)
            self.assertEqual(hashlib.sha256(fixture.source.read_bytes()).hexdigest(),source_hash)
            self.assertNotIn('SYNTHETIC_HASH_DO_NOT_REPLACE',result.stdout+result.stderr)
            rerun=subprocess.run([os.environ.get('ERP_TEST_PYTHON','python'),str(ROOT/'tools/export_mysql.py'),'--source',str(fixture.source),'--output',str(output),'--verify-local-mysql'],cwd=ROOT,env=fixture.env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=30)
            self.assertNotEqual(rerun.returncode,0)
    def test_nonempty_local_target_leaves_sentinel_and_emits_not_ready_report(self):
        with MysqlImportFixture(clean_accounting=True) as fixture, tempfile.TemporaryDirectory(prefix='erp-package-out-') as parent:
            fixture.create_sentinel();before=fixture.sentinel();output=Path(parent)/('school-'+uuid.uuid4().hex)
            result=subprocess.run([os.environ.get('ERP_TEST_PYTHON','python'),str(ROOT/'tools/export_mysql.py'),'--source',str(fixture.source),'--output',str(output),'--verify-local-mysql'],cwd=ROOT,env=fixture.env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=120)
            self.assertNotEqual(result.returncode,0);self.assertEqual(fixture.sentinel(),before)
            report=json.loads((output/'migration-report.json').read_text(encoding='utf-8'))
            self.assertFalse(report['ready'])
if __name__=='__main__':unittest.main()
