import importlib.util,os,sqlite3,subprocess,sys,tempfile,unittest
from contextlib import closing
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))

class ProjectionTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('mysql_migration.projection'),'Add-only schema projection is missing')
        from mysql_migration.projection import build_blueprint,project_snapshot,assert_preserved
        from mysql_migration.snapshot import capture_snapshot,inspect_database
        self.project=project_snapshot;self.preserved=assert_preserved;self.inspect=inspect_database
        self.temp=tempfile.TemporaryDirectory(prefix='erp-mysql-projection-');self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
        (self.root/'source').mkdir();source=self.root/'source/v7.sqlite'
        env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(source)}
        subprocess.run([os.environ.get('ERP_TEST_PHP','php'),'tests/schema7_fixture.php'],cwd=ROOT,env=env,check=True,capture_output=True)
        with closing(sqlite3.connect(source)) as db:
            db.execute("UPDATE subjects SET name_en='Compulsory Nepali' WHERE id=(SELECT MIN(id) FROM subjects)")
            db.execute("UPDATE users SET password='SYNTHETIC_HASH_DO_NOT_REPLACE' WHERE id=1")
            db.execute('DELETE FROM marks');db.execute('DELETE FROM students');db.execute('DELETE FROM exams')
            for i in range(50):db.execute('INSERT INTO marks(student_id,exam_id,subject_id,theory_obtained) VALUES(?,999999,1,?)',(999999+i,i))
            db.execute('CREATE TABLE custom_rows(label TEXT)');db.execute("INSERT INTO custom_rows VALUES('keep duplicate'),('keep duplicate')")
            db.execute("UPDATE sqlite_sequence SET seq=9000 WHERE name='marks'")
            db.commit()
        self.snap=capture_snapshot(source,self.root/'private');self.blueprint=build_blueprint(os.environ.get('ERP_TEST_PHP','php'),self.root/'blueprint')
    def test_original_cells_and_fifty_orphans_survive_schema18(self):
        result=self.project(self.snap,self.blueprint,self.root/'projected.sqlite')
        self.preserved(self.snap.path,result.path)
        m=result.manifest
        self.assertEqual(m['schema_version'],'18');self.assertEqual(m['tables']['marks']['row_count'],50)
        self.assertEqual(m['tables']['students']['row_count'],0);self.assertEqual(m['tables']['exams']['row_count'],0)
        self.assertEqual(m['tables']['custom_rows']['row_count'],2)
        self.assertEqual(len(m['foreign_key_violations']),len(self.snap.manifest['foreign_key_violations']))
        self.assertGreaterEqual(m['tables']['marks']['sequence'],9000)
        with closing(sqlite3.connect(result.path)) as db:
            self.assertEqual(db.execute('SELECT password FROM users WHERE id=1').fetchone()[0],'SYNTHETIC_HASH_DO_NOT_REPLACE')
            self.assertTrue(db.execute("SELECT 1 FROM subjects WHERE name_en='Compulsory Nepali'").fetchone())
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ac_invoices').fetchone()[0],1)
            self.assertEqual(db.execute('SELECT total_minor FROM ac_invoices WHERE id=21').fetchone()[0],12345)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ac_journals').fetchone()[0],1)
    def test_second_projection_has_identical_rows_and_defaults(self):
        from mysql_migration.snapshot import Snapshot,digest_file
        first=self.project(self.snap,self.blueprint,self.root/'one.sqlite')
        next_snapshot=Snapshot(first.path,self.snap.source_path,first.manifest,first.manifest,'synthetic',digest_file(first.path))
        second=self.project(next_snapshot,self.blueprint,self.root/'two.sqlite')
        for name,meta in first.manifest['tables'].items():
            self.assertEqual(meta['rows_digest'],second.manifest['tables'][name]['rows_digest'],name)
    def test_existing_password_rewrite_is_detected(self):
        from mysql_migration.snapshot import MigrationError
        result=self.project(self.snap,self.blueprint,self.root/'projected.sqlite')
        with closing(sqlite3.connect(result.path)) as db:db.execute("UPDATE users SET password='unexpected rewrite' WHERE id=1");db.commit()
        with self.assertRaises(MigrationError):self.preserved(self.snap.path,result.path)

if __name__=='__main__':unittest.main()
