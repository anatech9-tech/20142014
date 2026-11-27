<?php
/** SQLite fixtures and mock transport, not MySQL, network or real cron. */
class ShipmentTestPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,shipment_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_site_shipment_meta','INSERT OR IGNORE INTO crm_site_shipment_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SITE_SHIPMENT_META_ENABLED',true); define('SITE_SHIPMENT_META_KEY',str_repeat('s',32)); define('SITE_SHIPMENT_SOURCE_ID',str_repeat('a',32)); define('SITE_SHIPMENT_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shipments/ShipmentMetadataSync.php'; require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SITE_SHIPMENT_SOURCE_ID; $key=SITE_SHIPMENT_META_KEY; $now=time();
check(site_shipment_sync_configured() && crm_site_shipment_sync_configured(),'separate opt-in enabled');
$sig=hash_hmac('sha256','site-shipment-meta-v1.'.$now.'.body',$key);
check(crm_site_shipment_sync_signature_valid($key,(string)$now,$sig,'body',$now),'HMAC valid');
check(!crm_site_shipment_sync_signature_valid($key,(string)$now,$sig,'changed',$now) && !crm_site_shipment_sync_signature_valid($key,(string)$now,$sig,'body',$now+301) && !crm_site_shipment_sync_signature_valid(str_repeat('x',32),(string)$now,$sig,'body',$now),'HMAC tamper, expiry and wrong key rejected');
$site=new ShipmentTestPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE shipments(id INTEGER PRIMARY KEY,service_type TEXT,status TEXT,is_public INTEGER,tracking_code TEXT,customer_name TEXT,notes TEXT)',
 'CREATE TABLE site_shipment_meta_cursor(source_id TEXT PRIMARY KEY,last_shipment_id INTEGER)',
 'CREATE TABLE site_shipment_meta_state(source_id TEXT,shipment_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,shipment_id))',
 'CREATE TABLE site_shipment_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,shipment_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO shipments VALUES(1,'air','registered',1,'PRIVATE CODE','PRIVATE NAME','PRIVATE NOTES'),(2,'sea','in_transit',0,'PRIVATE CODE2','PRIVATE NAME2','PRIVATE NOTES2')");
