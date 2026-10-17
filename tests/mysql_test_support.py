"""Synthetic fixtures for a native local MySQL round-trip; never opens owner data."""
import json,os,re,shutil,sqlite3,subprocess,sys,tempfile,uuid
from contextlib import closing
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
class MysqlImportFixture:
    def __init__(self,clean_accounting=False):self.clean_accounting=clean_accounting
    def __enter__(self):
        from mysql_migration.snapshot import capture_snapshot
        from mysql_migration.projection import build_blueprint,project_snapshot
        from mysql_migration.sql_writer import write_mysql_dump
        self.temp=tempfile.TemporaryDirectory(prefix='erp-mysql-import-');self.root=Path(self.temp.name)
        self.state_dir=Path(tempfile.gettempdir())/('erp-mysql-server-'+uuid.uuid4().hex)
        self.helper=ROOT/'tools/mysql_test_server.ps1'
        result=subprocess.run(['powershell','-NoProfile','-File',str(self.helper),'-Action','Start','-StateDirectory',str(self.state_dir)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=90)
        if result.returncode:raise RuntimeError('Private MySQL fixture could not start; inspect its private state directory.')
        state=json.loads((self.state_dir/'state.json').read_text(encoding='utf-8-sig'));self.server={key:state[key] for key in ('host','port','database','username','version')}
        self.env={**os.environ,'ERP_DB_TYPE':'mysql','ERP_DB_HOST':'127.0.0.1','ERP_DB_PORT':str(state['port']),'ERP_DB_NAME':state['database'],'ERP_DB_USER':state['username'],'ERP_DB_PASS':state['password'],'ERP_TEST_PHP':os.environ.get('ERP_TEST_PHP','php')}
        try:
            self._make_source(self.clean_accounting);snap=capture_snapshot(self.source,self.root/'snapshot')
            blueprint=build_blueprint(self.env['ERP_TEST_PHP'],self.root/'blueprint')
            projected=project_snapshot(snap,blueprint,self.root/'projected.sqlite')
            self.sql=self.root/'school_erp_mysql.sql';export=write_mysql_dump(projected,self.sql)
            self.package=self.root/'package';self.package.mkdir()
            for name,path in [('school_erp_mysql.sql',self.sql),('statements.jsonl',self.sql.with_name(self.sql.name+'.statements.jsonl'))]:
                (self.package/name).write_bytes(path.read_bytes())
            (self.package/'projected-manifest.json').write_text(json.dumps(projected.manifest,ensure_ascii=False),encoding='utf-8')
            manifest={'sql_file':'school_erp_mysql.sql','sql_sha256':export['sha256'],'statements_file':'statements.jsonl','statements_sha256':export['statements_sha256'],'projected_manifest_file':'projected-manifest.json','source_manifest':snap.manifest,'schema_mappings':export['schema_mappings']}
            (self.package/'migration-manifest.json').write_text(json.dumps(manifest,ensure_ascii=False),encoding='utf-8')
            self.snap=snap;self.projection=projected;self.report_input={'package':str(self.package)}
            return self
        except Exception:
            self._stop_server();raise
    def __exit__(self,exc_type,exc,tb):
        self._stop_server()
        if exc_type is None:
            self._cleanup_server_state();self.temp.cleanup()
        else: self.temp._finalizer.detach()
    def _stop_server(self):
        if self.state_dir.exists() and (self.state_dir/'state.json').exists():
            stopped=subprocess.run(['powershell','-NoProfile','-File',str(self.helper),'-Action','Stop','-StateDirectory',str(self.state_dir)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=30)
            if stopped.returncode:raise RuntimeError('Owned test MySQL process did not stop; private fixture retained at '+str(self.state_dir))
    def _cleanup_server_state(self):
        root=Path(tempfile.gettempdir()).resolve();target=self.state_dir.resolve(strict=True)
        if target.parent!=root or not re.fullmatch(r'erp-mysql-server-[a-f0-9]{32}',target.name) or not (target/'state.json').is_file():raise RuntimeError('Refusing to remove an unverified MySQL fixture directory.')
        shutil.rmtree(target)
    def _make_source(self,clean_accounting=False):
        source_dir=self.root/'source';source_dir.mkdir();self.source=source_dir/'source.sqlite';env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(self.source)}
        subprocess.run([os.environ.get('ERP_TEST_PHP','php'),'tests/schema7_fixture.php'],cwd=ROOT,env=env,check=True,capture_output=True)
        with closing(sqlite3.connect(self.source)) as db:
            db.execute("UPDATE subjects SET name_en='Compulsory Nepali' WHERE id=(SELECT MIN(id) FROM subjects)")
            db.execute("UPDATE users SET password='SYNTHETIC_HASH_DO_NOT_REPLACE' WHERE id=1")
            if clean_accounting:
                for table in ('ac_journal_lines','ac_journals','ac_invoice_items','ac_invoices','ac_periods'):
                    db.execute('DELETE FROM '+table)
            db.execute('DELETE FROM marks');db.execute('DELETE FROM students');db.execute('DELETE FROM exams')
            for i in range(50):db.execute('INSERT INTO marks(student_id,exam_id,subject_id,theory_obtained) VALUES(?,999999,1,?)',(999999+i,i))
            db.execute('CREATE TABLE custom_rows(label TEXT)');db.execute("INSERT INTO custom_rows VALUES('keep duplicate'),('keep duplicate')")
            db.execute("UPDATE sqlite_sequence SET seq=9000 WHERE name='marks'");db.commit()
    def command(self,action='import',env=None):
        return subprocess.run([self.env['ERP_TEST_PHP'],str(ROOT/'tools/mysql_migration/verify_mysql.php'),'--action',action,'--package',str(self.package)],cwd=ROOT,env=env or self.env,capture_output=True,text=True)
    def import_command(self):return self.command()
    def import_and_verify(self):
        result=self.command()
        if result.returncode:raise AssertionError('MySQL import failed without a row dump; private fixture retained at '+str(self.root)+'. '+result.stderr[:300])
        return json.loads(result.stdout)
    def bad_password_request(self):
        env=self.env.copy();env['ERP_DB_PASS']='PRIVATE_INVALID_PASSWORD';return self.command(env=env)
    def _sql(self,source):
        code="$p=new PDO('mysql:host=127.0.0.1;port='.getenv('ERP_DB_PORT').';dbname='.getenv('ERP_DB_NAME').';charset=utf8mb4',getenv('ERP_DB_USER'),getenv('ERP_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); foreach(explode(';',"+json.dumps(source)+") as $q) if(trim($q)!=='') $p->exec($q);"
        return subprocess.run([self.env['ERP_TEST_PHP'],'-r',code],cwd=ROOT,env=self.env,capture_output=True,text=True)
    def create_sentinel(self):
        result=self._sql('CREATE TABLE sentinel(value VARCHAR(30)); INSERT INTO sentinel VALUES (\'keep\')')
        if result.returncode:raise AssertionError('Could not create synthetic nonempty-target sentinel.')
    def create_partial(self):
        result=self._sql('CREATE TABLE partial_import(value VARCHAR(30))')
        if result.returncode:raise AssertionError('Could not create synthetic partial-import target.')
    def sentinel(self):
        code="$p=new PDO('mysql:host=127.0.0.1;port='.getenv('ERP_DB_PORT').';dbname='.getenv('ERP_DB_NAME'),getenv('ERP_DB_USER'),getenv('ERP_DB_PASS')); echo json_encode($p->query('SELECT value FROM sentinel')->fetchAll(PDO::FETCH_COLUMN));"
        result=subprocess.run([self.env['ERP_TEST_PHP'],'-r',code],cwd=ROOT,env=self.env,capture_output=True,text=True,check=True);return json.loads(result.stdout)
    def assert_fk_rejected(self):
        result=self._sql('INSERT INTO marks(student_id,exam_id,subject_id,theory_obtained) VALUES (777777,777777,1,0)')
        if result.returncode==0:raise AssertionError('MySQL accepted a new invalid foreign-key row.')
    def assert_readiness_read_only(self):
        before=self.command('inspect')
        if before.returncode:raise AssertionError('Pre-readiness MySQL manifest failed.')
        code="require 'includes/database_readiness.php'; $p=new PDO('mysql:host=127.0.0.1;port='.getenv('ERP_DB_PORT').';dbname='.getenv('ERP_DB_NAME'),getenv('ERP_DB_USER'),getenv('ERP_DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); schoolAssertDatabaseReady($p);"
        result=subprocess.run([self.env['ERP_TEST_PHP'],'-r',code],cwd=ROOT,env=self.env,capture_output=True,text=True)
        if result.returncode:raise AssertionError('Read-only readiness failed.')
        after=self.command('inspect')
        if after.returncode or json.loads(before.stdout)['actual']!=json.loads(after.stdout)['actual']:raise AssertionError('Readiness changed the imported MySQL data or schema.')

