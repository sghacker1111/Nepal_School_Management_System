"""Two real processes must produce one pending correction, not duplicate rows."""
import subprocess
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
with ERPHttpFixture() as app:
    f=extend_learning(app)
    scope=app.db.execute('SELECT c.id,s.id FROM classes c JOIN class_subjects cs ON cs.class_id=c.id AND cs.school_id=0 JOIN subjects s ON s.id=cs.subject_id WHERE c.school_id=0 AND c.level=10 LIMIT 1').fetchone()
    # Match the fixture local class/subject by source IDs, without name guessing.
    cls=app.db.execute('SELECT source_id FROM classes WHERE id=?',(f['class'],)).fetchone()[0]
    sub=app.db.execute('SELECT source_id FROM subjects WHERE id=?',(f['subject'],)).fetchone()[0]
    assert cls and sub
    qid=app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,question_text,visibility) VALUES(0,?,?,'Sets','Concurrent shared','public')",(cls,sub)).lastrowid;app.db.commit()
    command=[app.php,'tests/question_correction_worker.php',str(f['ids']['teacher']),str(qid)]
    workers=[subprocess.Popen(command,cwd=app.root,env=app.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(2)]
    outputs=[p.communicate(timeout=30) for p in workers]
    assert all(p.returncode==0 for p in workers),outputs
    assert outputs[0][0]==outputs[1][0],outputs
    assert app.db.execute('SELECT count(*) FROM question_correction_requests').fetchone()[0]==1
    print('Correction concurrency passed: two processes, one Pending row.')
