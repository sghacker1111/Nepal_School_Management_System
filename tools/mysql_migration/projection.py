"""Add structural requirements without invoking historical row-rewriting upgrades."""
from collections import Counter
from contextlib import closing
from dataclasses import dataclass,field
import os,sqlite3,subprocess,tempfile
from pathlib import Path
from .snapshot import ROOT,Snapshot,MigrationError,inspect_database,qi,canonical_row,json_text

@dataclass
class Projection:
    path:Path
    manifest:dict
    original_manifest:dict
    additions:list
    known_anomalies:list
    runtime_types:dict=field(default_factory=dict)

def build_blueprint(php_binary:str,directory:Path)->Path:
    directory=Path(directory);directory.mkdir(parents=True,mode=0o700,exist_ok=False)
    path=directory/'current.sqlite'
    result=subprocess.run([php_binary,str(ROOT/'tools/mysql_schema_blueprint.php'),'--action','blueprint','--database',str(path)],cwd=ROOT,capture_output=True,text=True)
    if result.returncode:raise MigrationError('Current-schema blueprint failed; no owner data was used.')
    return path

def _row_counter(db,table,columns):
    names=[c['name'] for c in columns];where=" WHERE meta_key<>'workflow_schema'" if table=='app_meta' else ''
    return Counter(json_text(canonical_row(row,columns)) for row in db.execute('SELECT '+','.join(qi(n) for n in names)+' FROM '+qi(table)+where))

def assert_preserved(original:Path,target:Path)->None:
    before=inspect_database(original);after=inspect_database(target)
    allowed={'app_meta','ac_settings','ac_accounts','ac_methods','exam_templates'}
    with closing(sqlite3.connect(Path(original).as_uri()+'?mode=ro',uri=True)) as old,closing(sqlite3.connect(Path(target).as_uri()+'?mode=ro',uri=True)) as new:
        for table,meta in before['tables'].items():
            if table not in after['tables']:raise MigrationError('Original table missing after projection: '+table)
            a=_row_counter(old,table,meta['columns']);b=_row_counter(new,table,meta['columns'])
            if a-b:raise MigrationError('Projection would rewrite or remove existing cells in '+table)
            if table not in allowed and a!=b:raise MigrationError('Projection would fabricate data in '+table)
            seq=meta['sequence']
            if seq is not None and (after['tables'][table]['sequence'] or 0)<seq:raise MigrationError('Projection would reuse a historical sequence in '+table)
    if before['foreign_key_violations']!=after['foreign_key_violations']:raise MigrationError('Projection changed historical broken relationships; no export approved.')

def project_snapshot(snapshot:Snapshot,blueprint:Path,output:Path)->Projection:
    output=Path(output).resolve();temp=Path(tempfile.gettempdir()).resolve()
    if output.exists() or not output.is_relative_to(temp):raise MigrationError('Projection requires a new path in an owned system-temporary directory.')
    output.parent.mkdir(parents=True,mode=0o700,exist_ok=True)
    current=inspect_database(blueprint);original=snapshot.manifest
    version=original['schema_version']
    if version is None or not str(version).isdigit() or not 6<=int(version)<=18:raise MigrationError('Only audited legacy schema versions 6–18 can be projected.')
    additions=[]
    with closing(sqlite3.connect(snapshot.path.as_uri()+'?mode=ro',uri=True)) as old,closing(sqlite3.connect(output)) as db:
        old.backup(db)
        for table,required in current['tables'].items():
            existing=original['tables'].get(table)
            if existing is None:
                db.execute(required['sql']);additions.append({'table':table,'action':'create'});continue
            existing_names={c['name'] for c in existing['columns']}
            for column in required['columns']:
                if column['name'] in existing_names:continue
                if column['pk']:raise MigrationError('An existing table is missing its required primary key: '+table)
                definition=qi(column['name'])+' '+column['type']
                if column['notnull']:definition+=' NOT NULL'
                if column['default'] is not None:definition+=' DEFAULT '+column['default']
                db.execute('ALTER TABLE '+qi(table)+' ADD COLUMN '+definition)
                if column['name']=='visibility' and table in ('questions','learning_notes'):
                    # This column is new; retaining the former school's read scope
                    # does not grant a new author or release exam items to students.
                    db.execute('UPDATE '+qi(table)+" SET visibility='public'")
                additions.append({'table':table,'column':column['name'],'action':'add_column'})
        for table,required in current['tables'].items():
            known=[]
            for row in db.execute('PRAGMA index_list('+qi(table)+')').fetchall():
                known.append((bool(row[2]),tuple(r[2] for r in db.execute('PRAGMA index_info('+qi(row[1])+')'))))
            for number,index in enumerate(required['indexes']):
                key=(index['unique'],tuple(index['columns']))
                if index['origin']=='pk' or key in known:continue
                if index['partial'] or None in index['columns']:raise MigrationError('Current schema requires an unsupported expression index.')
                name='projection_'+table+'_'+str(number)
                db.execute('CREATE '+('UNIQUE ' if index['unique'] else '')+'INDEX '+qi(name)+' ON '+qi(table)+'('+','.join(qi(c) for c in index['columns'])+')')
                known.append(key);additions.append({'table':table,'index':name,'action':'add_index'})
        db.commit()
    env={**os.environ,'ERP_RUNTIME':'test','ERP_TEST_SQLITE':'1','ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(output)}
    result=subprocess.run([os.environ.get('ERP_TEST_PHP','php'),str(ROOT/'tools/mysql_schema_blueprint.php'),'--action','metadata','--database',str(output)],cwd=ROOT,env=env,capture_output=True,text=True)
    if result.returncode:raise MigrationError('Required metadata provisioning failed on the private working copy.')
    assert_preserved(snapshot.path,output)
    with closing(sqlite3.connect(output)) as db:
        db.execute("UPDATE app_meta SET meta_value='18' WHERE meta_key='workflow_schema'");db.commit()
    manifest=inspect_database(output)
    for table,meta in current['tables'].items():
        actual={c['name'] for c in manifest['tables'][table]['columns']}
        if {c['name'] for c in meta['columns']}-actual:raise MigrationError('Projection omitted required schema columns.')
    assert_preserved(snapshot.path,output)
    runtime_types={table:{column['name']:column['type'] for column in meta['columns']} for table,meta in current['tables'].items()}
    return Projection(output,manifest,original,additions,original['foreign_key_violations'],runtime_types)
