#!/usr/bin/env python3
"""Render the real order page with SQLite fixture and stubbed CRM shell (not a browser test)."""
import os, pathlib, shutil, subprocess, tempfile
root = pathlib.Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BIN', 'php')
with tempfile.TemporaryDirectory() as tmp:
    app=pathlib.Path(tmp)
    for d in ('pages','includes'): (app/d).mkdir()
    shutil.copy(root/'pages/shop_orders.php',app/'pages/shop_orders.php')
    for name in ('shop_order_requests.php','shop_order_commands.php','shop_return_requests.php','shop_shipping_tracking.php','shop_shipping_packages.php'):
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
function require_login(){} function is_strict_admin(){return !in_array(($GLOBALS['case'] ?? ''),['viewer','shipping_viewer','post_shipping_viewer','package_viewer','post_package_viewer','post_delivery_viewer'],true);} function managed_team_ids($p){return [];}
function is_nazer_kol(){return in_array(($GLOBALS['case'] ?? ''),['readonly','shipping_readonly','post_shipping_readonly','package_readonly','post_package_readonly','post_delivery_readonly'],true);} function access_is_special_account(){return true;} function access_mode($k){return 'editor';}
function to_jalali($x){return $x;} function showToast($x,$y=''){} function redirect($x){exit;} function operations_audit(...$x){} function get_csrf_field(){return '<input name="csrf_token" value="test">';}
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('k',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/api');
$_SESSION=['user_id'=>1]; $_GET=['view'=>1]; $_SERVER['REQUEST_METHOD']='GET'; $case=$argv[1] ?? ''; if (in_array($case,['return_list','return_mismatch_list'],true)) $_GET=[]; if (in_array($case,['cancel_enabled','cancel_paid','cancel_queued','cancel_post_no_ack','cancel_post_one','cancel_post_both'],true)) define('SHOP_CRM_CANCEL_ENABLED',true);
if (in_array($case,['delivery_enabled','delivery_delivered','delivery_post_none','delivery_post_one','delivery_post_both','post_delivery_viewer','post_delivery_readonly'],true)) define('SHOP_CRM_DELIVERY_COMMAND_ENABLED',true);
class UITestPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }}
$pdo=new UITestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,team_id INTEGER,username TEXT,full_name TEXT)',
 'CREATE TABLE teams(id INTEGER PRIMARY KEY,name TEXT,manager_user_id INTEGER)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT,received_at TEXT)',
 'CREATE TABLE crm_shop_order_work(replica_id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER,crm_customer_id INTEGER,version INTEGER)',
 'CREATE TABLE crm_shop_order_work_events(id INTEGER PRIMARY KEY,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_status_request_events(id INTEGER PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_return_requests(id INTEGER PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,observed_status TEXT,reason TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_return_request_events(id INTEGER PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_shipping_tracking(replica_id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_shipping_tracking_events(id INTEGER PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,replica_revision INTEGER,action TEXT,actor_id INTEGER,created_at TEXT)',
 'CREATE TABLE crm_shop_shipping_packages(package_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_shipping_package_events(id INTEGER PRIMARY KEY,package_id TEXT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,replica_revision INTEGER,action TEXT,actor_id INTEGER,created_at TEXT)',
 'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $q) $pdo->exec($q);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin',NULL,'admin','Admin')");
