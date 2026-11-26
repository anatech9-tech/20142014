<?php
/** SQLite fixtures and simulated transport only; not a real shipment, MySQL or HTTP test. */
class DeliveryPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) {
   $sql=str_replace('INSERT INTO shop_crm_command_receipts','INSERT OR IGNORE INTO shop_crm_command_receipts',$sql);
   $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql);
  }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_DELIVERY_COMMAND_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('k',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/cmd');
require_once __DIR__.'/../../website/includes/shop/CrmOrderSync.php'; require_once __DIR__.'/../../website/includes/shop/CrmOrderCommands.php'; require_once __DIR__.'/../includes/shop_order_requests.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
function site(): DeliveryPDO {
 $p=new DeliveryPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 foreach ([
  'CREATE TABLE orders(id INTEGER PRIMARY KEY,order_no TEXT,customer_id INTEGER,customer_name TEXT,customer_phone TEXT,customer_email TEXT,subtotal_toman INTEGER,discount_toman INTEGER,shipping_fee INTEGER,total_toman INTEGER,order_status TEXT,payment_status TEXT,created_at TEXT,status_note TEXT,coupon_code TEXT)',
  'CREATE TABLE order_items(id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,product_name TEXT,qty INTEGER,price_toman INTEGER,total_toman INTEGER)',
  'CREATE TABLE order_events(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,status TEXT,note TEXT)',
  'CREATE TABLE products(id INTEGER PRIMARY KEY,stock INTEGER,sold_count INTEGER)',
  'CREATE TABLE shop_crm_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,shop_order_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)'
 ] as $q) $p->exec($q);
 $p->exec("INSERT INTO orders VALUES(1,'W-1',NULL,'Test','09120000000','',100,0,0,100,'packed','paid','2025-01-01 10:00:00','',NULL)");
 $p->exec("INSERT INTO order_items VALUES(1,1,7,'A',1,100,100); INSERT INTO order_events VALUES(1,1,'packed','old'); INSERT INTO products VALUES(7,2,1)"); return $p;
}
function snapshot(PDO $p): array {
 $o=$p->query('SELECT * FROM orders WHERE id=1')->fetch(PDO::FETCH_ASSOC); $o['order_event_id']=(int)$p->query('SELECT COALESCE(MAX(id),0) FROM order_events WHERE order_id=1')->fetchColumn();
 return shop_crm_snapshot($o,$p->query('SELECT product_id,product_name,qty,price_toman,total_toman FROM order_items WHERE order_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}
function cmd(PDO $site,int $n=1): array {
 $s=snapshot($site); return ['type'=>'shop_delivery_v1','command_id'=>str_pad(dechex($n),32,'0',STR_PAD_LEFT),'source_id'=>SHOP_CRM_SOURCE_ID,'order_id'=>1,'expected_event_id'=>$s['order_event_id'],'expected_status'=>$s['order_status'],'expected_payment'=>$s['payment_status'],'target_status'=>$s['order_status']==='packed'?'shipped':'delivered','snapshot_hash'=>hash('sha256',json_encode($s,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),'issued_at'=>1700000000,'expires_at'=>1700000900];
}
$now=1700000000; $source=SHOP_CRM_SOURCE_ID; $s=site(); $c=cmd($s);
check(shop_delivery_command_enabled() && crm_shop_delivery_command_enabled() && shop_command_validate($c,$source),'independent opt-in permits packed to shipped protocol');
foreach ([['expected_status','confirmed'],['expected_status','shipped'],['target_status','delivered'],['target_status','cancelled'],['type','shop_status_v1']] as [$key,$value]) { $x=$c; $x[$key]=$value; check(!shop_command_validate($x,$source),'invalid transition '.$key.' '.$value.' rejected'); }
$crm=new DeliveryPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_status_request_events(id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER UNIQUE,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $q) $crm->exec($q);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$crm->prepare('INSERT INTO crm_shop_orders VALUES(1,?,1,1,?)')->execute([$source,json_encode(snapshot($s),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid=shop_status_request_create($crm,1,1,true,1,'shipped');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,2,true,$now,$source,false,true),'non-admin denied');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,3,true,$now,$source,false,true),'inactive admin denied');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source),'no delivery confirmation denied');
$id=crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source,false,true);
$row=$crm->query('SELECT * FROM crm_shop_order_commands')->fetch(PDO::FETCH_ASSOC); $queued=json_decode($row['payload'],true);
check($queued['type']==='shop_delivery_v1' && shop_command_validate($queued,$source) && strpos($row['payload'],'09120000000')===false,'delivery command omits customer data');
denied(fn()=>shop_status_request_close($crm,1,$rid,1,true),'unknown queued command cannot be closed');
$s->exec("CREATE TRIGGER fail_event BEFORE INSERT ON order_events BEGIN SELECT RAISE(ABORT,'event'); END");
try { shop_command_apply($s,$queued,$source,$now); check(false,'event failure'); } catch (PDOException $e) {}
check($s->query('SELECT order_status FROM orders')->fetchColumn()==='packed' && (int)$s->query('SELECT COUNT(*) FROM shop_crm_command_receipts')->fetchColumn()===0,'event failure rolls back status and receipt');
$s->exec('DROP TRIGGER fail_event'); $first=true;
$sender=function($body) use ($s,$source,$now,&$first) { $c=json_decode($body,true); $r=shop_command_apply($s,$c,$source,$now); if ($first) { $first=false; return [0,null]; } return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$r]]; };
$r=crm_shop_command_pump($crm,$sender,$now);
check($r['failed']===1 && $s->query('SELECT order_status FROM orders')->fetchColumn()==='shipped' && $crm->query('SELECT state FROM crm_shop_order_commands')->fetchColumn()==='queued','lost response remains unknown');
check($s->query('SELECT payment_status FROM orders')->fetchColumn()==='paid' && (int)$s->query('SELECT stock FROM products')->fetchColumn()===2,'payment and stock unchanged');
$r=crm_shop_command_pump($crm,$sender,$now+61);
check($r['settled']===1 && (int)$s->query('SELECT COUNT(*) FROM order_events')->fetchColumn()===2 && shop_command_apply($s,$queued,$source,$now+5000)==='applied','retry retrieves receipt without duplicate event even after expiration');
$bad=$queued; $bad['snapshot_hash']=str_repeat('f',64); denied(fn()=>shop_command_apply($s,$bad,$source,$now),'same id with altered payload rejected');
shop_status_request_close($crm,1,$rid,1,true);
$crm->prepare('UPDATE crm_shop_orders SET revision=2,payload=? WHERE id=1')->execute([json_encode(snapshot($s),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid2=shop_status_request_create($crm,1,1,true,2,'delivered');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid2,1,true,$now,$source),'delivery also requires confirmation');
$id2=crm_shop_command_enqueue($crm,1,$rid2,1,true,$now,$source,false,true);
$st=$crm->prepare('SELECT payload FROM crm_shop_order_commands WHERE command_id=?'); $st->execute([$id2]); $delivered=json_decode($st->fetchColumn(),true);
check(shop_command_validate($delivered,$source) && shop_command_apply($s,$delivered,$source,$now)==='applied' && $s->query('SELECT order_status FROM orders')->fetchColumn()==='delivered','shipped to delivered executes with current snapshot');
$t=site(); $old=cmd($t,100); $t->exec("INSERT INTO order_events(order_id,status,note) VALUES(1,'packed','new event')");
check(shop_command_apply($t,$old,$source,$now)==='conflict' && $t->query('SELECT order_status FROM orders')->fetchColumn()==='packed','stale event blocks transition');
$t=site(); $old=cmd($t,101); $t->exec("UPDATE orders SET payment_status='refunded'");
check(shop_command_apply($t,$old,$source,$now)==='conflict','changed payment label blocks stale command');
$t=site(); $firstCommand=cmd($t,103); $secondCommand=cmd($t,104);
check(shop_command_apply($t,$firstCommand,$source,$now)==='applied' && shop_command_apply($t,$secondCommand,$source,$now)==='conflict' && (int)$t->query('SELECT COUNT(*) FROM order_events')->fetchColumn()===2,'different command based on stale snapshot cannot execute twice');
$t=site(); $receiptFailure=cmd($t,105); $t->exec("CREATE TRIGGER fail_receipt BEFORE UPDATE ON shop_crm_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { shop_command_apply($t,$receiptFailure,$source,$now); check(false,'receipt failure'); } catch (PDOException $e) {}
check($t->query('SELECT order_status FROM orders')->fetchColumn()==='packed' && (int)$t->query('SELECT COUNT(*) FROM order_events')->fetchColumn()===1 && (int)$t->query('SELECT COUNT(*) FROM shop_crm_command_receipts')->fetchColumn()===0,'receipt failure rolls back status and event');
$t=site(); $old=cmd($t,102);
check(shop_command_apply($t,$old,$source,$now+901)==='expired' && $t->query('SELECT order_status FROM orders')->fetchColumn()==='packed','expired command cannot execute');
