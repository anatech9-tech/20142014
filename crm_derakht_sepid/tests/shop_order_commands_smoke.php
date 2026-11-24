<?php
/** Two isolated SQLite DBs and simulated network. No real MySQL locks, TLS, HTTP or sessions. */
class CommandTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) {
            $sql=str_replace('INSERT INTO shop_crm_command_receipts','INSERT OR IGNORE INTO shop_crm_command_receipts',$sql);
            $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('k',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/shop/api/crm_order_command.php');
require_once __DIR__.'/../../website/includes/shop/CrmOrderSync.php';
require_once __DIR__.'/../../website/includes/shop/CrmOrderCommands.php';
require_once __DIR__.'/../includes/shop_order_requests.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $fn,string $label) { try { $fn(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$source=SHOP_CRM_SOURCE_ID; $key=SHOP_CRM_COMMAND_KEY; $now=1700000000;
check(shop_command_configured() && crm_shop_command_configured(),'explicitly enabled with independent key');
$sig=hash_hmac('sha256','shop-command-v1.'.$now.'.body',$key);
check(shop_command_signature_valid($key,(string)$now,$sig,'body',$now),'valid signature');
check(!shop_command_signature_valid($key,(string)$now,$sig,'body altered',$now) && !shop_command_signature_valid($key,(string)$now,$sig,'body',$now+301) && !shop_command_signature_valid(str_repeat('b',32),(string)$now,$sig,'body',$now),'modified payload, expired signature, wrong key rejected');
$site=new CommandTestPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE orders(id INTEGER PRIMARY KEY,order_no TEXT,customer_id INTEGER,customer_name TEXT,customer_phone TEXT,customer_email TEXT,subtotal_toman INTEGER,discount_toman INTEGER,shipping_fee INTEGER,total_toman INTEGER,order_status TEXT,payment_status TEXT,created_at TEXT,status_note TEXT)',
 'CREATE TABLE order_items(id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,product_name TEXT,qty INTEGER,price_toman INTEGER,total_toman INTEGER)',
 'CREATE TABLE order_events(id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,status TEXT,note TEXT)',
 'CREATE TABLE shop_crm_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,shop_order_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO orders VALUES(1,'W-1',NULL,'Test','09120000000','',100,0,0,100,'pending','pending','2025-01-01 10:00:00','')");
$site->exec("INSERT INTO order_items VALUES(1,1,7,'Item',1,100,100); INSERT INTO order_events VALUES(1,1,'pending','initial')");
function snapshot(PDO $pdo): array {
    $o=$pdo->query('SELECT * FROM orders WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $o['order_event_id']=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM order_events WHERE order_id=1')->fetchColumn();
    return shop_crm_snapshot($o,$pdo->query('SELECT product_id,product_name,qty,price_toman,total_toman FROM order_items WHERE order_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}
$crm=new CommandTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,payload TEXT)',
 'CREATE TABLE crm_shop_status_requests(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,source_id TEXT,shop_order_id INTEGER,replica_revision INTEGER,expected_status TEXT,expected_payment TEXT,requested_status TEXT,state TEXT,created_by INTEGER,created_at TEXT,closed_by INTEGER,closed_at TEXT)',
 'CREATE TABLE crm_shop_status_request_events(id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)',
 'CREATE TABLE crm_shop_order_commands(command_id TEXT PRIMARY KEY,request_id INTEGER UNIQUE,replica_id INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)'
] as $sql) $crm->exec($sql);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user')");
$st=$crm->prepare('INSERT INTO crm_shop_orders VALUES(1,?,1,1,?)'); $st->execute([$source,json_encode(snapshot($site),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid=shop_status_request_create($crm,1,1,true,1,'confirmed');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,2,true,$now,$source),'forged admin cannot enqueue');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,1,true,$now,str_repeat('b',32)),'different source denied');
$crm->exec("CREATE TRIGGER fail_queue_event BEFORE INSERT ON crm_shop_status_request_events WHEN NEW.action='send' BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source); check(false,'enqueue event failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_order_commands')->fetchColumn()===0,'enqueue audit failure rolls back');
$crm->exec('DROP TRIGGER fail_queue_event');
$id=crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source);
$row=$crm->query('SELECT * FROM crm_shop_order_commands')->fetch(PDO::FETCH_ASSOC); $cmd=json_decode($row['payload'],true);
check(shop_command_validate($cmd,$source) && strpos($row['payload'],'09120000000')===false,'matching protocol without customer data');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid,1,true,$now,$source),'duplicate request enqueue denied');
denied(fn()=>shop_status_request_close($crm,1,$rid,1,true),'queued request cannot be closed');
$bad=$cmd; $bad['target_status']='cancelled'; check(!shop_command_validate($bad,$source),'cancel is never an executable command');
$bad=$cmd; $bad['target_status']='shipped'; check(!shop_command_validate($bad,$source),'shipment transition not executable');
$bad=$cmd; $bad['expected_event_id']=0; check(!shop_command_validate($bad,$source),'zero source version denied');
$bad=$cmd; $bad['extra']='x'; check(!shop_command_validate($bad,$source),'unexpected field rejected');
$site->exec("CREATE TRIGGER fail_order_event BEFORE INSERT ON order_events BEGIN SELECT RAISE(ABORT,'event'); END");
try { shop_command_apply($site,$cmd,$source,$now); check(false,'source event failure'); } catch (PDOException $e) {}
check($site->query('SELECT order_status FROM orders')->fetchColumn()==='pending' && (int)$site->query('SELECT COUNT(*) FROM shop_crm_command_receipts')->fetchColumn()===0,'source failure rolls back order and receipt');
$site->exec('DROP TRIGGER fail_order_event');
// First call executes but the response is lost. The retry must return the durable receipt, not apply again.
$first=true;
$sender=function($body) use ($site,$source,$now,&$first) {
 $data=json_decode($body,true); $result=shop_command_apply($site,$data,$source,$now);
 if ($first) { $first=false; return [0,null]; }
 return [200,['ok'=>true,'command_id'=>$data['command_id'],'result'=>$result]];
};
$a=crm_shop_command_pump($crm,$sender,$now);
check($a['failed']===1 && $site->query('SELECT order_status FROM orders')->fetchColumn()==='confirmed' && $crm->query('SELECT state FROM crm_shop_order_commands')->fetchColumn()==='queued','lost acknowledgement leaves result unknown in CRM');
$a=crm_shop_command_pump($crm,$sender,$now+61);
check($a['settled']===1 && $crm->query('SELECT state FROM crm_shop_order_commands')->fetchColumn()==='applied' && (int)$site->query('SELECT COUNT(*) FROM order_events')->fetchColumn()===2,'retry reports prior result without duplicate event');
check(shop_command_apply($site,$cmd,$source,$now+5000)==='applied','duplicate returns receipt even after expiry');
$bad=$cmd; $bad['snapshot_hash']=str_repeat('f',64);
denied(fn()=>shop_command_apply($site,$bad,$source,$now),'same id with altered payload rejected');
check(!crm_shop_command_settle($crm,$row,['ok'=>true,'command_id'=>str_repeat('b',32),'result'=>'applied'],200,$now),'wrong acknowledgement rejected');
shop_status_request_close($crm,1,$rid,1,true);
check($crm->query('SELECT state FROM crm_shop_status_requests')->fetchColumn()==='closed','settled request can be closed');
// New version, then mutate the source before executing: the queued command must conflict.
$st=$crm->prepare('UPDATE crm_shop_orders SET revision=2,payload=? WHERE id=1'); $st->execute([json_encode(snapshot($site),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid2=shop_status_request_create($crm,1,1,true,2,'packed'); $id2=crm_shop_command_enqueue($crm,1,$rid2,1,true,$now+100,$source);
$st=$crm->prepare('SELECT * FROM crm_shop_order_commands WHERE command_id=?'); $st->execute([$id2]); $row2=$st->fetch(PDO::FETCH_ASSOC); $cmd2=json_decode($row2['payload'],true);
$site->exec("UPDATE orders SET total_toman=101 WHERE id=1");
check(shop_command_apply($site,$cmd2,$source,$now+100)==='conflict' && $site->query('SELECT order_status FROM orders')->fetchColumn()==='confirmed','changed snapshot rejected without mutation');
check(shop_command_apply($site,$cmd2,$source,$now+101)==='conflict','conflict result is durable');
check(crm_shop_command_settle($crm,$row2,['ok'=>true,'command_id'=>$id2,'result'=>'conflict'],200,$now+100),'conflict acknowledged');
shop_status_request_close($crm,1,$rid2,1,true);
$st=$crm->prepare('UPDATE crm_shop_orders SET revision=3,payload=? WHERE id=1'); $st->execute([json_encode(snapshot($site),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid3=shop_status_request_create($crm,1,1,true,3,'packed'); $id3=crm_shop_command_enqueue($crm,1,$rid3,1,true,$now+200,$source);
$st=$crm->prepare('SELECT * FROM crm_shop_order_commands WHERE command_id=?'); $st->execute([$id3]); $row3=$st->fetch(PDO::FETCH_ASSOC); $cmd3=json_decode($row3['payload'],true);
check(shop_command_apply($site,$cmd3,$source,$now+1101)==='expired' && $site->query('SELECT order_status FROM orders')->fetchColumn()==='confirmed','expired command does not execute');
$crm->exec("CREATE TRIGGER fail_settle_event BEFORE INSERT ON crm_shop_status_request_events WHEN NEW.action='expired' BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_shop_command_settle($crm,$row3,['ok'=>true,'command_id'=>$id3,'result'=>'expired'],200,$now+1101); check(false,'settle event failure'); } catch (PDOException $e) {}
check($crm->query("SELECT state FROM crm_shop_order_commands WHERE command_id='$id3'")->fetchColumn()==='queued','settlement audit failure rolls back');
$crm->exec('DROP TRIGGER fail_settle_event');
check(crm_shop_command_settle($crm,$row3,['ok'=>true,'command_id'=>$id3,'result'=>'expired'],200,$now+1101),'expired result acknowledged after retry');
shop_status_request_close($crm,1,$rid3,1,true);
$rid4=shop_status_request_create($crm,1,1,true,3,'packed'); $id4=crm_shop_command_enqueue($crm,1,$rid4,1,true,$now+1200,$source);
$st=$crm->prepare('SELECT * FROM crm_shop_order_commands WHERE command_id=?'); $st->execute([$id4]); $row4=$st->fetch(PDO::FETCH_ASSOC); $cmd4=json_decode($row4['payload'],true);
check(shop_command_apply($site,$cmd4,$source,$now+1201)==='applied' && $site->query('SELECT order_status FROM orders')->fetchColumn()==='packed','confirmed to packed applies with current snapshot');
check(crm_shop_command_settle($crm,$row4,['ok'=>true,'command_id'=>$id4,'result'=>'applied'],200,$now+1201),'packed result acknowledged');
shop_status_request_close($crm,1,$rid4,1,true);
$st=$crm->prepare('UPDATE crm_shop_orders SET revision=4,payload=? WHERE id=1'); $st->execute([json_encode(snapshot($site),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$rid5=shop_status_request_create($crm,1,1,true,4,'shipped');
denied(fn()=>crm_shop_command_enqueue($crm,1,$rid5,1,true,$now+1300,$source),'shipment request remains manual only');
shop_status_request_close($crm,1,$rid5,1,true);
