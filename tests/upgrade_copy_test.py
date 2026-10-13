"""Upgrade a consistent read-only snapshot, never the supplied original database."""
import argparse, os, sqlite3, subprocess, tempfile
from contextlib import closing
from pathlib import Path

parser=argparse.ArgumentParser();parser.add_argument('--source',required=True);args=parser.parse_args()
source=Path(args.source).resolve(strict=True)
root=Path(__file__).resolve().parents[1]
def snapshot(db,baseline=None):
    tables={row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    result={}
    for table in ['students','marks','exams','users','attendances','ac_invoices','ac_payments','ac_journals','ac_journal_lines','ac_credits','ac_advance_entries','questions','learning_notes','chapters','topics']:
        if baseline is not None and table not in baseline:continue
        if table in tables:
            cols=[row[1] for row in db.execute(f'PRAGMA table_info({table})')]
            stable=baseline[table]['columns'] if baseline is not None else (cols if table in ['questions','learning_notes','chapters','topics'] else ['id']+[col for col in cols if col.endswith('_minor')])
            where='';params=[]
            if baseline is not None and table in ['chapters','topics']:
                params=[row[baseline[table]['columns'].index('id')] for row in baseline[table]['rows']]
                where=' WHERE id IN ('+','.join('?' for _ in params)+')' if params else ' WHERE 1=0'
            result[table]={'columns':stable,'rows':db.execute('SELECT '+','.join(stable)+f' FROM {table}'+where+' ORDER BY id',params).fetchall()}
    return result

with tempfile.TemporaryDirectory(prefix='erp-upgrade-copy-') as directory, closing(sqlite3.connect(source.as_uri()+'?mode=ro',uri=True)) as original:
    original_version=original.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone()[0]
    before=snapshot(original)
    target=Path(directory)/'upgrade.sqlite'
    with closing(sqlite3.connect(target)) as copied:original.backup(copied)
    env={**os.environ,'ERP_DB_TYPE':'sqlite','ERP_DB_PATH':str(target)}
    subprocess.run([os.environ.get('ERP_TEST_PHP','php'),'tests/upgrade_test.php'],cwd=root,env=env,check=True)
    with closing(sqlite3.connect(target)) as copied:
        after=snapshot(copied,before)
        assert after==before,'Upgrade changed stable records: '+str([(t,len(before[t]['rows']),len(after[t]['rows'])) for t in before if after[t]!=before[t]])
        assert copied.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
        for table in ['ac_invoices','ac_journals']:
            if table not in before:assert copied.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0]==0,'Migration fabricated transactions'
    assert snapshot(original)==before,'Original database changed'
    assert original.execute("SELECT meta_value FROM app_meta WHERE meta_key='workflow_schema'").fetchone()[0]==original_version
    print(f'Consistent-copy upgrade passed from schema {original_version}; original unchanged, IDs and money retained.')
