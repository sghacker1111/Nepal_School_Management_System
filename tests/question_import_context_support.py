"""Existing catalog selections for disposable import fixtures only."""
from urllib.parse import urlencode


def catalog_context(app, school=0, cls=None, sub=None, chapter='Source Chapter', topic='Source Topic'):
    if cls is None or sub is None:
        cls, sub = app.db.execute("SELECT cs.class_id,cs.subject_id FROM class_subjects cs JOIN classes c ON c.id=cs.class_id JOIN subjects s ON s.id=cs.subject_id WHERE cs.school_id=? AND c.name='Class 10' AND s.name_en='Mathematics' LIMIT 1", (school,)).fetchone()
    ch = app.db.execute('SELECT id FROM chapters WHERE school_id=? AND class_id=? AND subject_id=? AND name=?', (school, cls, sub, chapter)).fetchone()
    ch = ch[0] if ch else app.db.execute('INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(?,?,?,?)', (school, cls, sub, chapter)).lastrowid
    tp = app.db.execute('SELECT id FROM topics WHERE school_id=? AND chapter_id=? AND name=?', (school, ch, topic)).fetchone()
    tp = tp[0] if tp else app.db.execute('INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(?,?,?,?,?)', (school, cls, sub, ch, topic)).lastrowid
    app.db.commit()
    return dict(import_class_id=cls, import_subject_id=sub, import_chapter_id=ch, import_topic_id=tp)


def context_query(app):
    return '&' + urlencode(app.import_context)
