"""Guard the FTP workflow against overwriting/deleting school-owned runtime data."""
from pathlib import Path
import re

workflow = (Path(__file__).resolve().parents[1] / '.github/workflows/deploy.yml').read_text(encoding='utf-8')
exclude_block = re.search(r'^        exclude: \|\n((?:          .*(?:\n|$))+)', workflow, re.M)
assert exclude_block, 'FTP deployment must explicitly exclude persistent runtime data'
patterns = {line.strip() for line in exclude_block[1].splitlines()}
required = {
    '**/.git*', '**/.git*/**', '**/node_modules/**', '**/.env*',
    '**/*.sqlite', '**/*.sqlite-*', '**/*.sqlite3', '**/*.sqlite3-*',
    '**/*.db', '**/*.db-*', '**/*.bak',
    '**/.backups', '**/.backups/**', '**/backups', '**/backups/**',
    '**/uploads', '**/uploads/**', 'config/config.php',
    'config/local.php', 'config/*.local.php', '**/__pycache__/**',
    '**/*.pyc', 'scratch/**', 'tests/**', 'docs/**', '**/*.log',
    'frontend/**','tools/**','package.json','package-lock.json','.superpowers/**',
}
assert required <= patterns, f'Missing persistent-data exclusions: {sorted(required - patterns)}'
assert re.search(r'^        dangerous-clean-slate: false$', workflow, re.M), 'Destructive clean-slate deployment must stay disabled'
assert re.search(r'^  cancel-in-progress: false$', workflow, re.M), 'Never interrupt a production FTP transfer'
assert re.search(r'^  group: school-ftp-production$', workflow, re.M), 'Serialize deployment to this site'
assert 'server-dir: school.my-board.org/htdocs/' in workflow, 'Do not broaden the FTP destination'
assert 'branches:\n      - main' in workflow, 'Only main should deploy to production'
for secret in ('FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD'):
    assert '${{ secrets.' + secret + ' }}' in workflow, f'Use the configured {secret} secret'
# Keep source migrations available; the real database itself must remain excluded.
assert '*.sql' not in patterns and '**/*.sql' not in patterns
assert 'npm ci --no-fund' in workflow and 'npm run build' in workflow and 'npm run test:ui' in workflow
assert not any(pattern in ('assets/**','assets/dist/**','app.php','.htaccess') for pattern in patterns),'Runtime routing and compiled assets must ship'
print(f'Deployment safety checks passed: {len(required)} runtime exclusions, secrets, destination and serialized non-destructive FTP.')
