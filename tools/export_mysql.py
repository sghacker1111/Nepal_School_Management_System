"""Create a private, read-only-source MySQL export and verify it locally."""
import argparse,gzip,hashlib,json,os,shutil,subprocess,sys,tempfile
from pathlib import Path
from mysql_migration.snapshot import capture_snapshot,source_matches,validate_output_directory,MigrationError,digest_file,ROOT
from mysql_migration.projection import build_blueprint,project_snapshot
from mysql_migration.sql_writer import write_mysql_dump

def private_json(path,value):
    path=Path(path);path.write_text(json.dumps(value,ensure_ascii=False,indent=2)+'\n',encoding='utf-8');os.chmod(path,0o600)
def gzip_file(source,destination):
    with Path(source).open('rb') as src,Path(destination).open('xb') as raw:
        with gzip.GzipFile(filename='',mode='wb',fileobj=raw,mtime=0) as zipped:shutil.copyfileobj(src,zipped,length=1024*1024)
    os.chmod(destination,0o600)
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source',required=True,type=Path);parser.add_argument('--output',required=True,type=Path)
    mode=parser.add_mutually_exclusive_group(required=True);mode.add_argument('--snapshot-only',action='store_true');mode.add_argument('--verify-local-mysql',action='store_true')
    args=parser.parse_args();source=args.source
    try:
        output=validate_output_directory(source,args.output);output.mkdir(parents=True,mode=0o700,exist_ok=False);os.chmod(output,0o700)
        if args.snapshot_only:
            snapshot=capture_snapshot(source,output/'snapshot')
            if not source_matches(snapshot):raise MigrationError('Source changed after snapshot; retain it only for preflight and take a new snapshot during a maintenance window.')
            private_json(output/'migration-report.json',{'ready':False,'snapshot_only':True,'source_sha256':snapshot.source_file_sha256,'snapshot_sha256':snapshot.snapshot_sha256,'tables':len(snapshot.manifest['tables']),'rows':sum(t['row_count'] for t in snapshot.manifest['tables'].values())})
            print(json.dumps({'output':str(output),'ready':False,'snapshot_only':True}));return 0
        source_hash=digest_file(source);snapshot=capture_snapshot(source,output/'snapshot')
        initial={'format_version':1,'ready':False,'source_file_sha256':snapshot.source_file_sha256,'snapshot_sha256':snapshot.snapshot_sha256,'source_manifest_file':'snapshot/migration-manifest.json','projected_manifest_file':'projected-manifest.json','sql_file':'school_erp_mysql.sql','statements_file':'statements.jsonl','sql_sha256':'','statements_sha256':'','schema_mappings':{},'source_manifest':snapshot.manifest}
        private_json(output/'migration-manifest.json',initial)
        report={'ready':False,'verified':False,'source_sha256':source_hash,'snapshot_sha256':snapshot.snapshot_sha256,'source_schema_version':snapshot.manifest['schema_version'],'known_orphans':len(snapshot.manifest['foreign_key_violations']),'new_violations':None,'table_count':len(snapshot.manifest['tables']),'source_rows':sum(t['row_count'] for t in snapshot.manifest['tables'].values()),'engine':None}
        private_json(output/'migration-report.json',report)
        temp=Path(tempfile.mkdtemp(prefix='erp-mysql-export-'))
        try:
            blueprint=build_blueprint(os.environ.get('ERP_TEST_PHP','php'),temp/'blueprint')
            projection=project_snapshot(snapshot,blueprint,temp/'projected.sqlite')
            private_json(output/'projected-manifest.json',projection.manifest)
            sql_path=output/'school_erp_mysql.sql';dump=write_mysql_dump(projection,sql_path)
            sidecar=sql_path.with_name(sql_path.name+'.statements.jsonl');statements=output/'statements.jsonl';sidecar.replace(statements);os.chmod(statements,0o600)
        finally:shutil.rmtree(temp,ignore_errors=False)
        manifest={**initial,'projected_manifest_file':'projected-manifest.json','sql_sha256':dump['sha256'],'statements_sha256':digest_file(statements),'schema_mappings':dump['schema_mappings'],'projected_rows':dump['row_counts'],'additions':projection.additions}
        private_json(output/'migration-manifest.json',manifest)
        env=os.environ.copy()
        if env.get('ERP_DB_HOST')!='127.0.0.1' or not env.get('ERP_DB_NAME','').startswith('erp_migration_test_'):raise MigrationError('Set ERP_DB_* for a fresh empty loopback disposable database beginning with erp_migration_test_.')
        command=[env.get('ERP_TEST_PHP','php'),str(ROOT/'tools/mysql_migration/verify_mysql.php'),'--action','import','--package',str(output)]
        verified=subprocess.run(command,cwd=ROOT,env=env,capture_output=True,text=True,encoding='utf-8',errors='replace')
        if verified.returncode:
            report['verification_error']='Local MySQL import/readback failed; review the private target and package diagnostics.';private_json(output/'migration-report.json',report)
            raise MigrationError('Local MySQL verification failed. The report remains not ready and the target was not cleared.')
        result=json.loads(verified.stdout)
        if not source_matches(snapshot) or digest_file(source)!=source_hash:
            report['verification_error']='Source data changed during conversion; this package is not ready for cutover.';private_json(output/'migration-report.json',report)
            raise MigrationError('Source changed during conversion; take a new snapshot during a maintenance window.')
        report.update({'ready':True,'verified':True,'new_violations':result['new_violations'],'known_orphans':result['known_orphans'],'engine':result['engine'],'mysql_rows':sum(v['row_count'] for v in result['actual']['tables'].values()),'sql_sha256':dump['sha256'],'sql_bytes':dump['bytes'],'gzip_sha256':None,'gzip_bytes':None,'statements_sha256':manifest['statements_sha256'],'uploaded_files_included':False})
        gzip_path=output/'school_erp_mysql.sql.gz';gzip_file(sql_path,gzip_path);report['gzip_sha256']=digest_file(gzip_path);report['gzip_bytes']=gzip_path.stat().st_size
        manifest['ready']=True;manifest['local_verification']={'engine':result['engine'],'known_orphans':result['known_orphans'],'new_violations':result['new_violations'],'preserved':result['preserved']};private_json(output/'migration-manifest.json',manifest)
        private_json(output/'migration-report.json',report)
        print(json.dumps({'output':str(output),'ready':True,'table_count':report['table_count'],'source_rows':report['source_rows'],'known_orphans':report['known_orphans'],'new_violations':report['new_violations'],'engine':report['engine'],'sql_sha256':report['sql_sha256'],'gzip_sha256':report['gzip_sha256']}))
        return 0
    except (MigrationError,FileNotFoundError,ValueError,OSError,subprocess.SubprocessError) as error:
        print('Migration stopped: '+str(error),file=sys.stderr);return 1
if __name__=='__main__':raise SystemExit(main())
