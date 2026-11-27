#!/usr/bin/env python3
"""Exercise receiver early rejection without a web server, MySQL or real HTTP body."""
import os, pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
endpoint=root/'api/site_shipment_metadata_sync.php'
def run(prefix):
    code=prefix+" require "+repr(str(endpoint))+";"
    result=subprocess.run([php,'-r',code],cwd=root,text=True,capture_output=True,check=True)
    assert 'Fatal error' not in result.stdout+result.stderr and 'Warning:' not in result.stdout+result.stderr,result.stdout+result.stderr
    return result.stdout
assert run("$_SERVER['REQUEST_METHOD']='GET';")=='{"ok":false}'
assert run("$_SERVER['REQUEST_METHOD']='POST';")=='{"ok":false,\"error\":\"disabled\"}'
config="define('SITE_SHIPMENT_META_ENABLED',true); define('SITE_SHIPMENT_META_KEY',str_repeat('s',32)); define('SITE_SHIPMENT_SOURCE_ID',str_repeat('a',32)); $_SERVER['REQUEST_METHOD']='POST';"
assert run(config)=='{"ok":false}'
print('PASS shipment endpoint rejects wrong method, disabled bridge and missing authentication before database access')
