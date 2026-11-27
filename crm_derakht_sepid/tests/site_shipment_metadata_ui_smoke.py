#!/usr/bin/env python3
"""Render the real page against in-memory fixtures, not a browser or HTTP server."""
import os, pathlib, shutil, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/site_shipment_metadata.php',app/'pages/site_shipment_metadata.php')
    for name in ('site_shipment_metadata_sync.php','site_shipment_metadata_contract.php'): shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/header.php').write_text('<header>shell</header>'); (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return $GLOBALS['case']!=='viewer';}
$case=$argv[1]; $_SESSION=['user_id'=>1]; $_GET=[]; $_SERVER['REQUEST_METHOD']='GET';
if ($case!=='disabled' && $case!=='missing') { define('SITE_SHIPMENT_META_ENABLED',true); define('SITE_SHIPMENT_META_KEY',str_repeat('s',32)); define('SITE_SHIPMENT_SOURCE_ID',str_repeat('a',32)); }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $pdo->prepare('INSERT INTO users VALUES(1,?,?)')->execute([$case==='inactive'?'inactive':'active',$case==='forged'?'user':'admin']);
if ($case!=='missing') {
 $pdo->exec('CREATE TABLE crm_site_shipment_meta(id INTEGER PRIMARY KEY,source_id TEXT,shipment_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,service_type TEXT,status TEXT,is_public INTEGER,received_at TEXT)');
 $st=$pdo->prepare('INSERT INTO crm_site_shipment_meta VALUES(?,?,?,?,?,?,?,?,?,?)');
 for ($i=1;$i<=31;$i++) $st->execute([$i,$i===31?'<script>':str_repeat('a',32),$i,1,str_repeat('b',32),str_repeat('c',64),'air',$i===31?'delivered':'registered',1,'2025-01-01']);
}
if ($case==='missing_filter') { $_GET['status']='missing'; $st->execute([32,str_repeat('a',32),99,3,str_repeat('b',32),str_repeat('c',64),'','',0,'2025-01-01']); }
if ($case==='deletion_on') define('SITE_SHIPMENT_META_DELETION_ENABLED',true);
if ($case==='filter') $_GET['status']='delivered'; if ($case==='page2') $_GET['before']='2';
include __DIR__.'/pages/site_shipment_metadata.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Warning:' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    page=run('admin'); assert 'محموله‌های مستقل سایت' in page and 'نبود مشاهده‌شده فقط با تطبیق جداگانه' in page and 'صفحه بعد' in page
    assert '&lt;script&gt;' in page and '<script>' not in page and '<form method="post"' not in page
    assert 'دریافت رویداد نبود محموله غیرفعال' in page and 'دریافت رویداد نبود محموله غیرفعال' not in run('deletion_on')
    missing=run('missing_filter'); assert '<td>99</td>' in missing and '<td>31</td>' not in missing and 'نبود مشاهده‌شده' in missing and '—' in missing
    assert 'دریافت در CRM غیرفعال' in run('disabled')
    assert 'جدول نمای محموله آماده نیست' in run('missing')
    assert '<td>31</td>' in run('filter') and '<td>30</td>' not in run('filter')
    assert '<td>1</td>' in run('page2') and '<td>2</td>' not in run('page2')
    for case in ('viewer','inactive','forged'):
        denied=run(case); assert 'دسترسی مجاز نیست' in denied and '<table' not in denied
    index=(root/'index.php').read_text(); config=(root/'config.php').read_text(); header=(root/'includes/header.php').read_text()
    assert "case 'site_shipment_metadata'" in index and "'site_shipment_metadata' => 'crm_customers'" in config and "'site_shipment_metadata' => ['site_shipment_metadata'" in header
    print('PASS shipment page authorization, disabled state, escaping, filtering, pagination and read-only UI')
