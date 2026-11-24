<?php
/** SQLite harness: transaction/queue logic only; MySQL locking and HTTP require host tests. */
class SyncTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
        $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,shop_order_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
        return parent::prepare($sql,$options);
    }
}
require_once __DIR__.'/../includes/shop/CrmOrderSync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!shop_crm_configured(),'disabled by default');
$pdo=new SyncTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
    'CREATE TABLE orders (id INTEGER PRIMARY KEY,order_no TEXT,customer_id INTEGER,customer_name TEXT,customer_phone TEXT,customer_email TEXT,subtotal_toman INTEGER,discount_toman INTEGER,shipping_fee INTEGER,total_toman INTEGER,order_status TEXT,payment_status TEXT,created_at TEXT,address TEXT,success_token TEXT,notes TEXT)',
    'CREATE TABLE order_items (id INTEGER PRIMARY KEY,order_id INTEGER,product_id INTEGER,product_name TEXT,qty INTEGER,price_toman INTEGER,total_toman INTEGER)',
    'CREATE TABLE order_events (id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,status TEXT,note TEXT)',
    'CREATE TABLE shop_crm_cursor(source_id TEXT PRIMARY KEY,last_order_id INTEGER)',
    'CREATE TABLE shop_crm_sync_state(source_id TEXT,shop_order_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,shop_order_id))',
    'CREATE TABLE shop_crm_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,shop_order_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO orders VALUES(1,'W-1',NULL,'Test','09120000000','a@example.com',100,0,0,100,'pending','pending','2025-01-01 10:00:00','PRIVATE ADDRESS','SECRET TOKEN','PRIVATE NOTE'),(2,'W-2',5,'Test2','09121111111','',200,0,0,200,'confirmed','paid','2025-01-02 10:00:00','PRIVATE ADDRESS','SECRET TOKEN','PRIVATE NOTE')");
