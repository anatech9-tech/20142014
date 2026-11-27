<?php
/** SQLite fixtures and mock transport: no MySQL gap-lock or real HTTPS/cron test. */
class ShipmentDeletionPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,shipment_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_site_shipment_meta','INSERT OR IGNORE INTO crm_site_shipment_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SITE_SHIPMENT_META_ENABLED',true); define('SITE_SHIPMENT_META_DELETION_ENABLED',true); define('SITE_SHIPMENT_META_KEY',str_repeat('s',32)); define('SITE_SHIPMENT_SOURCE_ID',str_repeat('a',32)); define('SITE_SHIPMENT_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shipments/ShipmentMetadataSync.php'; require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SITE_SHIPMENT_SOURCE_ID; check(site_shipment_sync_deletion_enabled() && crm_site_shipment_sync_deletion_enabled(),'separate deletion opt-in enabled');
$site=new ShipmentDeletionPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE shipments(id INTEGER PRIMARY KEY,service_type TEXT,status TEXT,is_public INTEGER,tracking_code TEXT,customer_name TEXT)',
 'CREATE TABLE site_shipment_meta_cursor(source_id TEXT PRIMARY KEY,last_shipment_id INTEGER)',
 'CREATE TABLE site_shipment_meta_reconcile_cursor(source_id TEXT PRIMARY KEY,last_shipment_id INTEGER)',
 'CREATE TABLE site_shipment_meta_state(source_id TEXT,shipment_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,shipment_id))',
 'CREATE TABLE site_shipment_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,shipment_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO shipments VALUES(1,'air','registered',1,'PRIVATE','PRIVATE'),(2,'sea','on_hold',0,'PRIVATE','PRIVATE')");
