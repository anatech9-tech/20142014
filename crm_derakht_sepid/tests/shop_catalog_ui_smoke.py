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
    shutil.copy(root/'includes/shop_product_commands.php',app/'includes/shop_product_commands.php')
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return true;} function is_nazer_kol(){return false;}
function access_is_special_account(){return !in_array(($GLOBALS['case'] ?? ''),['readonly','hide_readonly','hide_post_readonly'],true);} function access_mode($k){return 'viewer';}
function get_csrf_field(){return '<input name="csrf_token" value="test">';} function showToast($x,$y=''){} function redirect($x){exit;} function operations_audit(...$x){}
$_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET'; $case=$argv[1] ?? '';
if ($case==='filter') $_GET=['q'=>'special','active'=>'inactive'];
if (in_array($case,['category','category1','category2','malformed','badparam'])) $_GET=['category_source'=>str_repeat('a',32),'category_id'=>$case==='badparam'?'1 OR 1=1':'1','p'=>$case==='category2'?2:1];
if (in_array($case,['mapping','readonly'])) $_GET=['map_id'=>31,'product_search'=>'Target'];
if (str_starts_with($case,'hide_')) $_GET=['map_id'=>30];
if (in_array($case,['hide_enabled','hide_nomapping','hide_queued','hide_readonly','hide_post','hide_post_no_ack','hide_post_readonly','hide_viewer'],true)) { define('SHOP_PRODUCT_COMMAND_ENABLED',true); define('SHOP_PRODUCT_HIDE_ENABLED',true); define('SHOP_PRODUCT_COMMAND_KEY',str_repeat('p',32)); define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_PRODUCT_COMMAND_URL','https://example.test/cmd'); }
class UITestPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$pdo=new UITestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)');
$pdo->exec('CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT,specs TEXT,supplier TEXT)');
$pdo->exec('CREATE TABLE crm_shop_product_links(source_id TEXT,shop_product_id INTEGER,crm_product_id INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT,PRIMARY KEY(source_id,shop_product_id))');
$pdo->exec('CREATE TABLE crm_shop_product_link_events(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,replica_id INTEGER,actor_id INTEGER,old_crm_product_id INTEGER,new_crm_product_id INTEGER,created_at TEXT)');
$pdo->exec("INSERT INTO crm_products VALUES(1,'Target Product','', 'Vendor')");
$pdo->exec('CREATE TABLE crm_shop_catalog(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,name_fa TEXT,sku TEXT,is_active INTEGER,payload TEXT,received_at TEXT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute(['active',in_array($case,['viewer','hide_viewer'],true)?'user':'admin']);
$st=$pdo->prepare('INSERT INTO crm_shop_catalog VALUES(?,?,?,?,?,?,?,?,?,?,?)');
for ($i=1;$i<=31;$i++) {
 $p=['id'=>$i,'name_fa'=>$i===31?'<script>':'Item '.$i,'name_en'=>'Item','slug'=>'item-'.$i,'sku'=>$i===31?'special':'sku'.$i,'brand'=>'Brand','category_id'=>1,'category_name'=>'<Test>','currency'=>'toman','price_toman'=>100,'price_usd'=>null,'stock'=>3,'is_active'=>$i===31?0:1];
 $st->execute([$i,str_repeat('a',32),$i,$i,str_repeat('b',32),'', $p['name_fa'],$p['sku'],$i===31?0:1,json_encode($p),'2025-01-01 12:00:00']);
}
$pdo->exec('CREATE TABLE crm_shop_product_commands(command_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,product_id INTEGER,replica_revision INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_shop_product_command_events(id INTEGER PRIMARY KEY,command_id TEXT,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)');
if ($case==='hide_nomapping') $pdo->exec('DROP TABLE crm_products');
if ($case==='hide_queued') $pdo->prepare("INSERT INTO crm_shop_product_commands VALUES(?,30,?,30,30,1,'{}','queued',0,'2025-01-01','2025-01-01',NULL)")->execute([str_repeat('b',32),str_repeat('a',32)]);
if (in_array($case,['hide_post','hide_post_no_ack','hide_post_readonly'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['action'=>'hide_product','replica_id'=>'30','revision'=>'30']; if ($case!=='hide_post_no_ack') $_POST['confirmed_hide']='1';
 register_shutdown_function(function() use ($pdo) { echo 'HIDE_RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_product_commands')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_shop_product_command_events')->fetchColumn(); });
}
if ($case==='malformed') { $pdo->exec("UPDATE crm_shop_catalog SET payload='not json' WHERE id=1"); $pdo->prepare('UPDATE crm_shop_catalog SET payload=? WHERE id=2')->execute([json_encode(['category_id'=>'bad'])]); }
if ($case==='category') $pdo->exec("UPDATE crm_shop_catalog SET source_id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' WHERE id=31");
include __DIR__.'/pages/shop_catalog.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Parse error' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    html=run('all')
    assert '&lt;script&gt;' in html and '<script>' not in html and 'بعدی' in html and '۳۱' not in html # count uses Latin digits
    assert '31 کالا' in html and 'با تأخیر' in html and 'حذف کالا' in html
    filtered=run('filter'); assert 'special' in filtered and 'Item 30' not in filtered and 'غیرفعال' in filtered
    category=run('category'); assert '30 کالا' in category and 'special' not in category and '&lt;Test&gt;' in category and 'category_source' in category
    first=run('category1'); second=run('category2'); assert '31 کالا' in first and 'بعدی' in first and 'category_id=1' in first and 'Item 1' in second and 'قبلی' in second
    malformed=run('malformed'); assert '29 کالا' in malformed
    invalid=run('badparam'); assert '31 کالا' in invalid and 'فیلتر دستهٔ فروشگاه' not in invalid
    denied=run('viewer'); assert 'دسترسی مجاز نیست' in denied and 'special' not in denied
    mapping=run('mapping'); assert 'تأیید اتصال</button>' in mapping and 'Target Product' in mapping and '&lt;script&gt;' in mapping
    readonly=run('readonly'); assert 'تأیید اتصال</button>' not in readonly and 'Target Product' not in readonly
    assert 'ثبت فرمان مخفی‌کردن کالا</button>' not in run('hide_disabled')
    enabled=run('hide_enabled'); assert 'ثبت فرمان مخفی‌کردن کالا</button>' in enabled and 'name="confirmed_hide"' in enabled and 'csrf_token' in enabled
    no_mapping=run('hide_nomapping'); assert 'ثبت فرمان مخفی‌کردن کالا</button>' in no_mapping and 'بانک کالا یا جدول اتصال آماده نیست' in no_mapping
    queued=run('hide_queued'); assert 'در صف؛ نتیجه نامشخص' in queued and 'ثبت فرمان مخفی‌کردن کالا</button>' not in queued
    assert 'ثبت فرمان مخفی‌کردن کالا</button>' not in run('hide_readonly')
    assert 'دسترسی مجاز نیست' in run('hide_viewer')
    assert 'HIDE_RESULT:1:1' in run('hide_post') and 'HIDE_RESULT:0:0' in run('hide_post_no_ack') and 'HIDE_RESULT:0:0' in run('hide_post_readonly')
    print('PASS catalogue access, filtering, mapping and opt-in product hide UI')
