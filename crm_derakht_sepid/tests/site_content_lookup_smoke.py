#!/usr/bin/env python3
"""Combined read-only fixture plus early endpoint/config rejection tests."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[2]; php=os.environ.get('PHP_BIN','php')
website=root/'website/includes/content'; crm=root/'crm_derakht_sepid/includes'
assert (website/'ContentLookupContract.php').read_bytes()==(crm/'site_content_lookup_contract.php').read_bytes()
assert (website/'ContentCommandContract.php').read_bytes()==(crm/'site_content_command_contract.php').read_bytes()
with tempfile.TemporaryDirectory() as td:
    d=pathlib.Path(td)
    for sub in ('website/includes/content','crm/includes'): (d/sub).mkdir(parents=True)
    for f in ('ContentLookupContract.php','ContentLookup.php','ContentCommandContract.php','ContentCommandReceiver.php'): shutil.copy(website/f,d/'website/includes/content'/f)
    shutil.copy(root/'website/includes/ContentEditGuard.php',d/'website/includes/ContentEditGuard.php')
    for f in ('site_content_lookup.php','site_content_commands.php'): shutil.copy(crm/f,d/'crm/includes'/f)
    (d/'crm/includes/site_content_lookup_contract.php').symlink_to(d/'website/includes/content/ContentLookupContract.php')
    (d/'crm/includes/site_content_command_contract.php').symlink_to(d/'website/includes/content/ContentCommandContract.php')
    env=os.environ.copy();env['LOOKUP_FIXTURE_ROOT']=str(d)
    r=subprocess.run([php,str(root/'crm_derakht_sepid/tests/site_content_lookup_fixture.php')],env=env,capture_output=True,text=True,check=True)
    assert not any(x in r.stdout+r.stderr for x in ('FAIL ', 'Fatal error','Warning:','Uncaught','Parse error')),r.stdout+r.stderr
    print(r.stdout,end='')
endpoint=root/'website/api/crm_content_lookup.php'
def run(prefix,path=endpoint,suffix=''):
    r=subprocess.run([php,'-r',prefix+' require '+repr(str(path))+';'+suffix],capture_output=True,text=True,check=True)
    assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Parse error','Uncaught')),r.stdout+r.stderr
    return r.stdout
assert run("$_SERVER['REQUEST_METHOD']='GET';")=='{"ok":false}'
assert run("$_SERVER['REQUEST_METHOD']='POST';")=='{"ok":false,"error":"disabled"}'
base="define('SITE_CONTENT_LOOKUP_ENABLED',true); define('SITE_CONTENT_LOOKUP_KEY',str_repeat('l',32)); define('SITE_CONTENT_LOOKUP_SOURCE_ID',str_repeat('a',32)); "
assert run(base+"$_SERVER['REQUEST_METHOD']='POST';")=='{"ok":false}'
contract=website/'ContentLookupContract.php'
assert run('',contract,' echo (int)content_lookup_configured(false).(int)content_lookup_configured(true);')=='00'
assert run(base+"define('SITE_CONTENT_LOOKUP_URL','http://example.test');",contract,' echo (int)content_lookup_configured(false).(int)content_lookup_configured(true);')=='10'
assert run(base+"define('SITE_CONTENT_LOOKUP_URL','https://example.test'); define('SITE_CONTENT_COMMAND_KEY',str_repeat('l',32));",contract,' echo (int)content_lookup_configured(false).(int)content_lookup_configured(true);')=='00'
assert run(base+"define('SITE_CONTENT_LOOKUP_URL','https://user:pass@example.test/api');",contract,' echo (int)content_lookup_configured(true);')=='0'
command=crm/'site_content_commands.php'
lookup_contract=crm/'site_content_lookup_contract.php'
def pair_config(extra):
    setup=base+"define('SITE_CONTENT_COMMAND_ENABLED',true);define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32));define('SITE_CONTENT_COMMAND_URL','https://example.test/api/command');"+extra
    r=subprocess.run([php,'-r',setup+' require '+repr(str(command))+'; require '+repr(str(lookup_contract))+'; echo (int)content_lookup_matches_command();'],capture_output=True,text=True,check=True)
    assert not any(x in r.stdout+r.stderr for x in ('Fatal error','Warning:','Parse error','Uncaught')),r.stdout+r.stderr
    return r.stdout
assert pair_config("define('SITE_CONTENT_LOOKUP_URL','https://example.test/api/lookup');")=='1'
assert pair_config("define('SITE_CONTENT_LOOKUP_URL','https://other.test/api/lookup');")=='0'
assert pair_config("define('SITE_CONTENT_LOOKUP_URL','https://example.test:444/api/lookup');")=='0'
mismatch=base.replace("str_repeat('a',32)","str_repeat('b',32)")+"define('SITE_CONTENT_COMMAND_ENABLED',true);define('SITE_CONTENT_COMMAND_SOURCE_ID',str_repeat('a',32));define('SITE_CONTENT_COMMAND_KEY',str_repeat('c',32));define('SITE_CONTENT_COMMAND_URL','https://example.test/api/command');define('SITE_CONTENT_LOOKUP_URL','https://example.test/api/lookup');"
r=subprocess.run([php,'-r',mismatch+' require '+repr(str(command))+'; require '+repr(str(lookup_contract))+'; echo (int)content_lookup_matches_command();'],capture_output=True,text=True,check=True); assert r.stdout=='0' and 'Fatal error' not in r.stdout+r.stderr

print('PASS contracts agree, lookup endpoint closed by default, key reuse, wrong origin, unauthenticated requests and HTTP refused')
