"""Read-only FTPS verification of deployed question authoring/runtime files.

Only fixed public/source-code paths are read; never database.local.php, SQL,
uploads or school data. Credentials are environment-only and are never logged.
"""
from pathlib import Path
import ftplib,hashlib,os,re,ssl,sys

ROOT=Path(__file__).resolve().parents[1]


def runtime_paths(root=ROOT):
    names=['.htaccess','app.php','index.php','config/bootstrap.php','includes/header.php',
           'includes/database_settings.php','includes/exam_question_page.php',
           'includes/exam_question_editor_form.php','includes/question_editor.php',
           'includes/learning_content_service.php','modules/learning/questions.php',
           'modules/learning/exam_questions.php','modules/learning/exam_mcqs.php',
           'assets/js/question-editor.js','includes/auth.php','includes/xlsx_helper.php',
           'includes/runtime_security.php','includes/password_security.php','includes/attendance_service.php','modules/auth/change_password.php',
           'modules/auth/verify_password.php','modules/super_admin/index.php','assets/js/pdfjs-runtime.mjs']
    names.extend(['includes/school_offerings.php','includes/school_offerings_page.php','includes/request_guard.php','includes/school_setup.php','includes/school_validation.php','includes/teaching_scope.php','includes/learning_content.php','includes/exam_service.php','includes/marks_service.php','modules/super_admin/offerings.php','modules/super_admin/subjects.php','modules/subjects/index.php','includes/routes.php'])
    names.extend(['includes/question_parts.php','includes/question_parts_form.php','includes/exam_question_language.php','includes/exam_paper_service.php','assets/css/exam-question-language.css','includes/functions.php','assets/js/request-security.js'])
    names.extend(['includes/question_parts_import.php','includes/exam_question_import.php','includes/exam_question_import_form.php'])
    for folder in ('assets/dist','assets/vendor/tinymce-6.8.6','assets/vendor/mathjax-3.2.2','assets/vendor/pdfjs-6.4.299'):
        directory=root/folder
        if not directory.is_dir():raise ValueError('Build required public runtime assets before verification.')
        names.extend(path.relative_to(root).as_posix() for path in directory.rglob('*') if path.is_file())
    return sorted(set(names))


def verify(ftp,paths,root=ROOT):
    ftp.voidcmd('TYPE I')
    for name in paths:
        local=root/name
        size=ftp.size(name)
        if size!=local.stat().st_size:raise ValueError('Deployed file size differs: '+name)
    # Check content too for the controllers/loaders most relevant to this fix.
    critical=['.htaccess','app.php','config/bootstrap.php','includes/exam_question_page.php',
              'includes/exam_question_editor_form.php','assets/js/question-editor.js',
              'assets/dist/workspace.js','assets/dist/manifest.json',
              'assets/vendor/tinymce-6.8.6/tinymce.min.js',
              'assets/vendor/tinymce-6.8.6/models/dom/model.min.js',
              'assets/vendor/tinymce-6.8.6/themes/silver/theme.min.js',
              'assets/vendor/mathjax-3.2.2/tex-mml-chtml.js',
              'includes/auth.php','includes/xlsx_helper.php','includes/runtime_security.php',
              'modules/auth/change_password.php','modules/auth/verify_password.php',
              'modules/super_admin/index.php','modules/students/index.php',
              'modules/teachers/index.php','modules/classes/subjects.php','modules/settings/years.php',
              'includes/password_security.php','includes/attendance_service.php','modules/classes/index.php','modules/classes/routine.php',
              'assets/js/pdfjs-runtime.mjs','assets/vendor/pdfjs-6.4.299/pdf.min.mjs','assets/vendor/pdfjs-6.4.299/pdf.worker.min.mjs']
    critical.extend(['includes/school_offerings.php','includes/school_offerings_page.php','includes/request_guard.php','includes/school_setup.php','includes/school_validation.php','includes/teaching_scope.php','includes/learning_content.php','includes/exam_service.php','includes/marks_service.php','modules/super_admin/offerings.php','modules/super_admin/subjects.php','modules/subjects/index.php','includes/routes.php'])
    critical.extend(['includes/question_parts.php','includes/question_parts_form.php','includes/exam_question_language.php','includes/exam_paper_service.php','includes/learning_content_service.php','assets/css/exam-question-language.css','includes/functions.php','assets/js/request-security.js'])
    critical.extend(['includes/question_parts_import.php','includes/exam_question_import.php','includes/exam_question_import_form.php'])
    for name in critical:
        digest=hashlib.sha256()
        ftp.retrbinary('RETR '+name,digest.update)
        if digest.digest()!=hashlib.sha256((root/name).read_bytes()).digest():raise ValueError('Deployed file content differs: '+name)
    return len(paths),len(critical)


def main():
    settings={key:os.environ.get('FTP_'+key,'') for key in ('SERVER','USERNAME','PASSWORD','SERVER_DIR')}
    if not all(settings.values()):raise ValueError('Missing private FTP verification settings.')
    folder=settings['SERVER_DIR']
    if not re.fullmatch(r'/?(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*htdocs/',folder):raise ValueError('FTP verification requires the validated htdocs directory.')
    paths=runtime_paths()
    with ftplib.FTP_TLS(context=ssl.create_default_context(),timeout=45) as ftp:
        ftp.connect(settings['SERVER'],21);ftp.login(settings['USERNAME'],settings['PASSWORD']);ftp.prot_p();ftp.cwd(folder)
        count,hashes=verify(ftp,paths)
    print(f'FTPS runtime verified: {count} application/editor/math files present with exact sizes; {hashes} critical SHA-256 hashes match. No school data read or changed.')


if __name__=='__main__':
    try:main()
    except ValueError as error:print(str(error),file=sys.stderr);sys.exit(1)
    except Exception:print('Read-only FTPS verification failed. Check connection, certificate and deployed runtime files; credentials are not logged.',file=sys.stderr);sys.exit(1)
