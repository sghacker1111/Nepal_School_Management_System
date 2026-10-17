"""Attendance/results fragments retain exact scope and server-side marks authority."""
import json,re,subprocess,tempfile
from pathlib import Path
from http_support import ERPHttpFixture
from routes_http_test import send
from app_response_http_test import HX,marked

def run():
 with ERPHttpFixture() as app:
  f=app.fixture;cls=f['class'];exam=f['exam'];subject=f['subject'];student=f['student']
  app.login('school_admin');pages=[]
  tables=('students','marks','attendances','exam_student_attendance','exams','exam_subjects')
  before={table:app.snapshot(table) for table in tables}
  paths=('/attendance',f'/attendance?class={cls}&view=monthly&calendar=BS&year=2083&month=6',f'/attendance?class={cls}&view=range&calendar=AD&range_start=2026-10-01&range_end=2026-10-03', '/results',f'/results?exam={exam}&class={cls}',f'/results/marks-entry?exam={exam}&class={cls}&subject={subject}',f'/results/ledger?exam={exam}&class={cls}&mode=grade',f'/results/ledger?exam={exam}&class={cls}&mode=marks',f'/results/print-bulk?exam={exam}&class={cls}&issue_date_mode=manual&issue_calendar=BS&issue_date=2083-06-20&display_calendar=BS&date_format=ymd','/reports')
  empty=app.db.execute("SELECT id FROM classes WHERE school_id=1 AND name='Class 1'").fetchone()[0]
  for path in (*paths,f'/results?exam={exam}&class={empty}'):
   h,b,_=send(app,path,headers=HX);data=marked(h,b)
   assert data['adapterReady'] is True,(path,data['pageId'])
   assert not re.search(rb'<script(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|keyup)=',b,re.I),path
   if path.endswith('class='+str(empty)):assert b'id="resultsTable"' not in b and b'No students found' in b
   if 'issue_date_mode=manual' in path:assert b'value="2083-06-20"' in b and b'name="issue_calendar"' in b
   pages.append({'url':app.base+path,'html':b.decode(),'config':data})
  with tempfile.TemporaryDirectory(prefix='erp-results-mounts-') as directory:
   fixture=Path(directory)/'pages.json';fixture.write_text(json.dumps(pages),encoding='utf8')
   subprocess.run(['node','tests/school_page_mounts.mjs',str(fixture)],cwd=app.root,check=True)
  for table,rows in before.items():assert app.snapshot(table)==rows,table
  # A teacher cannot gain another subject via HTMX headers.
  app.db.execute('UPDATE classes SET class_teacher_id=NULL WHERE id=?',(cls,));app.db.commit()
  app.login('teacher');other=app.db.execute('SELECT subject_id FROM class_subjects WHERE class_id=? AND subject_id<>? LIMIT 1',(cls,subject)).fetchone()[0]
  h,b,_=send(app,f'/results/marks-entry?exam={exam}&class={cls}&subject={other}',headers=HX,status=404);marked(h,b)
  # Locking cannot be bypassed with a crafted dynamic save; reopening retains normal authority.
  app.login('school_admin');app.request('/exams?lock='+str(exam),{})
  app.login('teacher');path=f'/results/marks-entry?exam={exam}&class={cls}'
  data={'save_marks':'1','marks_complete':'1','exam_id':exam,'class_id':cls,f'marks[{student}][{subject}][theory]':'55',f'marks[{student}][{subject}][participation]':'3',f'marks[{student}][{subject}][project_work]':'12',f'marks[{student}][{subject}][previous_exam]':'5'}
  marks=app.snapshot('marks');h,b,_=send(app,path,data=data,headers=HX,status=422);marked(h,b);assert b'locked' in b.lower() and app.snapshot('marks')==marks
  app.login('school_admin');app.request('/exams?reopen='+str(exam),{});app.login('teacher')
  h,b,_=send(app,path,data=data,headers=HX,status=204);assert h['HX-Location'] and not b
  assert app.db.execute('SELECT theory_obtained FROM marks WHERE student_id=? AND subject_id=?',(student,subject)).fetchone()==(55,)
  print('Results app HTTP passed: inert/scoped page mounts, empty classes, print dates, locked marks and reopening.')
if __name__=='__main__':run()
