<?php
/** SQLite fixture; no proof of MySQL locking, isolation or actual transport. */
class CrossShippingPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_shipping_tracking.php';
require_once __DIR__.'/../includes/shop_shipping_packages.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,$label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new CrossShippingPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_shipping_tracking(replica_id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_shipping_tracking_events(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,replica_revision INTEGER,action TEXT,actor_id INTEGER,created_at TEXT)',
 'CREATE TABLE crm_shop_shipping_packages(package_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_shipping_package_events(id INTEGER PRIMARY KEY AUTOINCREMENT,package_id TEXT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,replica_revision INTEGER,action TEXT,actor_id INTEGER,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$source=str_repeat('a',32); $one=str_repeat('1',32); $two=str_repeat('2',32); $three=str_repeat('3',32);
$pdo->exec("INSERT INTO users VALUES (1,'active','admin')");
$pdo->prepare('INSERT INTO crm_shop_orders VALUES(1,?,10,7,?)')->execute([$source,json_encode(['id'=>10,'order_status'=>'packed'])]);
shop_shipping_apply($pdo,1,1,true,7,0,'save','Post','MAIN-1');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$one,0,'create','Other','MAIN-1'),'package creation cannot reuse primary code, even for another carrier');
shop_packages_apply($pdo,1,1,true,7,$one,0,'create','Post','PK-1');
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,1,'save','Post','PK-1'),'primary edit cannot reuse package code');
shop_packages_apply($pdo,1,1,true,7,$two,0,'create','Post','PK-2');
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$two,1,'save','Post','MAIN-1'),'package edit cannot reuse primary code');
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_tracking_events')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_package_events')->fetchColumn()===2,'rejected operations have no events');
// Legacy duplicate: show without automatic mutation; explicit clear resolves it.
$pdo->prepare('UPDATE crm_shop_shipping_packages SET tracking_code=? WHERE package_id=?')->execute(['MAIN-1',$one]);
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,1,'save','Other','MAIN-1'),'historical duplicate blocks primary save');
shop_packages_apply($pdo,1,1,true,7,$one,1,'clear');
shop_shipping_apply($pdo,1,1,true,7,1,'save','Other','MAIN-1');
check($pdo->query('SELECT carrier FROM crm_shop_shipping_tracking')->fetchColumn()==='Other','clear resolves legacy duplicate without implicit deletion');
$pdo->prepare('UPDATE crm_shop_shipping_tracking SET source_id=?')->execute([str_repeat('b',32)]);
denied(fn()=>shop_packages_apply($pdo,1,1,true,7,$three,0,'create','Post','THREE'),'foreign primary identity blocks package save');
shop_packages_apply($pdo,1,1,true,7,$two,1,'clear');
check($pdo->query("SELECT tracking_code FROM crm_shop_shipping_packages WHERE package_id='$two'")->fetchColumn()===null,'clear remains available despite foreign primary');
$pdo->prepare('UPDATE crm_shop_shipping_tracking SET source_id=?')->execute([$source]);
$pdo->prepare('UPDATE crm_shop_shipping_packages SET source_id=? WHERE package_id=?')->execute([str_repeat('b',32),$one]);
denied(fn()=>shop_shipping_apply($pdo,1,1,true,7,2,'save','Post','DIFFERENT'),'even cleared foreign package identity blocks primary save');
shop_shipping_apply($pdo,1,1,true,7,2,'clear');
check($pdo->query('SELECT tracking_code FROM crm_shop_shipping_tracking')->fetchColumn()===null,'primary clear remains available despite foreign package');
$pdo->exec('DROP TABLE crm_shop_shipping_packages');
try { shop_shipping_apply($pdo,1,1,true,7,3,'save','Post','NEW-1'); check(false,'missing counterpart'); } catch (PDOException $e) { check(!$pdo->inTransaction(),'missing package table fails closed and rolls back'); }
check($pdo->query('SELECT tracking_code FROM crm_shop_shipping_tracking')->fetchColumn()===null,'failed cross-check does not modify code');
$pdo->exec('CREATE TABLE crm_shop_shipping_packages(package_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,version INTEGER,carrier TEXT,tracking_code TEXT,replica_revision INTEGER,updated_by INTEGER,updated_at TEXT)');
$pdo->exec('DROP TABLE crm_shop_shipping_tracking');
try { shop_packages_apply($pdo,1,1,true,7,$three,0,'create','Post','NEW-2'); check(false,'missing primary table'); } catch (PDOException $e) { check(!$pdo->inTransaction(),'missing primary table fails closed and rolls back'); }
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_shipping_packages')->fetchColumn()===0,'failed package cross-check does not create package');
check(!str_contains(json_encode($pdo->query('SELECT * FROM crm_shop_shipping_tracking_events')->fetchAll(PDO::FETCH_ASSOC)),'MAIN-1'),'history does not retain sensitive codes');