$pdo->exec("INSERT INTO order_items VALUES(1,1,7,'Item',1,100,100),(2,2,NULL,'Old item',2,100,200)");
$source=str_repeat('a',32);
$r=shop_crm_scan($pdo,$source,1); check($r['scanned']===1 && $r['queued']===1,'incremental backfill starts');
$payload=$pdo->query('SELECT snapshot FROM shop_crm_outbox WHERE id=1')->fetchColumn();
check(strpos($payload,'PRIVATE')===false && strpos($payload,'SECRET')===false && json_decode($payload,true)['id']===1,'snapshot excludes sensitive fields');
check(json_decode($payload,true)['order_event_id']===0,'legacy order with no event has zero token');
$r=shop_crm_scan($pdo,$source,1); check($r['queued']===1 && $r['scanned']===1,'second order queued');
check(shop_crm_scan($pdo,$source,1)['sweep_complete'],'sweep resets');
$r=shop_crm_scan($pdo,$source,2); check($r['queued']===0 && (int)$pdo->query('SELECT COUNT(*) FROM shop_crm_outbox')->fetchColumn()===2,'unchanged orders deduplicated');
shop_crm_scan($pdo,$source,2);
$pdo->exec("UPDATE orders SET order_status='cancelled' WHERE id=1");
$r=shop_crm_scan($pdo,$source,2); check($r['queued']===1 && (int)$pdo->query('SELECT COUNT(*) FROM shop_crm_outbox')->fetchColumn()===3,'changed order gets newer revision');
$pdo->exec("CREATE TRIGGER fail_queue BEFORE INSERT ON shop_crm_outbox BEGIN SELECT RAISE(ABORT,'queue failure'); END");
shop_crm_scan($pdo,$source,2); $pdo->exec("UPDATE orders SET order_status='shipped' WHERE id=1");
try { shop_crm_scan($pdo,$source,2); check(false,'queue failure must throw'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT last_order_id FROM shop_crm_cursor')->fetchColumn()===0 && (int)$pdo->query('SELECT COUNT(*) FROM shop_crm_outbox')->fetchColumn()===3,'failed queue does not advance cursor');
$pdo->exec('DROP TRIGGER fail_queue'); check(shop_crm_scan($pdo,$source,2)['queued']===1,'retry captures failed order');
try { shop_crm_uint('999999999999999999999999'); check(false,'overflow'); } catch (RuntimeException $e) { check(true,'overflow rejected'); }

$row=$pdo->query('SELECT * FROM shop_crm_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$ack=['ok'=>true,'event_id'=>$row['event_id'],'revision'=>(int)$row['id'],'result'=>'stale'];
check(shop_crm_acknowledged($pdo,$row,$ack,200),'superseded older event can be acknowledged');
$row=$pdo->query('SELECT * FROM shop_crm_outbox ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ack=['ok'=>true,'event_id'=>$row['event_id'],'revision'=>(int)$row['id'],'result'=>'stale'];
check(!shop_crm_acknowledged($pdo,$row,$ack,200),'latest stale revision detects potential restore regression');
$ack['result']='updated'; check(shop_crm_acknowledged($pdo,$row,$ack,200),'matching update acknowledged');
$ack['result']='unknown'; check(!shop_crm_acknowledged($pdo,$row,$ack,200),'unknown acknowledgement denied');

// Cross-component protocol test using separate SQLite databases; no real network or MySQL.
require_once __DIR__.'/../../crm_derakht_sepid/includes/site_inquiries.php';
require_once __DIR__.'/../../crm_derakht_sepid/includes/shop_order_sync.php';
class TargetTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=str_replace(' FOR UPDATE','',$sql);
        if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) {
            $sql=str_replace('INSERT INTO crm_shop_orders','INSERT OR IGNORE INTO crm_shop_orders',$sql);
            $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql);
        }
        return parent::prepare($sql,$options);
    }
}
$crm=new TargetTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,shop_order_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,payload TEXT,received_at TEXT,UNIQUE(source_id,shop_order_id))');
$key=str_repeat('k',32); $now=time();
foreach ([4,1,4] as $index=>$revision) {
    $st=$pdo->prepare('SELECT * FROM shop_crm_outbox WHERE id=?'); $st->execute([$revision]); $event=$st->fetch(PDO::FETCH_ASSOC);
    $body=json_encode(['type'=>'shop_order','source_id'=>$event['source_id'],'event_id'=>$event['event_id'],'revision'=>(int)$event['id'],'order'=>json_decode($event['snapshot'],true)],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig=hash_hmac('sha256',$now.'.'.$body,$key);
    check(site_inquiry_signature_valid($key,(string)$now,$sig,$body,$now) && !site_inquiry_signature_valid($key,(string)$now,$sig,$body.'x',$now),'cross-component signed payload '.($index+1));
    $data=json_decode($body,true); check(crm_shop_order_validate($data,$source),'cross-component payload validation '.($index+1));
    $result=crm_shop_order_store($crm,$data,hash('sha256',$body));
    check($result===['updated','stale','duplicate'][$index],'cross-component delivery '.($index+1));
}
$replica=$crm->query('SELECT * FROM crm_shop_orders')->fetch(PDO::FETCH_ASSOC);
check((int)$replica['revision']===4 && json_decode($replica['payload'],true)['order_status']==='shipped','out-of-order transfer preserves latest snapshot');

// A change event alone changes the snapshot, even if status and payment return to their old values.
$pdo->exec("INSERT INTO order_events(order_id,status,note) VALUES(1,'pending','PRIVATE NOTE')");
check(shop_crm_capture($pdo,$source,1),'event-only change queues a new replica version');
$eventPayload=$pdo->query('SELECT snapshot FROM shop_crm_outbox ORDER BY id DESC LIMIT 1')->fetchColumn();
check(json_decode($eventPayload,true)['order_event_id']===1 && strpos($eventPayload,'PRIVATE NOTE')===false,'event token replicated without event notes');
check(!shop_crm_capture($pdo,$source,1),'unchanged event token does not requeue');
