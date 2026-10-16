"""Reproducible non-production regression runner. Artifacts stay in scratch/."""
import argparse,json,os,subprocess,time
from pathlib import Path
root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--upgrade-source',type=Path);args=parser.parse_args()
out=root/'scratch/ui-migration';out.mkdir(parents=True,exist_ok=True)
commands=[]
php=os.environ.get('ERP_TEST_PHP','php')
php_files=sorted(p for p in root.rglob('*.php') if not {'node_modules','.git','scratch','.superpowers'} & set(p.relative_to(root).parts))
commands.extend(('lint '+str(p.relative_to(root)),[php,'-l',str(p)]) for p in php_files)
groups={'PHP':sorted(root.glob('tests/*_test.php')),'JavaScript':sorted(root.glob('tests/*_test.js')),'HTTP':sorted(root.glob('tests/*_http_test.py'))}
for kind,files in groups.items():
    for p in files:
        if p.name=='upgrade_test.php':continue
        runtime=php if kind=='PHP' else 'node' if kind=='JavaScript' else os.sys.executable
        commands.append((str(p.relative_to(root)),[runtime,str(p)]))
commands.extend([('native UI tests',['node','--test','tests/ui_components_test.mjs','tests/dynamic_workspace_test.mjs']),('legacy HTTP',[os.sys.executable,'tests/http_test.py']),('deployment safety',[os.sys.executable,'tests/deployment_test.py']),('correction concurrency',[os.sys.executable,'tests/question_correction_concurrency_test.py'])])
if args.upgrade_source:commands.append(('consistent-copy upgrade',[os.sys.executable,'tests/upgrade_copy_test.py','--source',str(args.upgrade_source.resolve())]))
failures=[];started=time.time()
with (out/'verification.log').open('w',encoding='utf-8') as log:
    for index,(name,command) in enumerate(commands,1):
        result=subprocess.run(command,cwd=root,env=os.environ.copy(),capture_output=True,text=True,encoding='utf-8',errors='replace')
        log.write(f'\n[{index}/{len(commands)}] {name} EXIT {result.returncode}\n'+result.stdout+result.stderr);log.flush()
        if result.returncode:failures.append(name)
        if not name.startswith('lint ') or result.returncode:print(f'{index}/{len(commands)} {name}: '+('FAIL' if result.returncode else 'PASS'),flush=True)
summary={'php_lint':len(php_files),'commands':len(commands),'failures':failures,'seconds':round(time.time()-started,1)}
(out/'verification.json').write_text(json.dumps(summary,indent=2),encoding='utf-8')
print(json.dumps(summary),flush=True)
raise SystemExit(bool(failures))
