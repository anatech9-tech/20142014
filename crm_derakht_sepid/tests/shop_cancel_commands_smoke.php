<?php
/** Two SQLite DBs and simulated transport; never proof of MySQL locking, payment or real shipment state. */
class CancelTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) {
            $sql=str_replace('INSERT INTO shop_crm_command_receipts','INSERT OR IGNORE INTO shop_crm_command_receipts',$sql);
            $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_CANCEL_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('k',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/shop/api/crm_order_command.php');
require_once __DIR__.'/../../website/includes/shop/CrmOrderSync.php'; require_once __DIR__.'/../../website/includes/shop/CrmOrderCommands.php'; require_once __DIR__.'/../includes/shop_order_requests.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
function site(): CancelTestPDO {
 $p=new CancelTestPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 foreach ([
  'CREATE TABLE orders(id INTEGER PRIMARY KEY,order_no TEXT,customer_id INTEGER,customer_name TEXT,customer_phone TEXT,customer_email TEXT,subtotal_toman INTEGER,discount_toman INTEGER,shipping_fee INTEGER,total_toman INTEGER,order_status TEXT,payment_status TEXT,created_at TEXT,status_note TEXT,coupon_code TEXT)',
  'CREATE TABLE order_items(id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,product_name TEXT,qty INTEGER,price_toman INTEGER,total_toman INTEGER)',
  'CREATE TABLE order_events(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,status TEXT,note TEXT)',
  'CREATE TABLE products(id INTEGER PRIMARY KEY,stock INTEGER,sold_count INTEGER)',
  'CREATE TABLE shop_crm_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,shop_order_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)'
 ] as $q) $p->exec($q);
 $p->exec("INSERT INTO orders VALUES(1,'W-1',NULL,'Test','09120000000','',200,0,0,200,'packed','pending','2025-01-01 10:00:00','',NULL)");
 $p->exec("INSERT INTO order_items VALUES(1,1,7,'A',1,100,100),(2,1,8,'B',1,100,100); INSERT INTO order_events VALUES(1,1,'packed','old'); INSERT INTO products VALUES(7,2,1),(8,5,1)"); return $p;
}
function snap(PDO $p): array {
 $o=$p->query('SELECT * FROM orders WHERE id=1')->fetch(PDO::FETCH_ASSOC); $o['order_event_id']=(int)$p->query('SELECT COALESCE(MAX(id),0) FROM order_events WHERE order_id=1')->fetchColumn();
 return shop_crm_snapshot($o,$p->query('SELECT product_id,product_name,qty,price_toman,total_toman FROM order_items WHERE order_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}
function command(PDO $site,int $n=1): array {
 $s=snap($site); return ['type'=>'shop_cancel_v1','command_id'=>str_pad(dechex($n),32,'0',STR_PAD_LEFT),'source_id'=>SHOP_CRM_SOURCE_ID,'order_id'=>1,'expected_event_id'=>$s['order_event_id'],'expected_status'=>$s['order_status'],'expected_payment'=>$s['payment_status'],'target_status'=>'cancelled','snapshot_hash'=>hash('sha256',json_encode($s,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),'issued_at'=>1700000000,'expires_at'=>1700000900];
}
$now=1700000000; $source=SHOP_CRM_SOURCE_ID; $s=site(); $cmd=command($s);
check(shop_cancel_enabled() && crm_shop_cancel_enabled() && shop_command_validate($cmd,$source),'explicit opt-in and constrained cancellation protocol');
foreach ([['expected_status','shipped'],['expected_status','delivered'],['expected_payment','paid'],['expected_payment','refunded'],['target_status','shipped'],['type','shop_status_v1']] as [$key,$value]) { $x=$cmd; $x[$key]=$value; check(!shop_command_validate($x,$source),'invalid cancellation '.$key.' '.$value.' rejected'); }
$crm=new CancelTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_status_request_events(id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER UNIQUE,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $q) $crm->exec($q);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$crm->prepare('INSERT INTO crm_shop_orders VALUES(1,?,1,1,?)')->execute([$source,json_encode(snap($s),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid=shop_status_request_create($crm,1,1,true,1,'cancelled');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,2,true,$now,$source),'non-admin cannot enqueue cancel');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,3,true,$now,$source),'inactive admin cannot enqueue cancel');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source),'missing explicit payment and shipment checks denied');
$id=crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source,true);
$row=$crm->query('SELECT * FROM crm_shop_order_commands')->fetch(PDO::FETCH_ASSOC); $queued=json_decode($row['payload'],true);
check($queued['type']==='shop_cancel_v1' && shop_command_validate($queued,$source) && strpos($row['payload'],'09120000000')===false,'manager explicitly queues cancellation without customer data');
denied(fn()=>shop_status_request_close($crm,1,$rid,1,true),'unknown queued cancellation cannot be closed');
$s->exec("CREATE TRIGGER fail_cancel_event BEFORE INSERT ON order_events BEGIN SELECT RAISE(ABORT,'fail event'); END");
try { shop_command_apply($s,$queued,$source,$now); check(false,'event insertion failure'); } catch (PDOException $e) {}
check((int)$s->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2 && $s->query('SELECT order_status FROM orders')->fetchColumn()==='packed' && (int)$s->query('SELECT COUNT(*) FROM shop_crm_command_receipts')->fetchColumn()===0,'event failure rolls back both stocks, status and receipt');
$s->exec('DROP TRIGGER fail_cancel_event');
$s->exec("CREATE TRIGGER fail_second_product BEFORE UPDATE ON products WHEN OLD.id=8 BEGIN SELECT RAISE(ABORT,'product'); END");
try { shop_command_apply($s,$queued,$source,$now); check(false,'second product update failure'); } catch (PDOException $e) {}
check((int)$s->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2 && (int)$s->query('SELECT stock FROM products WHERE id=8')->fetchColumn()===5 && (int)$s->query('SELECT COUNT(*) FROM shop_crm_command_receipts')->fetchColumn()===0,'second product failure rolls back first and receipt');
$s->exec('DROP TRIGGER fail_second_product'); $first=true;
$sender=function($body) use ($s,$source,$now,&$first) { $c=json_decode($body,true); $r=shop_command_apply($s,$c,$source,$now); if ($first) { $first=false; return [0,null]; } return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$r]]; };
$r=crm_shop_command_pump($crm,$sender,$now);
check($r['failed']===1 && $s->query('SELECT order_status FROM orders')->fetchColumn()==='cancelled' && $crm->query('SELECT state FROM crm_shop_order_commands')->fetchColumn()==='queued','lost response means result unknown');
check((int)$s->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===3 && (int)$s->query('SELECT stock FROM products WHERE id=8')->fetchColumn()===6 && $s->query('SELECT payment_status FROM orders')->fetchColumn()==='pending','restocked exactly once with payment unchanged');
$r=crm_shop_command_pump($crm,$sender,$now+61);
check($r['settled']===1 && $crm->query('SELECT state FROM crm_shop_order_commands')->fetchColumn()==='applied' && (int)$s->query('SELECT COUNT(*) FROM order_events')->fetchColumn()===2,'retry acknowledges receipt without duplicate restock or event');
check(shop_command_apply($s,$queued,$source,$now+5000)==='applied','receipt persists even after expiration');
$bad=$queued; $bad['snapshot_hash']=str_repeat('f',64); denied(fn()=>shop_command_apply($s,$bad,$source,$now),'same command id cannot carry altered payload');
// Each source-side restriction must reject atomically, including one bad item after another valid one.
$cases=[
 'empty items' => 'DELETE FROM order_items',
 'coupon' => "UPDATE orders SET coupon_code='ONE'",
 'whitespace coupon' => "UPDATE orders SET coupon_code='  '",
 'discount' => 'UPDATE orders SET discount_toman=1',
 'deleted product reference' => 'UPDATE order_items SET product_id=NULL WHERE id=2',
 'missing product' => 'DELETE FROM products WHERE id=8',
 'insufficient sold count' => 'UPDATE products SET sold_count=0 WHERE id=8',
 'stock overflow' => 'UPDATE products SET stock=4294967295 WHERE id=8',
 'zero qty' => 'UPDATE order_items SET qty=0 WHERE id=2',
];
foreach ($cases as $label=>$mutation) {
 $t=site(); $t->exec($mutation); $c=command($t,rand(2,999999)); $result=shop_command_apply($t,$c,$source,$now);
 check($result==='conflict' && $t->query('SELECT order_status FROM orders')->fetchColumn()==='packed' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2, $label.' blocks cancellation with no partial restock');
}
$t=site(); $t->exec("UPDATE order_items SET product_id=7 WHERE id=2; UPDATE products SET sold_count=2 WHERE id=7"); $c=command($t,1233);
check(shop_command_apply($t,$c,$source,$now)==='applied' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===4 && (int)$t->query('SELECT sold_count FROM products WHERE id=7')->fetchColumn()===0,'duplicate product lines are aggregated for one restock');
$t=site(); $c=command($t,1239); $other=command($t,1240);
check(shop_command_apply($t,$c,$source,$now)==='applied' && shop_command_apply($t,$other,$source,$now)==='conflict' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===3,'second distinct command after cancellation cannot restock twice');
$t=site(); $c=command($t,1234); $t->exec('UPDATE orders SET total_toman=201');
check(shop_command_apply($t,$c,$source,$now)==='conflict' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2,'source snapshot change conflicts');
$t=site(); $c=command($t,1236); $t->exec("INSERT INTO order_events(order_id,status,note) VALUES(1,'packed','new event')");
check(shop_command_apply($t,$c,$source,$now)==='conflict' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2,'new source event blocks stale cancellation');
$t=site(); $c=command($t,1241); $t->exec("UPDATE orders SET coupon_code='ONE'");
check(shop_command_apply($t,$c,$source,$now)==='conflict' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2,'coupon added after snapshot still blocks cancellation');
$t=site(); $c=command($t,1237); $t->exec("UPDATE orders SET payment_status='paid'");
check(shop_command_apply($t,$c,$source,$now)==='conflict' && $t->query('SELECT payment_status FROM orders')->fetchColumn()==='paid','changed payment label blocks cancellation');
$t=site(); $c=command($t,1238); $t->exec("UPDATE orders SET order_status='shipped'");
check(shop_command_apply($t,$c,$source,$now)==='conflict' && $t->query('SELECT order_status FROM orders')->fetchColumn()==='shipped','shipment change blocks cancellation');
$t=site(); $c=command($t,1235);
check(shop_command_apply($t,$c,$source,$now+901)==='expired' && (int)$t->query('SELECT stock FROM products WHERE id=7')->fetchColumn()===2,'expired cancellation never mutates stock');
