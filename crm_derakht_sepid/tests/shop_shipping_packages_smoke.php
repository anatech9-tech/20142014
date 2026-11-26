<?php
/** SQLite fixture only; does not prove MySQL locking or real HTTP/CSRF. */
class PackageTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_shipping_packages.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new PackageTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_shipping_packages(package_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_shipping_package_events(id INTEGER PRIMARY KEY AUTOINCREMENT,package_id TEXT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,replica_revision INTEGER,action TEXT,actor_id INTEGER,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$source=str_repeat('a',32); $id1=str_repeat('1',32); $id2=str_repeat('2',32); $id3=str_repeat('3',32);
$payload=json_encode(['id'=>10,'order_status'=>'packed','payment_status'=>'paid']);
$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,?,?,?)')->execute([$source,10,7,$payload]);
denied(fn()=>shop_packages_apply($pdo,1,2,true,7,$id1,0,'create','Post','AB123'),'forged admin rejected');
denied(fn()=>shop_packages_apply($pdo,1,3,true,7,$id1,0,'create','Post','AB123'),'inactive admin rejected');
denied(fn()=>shop_packages_apply($pdo,1,1,false,7,$id1,0,'create','Post','AB123'),'non-admin denied');
denied(fn()=>shop_packages_apply($pdo,1,1,true,6,$id1,0,'create','Post','AB123'),'stale order revision rejected');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','<script>','AB123'),'invalid carrier rejected');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','Post','https://evil.test'),'URL rejected');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'pending'])]);
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','Post','AB123'),'early order status rejected');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([$payload]);
$pdo->exec("CREATE TRIGGER fail_package BEFORE INSERT ON crm_shop_shipping_package_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','Post','AB123'); check(false,'audit failure throws'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_packages')->fetchColumn()===0,'audit failure rolls back creation');
$pdo->exec('DROP TRIGGER fail_package');
shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','پست','AB123');
shop_packages_apply($pdo,1,1,true,7,$id2,0,'create','Other','ZZ999');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_packages')->fetchColumn()===2,'two independent package notes');
check($pdo->query('SELECT payload FROM crm_shop_orders')->fetchColumn()===$payload,'order and payment snapshot unchanged');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','Post','AB123'),'resubmitted creation cannot duplicate');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id3,0,'create','Post','AB123'),'duplicate active tracking rejected');
$pdo->prepare('INSERT INTO crm_shop_orders VALUES(2,?,?,?,?)')->execute([$source,11,7,json_encode(['id'=>11,'order_status'=>'packed'])]);
denied(fn()=>shop_packages_apply($pdo,2,1,true,7,$id2,0,'create','Post','OTHER'),'package identifier cannot be reused on another order');

denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,0,'save','Post','XY123'),'old version rejected');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,1,'save','Post','ZZ999'),'editing to existing tracking rejected');
$pdo->exec("CREATE TRIGGER fail_edit BEFORE INSERT ON crm_shop_shipping_package_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { shop_packages_apply($pdo,1,1,true,7,$id1,1,'save','Post','XY123'); check(false,'audit failure throws'); } catch (PDOException $e) {}
check($pdo->query("SELECT tracking_code FROM crm_shop_shipping_packages WHERE package_id='$id1'")->fetchColumn()==='AB123','edit rolled back when audit fails');
$pdo->exec('DROP TRIGGER fail_edit');
shop_packages_apply($pdo,1,1,true,7,$id1,1,'save','Post','XY123');
shop_packages_apply($pdo,1,1,true,7,$id1,2,'clear');
check($pdo->query("SELECT tracking_code FROM crm_shop_shipping_packages WHERE package_id='$id1'")->fetchColumn()===null,'clear removes current code');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,2,'save','Post','XY123'),'old edit cannot restore cleared code');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id1,0,'create','Post','XY123'),'cleared package id cannot be reused');
$pdo->prepare('UPDATE crm_shop_orders SET source_id=? WHERE id=1')->execute([str_repeat('b',32)]);
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id2,1,'clear'),'changed order identity blocks all actions');
$pdo->prepare('UPDATE crm_shop_orders SET source_id=? WHERE id=1')->execute([$source]);
for ($i=0;$i<19;$i++) shop_packages_apply($pdo,1,1,true,7,str_pad(dechex(100+$i),32,'0',STR_PAD_LEFT),0,'create','Post','CODE'.$i);
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id3,0,'create','Post','LAST'),'limit of twenty active packages enforced');
$pdo->prepare('UPDATE crm_shop_orders SET revision=8,payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'pending'])]);
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$id2,1,'clear'),'stale revision cannot clear');
shop_packages_apply($pdo,1,1,true,8,$id2,1,'clear');
check($pdo->query("SELECT tracking_code FROM crm_shop_shipping_packages WHERE package_id='$id2'")->fetchColumn()===null,'clear allowed after state changes with current revision');
$st=$pdo->prepare('INSERT INTO crm_shop_shipping_packages VALUES(?,1,?,10,2,NULL,NULL,8,1,?)');
for ($i=0;$i<79;$i++) $st->execute([str_pad(dechex(1000+$i),32,'0',STR_PAD_LEFT),$source,'2025-01-01']);
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([$payload]);
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_packages')->fetchColumn()===100,'tombstone fixture reaches lifetime cap');
denied(fn()=>shop_packages_apply($pdo,1,1,true,8,$id3,0,'create','Post','LAST'),'lifetime cap prevents unbounded tombstones');
$events=$pdo->query('SELECT * FROM crm_shop_shipping_package_events')->fetchAll(PDO::FETCH_ASSOC);
check(!str_contains(json_encode($events),'AB123') && !str_contains(json_encode($events),'XY123'),'events do not retain tracking codes');
