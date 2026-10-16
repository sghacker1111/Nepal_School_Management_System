"""Disposable local UI acceptance data. No production credentials or records."""
import argparse,json,time
from pathlib import Path
from http_support import ERPHttpFixture
from exam_chapter_paper_http_test import seed_chapter_papers
parser=argparse.ArgumentParser();parser.add_argument('--stop-file',required=True);args=parser.parse_args()
with ERPHttpFixture() as app:
    f,chapters,topics,ids=seed_chapter_papers(app)
    # More than one page exercises partial paging rather than only the empty state.
    qid=ids['MCQ'][0]
    cols=[r[1] for r in app.db.execute('PRAGMA table_info(questions)') if r[1]!='id']
    row=app.db.execute('SELECT '+','.join(cols)+' FROM questions WHERE id=?',(qid,)).fetchone()
    for _ in range(14):app.db.execute('INSERT INTO questions('+','.join(cols)+') VALUES('+','.join('?' for _ in cols)+')',row)
    app.db.commit()
    protected={t:app.snapshot(t) for t in ('students','users','questions','chapters','topics','marks','exams','ac_invoices','ac_payments','ac_journals')}
    print(json.dumps(dict(base=app.base,cls=f['class'],subject=f['subject'],user='teacher',password='TestPassword123!')),flush=True)
    while not Path(args.stop_file).exists():time.sleep(.25)
    for table,rows in protected.items():assert app.snapshot(table)==rows,table+' changed during read-only UI verification'
    print('Local UI data preservation verified.',flush=True)