check(site_shipment_sync_scan($site,$source,2)['queued']===2,'baseline captured');
$first=$site->query('SELECT * FROM site_shipment_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
function crm_db(): ShipmentDeletionPDO { $p=new ShipmentDeletionPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $p->exec('CREATE TABLE crm_site_shipment_meta(id INTEGER PRIMARY KEY,source_id TEXT,shipment_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,service_type TEXT,status TEXT,is_public INTEGER,received_at TEXT,UNIQUE(source_id,shipment_id))'); return $p; }
$crm=crm_db();
$send=function($body) use($crm,$source) { $e=json_decode($body,true); if (!crm_site_shipment_metadata_validate($e,$source)) return [422,null]; $result=crm_site_shipment_sync_store($crm,$e,hash('sha256',$body)); return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$result]]; };
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM site_shipment_meta_outbox')->fetchColumn())+1;
check(site_shipment_sync_pump($site,$source,$send,$when)['sent']===2,'baseline delivered');
check(site_shipment_sync_reconcile($site,$source,1)['queued']===0 && site_shipment_sync_reconcile($site,$source,1)['queued']===0 && site_shipment_sync_reconcile($site,$source,1)['sweep_complete'],'bounded pass does not mark live shipments');
$site->exec('DELETE FROM shipments WHERE id=1');
check(site_shipment_sync_reconcile($site,$source,1)['queued']===1,'missing known shipment creates marker');
$tomb=$site->query('SELECT * FROM site_shipment_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
check(json_decode($tomb['snapshot'],true)===['id'=>1,'deleted'=>true] && !str_contains($tomb['snapshot'],'PRIVATE'),'marker contains only id and boolean');
check(site_shipment_sync_reconcile($site,$source,1)['queued']===0 && site_shipment_sync_reconcile($site,$source,1)['sweep_complete'] && site_shipment_sync_reconcile($site,$source,2)['queued']===0,'repeat absence does not queue duplicate');
$event=['type'=>'site_shipment_meta_deleted_v1','source_id'=>$source,'event_id'=>$tomb['event_id'],'revision'=>3,'shipment'=>json_decode($tomb['snapshot'],true)];
check(crm_site_shipment_metadata_validate($event,$source),'marker validates');
foreach (['tracking_code','customer_name','status'] as $key) { $bad=$event; $bad['shipment'][$key]='PRIVATE'; check(!crm_site_shipment_metadata_validate($bad,$source),'extra marker field rejected'); }
$bad=$event; $bad['shipment']['deleted']=1; check(!crm_site_shipment_metadata_validate($bad,$source),'marker must be boolean true');
$empty=crm_db(); $old=['type'=>'site_shipment_meta_v1','source_id'=>$source,'event_id'=>$first['event_id'],'revision'=>1,'shipment'=>json_decode($first['snapshot'],true)];
check(crm_site_shipment_sync_store($empty,$event,hash('sha256',json_encode($event)))==='updated' && crm_site_shipment_sync_store($empty,$old,hash('sha256',json_encode($old)))==='stale','marker before baseline prevents old resurrection');
$lost=true; $lostSend=function($body) use ($send,&$lost) { $reply=$send($body); if ($lost) { $lost=false; return [0,null]; } return $reply; };
$when=max($when+62,strtotime($tomb['next_attempt_at'])+1); check(site_shipment_sync_pump($site,$source,$lostSend,$when)['failed']===1,'lost marker response remains queued');
$row=$crm->query('SELECT * FROM crm_site_shipment_meta WHERE shipment_id=1')->fetch(PDO::FETCH_ASSOC);
check($row['service_type']==='' && $row['status']==='' && (int)$row['is_public']===0 && (int)$row['revision']===3,'marker clears old metadata and retains revision');
check(site_shipment_sync_pump($site,$source,$lostSend,$when+61)['sent']===1 && crm_site_shipment_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','retry is idempotent and old data stays stale');
$site->exec("INSERT INTO shipments VALUES(1,'air','registered',1,'PRIVATE','PRIVATE')"); check(site_shipment_sync_capture($site,$source,1),'observed reappearance generates newer metadata');
check(site_shipment_sync_pump($site,$source,$send,$when+120)['sent']===1 && $crm->query('SELECT status FROM crm_site_shipment_meta WHERE shipment_id=1')->fetchColumn()==='registered','later reappearance replaces marker');
check(crm_site_shipment_sync_store($crm,$event,hash('sha256',json_encode($event)))==='stale','old marker cannot overwrite later reappearance');
$site->exec('DELETE FROM shipments WHERE id=2'); $site->exec("CREATE TRIGGER fail_ship_tomb BEFORE INSERT ON site_shipment_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
$cursor=$site->query('SELECT last_shipment_id FROM site_shipment_meta_reconcile_cursor')->fetchColumn(); $hash=$site->query('SELECT snapshot_hash FROM site_shipment_meta_state WHERE shipment_id=2')->fetchColumn();
try { site_shipment_sync_reconcile_one($site,$source,2); check(false,'queue failure'); } catch (PDOException $e) {}
check($site->query('SELECT last_shipment_id FROM site_shipment_meta_reconcile_cursor')->fetchColumn()===$cursor && $site->query('SELECT snapshot_hash FROM site_shipment_meta_state WHERE shipment_id=2')->fetchColumn()===$hash && !$site->inTransaction(),'queue failure rolls back state and cursor');
$site->exec('DROP TRIGGER fail_ship_tomb'); check(site_shipment_sync_reconcile_one($site,$source,2),'failed marker retry succeeds');
$site->exec("INSERT INTO shipments VALUES(2,'sea','on_hold',0,'PRIVATE','PRIVATE')");
check(!site_shipment_sync_reconcile_one($site,$source,2),'reappeared row before reconciliation is not marked missing');
$site->prepare('UPDATE site_shipment_meta_outbox SET snapshot=? WHERE id=5')->execute([json_encode(['id'=>2,'deleted'=>true,'customer_name'=>'PRIVATE'])]); $called=false;
try { site_shipment_sync_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+200); check(false,'tampered marker'); } catch (RuntimeException $e) { check(!$called,'tampered marker with private field never sent'); }
check($site->query('SELECT customer_name FROM shipments WHERE id=1')->fetchColumn()==='PRIVATE','source private field remains untouched');
