<?php
/** SQLite fixture only: does not prove MySQL locks, real sessions, CSRF or HTTP. */
class ReturnTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_return_requests.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new ReturnTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_return_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,observed_status TEXT,reason TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_return_request_events(id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'
] as $q) $pdo->exec($q);
$pdo->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$payload=json_encode(['id'=>10,'order_status'=>'shipped','payment_status'=>'paid']);
$st=$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,?,?,?)'); $st->execute([str_repeat('a',32),10,7,$payload]);
denied(fn()=>shop_return_request_create($pdo,1,2,false,7,'damaged'),'non-admin denied');
denied(fn()=>shop_return_request_create($pdo,1,2,true,7,'damaged'),'forged admin role denied');
denied(fn()=>shop_return_request_create($pdo,1,3,true,7,'damaged'),'inactive admin denied');
denied(fn()=>shop_return_request_create($pdo,1,1,true,6,'damaged'),'stale replica denied');
denied(fn()=>shop_return_request_create($pdo,1,1,true,7,'refund'),'arbitrary reason denied');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'packed'])]);
denied(fn()=>shop_return_request_create($pdo,1,1,true,7,'damaged'),'pre-shipment request denied');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([json_encode(['id'=>11,'order_status'=>'shipped'])]);
denied(fn()=>shop_return_request_create($pdo,1,1,true,7,'damaged'),'mismatched shop order identity denied');
$pdo->prepare('UPDATE crm_shop_orders SET payload=? WHERE id=1')->execute([$payload]);
$pdo->exec("CREATE TRIGGER fail_create BEFORE INSERT ON crm_shop_return_request_events BEGIN SELECT RAISE(ABORT,'failure'); END");
try { shop_return_request_create($pdo,1,1,true,7,'damaged'); check(false,'audit create failure throws'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_return_requests')->fetchColumn()===0,'create and audit roll back together');
$pdo->exec('DROP TRIGGER fail_create'); $id=shop_return_request_create($pdo,1,1,true,7,'damaged');
$r=$pdo->query('SELECT * FROM crm_shop_return_requests')->fetch(PDO::FETCH_ASSOC);
check($r['state']==='open' && $r['reason']==='damaged' && (int)$r['replica_revision']===7 && $r['observed_status']==='shipped','ticket records observed state');
check($pdo->query('SELECT payload FROM crm_shop_orders')->fetchColumn()===$payload,'no order status or payment modified');
denied(fn()=>shop_return_request_create($pdo,1,1,true,7,'other'),'second open request denied');
denied(fn()=>shop_return_request_close($pdo,1,$id,2,true),'non-admin cannot close');
denied(fn()=>shop_return_request_close($pdo,2,$id,1,true),'wrong order cannot close');
$pdo->prepare('UPDATE crm_shop_orders SET source_id=? WHERE id=1')->execute([str_repeat('b',32)]);
denied(fn()=>shop_return_request_close($pdo,1,$id,1,true),'changed source cannot close old ticket');
$pdo->prepare('UPDATE crm_shop_orders SET source_id=?,shop_order_id=11 WHERE id=1')->execute([str_repeat('a',32)]);
denied(fn()=>shop_return_request_close($pdo,1,$id,1,true),'changed order id cannot close old ticket');
$pdo->prepare('UPDATE crm_shop_orders SET shop_order_id=10,payload=? WHERE id=1')->execute([json_encode(['id'=>11,'order_status'=>'shipped'])]);
denied(fn()=>shop_return_request_close($pdo,1,$id,1,true),'mismatched payload cannot close ticket');
check($pdo->query('SELECT state FROM crm_shop_return_requests')->fetchColumn()==='open' && (int)$pdo->query('SELECT COUNT(*) FROM crm_shop_return_request_events')->fetchColumn()===1,'identity failures cause no mutation or event');
$pdo->prepare('UPDATE crm_shop_orders SET revision=8,payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'delivered','payment_status'=>'paid'])]);
check($pdo->query('SELECT state FROM crm_shop_return_requests')->fetchColumn()==='open','later snapshot does not auto-complete request');
$pdo->exec("CREATE TRIGGER fail_close BEFORE INSERT ON crm_shop_return_request_events BEGIN SELECT RAISE(ABORT,'failure'); END");
try { shop_return_request_close($pdo,1,$id,1,true); check(false,'audit close failure throws'); } catch (PDOException $e) {}
check($pdo->query('SELECT state FROM crm_shop_return_requests')->fetchColumn()==='open','close audit failure rolls back');
$pdo->exec('DROP TRIGGER fail_close'); shop_return_request_close($pdo,1,$id,1,true);
check($pdo->query('SELECT state FROM crm_shop_return_requests')->fetchColumn()==='closed' && (int)$pdo->query('SELECT COUNT(*) FROM crm_shop_return_request_events')->fetchColumn()===2,'closing and event persisted');
denied(fn()=>shop_return_request_close($pdo,1,$id,1,true),'duplicate closure denied');
$id2=shop_return_request_create($pdo,1,1,true,8,'wrong_item'); check($id2>$id,'new request allowed after closure');
