"""Portable MySQL SQL from structural metadata, never SQLite SQL substitution."""
from contextlib import closing
from datetime import date,datetime
from decimal import Decimal,InvalidOperation
import hashlib,json,os,re,sqlite3
from itertools import chain
from pathlib import Path
from .snapshot import MigrationError,qi,digest_file,json_text

def mq(name):
    if not name or len(name)>64 or re.search(r'[\x00-\x1f\x7f]',name):raise MigrationError('An SQL identifier cannot be represented safely.')
    return '`'+name.replace('`','``')+'`'

def _number(value):
    try:number=Decimal(str(value))
    except InvalidOperation:raise MigrationError('A numeric column contains nonnumeric legacy data.') from None
    if not number.is_finite():raise MigrationError('A numeric value is not finite.')
    return number

def _type(column,values,indexed):
    declaration=column['type'].upper().strip();items=(v for v in values if v is not None)
    if re.search(r'INT',declaration):
        precision=1;scale=0;fractional=False;out_of_range=False
        for value in items:
            n=_number(value)
            if n!=n.to_integral_value():fractional=True
            text=format(abs(n),'f');whole,_,fraction=text.partition('.');precision=max(precision,len(whole.lstrip('0')) or 1);scale=max(scale,len(fraction.rstrip('0')))
            if not -(2**63)<=n<=2**63-1:out_of_range=True
        if fractional:
            if scale>30 or precision+scale>65:raise MigrationError('Fractional data in an integer column exceeds MySQL decimal capacity: '+column['name'])
            return f'DECIMAL({max(precision+scale,scale+1)},{scale})'
        if out_of_range:raise MigrationError('An integer column has an out-of-range value: '+column['name'])
        return 'BIGINT'
    dec=re.fullmatch(r'(?:DECIMAL|NUMERIC)(?:\((\d+)(?:\s*,\s*(\d+))?\))?',declaration)
    if dec:
        precision=int(dec[1] or 10);scale=int(dec[2] or 0);digits=max(1,precision-scale)
        for value in items:
            text=format(abs(_number(value)),'f');whole,_,fraction=text.partition('.')
            digits=max(digits,len(whole.lstrip('0')) or 1);scale=max(scale,len(fraction.rstrip('0')))
        precision=max(precision,digits+scale)
        if precision>65 or scale>30:raise MigrationError('Decimal precision exceeds MySQL capacity: '+column['name'])
        return f'DECIMAL({precision},{scale})'
    if declaration in ('REAL','FLOAT','DOUBLE','DOUBLE PRECISION'):
        for value in items:_number(value)
        return 'DOUBLE'
    if declaration in ('DATE','DATETIME','TIMESTAMP'):
        fractional=False
        for value in items:
            if not isinstance(value,str):raise MigrationError('A date column contains nontext legacy values.')
            try:
                if declaration=='DATE':
                    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}',value):raise ValueError()
                    date.fromisoformat(value)
                else:
                    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?',value):raise ValueError()
                    datetime.fromisoformat(value);fractional|='.' in value
            except ValueError:raise MigrationError('A legacy date is not a valid ISO date; no automatic date correction: '+column['name']) from None
        return 'DATE' if declaration=='DATE' else 'DATETIME'+('(6)' if fractional else '')
    if declaration in ('BLOB','LONGBLOB','MEDIUMBLOB'):
        if any(not isinstance(v,bytes) for v in items):raise MigrationError('Mixed binary/nonbinary storage needs an explicit conversion.')
        return 'LONGBLOB'
    varchar=re.fullmatch(r'(?:VAR)?CHAR\((\d+)\)',declaration)
    if declaration not in ('','TEXT','LONGTEXT','MEDIUMTEXT','CLOB') and not varchar:raise MigrationError('Unsupported declared type for '+column['name'])
    size=int(varchar[1]) if varchar else (191 if indexed else 1)
    for value in items:
        if not isinstance(value,str):raise MigrationError('Mixed text/nontext storage needs an explicit conversion: '+column['name'])
        size=max(size,len(value))
    if indexed and size>768:raise MigrationError('Indexed text exceeds a safe full-key size: '+column['name'])
    return f'VARCHAR({size})' if indexed or varchar and size<=4096 else 'LONGTEXT'

