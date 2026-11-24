<?php
/** Isolated SQLite test of internal handoffs; not a real MySQL lock or browser test. */
class RequestTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_order_requests.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new RequestTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
    'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,full_name TEXT)',
    'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
    'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
    'CREATE TABLE crm_shop_status_request_events(id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
    'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES (1,'active','admin','admin'),(2,'active','user','user'),(3,'inactive','admin','inactive')");
$payload=json_encode(['id'=>10,'order_status'=>'pending','payment_status'=>'paid']);
$st=$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,?,?,?)'); $st->execute([str_repeat('a',32),10,7,$payload]);
denied(fn()=>shop_status_request_create($pdo,1,2,false,7,'confirmed'),'non-admin denied');
denied(fn()=>shop_status_request_create($pdo,1,2,true,7,'confirmed'),'forged admin role denied');
denied(fn()=>shop_status_request_create($pdo,1,3,true,7,'confirmed'),'inactive admin denied');
denied(fn()=>shop_status_request_create($pdo,1,1,true,6,'confirmed'),'stale replica version denied');
denied(fn()=>shop_status_request_create($pdo,1,1,true,7,'shipped'),'skipping normal steps denied');
denied(fn()=>shop_status_request_create($pdo,1,1,true,7,'paid'),'payment changes denied');
$id=shop_status_request_create($pdo,1,1,true,7,'confirmed');
$r=$pdo->query('SELECT * FROM crm_shop_status_requests')->fetch(PDO::FETCH_ASSOC);
check($id>0 && $r['state']==='open' && $r['expected_status']==='pending' && $r['expected_payment']==='paid' && $r['requested_status']==='confirmed','request captures observed version and statuses');
check($pdo->query('SELECT payload FROM crm_shop_orders')->fetchColumn()===$payload,'request does not mutate replica or shop status');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_status_request_events')->fetchColumn()===1,'request creation audited');
denied(fn()=>shop_status_request_create($pdo,1,1,true,7,'cancelled'),'second open request denied');
denied(fn()=>shop_status_request_close($pdo,1,$id,2,true),'non-admin cannot close');
$pdo->prepare('UPDATE crm_shop_orders SET revision=8,payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'confirmed','payment_status'=>'paid'])]);
check($pdo->query('SELECT state FROM crm_shop_status_requests')->fetchColumn()==='open','new replica never auto-confirms request');
$pdo->exec("CREATE TRIGGER fail_close BEFORE INSERT ON crm_shop_status_request_events BEGIN SELECT RAISE(ABORT,'failure'); END");
try { shop_status_request_close($pdo,1,$id,1,true); check(false,'close failure'); } catch (PDOException $e) {}
check($pdo->query('SELECT state FROM crm_shop_status_requests')->fetchColumn()==='open','close audit failure rolls back');
$pdo->exec('DROP TRIGGER fail_close'); shop_status_request_close($pdo,1,$id,1,true);
check($pdo->query('SELECT state FROM crm_shop_status_requests')->fetchColumn()==='closed' && (int)$pdo->query('SELECT COUNT(*) FROM crm_shop_status_request_events')->fetchColumn()===2,'manual closure and audit');
denied(fn()=>shop_status_request_close($pdo,1,$id,1,true),'duplicate closure denied');
$pdo->exec("CREATE TRIGGER fail_create BEFORE INSERT ON crm_shop_status_request_events BEGIN SELECT RAISE(ABORT,'failure'); END");
try { shop_status_request_create($pdo,1,1,true,8,'packed'); check(false,'create failure'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_status_requests')->fetchColumn()===1,'create audit failure rolls back');
$pdo->exec('DROP TRIGGER fail_create');
$id2=shop_status_request_create($pdo,1,1,true,8,'cancelled');
check($id2!==$id,'after closing, a new request may be created');
shop_status_request_close($pdo,1,$id2,1,true);
$pdo->prepare('UPDATE crm_shop_orders SET revision=9,payload=? WHERE id=1')->execute([json_encode(['id'=>10,'order_status'=>'shipped','payment_status'=>'paid'])]);
denied(fn()=>shop_status_request_create($pdo,1,1,true,9,'cancelled'),'shipped cancellation not offered');
$id3=shop_status_request_create($pdo,1,1,true,9,'delivered');
check($id3>0,'delivered may be requested after shipped');
check(shop_status_request_targets('cancelled')===[] && shop_status_request_targets('delivered')===[],'terminal statuses have no next request');
