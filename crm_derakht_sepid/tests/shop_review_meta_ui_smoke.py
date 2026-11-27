#!/usr/bin/env python3
"""Render the real page with an isolated SQLite fixture, not a browser/HTTP test."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_review_meta.php',app/'pages/shop_review_meta.php')
    shutil.copy(root/'includes/shop_review_meta_sync.php',app/'includes/shop_review_meta_sync.php')
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
if ($case!=='disabled' && $case!=='missing') { define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); }
if ($case==='filter') $_GET['status']='inactive'; if ($case==='missing_filter') $_GET['status']='missing'; if ($case==='page2') $_GET=['status'=>'all','before'=>'2'];
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)');
$pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='missing') {
 $pdo->exec('CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,rating INTEGER,is_active INTEGER,received_at TEXT)');
 $st=$pdo->prepare('INSERT INTO crm_shop_review_meta VALUES(?,?,?,?,?,?,?)');
 for ($i=1;$i<=31;$i++) $st->execute([$i,$i===31?'<script>':str_repeat('a',32),$i,10,5,$i===31?0:1,'2025-01-01']);
 if ($case==='missing_filter') $st->execute([32,str_repeat('a',32),99,0,0,0,'2025-01-01']);
}
include __DIR__.'/pages/shop_review_meta.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    admin=run('admin'); assert 'نمای کلی دیدگاه‌های فروشگاه' in admin and 'مشاهدهٔ نبود در سایت فقط در صورت فعال‌بودن تطبیق مستقل' in admin and 'صفحه بعد' in admin
    assert '&lt;script&gt;' in admin and '<script>' not in admin and '<form method="post"' not in admin
    page2=run('page2'); assert '<td>1<br>' in page2 and '<td>2<br>' not in page2
    filtered=run('filter'); assert '<td>31<br>' in filtered and '<td>30<br>' not in filtered
    missingfilter=run('missing_filter'); assert '<td>99<br>' in missingfilter and '<td>31<br>' not in missingfilter and 'نبود مشاهده‌شده' in missingfilter and '—' in missingfilter
    disabled=run('disabled'); assert 'دریافت در CRM غیرفعال' in disabled and '<td>31<br>' in disabled
    missing=run('missing'); assert 'جدول نمای دیدگاه آماده نیست' in missing
    for case in ('viewer','forged','inactive'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<table' not in denied
    print('PASS review metadata page role checks, escaping, filters and disabled state')
