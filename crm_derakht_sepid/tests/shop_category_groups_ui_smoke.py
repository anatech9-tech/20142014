#!/usr/bin/env python3
"""Simulated rendering and POSTs, not a browser, HTTP/CSRF or MySQL test."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_category_groups.php',app/'pages/shop_category_groups.php')
    for name in ('shop_category_groups.php','shop_category_metadata_sync.php','shop_category_metadata_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
function is_nazer_kol(){return str_contains($GLOBALS['case'],'readonly');} function access_is_special_account(){return true;} function access_mode($x){return 'viewer';}
function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function operations_audit(...$x){} function redirect($s){exit;}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
if (!in_array($case,['disabled','post_unlink_disabled'],true)) { define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); }
class GroupUIPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$pdo=new GroupUIPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
$pdo->exec('CREATE TABLE crm_shop_category_groups(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE,is_active INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT)');
$pdo->exec('CREATE TABLE crm_shop_category_group_events(id INTEGER PRIMARY KEY AUTOINCREMENT,group_id INTEGER,version INTEGER,old_name TEXT,new_name TEXT,old_active INTEGER,new_active INTEGER,actor_id INTEGER,created_at TEXT,UNIQUE(group_id,version))');
$pdo->exec('CREATE TABLE crm_shop_category_group_links(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,category_id INTEGER,replica_id INTEGER,group_id INTEGER,version INTEGER,source_revision INTEGER,group_version INTEGER,updated_by INTEGER,updated_at TEXT,UNIQUE(source_id,category_id))');
$pdo->exec('CREATE TABLE crm_shop_category_group_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,version INTEGER,source_id TEXT,category_id INTEGER,replica_id INTEGER,old_group_id INTEGER,new_group_id INTEGER,source_revision INTEGER,group_version INTEGER,actor_id INTEGER,created_at TEXT,UNIQUE(link_id,version))');
if ($case!=='no_replica') { $pdo->exec('CREATE TABLE crm_shop_category_meta(id INTEGER PRIMARY KEY,source_id TEXT,category_id INTEGER,revision INTEGER,category_name TEXT,is_active INTEGER,received_at TEXT)'); $pdo->prepare('INSERT INTO crm_shop_category_meta VALUES(11,?,7,?, ?,1,?)')->execute([str_repeat('a',32),$case==='stale'?2:1,'<script>category</script>','2025-01-01']); }
$pdo->exec("INSERT INTO crm_shop_category_groups VALUES(1,'<script>group</script>',1,1,1,'2025-01-01')");
if (!in_array($case,['post_link','post_link_one','post_link_stale','post_link_readonly'],true)) $pdo->prepare('INSERT INTO crm_shop_category_group_links VALUES(1,?,7,11,1,1,1,1,1,?)')->execute([str_repeat('a',32),'2025-01-01']);
if ($case==='reused_replica') $pdo->exec('UPDATE crm_shop_category_meta SET id=12 WHERE id=11');
if ($case==='history') {
 $pdo->prepare('INSERT INTO crm_shop_category_group_events(group_id,version,old_name,new_name,old_active,new_active,actor_id,created_at) VALUES(1,1,?,?,NULL,1,1,?)')->execute(['<script>old</script>','<script>new</script>','2025-01-01']);
 $st=$pdo->prepare('INSERT INTO crm_shop_category_group_link_events(link_id,version,source_id,category_id,replica_id,old_group_id,new_group_id,source_revision,group_version,actor_id,created_at) VALUES(1,?,?,7,11,1,NULL,NULL,NULL,1,?)');
 for ($i=1;$i<=31;$i++) $st->execute([$i,'<script>source</script>','2025-01-01']);
}
if (in_array($case,['selected','readonly','stale','disabled','no_replica'],true)) $_GET=['replica'=>'11','group'=>'1'];
if (str_starts_with($case,'post_')) {
 $_SERVER['REQUEST_METHOD']='POST';
 if (str_starts_with($case,'post_link')) { $_POST=['action'=>'link','replica'=>'11','revision'=>$case==='post_link_stale'?'2':'1','group_id'=>'1','group_version'=>'1','version'=>'0','checked_source'=>'1']; if ($case!=='post_link_one') $_POST['checked_group']='1'; }
 elseif ($case==='post_create') $_POST=['action'=>'group_save','group_id'=>'0','version'=>'0','name'=>'New group','active'=>'1','confirmed'=>'1'];
 else $_POST=['action'=>'unlink','link_id'=>'1','version'=>'1','confirmed'=>'1'];
 register_shutdown_function(function() use ($pdo) { echo 'RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_category_groups')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_shop_category_group_link_events')->fetchColumn().':'.$pdo->query('SELECT COALESCE(group_id,0) FROM crm_shop_category_group_links WHERE id=1')->fetchColumn(); });
}
include __DIR__.'/pages/shop_category_groups.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr,r.stdout+r.stderr
        return r.stdout
    page=run('selected'); assert '&lt;script&gt;group&lt;/script&gt;' in page and '&lt;script&gt;category&lt;/script&gt;' in page and '<script>' not in page
    assert 'name="csrf_token"' in page and 'name="checked_source"' in page and 'name="checked_group"' in page and 'نسخه‌های دریافتی بدون تغییر' in page
    assert 'نیازمند بررسی مجدد' in run('stale') and 'پل دسته غیرفعال' in run('disabled') and 'نیازمند بررسی مجدد' in run('no_replica') and 'نیازمند بررسی مجدد' in run('reused_replica')
    assert '<form method="post"' not in run('readonly')
    history=run('history'); assert '&lt;script&gt;old&lt;/script&gt;' in history and '&lt;script&gt;source&lt;/script&gt;' in history and '<script>' not in history and 'رویدادهای ارجاع بعدی' in history and 'قطع' in history
    assert 'RESULT:2:0:1' in run('post_create') and 'RESULT:1:1:1' in run('post_link')
    for case in ('post_link_one','post_link_stale','post_link_readonly'): assert 'RESULT:1:0:' in run(case)
    assert 'RESULT:1:1:0' in run('post_unlink_disabled')
    for case in ('viewer','inactive','forged'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<form' not in denied
    index=(root/'index.php').read_text(); assert "case 'shop_category_groups'" in index and "verify_csrf($_POST['csrf_token'] ?? '')" in index and index.index('verify_csrf(')<index.index('pages/')
    assert "'shop_category_groups' => 'crm_customers'" in (root/'config.php').read_text()
    print('PASS category groups UI roles, escaping, stale warning, confirmations, readonly and POST fixtures')
