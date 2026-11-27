#!/usr/bin/env python3
"""Read-only category page against SQLite fixtures; not a browser or live database."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]; php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_category_metadata.php',app/'pages/shop_category_metadata.php')
    for name in ('shop_category_metadata_sync.php','shop_category_metadata_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
if ($case!=='disabled' && $case!=='missing') { define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='missing') {
 $pdo->exec('CREATE TABLE crm_shop_category_meta(id INTEGER PRIMARY KEY,source_id TEXT,category_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,category_name TEXT,is_active INTEGER,received_at TEXT)');
 $st=$pdo->prepare('INSERT INTO crm_shop_category_meta VALUES(?,?,?,?,?,?,?,?,?)');
 for ($i=1;$i<=31;$i++) $st->execute([$i,str_repeat('a',32),$i,1,str_repeat('b',32),str_repeat('c',64),$i===31?'<script>نام</script>':'نام',$i===31?0:1,'2025-01-01']);
 if ($case==='missing_filter') { $_GET['status']='missing'; $st->execute([32,str_repeat('a',32),99,3,str_repeat('b',32),str_repeat('c',64),null,0,'2025-01-01']); }
 if ($case==='empty_name') { $st->execute([32,str_repeat('a',32),99,3,str_repeat('b',32),str_repeat('c',64),'',0,'2025-01-01']); }
}
if ($case==='deletion_on') define('SHOP_CATEGORY_META_DELETION_ENABLED',true);
if ($case==='filter') $_GET['status']='inactive'; if ($case==='page2') $_GET['before']='2';
include __DIR__.'/pages/shop_category_metadata.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    page=run('admin'); assert 'نمای دسته‌های فروشگاه' in page and 'حتی اگر دسته کالایی نداشته باشد' in page and 'صفحه بعد' in page
    assert '&lt;script&gt;' in page and '<script>' not in page and '<form method="post"' not in page
    assert 'category_source=' + 'a'*32 in page and 'category_id=31' in page
    assert 'دریافت رویداد نبود دسته غیرفعال' in page and 'دریافت رویداد نبود دسته غیرفعال' not in run('deletion_on')
    assert 'دریافت در CRM غیرفعال' in run('disabled') and 'جدول نمای دسته آماده نیست' in run('missing')
    assert '<td>31</td>' in run('filter') and '<td>30</td>' not in run('filter')
    assert '<td>1</td>' in run('page2') and '<td>2</td>' not in run('page2')
    missing=run('missing_filter'); assert '<td>99</td>' in missing and '<td>31</td>' not in missing and 'نبود مشاهده‌شده' in missing and 'category_id=99' not in missing
    empty=run('empty_name'); assert 'نام خالی' in empty and 'category_id=99' in empty
    for case in ('viewer','inactive','forged'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<table' not in denied
    assert "case 'shop_category_metadata'" in (root/'index.php').read_text() and "'shop_category_metadata' => 'crm_customers'" in (root/'config.php').read_text()
    print('PASS category page permissions, escaping, filters, pagination, tombstone, empty label and links')
