import importlib.util,sqlite3,sys,tempfile,unittest,json,tracemalloc
from contextlib import closing
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))

class WriterTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('mysql_migration.sql_writer'),'Portable MySQL writer is missing')
        from mysql_migration.sql_writer import write_mysql_dump
        from mysql_migration.snapshot import inspect_database
        from mysql_migration.projection import Projection
        self.write=write_mysql_dump;self.inspect=inspect_database;self.Projection=Projection
        self.temp=tempfile.TemporaryDirectory(prefix='erp-mysql-writer-');self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name);self.dbfile=self.root/'fixture.sqlite'
        with closing(sqlite3.connect(self.dbfile)) as db:
            db.executescript('CREATE TABLE students(id INTEGER PRIMARY KEY AUTOINCREMENT); CREATE TABLE marks(id INTEGER PRIMARY KEY AUTOINCREMENT,student_id INTEGER,FOREIGN KEY(student_id) REFERENCES students(id)); CREATE TABLE texts(id INTEGER PRIMARY KEY AUTOINCREMENT,prose TEXT,empty VARCHAR(10),score DECIMAL(5,2),amount_minor BIGINT,raw BLOB,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,unique_key VARCHAR(64) UNIQUE); CREATE TABLE duplicate_rows(value TEXT);')
            for i in range(50):db.execute('INSERT INTO marks(student_id) VALUES(?)',(999+i,))
            db.execute('INSERT INTO students(id) VALUES(0)')
            db.execute('INSERT INTO texts(id,prose,empty,score,amount_minor,raw,created_at) VALUES(0,?,?,?,?,?,?)',("नेपाली \\frac{1}{2} 'quoted'",'',12.345678,9223372036854775807,b'\x00\xff','2026-10-17 12:00:00'))
            db.execute("INSERT INTO duplicate_rows VALUES(NULL),(NULL)")
            db.execute("UPDATE sqlite_sequence SET seq=9000 WHERE name='marks'");db.commit()
    def export(self):
        m=self.inspect(self.dbfile);p=self.Projection(self.dbfile,m,m,[],m['foreign_key_violations'])
        result=self.write(p,self.root/'school_erp_mysql.sql');return result,(self.root/'school_erp_mysql.sql').read_text(encoding='utf-8')
    def test_nepali_tex_quotes_null_and_zero(self):
        result,sql=self.export();self.assertEqual(result['row_counts']['marks'],50)
        self.assertEqual(result['row_counts']['duplicate_rows'],2)
        self.assertIn('NO_AUTO_VALUE_ON_ZERO',sql);self.assertIn('CONVERT(X\'',sql);self.assertIn("X'00ff'",sql)
        self.assertIn('12.345678',sql);self.assertIn('9223372036854775807',sql)
    def test_large_text_is_not_truncated(self):
        with closing(sqlite3.connect(self.dbfile)) as db:db.execute('UPDATE texts SET prose=?',('x'*70000,));db.commit()
        result,sql=self.export();self.assertIn('LONGTEXT',sql);self.assertIn('78'*70000,sql)
    def test_fk_types_and_sequences_are_retained(self):
        result,sql=self.export();mapping=result['schema_mappings']
        self.assertEqual(mapping['students']['columns']['id'],mapping['marks']['columns']['student_id'])
        self.assertIn('AUTO_INCREMENT=9001',sql);self.assertIn('DECIMAL(9,6)',sql)
    def test_fractional_legacy_integer_values_are_widened_without_loss(self):
        with closing(sqlite3.connect(self.dbfile)) as db:
            db.execute('CREATE TABLE legacy_integer_marks(value INTEGER)');db.execute('INSERT INTO legacy_integer_marks VALUES(35.5),(-0.25)');db.commit()
        result,sql=self.export();self.assertIn('DECIMAL(4,2)',sql);self.assertIn('35.5',sql);self.assertIn('-0.25',sql)
    def test_runtime_decimal_capacity_does_not_depend_on_observed_marks(self):
        for index,values in enumerate(([],[35],[35.5])):
            with self.subTest(values=values),closing(sqlite3.connect(self.dbfile)) as db:
                db.execute('CREATE TABLE marks_capacity_'+str(index)+'(score INTEGER)')
                db.executemany('INSERT INTO marks_capacity_'+str(index)+' VALUES(?)',[(value,) for value in values]);db.commit()
                m=self.inspect(self.dbfile);p=self.Projection(self.dbfile,m,m,[],[])
                p.runtime_types={'marks_capacity_'+str(index):{'score':'DECIMAL(5,2)'}}
                result=self.write(p,self.root/('capacity-'+str(index)+'.sql'))
                self.assertEqual(result['schema_mappings']['marks_capacity_'+str(index)]['columns']['score'],'DECIMAL(5,2)')
    def test_collation_collision_is_reported(self):
        from mysql_migration.snapshot import MigrationError
        with closing(sqlite3.connect(self.dbfile)) as db:db.execute("INSERT INTO texts(unique_key) VALUES('A'),('A ')");db.commit()
        with self.assertRaises(MigrationError):self.export()
    def test_unrepresentable_single_statement_is_refused(self):
        from mysql_migration.snapshot import MigrationError
        m=self.inspect(self.dbfile);p=self.Projection(self.dbfile,m,m,[],[])
        with self.assertRaises(MigrationError):self.write(p,self.root/'school_erp_mysql.sql',max_statement_bytes=10)
    def test_large_table_export_is_streamed(self):
        with closing(sqlite3.connect(self.dbfile)) as db:
            db.executemany('INSERT INTO texts(prose) VALUES(?)',(('x'*50000,) for _ in range(100)));db.commit()
        m=self.inspect(self.dbfile);p=self.Projection(self.dbfile,m,m,[],[])
        tracemalloc.start()
        try:
            self.write(p,self.root/'school_erp_mysql.sql');peak=tracemalloc.get_traced_memory()[1]
        finally:tracemalloc.stop()
        self.assertLess(peak,10*1024*1024,'Export retained the whole rich-text table or SQL dump in memory')
    def test_dump_has_no_destructive_or_privileged_commands(self):
        result,sql=self.export()
        for forbidden in ('DROP TABLE','DROP DATABASE','TRUNCATE','REPLACE INTO','SET GLOBAL','CREATE USER','CREATE DATABASE','DEFINER','USE `'):self.assertNotIn(forbidden,sql)
        self.assertLessEqual(max(result['statement_lengths']),1048576)
        self.assertIn('SET SESSION foreign_key_checks=@ERP_OLD_FOREIGN_KEY_CHECKS',sql)

if __name__=='__main__':unittest.main()
