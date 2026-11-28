#!/usr/bin/env python3
"""Delete commands on synthetic SQLite only, no live network or MySQL."""
import os, pathlib, shutil, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[2]; php = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory() as temp:
    d = pathlib.Path(temp)
    for folder in ('website/includes/content', 'crm/includes'): (d/folder).mkdir(parents=True)
    for name in ('ContentCommandContract.php', 'ContentCommandReceiver.php', 'ContentLookupContract.php', 'ContentLookup.php'): shutil.copy(root/'website/includes/content'/name, d/'website/includes/content'/name)
    shutil.copy(root/'website/includes/ContentEditGuard.php', d/'website/includes/ContentEditGuard.php')
    for name in ('site_content_commands.php', 'site_content_lookup.php'): shutil.copy(root/'crm_derakht_sepid/includes'/name, d/'crm/includes'/name)
    for crm_name, site_name in (('site_content_command_contract.php','ContentCommandContract.php'), ('site_content_lookup_contract.php','ContentLookupContract.php')):
        assert (root/'crm_derakht_sepid/includes'/crm_name).read_bytes() == (d/'website/includes/content'/site_name).read_bytes()
        (d/'crm/includes'/crm_name).symlink_to(d/'website/includes/content'/site_name)
    env = os.environ.copy(); env['CONTENT_DELETE_FIXTURE_ROOT'] = str(d)
    for kind in ('service','team'):
        for case in ('enabled','child_off','parent_off'):
            r = subprocess.run([php, str(root/'crm_derakht_sepid/tests/site_content_delete_fixture.php'),kind,case],env=env,capture_output=True,text=True,check=True)
            assert not any(x in r.stdout+r.stderr for x in ('FAIL ', 'Fatal error', 'Warning:', 'Uncaught', 'Parse error')), r.stdout+r.stderr
            assert 'PASS' in r.stdout
            if case=='enabled':
                assert 'PASS reinsert after receipt rolls back deletion and receipt' in r.stdout, r.stdout
                assert 'PASS tampered delete receipt rolls back deletion' in r.stdout, r.stdout
                assert 'PASS retry returns historical receipt without deleting reinserted row' in r.stdout, r.stdout
            print(r.stdout,end='')
for kind in ('SERVICE','TEAM'):
    for file, func, parent, child in (
        (root/'website/includes/content/ContentCommandContract.php','content_command_kind_enabled',f'SITE_CONTENT_{kind}_EDIT_ENABLED',f'SITE_CONTENT_{kind}_DELETE_ENABLED'),
        (root/'website/includes/content/ContentLookupContract.php','content_lookup_kind_enabled',f'SITE_CONTENT_{kind}_LOOKUP_ENABLED',f'SITE_CONTENT_{kind}_DELETE_LOOKUP_ENABLED'),
    ):
        for declarations, expected in (('', '0'),(f"define('{parent}',true);",'0'),(f"define('{child}',true);",'0'),(f"define('{parent}',true);define('{child}',true);",'1')):
            result=subprocess.run([php,'-r',declarations+f"require {str(file)!r}; echo (int){func}('{kind.lower()}_delete');"],text=True,capture_output=True,check=True)
            assert result.stdout==expected and not result.stderr,(result.stdout,result.stderr)
print('PASS delete flags default off and require respective parent and child')
