"""Consistent read-only snapshots and typed, order-independent data manifests."""
from contextlib import closing
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
import hashlib, json, os, re, sqlite3, subprocess
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
class MigrationError(ValueError):
    """Actionable migration failure without school row contents or credentials."""

def qi(name):return '"'+str(name).replace('"','""')+'"'
def digest_file(path):
    with Path(path).open('rb') as stream:return hashlib.file_digest(stream,'sha256').hexdigest()
def json_text(value):return json.dumps(value,ensure_ascii=False,separators=(',',':'),sort_keys=True)
def canonical_value(value,declared_type=''):
    if value is None:return ['null']
    if isinstance(value,bytes):return ['bytes',value.hex()]
    numeric=isinstance(value,(int,float,Decimal)) or bool(re.search(r'INT|DECIMAL|NUMERIC|REAL|DOUBLE|FLOAT',declared_type,re.I))
    if numeric:
        try:
            number=Decimal(str(value))
            if not number.is_finite():raise MigrationError('Non-finite numeric data cannot be represented safely.')
            text=format(number,'f')
            if '.' in text:text=text.rstrip('0').rstrip('.')
            if number==0:text='0'
            return ['number',text]
        except InvalidOperation:pass
    return ['text',str(value)]
def canonical_row(row,columns):return [canonical_value(value,column['type']) for value,column in zip(row,columns)]
def rows_digest(rows,columns):
    hashes=sorted(hashlib.sha256(json_text(canonical_row(row,columns)).encode('utf-8')).hexdigest() for row in rows)
    return hashlib.sha256('\n'.join(hashes).encode('ascii')).hexdigest(),len(hashes)

def _read_manifest(db):
    tables={};objects=db.execute("SELECT type,name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name").fetchall()
    table_sql={name:sql for kind,name,sql in objects if kind=='table'}
    sequences=dict(db.execute('SELECT name,seq FROM sqlite_sequence')) if db.execute("SELECT 1 FROM sqlite_master WHERE name='sqlite_sequence'").fetchone() else {}
    for table,sql in table_sql.items():
        columns=[dict(zip(('cid','name','type','notnull','default','pk','hidden'),row)) for row in db.execute('PRAGMA table_xinfo('+qi(table)+')')]
        indexes=[]
        for seq,name,unique,origin,partial in db.execute('PRAGMA index_list('+qi(table)+')'):
            index_sql=db.execute('SELECT sql FROM sqlite_master WHERE type=\'index\' AND name=?',(name,)).fetchone()
            indexes.append({'name':name,'unique':bool(unique),'origin':origin,'partial':bool(partial),'sql':index_sql[0] if index_sql else None,'columns':[row[2] for row in db.execute('PRAGMA index_info('+qi(name)+')')], 'collations':[row[4] for row in db.execute('PRAGMA index_xinfo('+qi(name)+')') if row[5]]})
        keys=[dict(zip(('id','seq','parent','from','to','on_update','on_delete','match'),row)) for row in db.execute('PRAGMA foreign_key_list('+qi(table)+')')]
        fingerprint,count=rows_digest(db.execute('SELECT '+','.join(qi(c['name']) for c in columns)+' FROM '+qi(table)),columns)
        tables[table]={'columns':columns,'primary_key':[c['name'] for c in sorted(columns,key=lambda c:c['pk']) if c['pk']], 'row_count':count,'rows_digest':fingerprint,'sequence':sequences.get(table),'sql':sql,'indexes':indexes,'foreign_keys':keys}
    violations=[]
    for table,meta in tables.items():
        groups={}
        for key in meta['foreign_keys']:groups.setdefault(key['id'],[]).append(key)
        for group in groups.values():
            group.sort(key=lambda k:k['seq']);parent=group[0]['parent']
            if parent not in tables:raise MigrationError('A declared foreign key references a missing table: '+parent)
            child_columns=[k['from'] for k in group];parent_columns=[k['to'] or tables[parent]['primary_key'][i] for i,k in enumerate(group)]
            test=' AND '.join('c.'+qi(c)+' IS NOT NULL' for c in child_columns)
            join=' AND '.join('p.'+qi(p)+'=c.'+qi(c) for c,p in zip(child_columns,parent_columns))
            for row in db.execute('SELECT c.* FROM '+qi(table)+' c WHERE '+test+' AND NOT EXISTS(SELECT 1 FROM '+qi(parent)+' p WHERE '+join+')'):
                encoded=canonical_row(row,meta['columns']);indices=[i for i,c in enumerate(meta['columns']) if c['name'] in meta['primary_key']]
                identity=[encoded[i] for i in indices] if indices else hashlib.sha256(json_text(encoded).encode()).hexdigest()
                violations.append({'table':table,'columns':child_columns,'parent':parent,'parent_columns':parent_columns,'identity':identity})
    version=None
    if 'app_meta' in tables:
        row=db.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone();version=row[0] if row else None
    return {'format_version':1,'schema_version':version,'tables':tables,'foreign_key_violations':sorted(violations,key=json_text),'views':[name for kind,name,sql in objects if kind=='view'],'triggers':[name for kind,name,sql in objects if kind=='trigger']}

