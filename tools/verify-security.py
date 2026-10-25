"""Portable security gate: synthetic fixtures only; no hosting credentials."""
import os, subprocess, sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
php = os.environ.get('ERP_TEST_PHP', 'php')
commands = []
for folder in ('includes', 'modules', 'config'):
    for path in sorted((root / folder).rglob('*.php')):
        if path.name.endswith('.local.php') or path.name == 'local.php':
            continue
        commands.append([php, '-l', str(path)])
for name in ('app.php','index.php','router.php'):
    commands.append([php, '-l', name])
for name in ('security_test.php','school_offerings_test.php','question_parts_test.php','question_bulk_archive_test.php','paper_format_test.php','mathematics_catalog_test.php','question_parts_import_test.php','import_security_test.php','packages_test.php',
             'learning_content_test.php','exam_teacher_scope_test.php'):
    commands.append([php, 'tests/' + name])
for name in ('school_offerings_http_test.py','question_parts_http_test.py','question_bulk_http_test.py','paper_format_http_test.py','mathematics_catalog_http_test.py','exam_question_language_http_test.py','question_parts_import_http_test.py','session_security_http_test.py','database_runtime_http_test.py',
             'class_isolation_http_test.py','attendance_filters_http_test.py',
             'packages_http_test.py','exam_teacher_scope_http_test.py',
             'question_authoring_app_http_test.py','single_page_coverage_http_test.py'):
    commands.append([sys.executable, 'tests/' + name])
commands.append([sys.executable, 'tests/deployment_test.py'])
for command in commands:
    result = subprocess.run(command, cwd=root, capture_output=True, text=True,
                            encoding='utf-8', errors='replace', timeout=180)
    if result.returncode:
        print(result.stdout + result.stderr)
        raise SystemExit(result.returncode)
    if '-l' not in command:
        print(result.stdout.strip(), flush=True)
print(f'Security gate passed: {len(commands)} commands.', flush=True)
