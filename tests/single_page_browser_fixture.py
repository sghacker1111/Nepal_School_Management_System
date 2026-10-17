"""Fresh disposable browser gate, reusing the reviewed synthetic school seed.

Run with --stop-file pointing to a new ignored task-local path. No production
credentials/data. The shared harness verifies protected rows on orderly exit.
"""
from pathlib import Path
import runpy
runpy.run_path(str(Path(__file__).with_name('ui_migration_browser_fixture.py')),run_name='__main__')
