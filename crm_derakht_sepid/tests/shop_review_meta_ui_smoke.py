#!/usr/bin/env python3
"""Render the real page with an isolated SQLite fixture, not a browser/HTTP test."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_review_meta.php',app/'pages/shop_review_meta.php')
    shutil.copy(root/'includes/shop_review_meta_sync.php',app/'includes/shop_review_meta_sync.php')
    shutil.copy(root/'includes/shop_review_commands.php',app/'includes/shop_review_commands.php')
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
function is_nazer_kol(){return in_array($GLOBALS['case'],['readonly','post_readonly'],true);} function access_is_special_account(){return true;} function access_mode($k){return 'viewer';} function get_csrf_field(){return '<input name="csrf_token" value="token">';} function showToast($s,$t=''){} function operations_audit(...$x){} function redirect($s){exit;}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
if ($case!=='disabled' && $case!=='missing') { define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); }
if (in_array($case,['hide','readonly','post_none','post_one','post_both','post_readonly','queued'],true)) {
 define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_HIDE_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32)); define('SHOP_REVIEW_COMMAND_URL','https://example.test/cmd'); $_GET['review']='30';
}
if ($case==='filter') $_GET['status']='inactive'; if ($case==='missing_filter') $_GET['status']='missing'; if ($case==='page2') $_GET=['status'=>'all','before'=>'2'];
class UITestPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$pdo=new UITestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='missing') {
 $pdo->exec('CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,rating INTEGER,is_active INTEGER,received_at TEXT)');
 $st=$pdo->prepare('INSERT INTO crm_shop_review_meta VALUES(?,?,?,?,?,?,?,?,?,?)');
 for ($i=1;$i<=31;$i++) $st->execute([$i,$i===31?'<script>':str_repeat('a',32),$i,10,15,str_repeat('b',32),str_repeat('c',64),5,$i===31?0:1,'2025-01-01']);
 if ($case==='missing_filter') $st->execute([32,str_repeat('a',32),99,0,15,str_repeat('b',32),str_repeat('c',64),0,0,'2025-01-01']);
}
$pdo->exec('CREATE TABLE crm_shop_review_commands(command_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,review_id INTEGER,replica_revision INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)');
$pdo->exec('CREATE TABLE crm_shop_review_command_events(id INTEGER PRIMARY KEY,command_id TEXT,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)');
if ($case==='queued') $pdo->prepare("INSERT INTO crm_shop_review_commands VALUES(?,30,?,30,15,1,'{}','queued',0,'2025-01-01','2025-01-01',NULL)")->execute([str_repeat('d',32),str_repeat('a',32)]);
if (str_starts_with($case,'post_')) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['replica_id'=>'30','revision'=>'15','action'=>'hide_review'];
 if ($case!=='post_none') $_POST['checked_review']='1'; if (in_array($case,['post_both','post_readonly'],true)) $_POST['confirmed_hide']='1';
 register_shutdown_function(function() use ($pdo) { echo 'COMMAND_RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_review_commands')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_shop_review_command_events')->fetchColumn(); });
}
include __DIR__.'/pages/shop_review_meta.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    admin=run('admin'); assert 'نمای کلی دیدگاه‌های فروشگاه' in admin and 'مشاهدهٔ نبود در سایت فقط در صورت فعال‌بودن تطبیق مستقل' in admin and 'صفحه بعد' in admin
    assert '&lt;script&gt;' in admin and '<script>' not in admin and '<form method="post"' not in admin
    page2=run('page2'); assert 'review=1">1</a>' in page2 and 'review=2">2</a>' not in page2
    filtered=run('filter'); assert 'review=31">31</a>' in filtered and 'review=30">30</a>' not in filtered
    missingfilter=run('missing_filter'); assert 'review=32">99</a>' in missingfilter and 'review=31">31</a>' not in missingfilter and 'نبود مشاهده‌شده' in missingfilter and '—' in missingfilter
    disabled=run('disabled'); assert 'دریافت در CRM غیرفعال' in disabled and 'review=31">31</a>' in disabled
    missing=run('missing'); assert 'جدول نمای دیدگاه آماده نیست' in missing
    hide=run('hide'); assert 'name="checked_review"' in hide and 'name="confirmed_hide"' in hide and 'csrf_token' in hide and 'محافظ محتوای مستقل' in hide
    assert 'ثبت فرمان مخفی‌سازی</button>' not in run('readonly') and 'ثبت فرمان مخفی‌سازی</button>' not in run('queued')
    assert 'COMMAND_RESULT:0:0' in run('post_none') and 'COMMAND_RESULT:0:0' in run('post_one') and 'COMMAND_RESULT:0:0' in run('post_readonly')
    assert 'COMMAND_RESULT:1:1' in run('post_both')
    for case in ('viewer','forged','inactive'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<table' not in denied
    print('PASS review metadata page role checks, escaping, filters and disabled state')
