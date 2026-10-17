import hashlib,importlib.util,json,subprocess,unittest
from mysql_test_support import MysqlImportFixture
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
class MySQLImportTests(unittest.TestCase):
    def test_storage_warning_and_appended_statements_are_refused(self):
        for sql,expected in [("INSERT INTO `custom_rows` (`label`) VALUES(CAST('invalid' AS SIGNED))",'storage warning'),("INSERT INTO `custom_rows` (`label`) VALUES('first'); DELETE FROM `custom_rows`",'MySQL migration verification failed')]:
            with self.subTest(expected=expected),MysqlImportFixture(clean_accounting=True) as fixture:
                manifest_path=fixture.package/'migration-manifest.json'
                manifest=json.loads(manifest_path.read_text(encoding='utf-8'))
                control=fixture.package/manifest['statements_file']
                statements=[json.loads(line) for line in control.read_text(encoding='utf-8').splitlines()]
                at=statements.index('COMMIT')+1
                statements[at:at]=["SET SESSION sql_mode=''",sql]
                control.write_text(''.join(json.dumps(statement,ensure_ascii=False)+'\n' for statement in statements),encoding='utf-8')
                dump=fixture.package/manifest['sql_file']
                dump.write_text('-- School ERP confidential export. Import into a NEW EMPTY database only.\n'+''.join(statement+';\n' for statement in statements),encoding='utf-8',newline='\n')
                manifest['sql_sha256']=hashlib.sha256(dump.read_bytes()).hexdigest()
                manifest['statements_sha256']=hashlib.sha256(control.read_bytes()).hexdigest()
                manifest_path.write_text(json.dumps(manifest),encoding='utf-8')
                result=fixture.command()
                self.assertNotEqual(result.returncode,0);self.assertIn(expected,result.stderr)
    def test_legacy_integer_marks_accept_future_fractional_and_full_marks(self):
        from decimal import Decimal
        with MysqlImportFixture(legacy_marks=True) as fixture:
            fixture.import_and_verify()
            for value in ('26.25','100'):
                result=fixture._sql('UPDATE marks SET theory_obtained='+value+' WHERE id=(SELECT value FROM (SELECT MIN(id) AS value FROM marks) AS chosen)')
                self.assertEqual(result.returncode,0,result.stderr[-500:])
                code="require 'config/bootstrap.php';require 'includes/database.php';echo json_encode(getDB()->query('SELECT theory_obtained FROM marks ORDER BY id LIMIT 1')->fetchColumn());"
                result=subprocess.run([fixture.env['ERP_TEST_PHP'],'-r',code],cwd=ROOT,env=fixture.env,capture_output=True,text=True,check=True)
                self.assertEqual(Decimal(json.loads(result.stdout)),Decimal(value))
    def test_native_mysql_round_trip_and_known_orphans(self):
        self.assertTrue((ROOT/'tools/mysql_migration/verify_mysql.php').is_file(),'Native MySQL import/readback bridge is missing')
        self.assertTrue((ROOT/'tests/mysql_test_support.py').is_file(),'Isolated MySQL fixture support is missing')
        from mysql_test_support import MysqlImportFixture
        with MysqlImportFixture() as fixture:
            report=fixture.import_and_verify()
            self.assertTrue(report['preserved']);self.assertEqual(report['known_orphans'],50);self.assertEqual(report['new_violations'],0)
            from mysql_migration.verify import verify_import
            with patch.dict('os.environ',fixture.env):verified=verify_import(fixture.snap,fixture.projection,fixture.report_input)
            self.assertTrue(verified['preserved']);self.assertEqual(verified['engine'],'8.0.45')
            fixture.assert_fk_rejected();fixture.assert_readiness_read_only()
    def test_nonempty_and_partial_targets_cannot_be_reimported(self):
        self.assertTrue((ROOT/'tools/mysql_migration/verify_mysql.php').is_file(),'Native MySQL import/readback bridge is missing')
        from mysql_test_support import MysqlImportFixture
        with MysqlImportFixture() as fixture:
            fixture.create_sentinel();before=fixture.sentinel()
            result=fixture.import_command()
            self.assertNotEqual(result.returncode,0);self.assertEqual(fixture.sentinel(),before)
        with MysqlImportFixture() as fixture:
            fixture.create_partial();result=fixture.import_command()
            self.assertNotEqual(result.returncode,0)
            self.assertEqual(fixture._sql('SELECT 1 FROM partial_import').returncode,0)
    def test_bad_credentials_do_not_create_sqlite_or_leak(self):
        self.assertTrue((ROOT/'tools/mysql_migration/verify_mysql.php').is_file(),'Native MySQL import/readback bridge is missing')
        from mysql_test_support import MysqlImportFixture
        with MysqlImportFixture() as fixture:
            result=fixture.bad_password_request()
            self.assertNotEqual(result.returncode,0);self.assertNotIn('PRIVATE_INVALID_PASSWORD',result.stdout+result.stderr)
            self.assertFalse((ROOT/'database.sqlite').exists())

if __name__=='__main__':unittest.main()
