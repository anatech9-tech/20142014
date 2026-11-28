#!/usr/bin/env python3
"""Inactive service/team creation on SQLite; no real HTTP, MySQL or production data."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[2]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as temp:
    d=pathlib.Path(temp)
    for folder in ('website/includes/content','crm/includes'): (d/folder).mkdir(parents=True)
    for name in ('ContentCommandContract.php','ContentCommandReceiver.php'): shutil.copy(root/'website/includes/content'/name,d/'website/includes/content'/name)
    shutil.copy(root/'website/includes/ContentEditGuard.php',d/'website/includes/ContentEditGuard.php')
    shutil.copy(root/'crm_derakht_sepid/includes/site_content_commands.php',d/'crm/includes/site_content_commands.php')
    assert (root/'crm_derakht_sepid/includes/site_content_command_contract.php').read_bytes()==(d/'website/includes/content/ContentCommandContract.php').read_bytes()
    (d/'crm/includes/site_content_command_contract.php').symlink_to(d/'website/includes/content/ContentCommandContract.php')
    env=os.environ.copy();env['CONTENT_CREATE_FIXTURE_ROOT']=str(d)
    for kind in ('service','team'):
        for case in ('enabled','disabled'):
            r=subprocess.run([php,str(root/'crm_derakht_sepid/tests/site_content_create_fixture.php'),kind,case],env=env,text=True,capture_output=True,check=True)
            assert not any(x in r.stdout+r.stderr for x in ('FAIL ','Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
            assert 'PASS' in r.stdout
            if case=='enabled':
                assert all('PASS '+item+' after receipt rolls back create' in r.stdout for item in ('changed_name','changed_null','changed_active','changed_order','deleted','receipt_id')),r.stdout
                assert 'PASS expired create settles without created ID or row' in r.stdout,r.stdout
            print(r.stdout,end='')
    contract=d/'website/includes/content/ContentCommandContract.php'
    for kind in ('service','team'):
        for setup,expected in (('', '0'),(f"define('SITE_CONTENT_{kind.upper()}_CREATE_ENABLED',true);",'1')):
            r=subprocess.run([php,'-r',setup+f"require {str(contract)!r}; echo (int)content_command_kind_enabled('{kind}_create');"],text=True,capture_output=True,check=True)
            assert r.stdout==expected and not r.stderr,(r.stdout,r.stderr)
    print('PASS independent creation flags default off')
