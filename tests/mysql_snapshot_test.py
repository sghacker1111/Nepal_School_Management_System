import hashlib, importlib.util, sqlite3, sys, tempfile, unittest, os, subprocess
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))

class SnapshotTests(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec('mysql_migration.snapshot'),'Read-only migration snapshot module is missing')
        from mysql_migration.snapshot import capture_snapshot, inspect_database, source_matches
        self.capture=capture_snapshot;self.inspect=inspect_database;self.matches=source_matches
        self.temp=tempfile.TemporaryDirectory(prefix='erp-snapshot-tests-');self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);(self.root/'source').mkdir();self.source=self.root/'source/source.sqlite'
        self.db=sqlite3.connect(self.source);self.addCleanup(self.db.close)
        self.db.executescript('PRAGMA journal_mode=WAL; CREATE TABLE events(id INTEGER PRIMARY KEY AUTOINCREMENT,body TEXT); CREATE TABLE duplicate_rows(value TEXT); INSERT INTO duplicate_rows VALUES (NULL),(NULL);')
        self.db.execute('INSERT INTO events(body) VALUES(?)',('नेपाली \\(x+1\\) <p>"quote"</p>',));self.db.commit()
    def test_wal_commits_are_captured(self):
        before=self.inspect(self.source);snap=self.capture(self.source,self.root/'private-out')
        self.assertEqual(snap.manifest['tables']['events']['row_count'],1)
        self.assertEqual(snap.manifest,before);self.assertTrue(self.matches(snap))
        self.assertEqual(self.inspect(self.source),before)
    def test_source_drift_is_reported(self):
        snap=self.capture(self.source,self.root/'private-out')
        self.db.execute("INSERT INTO events(body) VALUES('new committed change')");self.db.commit()
        self.assertFalse(self.matches(snap));self.assertEqual(snap.manifest['tables']['events']['row_count'],1)
    def test_no_primary_key_duplicates_are_preserved(self):
        before=self.inspect(self.source)['tables']['duplicate_rows']
        self.assertEqual(before['row_count'],2)
        self.db.execute('DELETE FROM duplicate_rows WHERE rowid=1');self.db.commit()
        self.assertNotEqual(before['rows_digest'],self.inspect(self.source)['tables']['duplicate_rows']['rows_digest'])
    def test_output_existing_or_in_source_or_app_is_rejected(self):
        from mysql_migration.snapshot import MigrationError
        app=Path(__file__).resolve().parents[1]
        for output in (self.root/'source/unsafe',self.root,app/'unsafe-export'):
            with self.assertRaises(MigrationError):self.capture(self.source,output)
        self.assertFalse((app/'unsafe-export').exists())
    def test_missing_source_never_creates_a_database(self):
        missing=self.root/'source/missing.sqlite'
        with self.assertRaises((FileNotFoundError,ValueError)):self.capture(missing,self.root/'private-out')
        self.assertFalse(missing.exists())
    def test_junction_or_symlink_into_app_is_rejected(self):
        from mysql_migration.snapshot import MigrationError
        alias=self.root/'app-alias';target=Path(__file__).resolve().parents[1]
        if os.name=='nt':
            ps="New-Item -ItemType Junction -Path '"+str(alias).replace("'","''")+"' -Target '"+str(target).replace("'","''")+"' | Out-Null"
            subprocess.run(['powershell','-NoProfile','-Command',ps],check=True,capture_output=True)
        else:alias.symlink_to(target,target_is_directory=True)
        try:
            with self.assertRaises(MigrationError):self.capture(self.source,alias/'unsafe-export')
            self.assertFalse((target/'unsafe-export').exists())
        finally:
            if os.name=='nt':alias.rmdir()
            else:alias.unlink()
    def test_triggers_are_reported_not_silently_dropped(self):
        from mysql_migration.snapshot import MigrationError
        self.db.executescript('CREATE TRIGGER extra AFTER INSERT ON events BEGIN UPDATE events SET body=body WHERE id=NEW.id; END;')
        with self.assertRaises(MigrationError):self.capture(self.source,self.root/'private-out')
    def test_known_orphan_identity_is_recorded(self):
        self.db.executescript('CREATE TABLE parents(id INTEGER PRIMARY KEY); CREATE TABLE children(id INTEGER PRIMARY KEY,parent_id INTEGER,FOREIGN KEY(parent_id) REFERENCES parents(id)); INSERT INTO children VALUES(8,9);')
        issues=self.inspect(self.source)['foreign_key_violations']
        self.assertEqual(len(issues),1);self.assertEqual(issues[0]['table'],'children');self.assertEqual(issues[0]['parent'],'parents')

if __name__=='__main__':unittest.main()