def _default(value,mysql_type):
    if value is None:return ''
    text=str(value).strip()
    while text.startswith('(') and text.endswith(')'):text=text[1:-1].strip()
    if text.upper()=='NULL':literal='NULL'
    elif text.upper()=='CURRENT_TIMESTAMP':literal='CURRENT_TIMESTAMP'
    elif re.fullmatch(r'[+-]?\d+(?:\.\d+)?',text):literal=text
    elif len(text)>=2 and text[0]==text[-1] and text[0] in ("'",'"'):
        quote=text[0];value=text[1:-1].replace(quote+quote,quote);literal="'"+value.replace("'","''")+"'"
        if 'TEXT' in mysql_type or 'BLOB' in mysql_type:literal='('+literal+')'
    else:raise MigrationError('Unsupported SQL default expression; it was not silently replaced.')
    return ' DEFAULT '+literal

def _value(value,mysql_type):
    if value is None:return 'NULL'
    if mysql_type.startswith(('BIGINT','DECIMAL','DOUBLE')):return format(_number(value),'f')
    if isinstance(value,bytes):return "X'"+value.hex()+"'"
    return "CONVERT(X'"+str(value).encode('utf-8').hex()+"' USING utf8mb4)"

def _compile_table(db,name,meta):
    if re.search(r'\bCHECK\s*\(',meta['sql'] or '',re.I):raise MigrationError('CHECK constraints require an explicit compatible conversion: '+name)
    columns=meta['columns'];types={};indexed=set(meta['primary_key'])
    for index in meta['indexes']:
        if index['partial'] or None in index['columns'] or any(c not in ('BINARY',None) for c in index['collations']):raise MigrationError('An index has unsupported expressions or collation: '+name)
        indexed.update(index['columns'])
    for key in meta['foreign_keys']:indexed.add(key['from'])
    # Column-at-a-time bounded scans avoid retaining complete rich-text tables.
    for column in columns:
        types[column['name']]=_type(column,(r[0] for r in db.execute('SELECT '+qi(column['name'])+' FROM '+qi(name))),column['name'] in indexed)
    pk=meta['primary_key'];auto=pk[0] if len(pk)==1 and types[pk[0]]=='BIGINT' and 'WITHOUT ROWID' not in (meta['sql'] or '').upper() else None
    definitions=[]
    for column in columns:
        key=column['name'];notnull=column['notnull'] or key in pk
        if notnull and db.execute('SELECT 1 FROM '+qi(name)+' WHERE '+qi(key)+' IS NULL LIMIT 1').fetchone():raise MigrationError('A required MySQL key contains NULL: '+name+'.'+key)
        definition=mq(key)+' '+types[key]+(' NOT NULL' if notnull else ' NULL')
        if key==auto:definition+=' AUTO_INCREMENT'
        else:definition+=_default(column['default'],types[key])
        definitions.append(definition)
    if pk:definitions.append('PRIMARY KEY ('+','.join(mq(c) for c in pk)+')')
    keys=[]
    for index in meta['indexes']:
        cols=index['columns']
        if index['origin']=='pk' or index['unique'] and cols==pk:continue
        width=sum(int(re.search(r'\d+',types[c])[0])*4 if types[c].startswith('VARCHAR') else 16 for c in cols)
        if width>3072:raise MigrationError('A complete index exceeds MySQL key capacity: '+name)
        if index['unique']:
            seen=set()
            for row in db.execute('SELECT '+','.join(qi(c) for c in cols)+' FROM '+qi(name)):
                if any(v is None for v in row):continue
                canonical=tuple(v.rstrip(' ') if isinstance(v,str) else v for v in row)
                if canonical in seen:raise MigrationError('MySQL collation would merge distinct unique keys: '+name)
                seen.add(canonical)
        identity=('uq_' if index['unique'] else 'ix_')+hashlib.sha256((name+json_text(cols)+str(index['unique'])).encode()).hexdigest()[:28]
        definitions.append(('UNIQUE ' if index['unique'] else '')+'KEY '+mq(identity)+' ('+','.join(mq(c) for c in cols)+')');keys.append(cols)
    groups={}
    for key in meta['foreign_keys']:groups.setdefault(key['id'],[]).append(key)
    for number,group in groups.items():
        group.sort(key=lambda k:k['seq']);first=group[0];parent=first['parent'];child=[k['from'] for k in group];parent_columns=[k['to'] for k in group]
        if None in parent_columns:raise MigrationError('Implicit foreign-key target columns require explicit conversion.')
        constraint='fk_'+hashlib.sha256((name+json_text(child)+parent).encode()).hexdigest()[:28]
        for action in (first['on_delete'],first['on_update']):
            if action not in ('NO ACTION','RESTRICT','CASCADE','SET NULL'):raise MigrationError('Unsupported foreign-key action.')
        definitions.append('CONSTRAINT '+mq(constraint)+' FOREIGN KEY ('+','.join(mq(c) for c in child)+') REFERENCES '+mq(parent)+' ('+','.join(mq(c) for c in parent_columns)+') ON DELETE '+first['on_delete']+' ON UPDATE '+first['on_update'])
    next_id=None
    if auto:
        maximum=db.execute('SELECT MAX('+qi(auto)+') FROM '+qi(name)).fetchone()[0] or 0
        next_id=max(meta['sequence'] or 0,maximum)+1
    sql='CREATE TABLE '+mq(name)+' (\n  '+',\n  '.join(definitions)+'\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin ROW_FORMAT=DYNAMIC'
    if next_id is not None:sql+=' AUTO_INCREMENT='+str(next_id)
    return sql,{'columns':types,'primary_key':pk,'auto_increment':auto,'next_id':next_id,'indexes':keys}

