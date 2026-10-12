"""Approved progress-card and ledger presentation over disposable real HTTP/PDO."""
import re
from http_support import ERPHttpFixture

def table(html, marker):
    return re.search(r'<table[^>]*'+marker+r'.*?</table>', html, re.S).group(0)

with ERPHttpFixture() as app:
    f=app.fixture; exam=f['exam']; sid=f['student']; cls=f['class']; sub=f['subject']
    app.db.execute("UPDATE exams SET result_system='percentage',date_calendar='both',date_format='long' WHERE id=?",(exam,))
    app.db.execute("UPDATE students SET dob_ad='2010-01-01' WHERE id=?",(sid,))
    app.db.execute('INSERT INTO marks(exam_id,student_id,subject_id,theory_obtained,practical_obtained) VALUES(?,?,?,60,20)',(exam,sid,sub))
    app.db.commit(); app.login('school_admin')
    routes=[f'/modules/results/report_card.php?student={sid}&exam={exam}',f'/modules/results/print_bulk.php?class={cls}&exam={exam}']
    for route in routes:
        body=app.request(route)
        assert 'GRADE SHEET' in body and 'GPA 3.60' in body, 'Saved exam system must not override default GPA printing'
        assert re.search(r'<option[^>]*value="BS"[^>]*selected',body), 'BS-only print calendar must be selected'
        assert re.search(r'<option[^>]*selected[^>]*>BS</option>',body), 'Manual input must default to BS'
        dob=re.search(r'Date of Birth:</span>\s*<strong>(.*?)</strong>',body,re.S).group(1)
        assert re.fullmatch(r'\d{4}-\d{2}-\d{2} BS',dob),dob
        body=app.request(route+'&issue_date_mode=manual&issue_calendar=BS&issue_date=2083-06-24')
        assert 'data-issue-date="2026-10-10">2083-06-24 BS' in body
        body=app.request(route+'&display_calendar=AD&issue_calendar=AD&issue_date_mode=manual&issue_date=2026-10-10')
        assert 'data-issue-date="2026-10-10">2026-10-10 AD' in body
        body=app.request(route+'&display_calendar=both&issue_date_mode=manual&issue_date=2083-06-24')
        assert '2083-06-24 BS (2026-10-10 AD)' in body
        body=app.request(route+'&system=percentage')
        assert 'MARKS SHEET' in body and 'GRADE SHEET' not in body
        t=table(body,'percentage-components')
        assert re.findall(r'<th\b[^>]*>(.*?)</th>',t,re.S)==['S.N.','Subject','Full Marks','Obtained Marks','Total Obtained','Remarks']
        assert '(TH)' in t and '(PR)' in t and 'rowspan="2"' in t
        assert 'Final Total Percentage:' in body and '80.00%' in body and 'Division:' in body and 'Distinction' in body
        for forbidden in ['GPA Range','ONE CREDIT HOUR','ABS:</strong>','Grading Key System:','P:</strong>']:
            assert forbidden not in body,(route,forbidden)
    assert app.db.execute('SELECT result_system,date_calendar,date_format FROM exams WHERE id=?',(exam,)).fetchone()==('percentage','both','long')
    # Older pupils may have only a BS DOB; the selected print calendar still applies.
    app.db.execute("UPDATE students SET dob_ad=NULL,dob_bs='2083-06-24' WHERE id=?",(sid,));app.db.commit()
    for route in routes:
        body=app.request(route+'&display_calendar=AD')
        dob=re.search(r'Date of Birth:</span>\s*<strong>(.*?)</strong>',body,re.S).group(1)
        assert dob=='2026-10-10 AD',dob
    # Division thresholds must work even for a legacy scheme with no component pass mark.
    app.db.execute('UPDATE exam_subjects SET theory_full=100,theory_pass=0,practical_full=0,practical_pass=0 WHERE exam_id=?',(exam,))
    app.db.execute("UPDATE exams SET type='unit',has_internal=0 WHERE id=?",(exam,));app.db.commit()
    for mark,division in [(100,'Distinction'),(80,'Distinction'),(79.99,'First Division'),(60,'First Division'),(59.99,'Second Division'),(45,'Second Division'),(44.99,'Third Division'),(35,'Third Division'),(34.99,'Failed'),(0,'Failed'),(None,'Incomplete')]:
        app.db.execute('UPDATE marks SET theory_obtained=? WHERE exam_id=?',(mark,exam));app.db.commit()
        for route in routes:
            body=app.request(route+'&system=percentage'); t=table(body,'percentage-components')
            assert f'Division: <strong>{division}</strong>' in body,(mark,division)
            assert '(PR)' in t and '—' in t,'Nonapplicable PR must stay a dash'
            if mark is None: assert 'Final Total Percentage: <strong>—</strong>' in body
    # Required practical failure beats an otherwise passing aggregate.
    app.db.execute("UPDATE exams SET type='terminal',has_internal=1 WHERE id=?",(exam,))
    app.db.execute('UPDATE exam_subjects SET theory_full=75,theory_pass=26.25,practical_full=25,practical_pass=10 WHERE exam_id=?',(exam,))
    app.db.execute('UPDATE marks SET theory_obtained=75,practical_obtained=9 WHERE exam_id=?',(exam,));app.db.commit()
    for route in routes:
        body=app.request(route+'&system=percentage');assert 'Division: <strong>Failed</strong>' in body
    # Thematic data uses the overall 100-mark score; it is not fabricated theory.
    app.db.execute("UPDATE exam_subjects SET assessment_method='THEMATIC_CONTINUOUS',theory_full=100,practical_full=0 WHERE exam_id=?",(exam,))
    app.db.execute('UPDATE marks SET theory_obtained=80,practical_obtained=NULL WHERE exam_id=?',(exam,));app.db.commit()
    for route in routes:
        t=table(app.request(route+'&system=percentage'),'percentage-components')
        assert 'Overall assessment' in t and '(TH)' not in t and '(PR)' not in t and '>80<' in t
    # The ledger's grade mode derives actual component grades even if stored grade is stale.
    app.db.execute("UPDATE exam_subjects SET assessment_method='THEORY_INTERNAL',theory_full=75,practical_full=25 WHERE exam_id=?",(exam,))
    app.db.execute("UPDATE marks SET theory_obtained=60,practical_obtained=20,grade='D',grade_point=1.6 WHERE exam_id=?",(exam,));app.db.commit()
    ledger=f'/modules/results/ledger.php?exam={exam}&class={cls}'
    for suffix in ['',f'&subject={sub}']:
        body=app.request(ledger+suffix);t=table(body,'id="ledgerTable"')
        assert all(label in t for label in ['GRADE (TH)','GRADE (IN)','FINAL GRADE','GRADE POINT','CH: 4','GPA'])
        assert 'FM:' not in t and '>60<' not in t and '>20<' not in t and '>A<' in t and '>3.60<' in t
        t=table(app.request(ledger+suffix+'&mode=marks'),'id="ledgerTable"')
        assert all(label in t for label in ['>TH<','>PR<','>TOTAL<','FM: 100','Percentage','Division'])
        assert 'CH:' not in t and 'GRADE (' not in t and '>60<' in t and '>20<' in t and '>80<' in t and 'Distinction' in t
    app.db.execute("UPDATE exams SET type='unit',has_internal=0 WHERE id=?",(exam,));app.db.commit()
    t=table(app.request(ledger),'id="ledgerTable"');assert 'GRADE (IN)' in t and '—' in t and '>60<' not in t
    t=table(app.request(ledger+'&mode=marks'),'id="ledgerTable"');assert '>60<' in t and 'FM: 75' in t and '—' in t
    app.login('teacher');body=app.request(ledger);assert 'Print Ledger' in body
    app.request(f'/modules/results/ledger.php?exam={exam}&class={f["otherClass"]}',status=404)
    print(f'Progress print HTTP passed: {app.count} requests; defaults, calendars, percentage components/divisions, thematic and two ledger modes.')
