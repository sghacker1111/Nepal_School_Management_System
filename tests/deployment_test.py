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
    '**/*.sql.gz','**/school_erp_mysql*.sql*','**/migration-manifest*.json',
    '**/source-manifest*.json','**/projected-manifest*.json','**/migration-report*.md',
    '**/*migration-report*.json','**/verification-error.json','**/statements.jsonl',
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
assert 'server-dir: ${{ secrets.FTP_SERVER_DIR }}' in workflow, 'Deploy to the explicitly configured new website directory'
assert 'FTP_SERVER_DIR: ${{ secrets.FTP_SERVER_DIR }}' in workflow, 'Validate the same directory that FTP uses'
assert 'school.my-board.org/htdocs/' not in workflow, 'Do not deploy the new system to the old website'
assert 'branches:\n      - main' in workflow, 'Only main should deploy to production'
assert 'workflow_dispatch:' in workflow and 'default: false' in workflow, 'A manual deployment must be an explicit choice'
assert "vars.FTP_DEPLOY_ENABLED == 'true'" in workflow, 'New repository pushes must not deploy before hosting is configured'
assert "github.ref == 'refs/heads/main'" in workflow, 'Manual branch runs must not deploy feature branches'
assert 'needs: validate' in workflow, 'Production must wait for successful validation'
assert 'environment: infinityfree-production' in workflow, 'Hosting secrets belong to the production environment/repository'
assert 'protocol: ftps' in workflow and 'port: 21' in workflow, 'Use explicit TLS for InfinityFree FTP'
assert 'for setting in FTP_SERVER FTP_USERNAME FTP_PASSWORD FTP_SERVER_DIR' in workflow, 'Missing credentials must fail before FTP writes'
assert '^/?([A-Za-z0-9_-][A-Za-z0-9._-]*/)*htdocs/$' in workflow, 'Reject root, traversal, URLs and non-htdocs destinations'
for secret in ('FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD', 'FTP_SERVER_DIR'):
    assert '${{ secrets.' + secret + ' }}' in workflow, f'Use the configured {secret} secret'
# Keep source migrations available; the real database itself must remain excluded.
assert '*.sql' not in patterns and '**/*.sql' not in patterns
assert '**/*.sql.gz' in patterns and '**/school_erp_mysql*.sql*' in patterns
assert '**/migration-manifest*.json' in patterns and '**/projected-manifest*.json' in patterns and '**/statements.jsonl' in patterns
assert 'npm ci --no-fund' in workflow and 'npm run build' in workflow and 'npm run test:ui' in workflow
assert not any(pattern in ('assets/**','assets/dist/**','app.php','.htaccess') for pattern in patterns),'Runtime routing and compiled assets must ship'
htaccess=(Path(__file__).resolve().parents[1]/'.htaccess').read_text(encoding='utf-8')
router=(Path(__file__).resolve().parents[1]/'router.php').read_text(encoding='utf-8')
assert 'sql(\\.gz)?' in htaccess and 'private-migration' in htaccess and 'statements\\.jsonl' in htaccess
assert 'sql(?:\\.gz)?' in router and '(?:migration|source|projected)-manifest' in router and 'statements\\.jsonl' in router and 'private-migration' in router
assert not (Path(__file__).resolve().parents[1]/'database_sqlite.sql').exists()
assert not (Path(__file__).resolve().parents[1]/'database.sql').exists()
assert (Path(__file__).resolve().parents[1]/'tests/fixtures/database_sqlite.sql').is_file()
assert (Path(__file__).resolve().parents[1]/'tests/fixtures/legacy_mysql.sql').is_file()
print(f'Deployment safety checks passed: {len(required)} runtime exclusions, secrets, destination and serialized non-destructive FTP.')