check(site_shipment_sync_scan($site,$source,1)['queued']===1 && site_shipment_sync_scan($site,$source,1)['queued']===1 && site_shipment_sync_scan($site,$source,1)['sweep_complete'],'bounded scan captures public and private metadata');
check(site_shipment_sync_scan($site,$source,2)['queued']===0,'unchanged metadata deduplicated');
$first=$site->query('SELECT * FROM site_shipment_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(json_decode($first['snapshot'],true)===['id'=>1,'service_type'=>'air','status'=>'registered','is_public'=>1] && !str_contains($first['snapshot'],'PRIVATE'),'only allowed fields copied');
site_shipment_sync_scan($site,$source,2); $site->exec("UPDATE shipments SET notes='CHANGED PRIVATE',customer_name='CHANGED PRIVATE',tracking_code='NEW SECRET' WHERE id=1");
check(site_shipment_sync_scan($site,$source,2)['queued']===0,'excluded private fields do not produce snapshots');
site_shipment_sync_scan($site,$source,2); $site->exec("UPDATE shipments SET status='delivered' WHERE id=1");
check(site_shipment_sync_scan($site,$source,2)['queued']===1,'metadata change creates revision');
$new=$site->query('SELECT * FROM site_shipment_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
function event(array $row): array { return ['type'=>'site_shipment_meta_v1','source_id'=>$row['source_id'],'event_id'=>$row['event_id'],'revision'=>(int)$row['id'],'shipment'=>json_decode($row['snapshot'],true)]; }
$crm=new ShipmentTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE crm_site_shipment_meta(id INTEGER PRIMARY KEY,source_id TEXT,shipment_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,service_type TEXT,status TEXT,is_public INTEGER,received_at TEXT,UNIQUE(source_id,shipment_id))');
$data=event($new); $old=event($first); $hash=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES));
check(crm_site_shipment_metadata_validate($data,$source) && crm_site_shipment_sync_store($crm,$data,$hash)==='updated' && crm_site_shipment_sync_store($crm,$data,$hash)==='duplicate' && crm_site_shipment_sync_store($crm,$data,str_repeat('f',64))==='conflict','revision update, duplicate, conflict');
check(crm_site_shipment_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','old event does not overwrite new');
check(site_shipment_sync_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>1,'result'=>'stale'],200) && !site_shipment_sync_ack($site,$new,['ok'=>true,'event_id'=>$new['event_id'],'revision'=>3,'result'=>'stale'],200),'only superseded stale revisions acknowledged');
check(!site_shipment_sync_ack($site,$first,['ok'=>true,'event_id'=>str_repeat('f',32),'revision'=>1,'result'=>'updated'],200) && !site_shipment_sync_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>99,'result'=>'updated'],200) && !site_shipment_sync_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>1,'result'=>'updated'],503),'wrong acknowledgements do not dequeue');
$lost=true; $send=function($body) use($crm,$source,&$lost) { $e=json_decode($body,true); check(crm_site_shipment_metadata_validate($e,$source) && !str_contains($body,'PRIVATE'),'transport allowlist'); $r=crm_site_shipment_sync_store($crm,$e,hash('sha256',$body)); if ($lost) { $lost=false; return [0,null]; } return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$r]]; };
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM site_shipment_meta_outbox')->fetchColumn())+1;
$r=site_shipment_sync_pump($site,$source,$send,$when); check($r['failed']===1 && $r['sent']===2,'lost response remains pending');
$r=site_shipment_sync_pump($site,$source,$send,$when+61); check($r['sent']===1 && $r['failed']===0,'retry handles duplicate or stale');
check((int)$crm->query('SELECT COUNT(*) FROM crm_site_shipment_meta')->fetchColumn()===2 && $crm->query('SELECT status FROM crm_site_shipment_meta WHERE shipment_id=1')->fetchColumn()==='delivered','separate replica keeps latest status');
check($site->query('SELECT snapshot FROM site_shipment_meta_outbox WHERE id=1')->fetchColumn()===null,'ack clears delivered payload');
$site->exec('DELETE FROM shipments WHERE id=2'); check((int)$crm->query('SELECT COUNT(*) FROM crm_site_shipment_meta WHERE shipment_id=2')->fetchColumn()===1,'deletion explicitly not synchronized');
site_shipment_sync_scan($site,$source,2); $site->exec("UPDATE shipments SET status='registered' WHERE id=1");
$site->exec("CREATE TRIGGER fail_shipment_event BEFORE INSERT ON site_shipment_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
try { site_shipment_sync_scan($site,$source,2); check(false,'queue failure'); } catch (PDOException $e) {}
check((int)$site->query('SELECT last_shipment_id FROM site_shipment_meta_cursor')->fetchColumn()===0 && (int)$site->query('SELECT COUNT(*) FROM site_shipment_meta_outbox')->fetchColumn()===3,'queue failure rolls back state and cursor');
$site->exec('DROP TRIGGER fail_shipment_event'); check(site_shipment_sync_scan($site,$source,2)['queued']===1,'change back observed as newer revision');
$site->exec("UPDATE shipments SET status='on_hold' WHERE id=1"); $site->exec("UPDATE shipments SET status='registered' WHERE id=1"); check(!site_shipment_sync_capture($site,$source,1),'change and revert between captures is not detected');
$site->exec("UPDATE shipments SET status='INVALID' WHERE id=1"); try { site_shipment_sync_capture($site,$source,1); check(false,'invalid source'); } catch (DomainException $e) { check(!$site->inTransaction(),'invalid source rolls back'); }
$replay=event($new); $replay['revision']=10; $replay['event_id']=str_repeat('e',32); $replay['shipment']['status']='on_hold';
$crm->exec("CREATE TRIGGER fail_shipment_update BEFORE UPDATE ON crm_site_shipment_meta BEGIN SELECT RAISE(ABORT,'failure'); END");
try { crm_site_shipment_sync_store($crm,$replay,hash('sha256',json_encode($replay))); check(false,'replica failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT revision FROM crm_site_shipment_meta WHERE shipment_id=1')->fetchColumn()===3 && !$crm->inTransaction(),'receiver write failure rolls back');
$site->exec("UPDATE site_shipment_meta_outbox SET snapshot='{\"id\":1,\"service_type\":\"air\",\"status\":\"registered\",\"is_public\":1,\"notes\":\"PRIVATE\"}' WHERE id=4"); $called=false;
try { site_shipment_sync_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+200); check(false,'tampered queue'); } catch (RuntimeException $e) { check(!$called,'tampered queue with private field not sent'); }
