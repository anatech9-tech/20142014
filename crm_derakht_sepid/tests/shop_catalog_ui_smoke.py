#!/usr/bin/env python3
"""Render real read-only catalogue with SQLite and stubbed CRM shell; not a browser test."""
import os,pathlib,shutil,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_catalog.php',app/'pages/shop_catalog.php')
    shutil.copy(root/'includes/shop_catalog_sync.php',app/'includes/shop_catalog_sync.php')
    shutil.copy(root/'includes/shop_product_mapping.php',app/'includes/shop_product_mapping.php')
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return true;} function is_nazer_kol(){return false;}
function access_is_special_account(){return ($GLOBALS['case'] ?? '')!=='readonly';} function access_mode($k){return 'viewer';}
function get_csrf_field(){return '<input name="csrf_token" value="test">';}
$_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET'; $case=$argv[1] ?? '';
if ($case==='filter') $_GET=['q'=>'special','active'=>'inactive'];
if (in_array($case,['mapping','readonly'])) $_GET=['map_id'=>31,'product_search'=>'Target'];
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)');
$pdo->exec('CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT,specs TEXT,supplier TEXT)');
$pdo->exec('CREATE TABLE crm_shop_product_links(source_id TEXT,shop_product_id INTEGER,crm_product_id INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT,PRIMARY KEY(source_id,shop_product_id))');
$pdo->exec('CREATE TABLE crm_shop_product_link_events(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,replica_id INTEGER,actor_id INTEGER,old_crm_product_id INTEGER,new_crm_product_id INTEGER,created_at TEXT)');
$pdo->exec("INSERT INTO crm_products VALUES(1,'Target Product','', 'Vendor')");
$pdo->exec('CREATE TABLE crm_shop_catalog(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,name_fa TEXT,sku TEXT,is_active INTEGER,payload TEXT,received_at TEXT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute(['active',$case==='viewer'?'user':'admin']);
$st=$pdo->prepare('INSERT INTO crm_shop_catalog VALUES(?,?,?,?,?,?,?,?,?,?,?)');
for ($i=1;$i<=31;$i++) {
 $p=['id'=>$i,'name_fa'=>$i===31?'<script>':'Item '.$i,'sku'=>$i===31?'special':'sku'.$i,'brand'=>'Brand','category_name'=>'Test','currency'=>'toman','price_toman'=>100,'stock'=>3];
 $st->execute([$i,str_repeat('a',32),$i,$i,str_repeat('b',32),'', $p['name_fa'],$p['sku'],$i===31?0:1,json_encode($p),'2025-01-01 12:00:00']);
}
include __DIR__.'/pages/shop_catalog.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    html=run('all')
    assert '&lt;script&gt;' in html and '<script>' not in html and 'بعدی' in html and '۳۱' not in html # count uses Latin digits
    assert '31 کالا' in html and 'با تأخیر' in html and 'حذف کالا' in html
    filtered=run('filter'); assert 'special' in filtered and 'Item 30' not in filtered and 'غیرفعال' in filtered
    denied=run('viewer'); assert 'دسترسی مجاز نیست' in denied and 'special' not in denied
    mapping=run('mapping'); assert 'تأیید اتصال</button>' in mapping and 'Target Product' in mapping and '&lt;script&gt;' in mapping
    readonly=run('readonly'); assert 'تأیید اتصال</button>' not in readonly and 'Target Product' not in readonly
    print('PASS catalogue page access, escaping, pagination, filtering and mapping controls')
