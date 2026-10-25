"""Exam-only bulk uploads use disposable records and the real PHP endpoints."""
import csv, io, json, re, zipfile
from urllib.request import Request
from urllib.error import HTTPError
from http_support import ERPHttpFixture
from learning_sharing_support import extend_learning
from question_import_context_support import catalog_context, context_query

PAGE = '/modules/learning/exam_questions.php'
MCQ_PAGE = '/modules/learning/exam_mcqs.php'
def page_for(kind):return MCQ_PAGE if kind=='mcq' else PAGE
HEADERS = ['Class', 'Subject', 'Chapter', 'Topic', 'Type', 'Difficulty', 'Source',
           'Cognitive Level', 'Marks', 'Required Minutes', 'Question (English)',
           'Question (Nepali)', 'Answer (English)', 'Answer (Nepali)',
           'Option A (English)', 'Option A (Nepali)', 'Option B (English)',
           'Option B (Nepali)', 'Option C (English)', 'Option C (Nepali)',
           'Option D (English)', 'Option D (Nepali)', 'Correct Option']
MCQ_HEADERS = HEADERS
HEADERS = HEADERS[:14]

def csv_bytes(rows):
    output = io.StringIO(); csv.writer(output).writerows(rows)
    return output.getvalue().encode('utf-8-sig')

def upload(app, content, filename='exam.csv', mode='normal', status=200, kind='other', **extra):
    boundary = 'ExamQuestionImportBoundary7615'
    fields = {'csrf_token': app.token, 'action': 'import',
              'import_content_mode': mode, 'import_kind': kind, 'import_visibility': 'private', **getattr(app,'import_context',{}), **extra}
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items()]
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="excel_file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode())
    request = Request(app.base + page_for(kind), data=b''.join(parts), headers={'Content-Type': f'multipart/form-data; boundary={boundary}'})
    try: response = app.opener.open(request, timeout=30)
    except HTTPError as error: response = error
    with response:
        body = response.read().decode(); assert response.status == status, (response.status, body[:1200])
    assert not re.search(r'Fatal error|Uncaught|Warning:', body), body[:1200]
    app.count += 1
    return body

def seed_exam_import(app):
    f = extend_learning(app)
    chapter = app.db.execute("INSERT INTO chapters(school_id,class_id,subject_id,name) VALUES(1,?,?,'Import unit')", (f['class'], f['subject'])).lastrowid
    topic = app.db.execute("INSERT INTO topics(school_id,class_id,subject_id,chapter_id,name) VALUES(1,?,?,?,'Import topic')", (f['class'], f['subject'], chapter)).lastrowid
    app.db.commit(); app.login('teacher')
    app.import_context=dict(import_class_id=f['class'],import_subject_id=f['subject'],import_chapter_id=chapter)
    cls = app.db.execute('SELECT name FROM classes WHERE id=?', (f['class'],)).fetchone()[0]
    sub = app.db.execute('SELECT code FROM subjects WHERE id=?', (f['subject'],)).fetchone()[0]
    row = [cls, sub, 'Import unit', 'Import topic', 'Short', 'Hard', 'School',
           'A', '2.50', '3.25', 'Exam import 1 < 2 & 3', 'परीक्षा प्रश्न',
           'Model answer', 'नमुना उत्तर']
    return f, row, chapter, topic

