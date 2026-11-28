#!/usr/bin/env python3
"""SQLite page/POST fixtures only; no HTTP, real browser, MySQL or concurrency."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/crm_product_groups.php',app/'pages/crm_product_groups.php')
    for name in ('crm_product_groups.php','shop_category_groups.php','shop_category_metadata_sync.php','shop_category_metadata_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
function is_nazer_kol(){return str_contains($GLOBALS['case'],'readonly');} function access_is_special_account(){return true;} function access_mode($x){return 'viewer';}
function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function operations_audit(...$x){} function redirect($s){exit;}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
class ProductGroupUIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$pdo=new ProductGroupUIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='no_product') { $pdo->exec('CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT)'); $pdo->prepare('INSERT INTO crm_products VALUES(1,?)')->execute([$case==='renamed'?'changed':'<script>product</script>']); }
if ($case!=='no_group') { $pdo->exec('CREATE TABLE crm_shop_category_groups(id INTEGER PRIMARY KEY,name TEXT,version INTEGER,is_active INTEGER)'); $pdo->prepare('INSERT INTO crm_shop_category_groups VALUES(1,?,?,?)')->execute(['<script>group</script>',$case==='group_stale'?2:1,$case==='group_inactive'?0:1]); }
$pdo->exec('CREATE TABLE crm_product_group_links(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,group_id INTEGER,is_linked INTEGER,version INTEGER,group_version INTEGER,product_name TEXT,updated_by INTEGER,updated_at TEXT,UNIQUE(product_id,group_id))');
$pdo->exec('CREATE TABLE crm_product_group_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,product_id INTEGER,group_id INTEGER,version INTEGER,group_version INTEGER,action TEXT,actor_id INTEGER,created_at TEXT,UNIQUE(link_id,version))');
if (!str_starts_with($case,'post_link')) $pdo->prepare('INSERT INTO crm_product_group_links VALUES(1,1,1,1,1,1,?,1,?)')->execute(['<script>product</script>','2025-01-01']);
if (in_array($case,['selected','readonly','renamed','group_stale','group_inactive'],true)) $_GET=['product'=>'1','group'=>'1'];
if ($case==='search') $_GET=['q'=>'product'];
if ($case==='filter') { $pdo->exec("INSERT INTO crm_shop_category_groups VALUES(2,'other',1,1); INSERT INTO crm_product_group_links VALUES(2,1,2,1,1,1,'other',1,'2025-01-01')"); $_GET=['filter_group'=>'1']; }
if (str_starts_with($case,'post_')) {
 $_SERVER['REQUEST_METHOD']='POST';
 if (str_starts_with($case,'post_link')) { $_POST=['action'=>'link','product_id'=>'1','product_name'=>$case==='post_link_stale'?'old':'<script>product</script>','group_id'=>'1','group_version'=>'1','version'=>'0','checked_product'=>'1']; if ($case!=='post_link_one') $_POST['checked_group']='1'; }
 else $_POST=['action'=>'unlink','product_id'=>'0','link_id'=>'1','version'=>'1','confirmed'=>'1'];
 register_shutdown_function(function() use ($pdo) { echo 'RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_product_group_events')->fetchColumn().':'.$pdo->query('SELECT COALESCE((SELECT is_linked FROM crm_product_group_links WHERE id=1),-1)')->fetchColumn(); });
}
include __DIR__.'/pages/crm_product_groups.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    page=run('selected'); assert '&lt;script&gt;product&lt;/script&gt;' in page and '&lt;script&gt;group&lt;/script&gt;' in page and '<script>' not in page
    assert 'name="csrf_token"' in page and 'name="checked_product"' in page and 'name="checked_group"' in page and 'نام و نسخه گروه بدون تغییر' in page
    for case in ('renamed','group_stale','group_inactive','no_product','no_group'): assert 'نیازمند بررسی مجدد' in run(case)
    assert 'بانک کالا یا گروه آماده نیست' in run('no_product') and 'بانک کالا یا گروه آماده نیست' in run('no_group')
    assert '<form method="post"' not in run('readonly') and 'product' in run('search')
    filtered=run('filter'); assert 'فیلتر گروه #1' in filtered and 'گروه #1:' in filtered and 'گروه #2:' not in filtered
    assert 'RESULT:1:1' in run('post_link') and 'RESULT:1:0' in run('post_unlink')
    for case in ('post_link_one','post_link_stale','post_link_readonly'): assert 'RESULT:0:-1' in run(case)
    for case in ('viewer','inactive','forged'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<form' not in denied
    index=(root/'index.php').read_text(); assert "case 'crm_product_groups'" in index and "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    assert "'crm_product_groups' => 'crm_customers'" in (root/'config.php').read_text()
    print('PASS product grouping UI permissions, escaping, stale warnings, confirmation, readonly and POST fixtures')
