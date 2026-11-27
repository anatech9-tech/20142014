#!/usr/bin/env python3
"""Read-only coupon page against SQLite fixtures; not a browser or live database."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_coupon_metadata.php',app/'pages/shop_coupon_metadata.php')
    for name in ('shop_coupon_metadata_sync.php','shop_coupon_metadata_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
if ($case!=='disabled' && $case!=='missing') { define('SHOP_COUPON_META_ENABLED',true); define('SHOP_COUPON_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='missing') {
 $pdo->exec('CREATE TABLE crm_shop_coupon_meta(id INTEGER PRIMARY KEY,source_id TEXT,coupon_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,coupon_type TEXT,is_active INTEGER,received_at TEXT)');
 $st=$pdo->prepare('INSERT INTO crm_shop_coupon_meta VALUES(?,?,?,?,?,?,?,?,?)');
 for ($i=1;$i<=31;$i++) $st->execute([$i,$i===31?'<script>':str_repeat('a',32),$i,1,str_repeat('b',32),str_repeat('c',64),'percent',$i===31?0:1,'2025-01-01']);
 if ($case==='missing_filter') { $_GET['status']='missing'; $st->execute([32,str_repeat('a',32),99,3,str_repeat('b',32),str_repeat('c',64),'',0,'2025-01-01']); }
}
if ($case==='deletion_on') define('SHOP_COUPON_META_DELETION_ENABLED',true);
if ($case==='filter') $_GET['status']='inactive'; if ($case==='page2') $_GET['before']='2';
include __DIR__.'/pages/shop_coupon_metadata.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    page=run('admin'); assert 'نمای کوپن‌های فروشگاه' in page and 'به معنی اعتبار فعلی' in page and 'صفحه بعد' in page
    assert '&lt;script&gt;' in page and '<script>' not in page and '<form method="post"' not in page
    assert 'دریافت رویداد نبود کوپن غیرفعال' in page and 'دریافت رویداد نبود کوپن غیرفعال' not in run('deletion_on')
    assert 'دریافت در CRM غیرفعال' in run('disabled') and 'جدول نمای کوپن آماده نیست' in run('missing')
    assert '<td>31</td>' in run('filter') and '<td>30</td>' not in run('filter')
    assert '<td>1</td>' in run('page2') and '<td>2</td>' not in run('page2')
    missing=run('missing_filter'); assert '<td>99</td>' in missing and '<td>31</td>' not in missing and 'نبود مشاهده‌شده' in missing and '—' in missing
    for case in ('viewer','inactive','forged'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<table' not in denied
    assert "case 'shop_coupon_metadata'" in (root/'index.php').read_text() and "'shop_coupon_metadata' => 'crm_customers'" in (root/'config.php').read_text()
    print('PASS coupon page role, escaped data, filters, pagination, deletion label and read-only warnings')