def run():
    with ERPHttpFixture() as app:
        f, row, chapter, topic = seed_exam_import(app)
        preserved = {t: app.snapshot(t) for t in ('students', 'marks', 'exams', 'ac_invoices', 'ac_payments', 'ac_journals')}
        page = app.request(PAGE+'?view=import')
        assert 'Import Exam Questions' in page and 'HTML Source' in page
        assert 'import_content_mode' in page and 'excel_file' in page
        assert 'id="examImportForm-mcq"' not in page and 'id="examImportForm-other"' in page
        assert page.count('name="import_chapter_id"')==1 and 'name="import_topic_id"' not in page
        mcq_page=app.request(MCQ_PAGE+'?view=import');assert 'id="examImportForm-mcq"' in mcq_page and 'id="examImportForm-other"' not in mcq_page
        catalog=json.loads(app.request(PAGE+f'?action=import_catalog&class_id={f["class"]}&subject_id={f["subject"]}'))
        assert any(c['id']==chapter for c in catalog['chapters']) and any(t['id']==topic for t in catalog['topics'][str(chapter)])
        scope_before={t:app.snapshot(t) for t in ('questions','chapters','topics','activity_logs')}
        for index in (0,1,2):
            bad=row.copy();bad[index]=''
            body=upload(app,csv_bytes([HEADERS,row,bad]),status=422)
            assert 'Row 3:' in body and 'No questions were imported' in body
            for t,r in scope_before.items():assert app.snapshot(t)==r,t
        for field in app.import_context:
            upload(app,csv_bytes([HEADERS,row]),status=422,**{field:''})
            upload(app,csv_bytes([HEADERS,row]),status=403 if field in ('import_class_id','import_subject_id') else 422,**{field:'999999'})
            for t,r in scope_before.items():assert app.snapshot(t)==r,t
        # Existing but different catalog choices cannot be silently substituted.
        alternate=catalog_context(app,1,f['class'],f['subject'],'Other existing chapter','Other existing topic')
        bad=row.copy();bad[2]='Other existing chapter';bad[3]='Other existing topic'
        upload(app,csv_bytes([HEADERS,row,bad]),status=422)
        assert app.snapshot('questions')==scope_before['questions']
        # Topic selection is deliberately not part of chapter-wide exam preflight.
        # Every mode/format template must import as-is for the downloading teacher.
        for kind in ('mcq', 'other'):
          count = 2 if kind == 'mcq' else 4
          for mode in ('normal', 'html'):
            for fmt in ('csv', 'xlsx'):
                headers, sample = app.request_bytes(page_for(kind) + f'?action=download_template&import_kind={kind}&format={fmt}&content_mode={mode}' + context_query(app))
                assert f'exam-questions-{kind}-{mode}-sample.{fmt}' in headers['Content-Disposition']
                assert f'.{fmt}' in headers['Content-Disposition']
                if fmt == 'csv':
                    data = list(csv.reader(io.StringIO(sample.decode('utf-8-sig'))))
                    assert len(data) == (2 if kind == 'mcq' else 6) + 1 and len(data[0]) == len(MCQ_HEADERS if kind == 'mcq' else HEADERS)+4 and 'Topic' not in data[0]
                    if kind == 'mcq': assert all(r[3] == 'MCQ' and r[21] in 'ABCD' and all(r[i] or r[i+1] for i in (13,15,17,19)) for r in data[1:])
                    else: assert {r[3] for r in data[1:]} == {'Very Short','Short','Long','Difference Between'} and not any('Option' in h for h in data[0])
                else:
                    with zipfile.ZipFile(io.BytesIO(sample)) as book:
                        sheet = book.read('xl/worksheets/sheet1.xml').decode()
                        assert '<pane' in sheet and '<cols>' in sheet
                        assert '<c r="H2" s="2"><v>1</v></c>' in sheet
                body = upload(app, sample, 'sample.' + fmt, mode, kind=kind)
                assert f'Successfully imported {count} exam questions' in body, body[-3000:]
        # A wrong form/sample combination cannot save or silently change types.
        wrong_before = app.snapshot('questions')
        mcq_row = row[:14] + ['1','१','2','२','3','३','4','४','B']; mcq_row[4] = 'MCQ'
        assert 'Other Questions imports do not use options' in upload(app, csv_bytes([MCQ_HEADERS,mcq_row]), status=422)
        assert 'MCQ imports require Option A' in upload(app, csv_bytes([HEADERS,row]), kind='mcq', status=422)
        mcq_as_other = row.copy(); mcq_as_other[4] = 'MCQ'
        assert 'Use the MCQs import' in upload(app, csv_bytes([HEADERS,mcq_as_other]), status=422)
        written_as_mcq = mcq_row.copy(); written_as_mcq[4] = 'Short'
        assert 'accepts Type MCQ only' in upload(app, csv_bytes([MCQ_HEADERS,written_as_mcq]), kind='mcq', status=422)
        for kind in ('', 'mixed', 'all'):
            upload(app, csv_bytes([HEADERS,row]), kind=kind, status=422)
            app.request(PAGE + '?action=download_template&format=csv&content_mode=normal&import_kind=' + kind, status=422)
        app.request(PAGE + '?action=download_template&format=csv&content_mode=normal', status=422)
        assert app.snapshot('questions') == wrong_before
        before = len(app.snapshot('questions'))
        assert 'Successfully imported 1 exam questions' in upload(app, csv_bytes([HEADERS, row]))
        q = app.db.execute("SELECT id,question_text,marks,required_minutes,cognitive_domain,bank_kind,visibility,student_visible,topic_id FROM questions WHERE question_text LIKE '%Exam import%'").fetchone()
        assert q[1] == 'Exam import 1 &lt; 2 &amp; 3' and float(q[2]) == 2.5 and float(q[3]) == 3.25
        assert q[4:] == ('Application', 'exam', 'private', 0, None)
        assert 'Exam import' not in app.request('/modules/learning/questions.php')
        rich = row.copy(); rich[10] = '<p><strong>Exam fraction</strong> \\(\\frac{1}{2}\\)</p><script>bad()</script>'
        rich[12] = '<ul><li>\\[x=1\\]</li></ul><img src="javascript:bad" onerror="bad()">'
        source_headers = HEADERS.copy(); source_headers[10] = 'Question English HTML Source'; source_headers[12] = 'Answer English HTML Source'
        assert 'Successfully imported 1 exam questions' in upload(app, csv_bytes([source_headers, rich]), mode='html')
        content = app.db.execute("SELECT question_text,answer FROM questions WHERE question_text LIKE '%Exam fraction%'").fetchone()
        assert '<strong>Exam fraction</strong>' in content[0] and '\\frac' in content[0] and '<script' not in content[0]
        assert '<ul>' in content[1] and 'javascript:' not in content[1] and 'onerror' not in content[1]
        # Mixed batches report partial success without calling rejected rows saved.
        invalid = row.copy(); invalid[8] = '0'
        body = upload(app, csv_bytes([HEADERS, row, invalid]))
        assert 'Successfully imported 1 exam questions' in body and '1 row(s) skipped' in body and 'Row 3:' in body
        snapshots = {t: app.snapshot(t) for t in ('questions', 'chapters', 'topics')}
        bad_rows = []
        for index, value in ((0, 'Class 1'), (1, 'Unassigned subject'), (2, 'Unknown unit'), (4, 'Essay'), (5, 'Impossible'), (6, 'Shared'), (7, 'Bad domain'), (8, '1.001'), (9, '0'), (10, '')):
            bad = row.copy(); bad[index] = value
            if index == 10: bad[11] = ''
            bad_rows.append(bad)
        for bad in bad_rows:
            body = upload(app, csv_bytes([HEADERS, bad]), status=422)
            assert ('No exam questions were imported' in body or 'No questions were imported' in body) and 'Successfully imported' not in body
            assert 'name="action" value="import"' in body
            for table, snapshot in snapshots.items(): assert app.snapshot(table) == snapshot, table
        for bad_headers in (HEADERS + ['Marks'], HEADERS + ['school_id'], HEADERS + ['student_visible'], HEADERS[:8]):
            upload(app, csv_bytes([bad_headers, row]), status=422)
            assert app.snapshot('questions') == snapshots['questions']
        upload(app, b'not a workbook', 'bad.xlsx', status=422)
        upload(app, csv_bytes([HEADERS, row]), mode='wrong', status=422)
        upload(app, csv_bytes([HEADERS, row]), csrf_token='', status=419)
        # No student or unassigned teacher import/template capability.
        for username in ('student_login', 'unrelated_teacher'):
            app.login(username)
            app.request(PAGE + '?action=download_template&format=xlsx&content_mode=normal', status=403)
            upload(app, csv_bytes([HEADERS, row]), status=403)
        app.login('teacher')
        assert len(app.snapshot('questions')) == before + 3
        # Blank lines retain physical file row numbers in the rejection report.
        invalid = row.copy(); invalid[9] = '0'
        body = upload(app, csv_bytes([HEADERS, row, [], invalid]))
        assert 'Row 4:' in body and '1 row(s) skipped' in body
        # Reject oversized/hostile XML before the shared workbook reader runs.
        for worksheet in ('<row r="1000000"><c r="A1000000"><v>1</v></c></row>',
                          '<row r="2"><c r="ZZZ2"><v>1</v></c></row>',
                          '<row r="2"><c r="A3"><v>1</v></c></row>'):
            buffer = io.BytesIO()
            with zipfile.ZipFile(buffer, 'w') as archive:
                archive.writestr('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' + worksheet + '</sheetData></worksheet>')
            upload(app, buffer.getvalue(), 'malformed.xlsx', status=422)
        upload(app, csv_bytes([HEADERS] + [invalid]*2001), status=422)
        upload(app, b'\xff\xfeBAD', status=422)
        # A reordered workbook imports its first worksheet, not filename sheet1.
        _, sample = app.request_bytes(MCQ_PAGE + '?action=download_template&import_kind=mcq&format=xlsx&content_mode=normal' + context_query(app))
        with zipfile.ZipFile(io.BytesIO(sample)) as original:
            entries = {name: original.read(name) for name in original.namelist()}
        worksheet = entries['xl/worksheets/sheet1.xml'].decode()
        # Move data row 3 to row 4, so an invalid mark reports its actual Excel row.
        worksheet = re.sub(r'<row r="3"(.*?)</row>', lambda m: m.group(0).replace('r="3"', 'r="4"').replace('3"', '4"').replace('<v>2.5</v>', '<v>0</v>'), worksheet)
        entries['xl/worksheets/sheet2.xml'] = worksheet.encode()
        entries['xl/worksheets/sheet1.xml'] = b'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>'
        entries['xl/workbook.xml'] = entries['xl/workbook.xml'].replace(b'<sheet name="Exam Questions" sheetId="1" r:id="rId1"/>', b'<sheet name="Exam Questions" sheetId="2" r:id="rId1"/>')
        entries['xl/_rels/workbook.xml.rels'] = entries['xl/_rels/workbook.xml.rels'].replace(b'worksheets/sheet1.xml', b'worksheets/sheet2.xml')
        output = io.BytesIO()
        with zipfile.ZipFile(output, 'w') as book:
            for name, content in entries.items(): book.writestr(name, content)
        body = upload(app, output.getvalue(), 'reordered.xlsx', kind='mcq')
        assert 'Successfully imported 1 exam questions' in body and 'Row 4:' in body
        hostile = io.BytesIO()
        with zipfile.ZipFile(hostile, 'w') as book:
            for name, content in entries.items():
                if name.endswith('.rels'): content = b'<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///nonexistent">]><x>&secret;</x>'
                book.writestr(name, content)
        upload(app, hostile.getvalue(), 'unsafe-xml.xlsx', status=422)
        # School-admin import and sample download keep the same school scope.
        app.login('school_admin')
        _, sample = app.request_bytes(PAGE + '?action=download_template&import_kind=other&format=csv&content_mode=html' + context_query(app))
        assert 'Successfully imported 4 exam questions' in upload(app, sample, mode='html')
        app.login('admin')
        app.import_context=catalog_context(app)
        shared_row=row.copy();shared_row[:4]=['Class 10','Mathematics','New from import','New from import'];shared_row[6]='Shared'
        shared_before={t:app.snapshot(t) for t in ('questions','chapters','topics')}
        upload(app,csv_bytes([HEADERS,shared_row]),status=422)
        for t,r in shared_before.items():assert app.snapshot(t)==r,t
        _, sample = app.request_bytes(MCQ_PAGE + '?action=download_template&import_kind=mcq&format=xlsx&content_mode=html' + context_query(app))
        assert 'Successfully imported 2 exam questions' in upload(app, sample, 'shared.xlsx', 'html', kind='mcq', import_visibility='public')
        assert app.db.execute("SELECT COUNT(*) FROM questions WHERE bank_kind='exam' AND school_id=0 AND student_visible=0").fetchone()[0] == 2
        for table, snapshot in preserved.items(): assert app.snapshot(table) == snapshot, table
        print(f'Exam question import HTTP passed: {app.count} requests; eight separate MCQ/other templates round-trip, exact scope, source sanitizing, decimals, rejected rows and protected records.')

if __name__ == '__main__': run()
