#!/usr/bin/env python3
"""Queue control on simulated SQLite locks; not MySQL concurrency or real network."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[2]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    d=pathlib.Path(temp); (d/'crm/includes').mkdir(parents=True)
    for name in ('site_content_commands.php','site_content_command_contract.php'): shutil.copy(root/'crm_derakht_sepid/includes'/name,d/'crm/includes'/name)
    env=os.environ.copy();env['CONTROL_FIXTURE_ROOT']=str(d)
    for mode in ('service','team_create'):
        for case in ('enabled','disabled'):
            r=subprocess.run([php,str(root/'crm_derakht_sepid/tests/site_content_queue_control_fixture.php'),mode,case],env=env,text=True,capture_output=True,check=True)
            assert not any(x in r.stdout+r.stderr for x in ('FAIL ','Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
            assert 'PASS' in r.stdout; print(r.stdout,end='')
cli=(root/'crm_derakht_sepid/cli/site_content_commands.php').read_text(); assert 'crm_content_command_lock($pdo)' in cli and 'crm_content_command_unlock($pdo)' in cli and 'stopped=' in cli
print('PASS queue controls use the same worker lock and report stopped count')
