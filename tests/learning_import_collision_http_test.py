"""Legacy canonical-equal subjects keep identities; imports require unique names or codes."""
import re
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from question_source_http_test import upload,csv_bytes
from question_import_context_support import catalog_context

with ERPHttpFixture() as app:
    f=extend_learning(app);cls,original=f['class'],f['subject']
    name=app.db.execute('SELECT name_en FROM subjects WHERE id=?',(original,)).fetchone()[0]
    duplicate=app.db.execute("INSERT INTO subjects(school_id,name_en,code,type,is_hidden) VALUES(1,?,'ZZCOLLIDE','compulsory',0)",(name,)).lastrowid
    app.db.execute('INSERT INTO class_subjects(school_id,class_id,subject_id,teacher_id,is_active) VALUES(1,?,?,?,1)',(cls,duplicate,str(f['ids']['teacher'])))
    app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Import collision chapter')",(cls,duplicate))
    app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Import collision chapter')",(cls,original));app.db.commit()
    app.login('teacher')
    page=app.request('/modules/learning/questions.php?class='+str(cls))
    subject_options=re.search(r'<select[^>]+name="subject"[^>]*>(.*?)</select>',page,re.S).group(1)
    assert 'ZZCOLLIDE' in subject_options,'Canonical-equal selector labels distinguish codes'
    app.import_context=catalog_context(app,1,cls,duplicate,'Import collision chapter','Collision topic')
    headers=['Class','Subject','Chapter','Topic','Question HTML']
    body=upload(app,csv_bytes([headers,['Class 9','ZZCOLLIDE','Import collision chapter','Collision topic','<p>Code-specific imported question</p>']]))
    assert 'Successfully imported 1 questions' in body,'A visible assigned legacy duplicate remains importable by its unique code'
    assert app.db.execute("SELECT subject_id,created_by_user_id,visibility FROM questions WHERE question_text LIKE '%Code-specific imported question%'").fetchone()==(duplicate,f['ids']['teacher'],'private')
    before=app.snapshot('questions')
    body=upload(app,csv_bytes([headers,['Class 9',name,'Import collision chapter','Collision topic','<p>Ambiguous subject must not import</p>']]))
    assert 'No questions were imported' in body and 'matches multiple' in body,'Ambiguous canonical names must not pick an arbitrary ID'
    assert app.snapshot('questions')==before
    print(f'Learning import collision HTTP passed: {app.count} requests; explicit code retained and ambiguous name rejected')
