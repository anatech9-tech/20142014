<?php
/** Main bridge on does not enable absence reconciliation on either side. */
define('SITE_SHIPMENT_META_ENABLED',true); define('SITE_SHIPMENT_META_KEY',str_repeat('s',32)); define('SITE_SHIPMENT_SOURCE_ID',str_repeat('a',32)); define('SITE_SHIPMENT_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shipments/ShipmentMetadataSync.php'; require_once __DIR__.'/../includes/site_shipment_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(site_shipment_sync_configured() && crm_site_shipment_sync_configured() && !site_shipment_sync_deletion_enabled() && !crm_site_shipment_sync_deletion_enabled(),'independent deletion flag defaults off');
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { site_shipment_sync_reconcile($pdo,SITE_SHIPMENT_SOURCE_ID); check(false,'disabled scan'); } catch (DomainException $e) { check(true,'disabled reconciliation fails closed'); }
$event=['type'=>'site_shipment_meta_deleted_v1','source_id'=>SITE_SHIPMENT_SOURCE_ID,'event_id'=>str_repeat('b',32),'revision'=>1,'shipment'=>['id'=>7,'deleted'=>true]];
check(crm_site_shipment_metadata_validate($event,SITE_SHIPMENT_SOURCE_ID),'disabled receiver recognizes valid marker');
try { crm_site_shipment_sync_store($pdo,$event,str_repeat('f',64)); check(false,'disabled receiver'); } catch (DomainException $e) { check(true,'disabled receiver refuses marker'); }
check((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,'disabled paths do not create tables');
$pdo->exec('CREATE TABLE site_shipment_meta_outbox(id INTEGER PRIMARY KEY,source_id TEXT,shipment_id INTEGER,event_id TEXT,snapshot TEXT,attempts INTEGER,next_attempt_at TEXT,delivered_at TEXT)');
$pdo->prepare('INSERT INTO site_shipment_meta_outbox VALUES(1,?,7,?,?,0,?,NULL)')->execute([SITE_SHIPMENT_SOURCE_ID,str_repeat('b',32),json_encode(['id'=>7,'deleted'=>true]),'2000-01-01 00:00:00']);
$called=false; $result=site_shipment_sync_pump($pdo,SITE_SHIPMENT_SOURCE_ID,function($body) use (&$called) { $called=true; $payload=json_decode($body,true); check(($payload['type'] ?? '')==='site_shipment_meta_deleted_v1','previous marker still sent after local opt-out'); return [503,null]; },time());
check($called && $result['failed']===1 && $pdo->query('SELECT delivered_at FROM site_shipment_meta_outbox')->fetchColumn()===null,'receiver rejection retains queued marker for retry');
