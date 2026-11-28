#!/usr/bin/env python3
"""Authorization and queue scheduling with SQLite; no real concurrency or transport."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as d:
    for name in ('site_content_commands.php','site_content_command_contract.php'): shutil.copy(root/'includes'/name,d)
    env=os.environ.copy();env['WORKER_FIXTURE_ROOT']=d
    for kind in ('service','team'):
        r=subprocess.run([php,str(root/'tests/site_content_worker_access_fixture.php'),kind],env=env,capture_output=True,text=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('FAIL ','Fatal error','Warning:','Uncaught','Parse error')) and r.stdout.count('PASS ')>=13,r.stdout+r.stderr
        print(kind, r.stdout,end='')
