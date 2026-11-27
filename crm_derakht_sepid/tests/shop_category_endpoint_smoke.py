#!/usr/bin/env python3
"""Endpoint early rejection only; no network, MySQL or real request body."""
import os, pathlib, subprocess
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php'); endpoint=root/'api/shop_category_metadata_sync.php'
def run(prefix):
    r=subprocess.run([php,'-r',prefix+' require '+repr(str(endpoint))+';'],cwd=root,text=True,capture_output=True,check=True)
    assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr,r.stdout+r.stderr
    return r.stdout
assert run("$_SERVER['REQUEST_METHOD']='GET';")=='{"ok":false}'
assert run("$_SERVER['REQUEST_METHOD']='POST';")=='{"ok":false,"error":"disabled"}'
config="define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); $_SERVER['REQUEST_METHOD']='POST';"
assert run(config)=='{"ok":false}'
print('PASS category endpoint rejects method, disabled state and missing authentication before database access')
