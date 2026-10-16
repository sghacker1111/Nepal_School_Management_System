"""Exact exam assignment scope, including crafted catalog/template and print filters."""
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
with ERPHttpFixture() as app:
    f=extend_learning(app); cls,sub=f['class'],f['subject']; route='/modules/learning/exam_questions.php'
    qid=app.db.execute("INSERT INTO questions(school_id,class_id,subject_id,chapter,question_text,bank_kind,visibility,type,created_by_user_id) VALUES(1,?,?,'Scope','Assignment secret','exam','public','Short',?)",(cls,sub,f['ids']['teacher'])).lastrowid
    app.db.commit(); query=f'?class_id={cls}&subject_id={sub}'
    app.login('unrelated_teacher')
    assert 'Assignment secret' not in app.request(route), 'Public exam must not leak to unrelated teacher'
    app.request(route+query,status=403)
    for action in ('catalog','import_catalog','download_template'):
        app.request(route+query+'&action='+action+'&import_kind=other&format=csv',status=403)
    app.request(route+query+'&record_id='+str(qid),status=403)
    app.request('/modules/learning/exam_paper_generator.php'+query,status=404)
    app.login('co_teacher'); assert 'Assignment secret' in app.request(route+query)
    app.login('school_admin'); assert 'Assignment secret' in app.request(route+query)
    app.db.execute('UPDATE class_subjects SET teacher_id=? WHERE class_id=? AND subject_id=?',(str(f['ids']['co_teacher']),cls,sub));app.db.commit()
    app.login('teacher'); assert 'Assignment secret' not in app.request(route)
    app.request(route+query,status=403)
    print(f'Exam teacher scope HTTP passed: {app.count} requests.')
