<?php
/** Metadata transport alone must not enable coupon absence processing. */
define('SHOP_COUPON_META_ENABLED',true); define('SHOP_COUPON_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_COUPON_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmCouponMetadataSync.php'; require_once __DIR__.'/../includes/shop_coupon_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(shop_coupon_sync_configured() && crm_shop_coupon_sync_configured() && !shop_coupon_sync_deletion_enabled() && !crm_shop_coupon_sync_deletion_enabled(),'absence flags remain independently off');
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { shop_coupon_sync_reconcile($p,SHOP_CRM_SOURCE_ID); check(false,'scan'); } catch (DomainException $e) { check(true,'disabled reconciliation refuses scan'); }
$c=['type'=>'shop_coupon_meta_deleted_v1','source_id'=>SHOP_CRM_SOURCE_ID,'event_id'=>str_repeat('b',32),'revision'=>1,'coupon'=>['id'=>7,'deleted'=>true]];
try { crm_shop_coupon_sync_store($p,$c,str_repeat('f',64)); check(false,'receiver'); } catch (DomainException $e) { check(true,'disabled receiver refuses absence event'); }
$p->exec('CREATE TABLE shop_coupon_meta_outbox(id INTEGER PRIMARY KEY,source_id TEXT,coupon_id INTEGER,event_id TEXT,snapshot TEXT,attempts INTEGER,next_attempt_at TEXT,delivered_at TEXT)');
$p->prepare('INSERT INTO shop_coupon_meta_outbox VALUES(1,?,7,?,?,0,?,NULL)')->execute([SHOP_CRM_SOURCE_ID,str_repeat('b',32),json_encode(['id'=>7,'deleted'=>true]),'2000-01-01 00:00:00']);
$called=false; $result=shop_coupon_sync_pump($p,SHOP_CRM_SOURCE_ID,function($body) use (&$called) { $called=true; return [503,null]; },time());
check($called && $result['failed']===1 && $p->query('SELECT delivered_at FROM shop_coupon_meta_outbox')->fetchColumn()===null,'queued marker still sent and retained after rejection');
