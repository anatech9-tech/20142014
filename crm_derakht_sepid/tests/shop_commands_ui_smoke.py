#!/usr/bin/env python3
"""Render the real order page with SQLite fixture and stubbed CRM shell (not a browser test)."""
import os, pathlib, shutil, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_orders.php',app/'pages/shop_orders.php')
    for name in ('shop_order_requests.php','shop_order_commands.php'):
        shutil.copy(root/'includes'/name,app/'includes'/name)
    (app/'includes/shop_order_sync.php').write_text('<?php function crm_shop_orders_ensure($p){return true;}')
    (app/'includes/shop_order_work.php').write_text('''<?php
function shop_work_ensure($p){return true;} function shop_work_scope($m,$a,$x){return ['1=1',[]];}
function shop_visible_customer($p,$id,$m,$a,$t){return null;}
''')
    (app/'includes/shop_product_mapping.php').write_text('<?php function shop_product_mapping_ensure($p){return false;}')
    (app/'includes/header.php').write_text('<header>shell</header>')
    (app/'includes/footer.php').write_text('<footer>shell</footer>')
    (app/'run.php').write_text('''<?php
function require_login(){} function is_strict_admin(){return true;} function managed_team_ids($p){return [];}
function is_nazer_kol(){return false;} function access_is_special_account(){return true;} function access_mode($k){return 'editor';}
function to_jalali($x){return $x;} function get_csrf_field(){return '<input name="csrf_token" value="test">';}
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('k',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/api');
$_SESSION=['user_id'=>1]; $_GET=['view'=>1]; $_SERVER['REQUEST_METHOD']='GET';
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,team_id INTEGER,username TEXT,full_name TEXT)',
 'CREATE TABLE teams(id INTEGER PRIMARY KEY,name TEXT,manager_user_id INTEGER)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT,received_at TEXT)',
 'CREATE TABLE crm_shop_order_work(replica_id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER,crm_customer_id INTEGER,version INTEGER)',
 'CREATE TABLE crm_shop_order_work_events(id INTEGER PRIMARY KEY,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_status_request_events(id INTEGER PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $q) $pdo->exec($q);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin',NULL,'admin','Admin')");
$order=['id'=>1,'order_no'=>'<order>','order_status'=>'pending','payment_status'=>'pending','order_event_id'=>1,'created_at'=>'2025-01-01 10:00:00','items'=>[]];
$st=$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,1,1,?,?)'); $st->execute([str_repeat('a',32),json_encode($order),'2025-01-01 10:00:00']);
$pdo->prepare("INSERT INTO crm_shop_status_requests VALUES(1,1,?,1,1,'pending','pending','confirmed','open',1,'2025-01-01 10:00:00',NULL,NULL)")->execute([str_repeat('a',32)]);
if (($argv[1] ?? '')==='queued') $pdo->exec("INSERT INTO crm_shop_order_commands VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1,1,'{}','queued',0,'2025-01-01','2025-01-01',NULL)");
include __DIR__.'/pages/shop_orders.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    available=run('available')
    assert '&lt;order&gt;' in available and '<order>' not in available
    assert 'ثبت درخواست به‌تنهایی' in available and 'تأیید و ثبت فرمان در صف' in available
    queued=run('queued')
    assert 'در صف؛ هنوز نتیجه مشخص نیست' in queued
    assert 'تأیید و ثبت فرمان در صف' not in queued and 'بستن درخواست داخلی</button>' not in queued
    print('PASS real page rendering: escaped order, explicit approval and queued-state controls')
