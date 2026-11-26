<?php
/** A separate PHP process proves delivery is off by default on both sides. */
class DeliveryDisabledPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_crm_command_receipts','INSERT OR IGNORE INTO shop_crm_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('k',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/cmd');
require_once __DIR__.'/../../website/includes/shop/CrmOrderCommands.php'; require_once __DIR__.'/../includes/shop_order_requests.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!shop_delivery_command_enabled() && !crm_shop_delivery_command_enabled(),'delivery disabled by default independently of ordinary command bridge');
$pdo=new DeliveryDisabledPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE shop_crm_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,shop_order_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$c=['type'=>'shop_delivery_v1','command_id'=>str_repeat('b',32),'source_id'=>SHOP_CRM_SOURCE_ID,'order_id'=>1,'expected_event_id'=>1,'expected_status'=>'packed','expected_payment'=>'pending','target_status'=>'shipped','snapshot_hash'=>str_repeat('d',64),'issued_at'=>1700000000,'expires_at'=>1700000900];
check(shop_command_validate($c,SHOP_CRM_SOURCE_ID),'delivery protocol can be recognized without enabling execution');
try { shop_command_apply($pdo,$c,SHOP_CRM_SOURCE_ID,1700000000); check(false,'disabled receiver'); } catch (RuntimeException $e) { check(true,'disabled receiver refuses execution'); }
check((int)$pdo->query('SELECT COUNT(*) FROM shop_crm_command_receipts')->fetchColumn()===0,'disabled receiver rolls back new receipt');
$hash=hash('sha256',json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$pdo->prepare("INSERT INTO shop_crm_command_receipts VALUES(?,?,1,'applied','2025-01-01','2025-01-01')")->execute([$c['command_id'],$hash]);
check(shop_command_apply($pdo,$c,SHOP_CRM_SOURCE_ID,1700000000)==='applied','prior receipt remains retrievable when disabled');
$crm=new DeliveryDisabledPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $sql) $crm->exec($sql);
$crm->exec("INSERT INTO users VALUES(1,'active','admin')");
$crm->prepare('INSERT INTO crm_shop_orders VALUES(1,?,1,1,?)')->execute([SHOP_CRM_SOURCE_ID,json_encode(['id'=>1,'order_event_id'=>1,'order_status'=>'packed','payment_status'=>'pending'])]);
$crm->prepare("INSERT INTO crm_shop_status_requests VALUES(1,1,?,1,1,'packed','pending','shipped','open',1,'2025-01-01',NULL,NULL)")->execute([SHOP_CRM_SOURCE_ID]);
try { crm_shop_command_enqueue($crm,1,1,1,true,1700000000,SHOP_CRM_SOURCE_ID,false,true); check(false,'disabled sender'); } catch (DomainException $e) { check(true,'disabled sender cannot enqueue delivery'); }
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_order_commands')->fetchColumn()===0,'disabled sender leaves no queued delivery');
