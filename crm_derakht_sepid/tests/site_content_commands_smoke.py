#!/usr/bin/env python3
"""Combined SQLite test plus disabled endpoint checks, no live HTTP or MySQL."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[2]; php=os.environ.get('PHP_BIN','php')
contract=root/'website/includes/content/ContentCommandContract.php'; assert contract.read_bytes()==(root/'crm_derakht_sepid/includes/site_content_command_contract.php').read_bytes()
with tempfile.TemporaryDirectory() as tmp:
    tmp=pathlib.Path(tmp)
    for d in ('website/includes/content','crm/includes'): (tmp/d).mkdir(parents=True)
    for f in ('ContentCommandContract.php','ContentCommandReceiver.php'): shutil.copy(root/'website/includes/content'/f,tmp/'website/includes/content'/f)
    shutil.copy(root/'website/includes/ContentEditGuard.php',tmp/'website/includes/ContentEditGuard.php')
    shutil.copy(root/'crm_derakht_sepid/includes/site_content_commands.php',tmp/'crm/includes/site_content_commands.php')
    (tmp/'crm/includes/site_content_command_contract.php').symlink_to(tmp/'website/includes/content/ContentCommandContract.php')
    env=os.environ.copy(); env['CONTENT_FIXTURE_ROOT']=str(tmp)
    fixture=root/'crm_derakht_sepid/tests/site_content_command_fixture.php'
    for case in ('enabled','blocked'):
        r=subprocess.run([php,str(fixture),case],env=env,text=True,capture_output=True,check=True)
        assert not any(x in r.stdout+r.stderr for x in ('FAIL ', 'Fatal error','Warning:','Uncaught','Parse error')), r.stdout+r.stderr
        print(r.stdout,end='')
endpoint=root/'website/api/crm_content_command.php'
def early(prefix):
    r=subprocess.run([php,'-r',prefix+' require '+repr(str(endpoint))+';'],capture_output=True,text=True,check=True)
    assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr,r.stdout+r.stderr
    return r.stdout
assert early("$_SERVER['REQUEST_METHOD']='GET';")=='{"ok":false}'
assert early("$_SERVER['REQUEST_METHOD']='POST';")=='{"ok":false,"error":"disabled"}'
prefix="define('SITE_CONTENT_COMMAND_ENABLED',true); define('SITE_CONTENT_COMMAND_KEY',str_repeat('b',32)); define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32)); $_SERVER['REQUEST_METHOD']='POST';"
assert early(prefix)=='{"ok":false}'
def config_result(prefix):
    code=prefix+' require '+repr(str(contract))+'; echo (int)content_command_configured(false).(int)content_command_configured(true).(int)content_command_kind_enabled(\"service\").(int)content_command_kind_enabled(\"team\");'
    r=subprocess.run([php,'-r',code],capture_output=True,text=True,check=True)
    assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Parse error','Uncaught')),r.stdout+r.stderr
    return r.stdout
base="define('SITE_CONTENT_COMMAND_ENABLED',true);define('SITE_CONTENT_COMMAND_KEY',str_repeat('b',32));define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32));"
assert config_result('')=='0000'
assert config_result(base+"define('SITE_CONTENT_COMMAND_URL','http://example.test');define('SITE_CONTENT_SERVICE_EDIT_ENABLED',true);")=='1010'
assert config_result(base+"define('SITE_CONTENT_COMMAND_URL','https://example.test');define('SITE_BLOG_META_KEY',str_repeat('b',32));")=='0000'
assert config_result(base+"define('SITE_CONTENT_COMMAND_URL','https://example.test');define('SITE_CONTENT_TEAM_EDIT_ENABLED',true);")=='1101'
print('PASS identical contracts, separate opt-ins and key/HTTPS restrictions; endpoint rejects method, disabled state and unauthenticated requests')