$order=['id'=>1,'order_no'=>'<order>','order_status'=>(in_array(($argv[1] ?? ''),['return_new','return_open','return_list','return_mismatch','return_mismatch_list','return_mismatch_post','readonly','viewer','shipping_new','shipping_existing','shipping_stale','shipping_mismatch','shipping_readonly','shipping_viewer','post_shipping','post_shipping_readonly','post_shipping_viewer','package_new','package_existing','package_mismatch','package_readonly','package_viewer','post_package','post_package_readonly','post_package_viewer','cancel_enabled','cancel_paid','cancel_queued','cancel_disabled','cancel_post_no_ack','cancel_post_one','cancel_post_both']) ? 'shipped' : 'pending'),'payment_status'=>'pending','order_event_id'=>1,'created_at'=>'2025-01-01 10:00:00','items'=>[]];
$st=$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,1,1,?,?)'); $st->execute([str_repeat('a',32),json_encode($order),'2025-01-01 10:00:00']);
$pdo->prepare("INSERT INTO crm_shop_status_requests VALUES(1,1,?,1,1,'pending','pending','confirmed','open',1,'2025-01-01 10:00:00',NULL,NULL)")->execute([str_repeat('a',32)]);
if (in_array(($argv[1] ?? ''),['return_open','return_list','return_mismatch','return_mismatch_list','return_mismatch_post','readonly','viewer'],true)) $pdo->prepare("INSERT INTO crm_shop_return_requests VALUES(1,1,?,1,1,'shipped','damaged','open',1,'2025-01-01',NULL,NULL)")->execute([str_repeat(str_starts_with($case,'return_mismatch')?'b':'a',32)]);
if ($case==='return_mismatch_post') { $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>'1','action'=>'close_return','return_number'=>'1']; register_shutdown_function(function() use ($pdo) { echo 'RETURN_RESULT:'.$pdo->query('SELECT state FROM crm_shop_return_requests')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_shop_return_request_events')->fetchColumn(); }); }
if (in_array($case,['delivery_disabled','delivery_enabled','delivery_delivered','delivery_post_none','delivery_post_one','delivery_post_both','post_delivery_viewer','post_delivery_readonly'],true)) {
 $old=$case==='delivery_delivered'?'shipped':'packed'; $target=$case==='delivery_delivered'?'delivered':'shipped';
 $pdo->prepare('UPDATE crm_shop_status_requests SET expected_status=?,requested_status=? WHERE id=1')->execute([$old,$target]);
 $order['order_status']=$old; $pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([json_encode($order)]);
}
if (in_array($case,['delivery_post_none','delivery_post_one','delivery_post_both','post_delivery_viewer','post_delivery_readonly'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>'1','action'=>'send_status_command','request_number'=>'1'];
 if ($case!=='delivery_post_none') $_POST['delivery_verified']='1';
 if (in_array($case,['delivery_post_both','post_delivery_viewer','post_delivery_readonly'],true)) $_POST['delivery_understood']='1';
 register_shutdown_function(function() use ($pdo) { echo 'DELIVERY_RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_order_commands')->fetchColumn(); });
}
if (in_array($case,['cancel_enabled','cancel_paid','cancel_queued','cancel_disabled','cancel_post_no_ack','cancel_post_one','cancel_post_both'],true)) {
 $pay=$case==='cancel_paid'?'paid':'pending';
 $pdo->prepare("UPDATE crm_shop_status_requests SET expected_status='packed',expected_payment=?,requested_status='cancelled' WHERE id=1")->execute([$pay]);
 $order['order_status']='packed'; $order['payment_status']=$pay; $pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([json_encode($order)]);
 if ($case==='cancel_queued') $pdo->exec("INSERT INTO crm_shop_order_commands VALUES('cccccccccccccccccccccccccccccccc',1,1,1,'{}','queued',0,'2025-01-01','2025-01-01',NULL)");
}
if (in_array($case,['cancel_post_no_ack','cancel_post_one','cancel_post_both'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>'1','action'=>'send_status_command','request_number'=>'1'];
 if ($case!=='cancel_post_no_ack') $_POST['cancel_unpaid']='1'; if ($case==='cancel_post_both') $_POST['cancel_unshipped']='1';
 register_shutdown_function(function() use ($pdo) { echo 'CANCEL_RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_order_commands')->fetchColumn(); });
}
if (in_array($case,['shipping_existing','shipping_stale','shipping_mismatch','shipping_readonly','shipping_viewer'],true)) {
 $source=$case==='shipping_mismatch'?str_repeat('b',32):str_repeat('a',32); $revision=$case==='shipping_stale'?2:1;
 $pdo->prepare("INSERT INTO crm_shop_shipping_tracking VALUES(1,?,1,1,'<Carrier>','AB123',?,1,'2025-01-01')")->execute([$source,$revision]);
}
if (in_array($case,['post_shipping','post_shipping_readonly','post_shipping_viewer'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>'1','action'=>'save_shipping','shipping_revision'=>'1','shipping_version'=>'0','carrier'=>'Post','tracking_code'=>'AB123'];
 register_shutdown_function(function() use ($pdo) { echo 'TRACKING_RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_tracking')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_tracking_events')->fetchColumn(); });
}
if (in_array($case,['package_existing','package_mismatch','package_readonly','package_viewer'],true)) {
 $source=$case==='package_mismatch'?str_repeat('b',32):str_repeat('a',32);
 $pdo->prepare("INSERT INTO crm_shop_shipping_packages VALUES(?,1,?,1,1,'<Carrier>','PK123',1,1,'2025-01-01')")->execute([str_repeat('1',32),$source]);
}
if (in_array($case,['post_package','post_package_readonly','post_package_viewer','post_delivery_viewer'],true)) {
 $_SERVER['REQUEST_METHOD']='POST'; $_POST=['id'=>'1','action'=>'create_package','package_revision'=>'1','package_version'=>'0','package_id'=>str_repeat('1',32),'carrier'=>'Post','tracking_code'=>'PK123'];
 register_shutdown_function(function() use ($pdo) { echo 'PACKAGE_RESULT:'.$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_packages')->fetchColumn().':'.$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_package_events')->fetchColumn(); });
}
if (($argv[1] ?? '')==='queued') $pdo->exec("INSERT INTO crm_shop_order_commands VALUES('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1,1,1,'{}','queued',0,'2025-01-01','2025-01-01',NULL)");
include __DIR__.'/pages/shop_orders.php';
''')
    def run(case):
        r=subprocess.run([php,str(app/'run.php'),case],cwd=app,capture_output=True,text=True,check=True)
        assert 'Fatal error' not in r.stdout+r.stderr and 'Parse error' not in r.stdout+r.stderr, r.stdout+r.stderr
        return r.stdout
    available=run('available')
    assert '&lt;order&gt;' in available and '<order>' not in available
    assert 'ثبت درخواست به‌تنهایی' in available and 'تأیید و ثبت فرمان در صف' in available
    queued=run('queued')
    assert 'در صف؛ هنوز نتیجه مشخص نیست' in queued
    assert 'تأیید و ثبت فرمان در صف' not in queued and 'بستن درخواست داخلی</button>' not in queued
    new=run('return_new'); assert 'ثبت درخواست بررسی مرجوعی</button>' in new and 'نه به معنی' not in new and 'بازپرداخت یا اطلاع‌رسانی' in new
    opened=run('return_open'); assert 'بستن پیگیری مرجوعی</button>' in opened and 'ثبت درخواست بررسی مرجوعی</button>' not in opened
    assert 'csrf_token' in new
    listed=run('return_list'); assert 'پیگیری‌های باز بررسی مرجوعی' in listed and '&lt;order&gt;' in listed
    mismatch=run('return_mismatch'); assert 'هویت پیگیری مرجوعی با سفارش فعلی ناسازگار است' in mismatch and 'بستن پیگیری مرجوعی</button>' not in mismatch and 'ثبت درخواست بررسی مرجوعی</button>' not in mismatch and 'آسیب‌دیدگی یا نقص' not in mismatch
    mismatch_list=run('return_mismatch_list'); assert 'یک پیگیری باز با هویت ناسازگار یافت شد' in mismatch_list and '&lt;order&gt;' not in mismatch_list.split('پیگیری‌های باز بررسی مرجوعی',1)[1].split('</div>',1)[0]
    assert 'RETURN_RESULT:open:0' in run('return_mismatch_post')
    readonly=run('readonly'); assert 'بستن پیگیری مرجوعی</button>' not in readonly and 'پیگیری داخلی بررسی مرجوعی' in readonly
    viewer=run('viewer'); assert 'پیگیری داخلی بررسی مرجوعی' not in viewer and 'بستن پیگیری مرجوعی</button>' not in viewer
    shipnew=run('shipping_new'); assert 'ثبت رهگیری داخلی</button>' in shipnew and 'csrf_token' in shipnew and 'اثبات ارسال یا تحویل نیست' in shipnew
    existing=run('shipping_existing'); assert '&lt;Carrier&gt;' in existing and '<Carrier>' not in existing and 'پاک‌کردن رهگیری داخلی</button>' in existing
    assert 'نسخهٔ دریافتی سفارش پس از ثبت تغییر کرده' in run('shipping_stale')
    mismatch=run('shipping_mismatch'); assert 'هویت رهگیری با سفارش فعلی ناسازگار است' in mismatch and 'AB123' not in mismatch and 'ثبت رهگیری داخلی</button>' not in mismatch
    read=run('shipping_readonly'); assert 'AB123' in read and 'پاک‌کردن رهگیری داخلی</button>' not in read and 'ثبت رهگیری داخلی</button>' not in read
    viewer=run('shipping_viewer'); assert 'AB123' not in viewer and 'رهگیری داخلی ارسال' not in viewer
    assert 'TRACKING_RESULT:1:1' in run('post_shipping')
    assert 'TRACKING_RESULT:0:0' in run('post_shipping_readonly') and 'TRACKING_RESULT:0:0' in run('post_shipping_viewer')
    package=run('package_new'); assert 'ثبت بسته اضافی</button>' in package and 'فقط یادداشت داخلی CRM' in package and 'csrf_token' in package
    package=run('package_existing'); assert 'PK123' in package and '&lt;Carrier&gt;' in package and 'ویرایش بسته</button>' in package and 'پاک‌کردن بسته</button>' in package
    mismatch=run('package_mismatch'); assert 'هویت بسته‌ها با سفارش فعلی ناسازگار است' in mismatch and 'PK123' not in mismatch and 'ثبت بسته اضافی</button>' not in mismatch
    readonly=run('package_readonly'); assert 'PK123' in readonly and 'ویرایش بسته</button>' not in readonly and 'ثبت بسته اضافی</button>' not in readonly
    viewer=run('package_viewer'); assert 'بسته‌های اضافی و کدهای داخلی' not in viewer and 'PK123' not in viewer
    assert 'PACKAGE_RESULT:1:1' in run('post_package')
    assert 'PACKAGE_RESULT:0:0' in run('post_package_readonly') and 'PACKAGE_RESULT:0:0' in run('post_package_viewer')
    assert 'تأیید و ثبت فرمان در صف' not in run('delivery_disabled')
    delivery=run('delivery_enabled'); assert 'name="delivery_verified"' in delivery and 'name="delivery_understood"' in delivery and 'تحویل فیزیکی همه بسته‌های سفارش به شرکت حمل' in delivery
    delivered=run('delivery_delivered'); assert 'تحویل همه بسته‌های سفارش به مشتری را با مدرک مستقل' in delivered and 'name="delivery_understood"' in delivered
    assert 'DELIVERY_RESULT:0' in run('delivery_post_none') and 'DELIVERY_RESULT:0' in run('delivery_post_one')
    assert 'DELIVERY_RESULT:1' in run('delivery_post_both')
    assert 'DELIVERY_RESULT:0' in run('post_delivery_viewer') and 'DELIVERY_RESULT:0' in run('post_delivery_readonly')
    cancel=run('cancel_enabled'); assert 'تأیید و ثبت فرمان در صف' in cancel and 'هیچ بازپرداختی انجام نمی‌شود' in cancel and 'وجهی دریافت نشده' in cancel and 'name="cancel_unpaid"' in cancel and 'name="cancel_unshipped"' in cancel
    assert 'تأیید و ثبت فرمان در صف' not in run('cancel_disabled')
    assert 'تأیید و ثبت فرمان در صف' not in run('cancel_paid')
    assert 'تأیید و ثبت فرمان در صف' not in run('cancel_queued')
    assert 'CANCEL_RESULT:0' in run('cancel_post_no_ack') and 'CANCEL_RESULT:0' in run('cancel_post_one')
    assert 'CANCEL_RESULT:1' in run('cancel_post_both')
    print('PASS order page, cancellation, delivery confirmations and private package controls')
