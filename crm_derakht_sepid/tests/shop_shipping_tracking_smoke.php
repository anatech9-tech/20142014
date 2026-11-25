<?php
/** Isolated SQLite only: does not exercise MySQL row locks, real CSRF or HTTP. */
class ShippingTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_shipping_tracking.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new ShippingTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_shipping_tracking(replica_id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_shipping_tracking_events(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,replica_revision INTEGER,action TEXT,actor_id INTEGER,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$payload=json_encode(['id'=>10,'order_status'=>'packed','payment_status'=>'paid']);
$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,?,?,?)')->execute([str_repeat('a',32),10,7,$payload]);
denied(fn()=>shop_shipping_apply($pdo,1,2,false,7,0,'save','Post','AB123'),'non-admin denied');
denied(fn()=>shop_shipping_apply($pdo,1,2,true,7,0,'save','Post','AB123'),'forged admin denied');
denied(fn()=>shop_shipping_apply($pdo,1,3,true,7,0,'save','Post','AB123'),'inactive admin denied');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,6,0,'save','Post','AB123'),'stale order revision denied');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,0,'save','<script>','AB123'),'HTML carrier rejected');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,0,'save','Post','https://evil.test'),'URL tracking rejected');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,0,'save','Post','12'),'short tracking rejected');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,0,'clear'),'cannot clear nonexistent data');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'pending'])]);
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,0,'save','Post','AB123'),'early status rejected');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([$payload]);
$pdo->exec("CREATE TRIGGER fail_shipping BEFORE INSERT ON crm_shop_shipping_tracking_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { shop_shipping_apply($pdo,1,1,true,7,0,'save','Post','AB123'); check(false,'audit failure'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_tracking')->fetchColumn()===0,'audit failure rolls back initial record');
$pdo->exec('DROP TRIGGER fail_shipping'); shop_shipping_apply($pdo,1,1,true,7,0,'save','پست','AB-123');
$row=$pdo->query('SELECT * FROM crm_shop_shipping_tracking')->fetch(PDO::FETCH_ASSOC);
check((int)$row['version']===1 && $row['carrier']==='پست' && $row['tracking_code']==='AB-123','manual tracking stored in CRM');
check($pdo->query('SELECT payload FROM crm_shop_orders')->fetchColumn()===$payload,'order snapshot and payment unchanged');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,0,'save','Post','AB999'),'stale tracking version denied');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,1,'save','پست','AB-123'),'unchanged value denied');
$pdo->exec("CREATE TRIGGER fail_update BEFORE INSERT ON crm_shop_shipping_tracking_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { shop_shipping_apply($pdo,1,1,true,7,1,'save','Post','AB-999'); check(false,'update audit failure'); } catch (PDOException $e) {}
check($pdo->query('SELECT tracking_code FROM crm_shop_shipping_tracking')->fetchColumn()==='AB-123' && (int)$pdo->query('SELECT version FROM crm_shop_shipping_tracking')->fetchColumn()===1,'update rolls back if audit fails');
$pdo->exec('DROP TRIGGER fail_update'); shop_shipping_apply($pdo,1,1,true,7,1,'save','Post','AB-999');
$pdo->prepare('UPDATE crm_shop_orders SET revision=8,payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'shipped','payment_status'=>'paid'])]);
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,2,'clear'),'stale order form cannot clear');
shop_shipping_apply($pdo,1,1,true,8,2,'clear');
$row=$pdo->query('SELECT * FROM crm_shop_shipping_tracking')->fetch(PDO::FETCH_ASSOC);
check((int)$row['version']===3 && $row['carrier']===null && $row['tracking_code']===null,'clear removes current code but retains version tombstone');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,8,0,'save','Post','AB123'),'old creation form cannot overwrite tombstone');
shop_shipping_apply($pdo,1,1,true,8,3,'save','Post','AB123');
$pdo->exec("UPDATE crm_shop_orders SET source_id='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' WHERE id=1");
denied(fn()=>shop_shipping_apply($pdo,1,1,true,8,4,'clear'),'replica reuse with different source refused');
$pdo->prepare('UPDATE crm_shop_orders SET source_id=?,shop_order_id=11,payload=? WHERE id=1')->execute([str_repeat('a',32),json_encode(['id'=>11,'order_status'=>'shipped'])]);
denied(fn()=>shop_shipping_apply($pdo,1,1,true,8,4,'clear'),'replica reuse with different order refused');
$events=$pdo->query('SELECT * FROM crm_shop_shipping_tracking_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(count($events)===4 && $events[2]['action']==='clear' && !str_contains(json_encode($events),'AB123'),'history records changes without retaining tracking code');
