import importlib.util,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1]
class MySQLImportTests(unittest.TestCase):
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