def _inserts(db,projection,mapping,limit):
    yield 'START TRANSACTION'
    for table,meta in projection.manifest['tables'].items():
        names=[c['name'] for c in meta['columns']];prefix='INSERT INTO '+mq(table)+' ('+','.join(mq(n) for n in names)+') VALUES\n';batch=[];size=len(prefix.encode())
        for row in db.execute('SELECT '+','.join(qi(c) for c in names)+' FROM '+qi(table)):
            rendered='('+','.join(_value(v,mapping[table]['columns'][n]) for n,v in zip(names,row))+')';length=len(rendered.encode('utf-8'))
            if len(prefix.encode())+length+2>limit:raise MigrationError('One legacy row exceeds the safe SQL statement limit: '+table)
            if batch and (size+length+4>limit or len(batch)>=200):yield prefix+',\n'.join(batch);batch=[];size=len(prefix.encode())
            batch.append(rendered);size+=length+2
        if batch:yield prefix+',\n'.join(batch)
    yield 'COMMIT'

def write_mysql_dump(projection,destination:Path,max_statement_bytes:int=1048576)->dict:
    destination=Path(destination);sidecar=Path(str(destination)+'.statements.jsonl')
    if destination.exists() or sidecar.exists():raise MigrationError('SQL destination already exists; no export was overwritten.')
    variables=[('SQL_MODE','sql_mode'),('FOREIGN_KEY_CHECKS','foreign_key_checks'),('TIME_ZONE','time_zone'),('CHARACTER_SET_CLIENT','character_set_client'),('CHARACTER_SET_RESULTS','character_set_results'),('CHARACTER_SET_CONNECTION','character_set_connection'),('COLLATION_CONNECTION','collation_connection')]
    header=['SET @ERP_OLD_'+v+'=@@SESSION.'+s for v,s in variables]
    header += ["SET SESSION sql_mode=CONCAT_WS(',',NULLIF(@ERP_OLD_SQL_MODE,''),'NO_AUTO_VALUE_ON_ZERO','NO_BACKSLASH_ESCAPES')",'SET SESSION foreign_key_checks=0',"SET SESSION time_zone='+00:00'",'SET NAMES utf8mb4 COLLATE utf8mb4_bin']
    footer=['SET SESSION '+s+'=@ERP_OLD_'+v for v,s in variables[1:3]+variables[:1]+variables[3:]]
    mapping={};ddl=[];lengths=[]
    with closing(sqlite3.connect(projection.path.as_uri()+'?mode=ro',uri=True)) as db:
        for table,meta in projection.manifest['tables'].items():
            statement,mapping[table]=_compile_table(db,table,meta);ddl.append(statement)
        if any(len(s.encode('utf-8'))+2>max_statement_bytes for s in header+ddl+footer):raise MigrationError('A schema statement exceeds the configured limit.')
        with destination.open('x',encoding='utf-8',newline='\n') as sql,sidecar.open('x',encoding='utf-8',newline='\n') as control:
            sql.write('-- School ERP confidential export. Import into a NEW EMPTY database only.\n')
            for statement in chain(header,ddl,_inserts(db,projection,mapping,max_statement_bytes),footer):
                lengths.append(len(statement.encode('utf-8'))+2);sql.write(statement+';\n');control.write(json.dumps(statement,ensure_ascii=False)+'\n')
    os.chmod(destination,0o600);os.chmod(sidecar,0o600)
    return {'sha256':digest_file(destination),'bytes':destination.stat().st_size,'table_count':len(mapping),'row_counts':{t:m['row_count'] for t,m in projection.manifest['tables'].items()},'schema_mappings':mapping,'statement_lengths':lengths,'statements_file':sidecar.name,'statements_sha256':digest_file(sidecar)}