def inspect_database(path:Path)->dict:
    path=Path(path).resolve(strict=True)
    with closing(sqlite3.connect(path.as_uri()+'?mode=ro',uri=True)) as db:
        db.execute('PRAGMA query_only=ON');db.execute('BEGIN')
        result=_read_manifest(db)
        if db.execute('PRAGMA quick_check').fetchone()[0]!='ok':raise MigrationError('SQLite integrity check failed; restore a known-good source before converting.')
        return result

def validate_output_directory(source:Path,output:Path)->Path:
    output=Path(output).resolve();source=Path(source).resolve(strict=True)
    roots=[ROOT]
    result=subprocess.run(['git','-C',str(ROOT),'worktree','list','--porcelain'],capture_output=True,text=True,check=True)
    roots.extend(Path(line[9:]).resolve() for line in result.stdout.splitlines() if line.startswith('worktree '))
    if output.exists() or output.is_relative_to(source.parent) or any(output.is_relative_to(root) for root in roots):raise MigrationError('Choose a new private output directory outside every application checkout and the source directory.')
    return output

@dataclass
class Snapshot:
    path:Path
    source_path:Path
    manifest:dict
    source_manifest:dict
    source_file_sha256:str
    snapshot_sha256:str

def capture_snapshot(source:Path,output_dir:Path)->Snapshot:
    source=Path(source).resolve(strict=True);output=validate_output_directory(source,output_dir)
    original_hash=digest_file(source);output.mkdir(parents=True,mode=0o700,exist_ok=False);target=output/'source_snapshot.sqlite'
    with closing(sqlite3.connect(source.as_uri()+'?mode=ro',uri=True)) as original,closing(sqlite3.connect(target)) as copied:
        original.backup(copied)
    os.chmod(target,0o600);manifest=inspect_database(target)
    if manifest['views'] or manifest['triggers']:raise MigrationError('Stored views/triggers require an explicit conversion design; the source was not changed.')
    for table in manifest['tables'].values():
        if any(c['hidden'] for c in table['columns']):raise MigrationError('Generated/hidden columns require explicit conversion support.')
        if any(i['partial'] or None in i['columns'] for i in table['indexes']):raise MigrationError('Partial/expression indexes require explicit conversion support.')
    snapshot=Snapshot(target,source,manifest,manifest,original_hash,digest_file(target))
    report={'format_version':1,'ready':False,'source_file_sha256':original_hash,'snapshot_sha256':snapshot.snapshot_sha256,'source':str(source),'manifest':manifest}
    file=output/'migration-manifest.json';file.write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8');os.chmod(file,0o600)
    return snapshot

def source_matches(snapshot:Snapshot)->bool:
    return inspect_database(snapshot.source_path)==snapshot.source_manifest
