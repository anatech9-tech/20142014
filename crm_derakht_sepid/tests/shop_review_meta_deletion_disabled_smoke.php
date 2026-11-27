<?php
/** Deletion is independently disabled even when the review metadata bridge is on. */
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmReviewMetaSync.php'; require_once __DIR__.'/../includes/shop_review_meta_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
check(shop_review_meta_configured() && crm_review_meta_configured() && !shop_review_meta_deletion_enabled() && !crm_review_meta_deletion_enabled(),'base bridge does not enable deletion on either side');
try { shop_review_meta_reconcile($pdo,SHOP_CRM_SOURCE_ID); check(false,'source disabled'); } catch (DomainException $e) { check(true,'disabled source does not scan'); }
$event=['type'=>'shop_review_meta_deleted','source_id'=>SHOP_CRM_SOURCE_ID,'event_id'=>str_repeat('e',32),'revision'=>1,'review'=>['id'=>1,'deleted'=>true]];
check(crm_review_meta_validate($event,SHOP_CRM_SOURCE_ID),'new type is recognized while receiver opt-in is off');
try { crm_review_meta_store($pdo,$event,str_repeat('f',64)); check(false,'receiver disabled'); } catch (DomainException $e) { check(!$pdo->inTransaction(),'disabled receiver refuses tombstone before any database write'); }
$pdo->exec('CREATE TABLE shop_review_meta_outbox(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,event_id TEXT,snapshot TEXT,attempts INTEGER,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)');
$pdo->prepare('INSERT INTO shop_review_meta_outbox VALUES(1,?,1,?,?,0,?,NULL,?)')->execute([SHOP_CRM_SOURCE_ID,str_repeat('e',32),json_encode(['id'=>1,'deleted'=>true]),'2025-01-01','2025-01-01']);
$called=false; $r=shop_review_meta_pump($pdo,SHOP_CRM_SOURCE_ID,function($body) use(&$called) { $called=true; return [503,null]; },time());
check($called && $r['failed']===1 && $pdo->query('SELECT delivered_at FROM shop_review_meta_outbox')->fetchColumn()===null,'disabling only source scan does not stop already queued tombstone; rejected delivery stays pending');
