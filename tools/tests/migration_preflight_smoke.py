#!/usr/bin/env python3
"""Check preflight specs against committed schema declarations and test failure redaction."""
import json, os, pathlib, re, subprocess
root=pathlib.Path(__file__).resolve().parents[2]
php=os.environ.get('PHP_BIN','php')
script=root/'tools/migration_preflight.php'
for component in ('site','crm'):
    code=f"define('MIGRATION_PREFLIGHT_TESTING',true); require '{script}'; echo json_encode(preflight_specs('{component}'));"
    res=subprocess.run([php,'-r',code],cwd=root,capture_output=True,text=True,check=True)
    specs=json.loads(res.stdout)
    sources=[root/'website/SHOP-SCHEMA.sql',root/'website/DATABASE-SCHEMA.sql'] if component=='site' else [root/'crm_derakht_sepid/install_db.php']
    sources+=list((root/('website/includes' if component=='site' else 'crm_derakht_sepid/includes')).rglob('*.php'))
    alltext='\n'.join(p.read_text() for p in sources)
    for group in specs.values():
        for table,columns in group['tables'].items():
            # Restrict to the table declaration, not the entire file. CRM installer may use quoted identifiers.
            pattern=r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?'+re.escape(table)+r'`?\s*\((.*?)(?:ENGINE\s*=\s*InnoDB|\)\s*\$tail)'
            matches=list(re.finditer(pattern,alltext,re.I|re.S))
            assert matches, f'{component}: table not found: {table}'
            declaration=matches[0].group(1)
            for column in columns:
                assert re.search(r'\b'+re.escape(column)+r'\b',declaration,re.I),f'{component}: {table}.{column} missing from declared schema'
    print('PASS',component,'preflight names match schema declarations')
result=subprocess.run([php,str(root/'tools/tests/migration_preflight_smoke.php')],cwd=root,capture_output=True,text=True,check=True)
assert 'FAIL ' not in result.stdout+result.stderr and 'Fatal error' not in result.stdout+result.stderr,result.stdout+result.stderr
print(result.stdout,end='')
env=os.environ.copy(); env.update(MIGRATION_DB_HOST='remote.example',MIGRATION_DB_NAME='valid',MIGRATION_DB_USER='canary-user',MIGRATION_DB_PASS='canary-secret')
r=subprocess.run([php,str(script),'crm'],cwd=root,env=env,capture_output=True,text=True)
assert 'canary-user' not in r.stdout+r.stderr and 'canary-secret' not in r.stdout+r.stderr and 'remote.example' not in r.stdout+r.stderr
assert 'Missing/invalid local-only database settings' in r.stderr
r=subprocess.run([php,str(script),'bogus'],cwd=root,env=env,capture_output=True,text=True)
assert 'Usage:' in r.stderr and 'canary-secret' not in r.stdout+r.stderr
env['MIGRATION_DB_HOST']='localhost'
code=f"define('MIGRATION_PREFLIGHT_TESTING',true); require '{script}'; preflight_main(['tool','site'],function(){{throw new Exception('canary-secret canary-user');}});"
r=subprocess.run([php,'-r',code],cwd=root,env=env,capture_output=True,text=True)
assert 'canary-user' not in r.stdout+r.stderr and 'canary-secret' not in r.stdout+r.stderr
assert 'PDO MySQL extension is missing' in r.stderr or 'Metadata inspection failed' in r.stderr
print('PASS invalid host/component and connection failures do not disclose credentials')
