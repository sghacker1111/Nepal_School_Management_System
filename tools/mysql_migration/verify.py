"""Call the local, loopback-only PHP PDO bridge and keep row details private."""
import json,os,subprocess
from pathlib import Path
from .snapshot import MigrationError,ROOT

def verify_import(snapshot,projection,target_manifest):
    env=os.environ.copy()
    if env.get('ERP_DB_HOST')!='127.0.0.1' or not env.get('ERP_DB_NAME','').startswith('erp_migration_test_'):
        raise MigrationError('MySQL verification only accepts the isolated loopback test database.')
    package=Path(target_manifest['package']).resolve(strict=True)
    result=subprocess.run([env.get('ERP_TEST_PHP','php'),str(ROOT/'tools/mysql_migration/verify_mysql.php'),'--action','inspect','--package',str(package)],cwd=ROOT,env=env,capture_output=True,text=True)
    if result.returncode:raise MigrationError('MySQL readback did not match the source snapshot; private diagnostics remain in the verification package.')
    report=json.loads(result.stdout)
    if not report.get('preserved'):raise MigrationError('MySQL readback reported a preservation mismatch.')
    report.pop('actual',None)
    return report
