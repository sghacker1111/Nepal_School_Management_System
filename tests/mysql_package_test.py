import hashlib,json,os,sqlite3,subprocess,sys,tempfile,unittest,uuid
from contextlib import closing
from unittest.mock import patch
from pathlib import Path
from mysql_test_support import MysqlImportFixture,ROOT

class MySQLPackageTests(unittest.TestCase):
    def test_source_commit_during_gzip_keeps_package_not_ready(self):
        sys.path.insert(0,str(ROOT/'tools'))
        import export_mysql
        with MysqlImportFixture(clean_accounting=True) as fixture, tempfile.TemporaryDirectory(prefix='erp-package-out-') as parent:
            output=Path(parent)/('school-'+uuid.uuid4().hex)
            original=export_mysql.gzip_file
            def commit_after_gzip(source,destination):
                original(source,destination)
                with closing(sqlite3.connect(fixture.source)) as db:
                    db.execute("INSERT INTO custom_rows VALUES('late committed row')");db.commit()
            args=['export_mysql.py','--source',str(fixture.source),'--output',str(output),'--verify-local-mysql']
            with patch.dict(os.environ,fixture.env),patch.object(sys,'argv',args),patch.object(export_mysql,'gzip_file',commit_after_gzip):
                code=export_mysql.main()
            self.assertNotEqual(code,0,'A source commit after SQL verification must invalidate the package')
            self.assertFalse(json.loads((output/'migration-report.json').read_text(encoding='utf-8'))['ready'])
            self.assertFalse(json.loads((output/'migration-manifest.json').read_text(encoding='utf-8'))['ready'])
            with closing(sqlite3.connect(fixture.source)) as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM custom_rows').fetchone()[0],3)
    def test_verified_private_export_is_complete_and_source_read_only(self):
        with MysqlImportFixture(clean_accounting=True) as fixture, tempfile.TemporaryDirectory(prefix='erp-package-out-') as parent:
            source_hash=hashlib.sha256(fixture.source.read_bytes()).hexdigest()
            output=Path(parent)/('school-'+uuid.uuid4().hex)
            result=subprocess.run([os.environ.get('ERP_TEST_PYTHON','python'),str(ROOT/'tools/export_mysql.py'),'--source',str(fixture.source),'--output',str(output),'--verify-local-mysql'],cwd=ROOT,env=fixture.env,capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=120)
            self.assertEqual(result.returncode,0,'Verified private export failed: '+result.stderr[-500:])
            self.assertTrue((output/'school_erp_mysql.sql').is_file());self.assertTrue((output/'school_erp_mysql.sql.gz').is_file())
            self.assertTrue((output/'IMPORT-INSTRUCTIONS.md').is_file())
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
