<?php
/** Isolated SQLite and mock transport; no real MySQL, HTTPS, lock or cron. */
class CouponMetaPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,coupon_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_shop_coupon_meta','INSERT OR IGNORE INTO crm_shop_coupon_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_COUPON_META_ENABLED',true); define('SHOP_COUPON_META_DELETION_ENABLED',true); define('SHOP_COUPON_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_COUPON_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmCouponMetadataSync.php'; require_once __DIR__.'/../includes/shop_coupon_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SHOP_CRM_SOURCE_ID; $key=SHOP_COUPON_META_KEY; $now=time();
check(shop_coupon_sync_configured() && crm_shop_coupon_sync_configured() && shop_coupon_sync_deletion_enabled() && crm_shop_coupon_sync_deletion_enabled(),'two distinct coupon opt-ins enabled');
$sig=hash_hmac('sha256','shop-coupon-meta-v1.'.$now.'.body',$key);
check(crm_shop_coupon_sync_signature_valid($key,(string)$now,$sig,'body',$now) && !crm_shop_coupon_sync_signature_valid($key,(string)$now,$sig,'altered',$now) && !crm_shop_coupon_sync_signature_valid($key,(string)$now,$sig,'body',$now+301) && !crm_shop_coupon_sync_signature_valid(str_repeat('x',32),(string)$now,$sig,'body',$now),'HMAC rejects wrong key, altered payload and expired request');
$site=new CouponMetaPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE discount_codes(id INTEGER PRIMARY KEY,code TEXT,type TEXT,value TEXT,min_amount INTEGER,max_uses INTEGER,used_count INTEGER,expires_at TEXT,is_active INTEGER)',
 'CREATE TABLE shop_coupon_meta_cursor(source_id TEXT PRIMARY KEY,last_coupon_id INTEGER)',
 'CREATE TABLE shop_coupon_meta_reconcile_cursor(source_id TEXT PRIMARY KEY,last_coupon_id INTEGER)',
 'CREATE TABLE shop_coupon_meta_state(source_id TEXT,coupon_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,coupon_id))',
 'CREATE TABLE shop_coupon_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,coupon_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO discount_codes VALUES(1,'PRIVATE COUPON','percent','10.00',500,20,4,'2020-01-01 00:00:00',1),(2,'OTHER COUPON','fixed','100.00',0,0,0,NULL,0)");
check(shop_coupon_sync_scan($site,$source,1)['queued']===1 && shop_coupon_sync_scan($site,$source,1)['queued']===1 && shop_coupon_sync_scan($site,$source,1)['sweep_complete'],'bounded scan captures active and inactive metadata');
check(shop_coupon_sync_scan($site,$source,2)['queued']===0,'unchanged deduplicated');
$first=$site->query('SELECT * FROM shop_coupon_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(json_decode($first['snapshot'],true)===['id'=>1,'type'=>'percent','is_active'=>1] && !str_contains($first['snapshot'],'PRIVATE'),'code, terms, usage and expiry excluded even for expired coupon');
shop_coupon_sync_scan($site,$source,2); $site->exec("UPDATE discount_codes SET code='CHANGED SECRET',value='99.00',used_count=9 WHERE id=1"); check(shop_coupon_sync_scan($site,$source,2)['queued']===0,'changes to excluded terms do not create snapshot');
shop_coupon_sync_scan($site,$source,2); $site->exec('UPDATE discount_codes SET is_active=0 WHERE id=1'); check(shop_coupon_sync_scan($site,$source,2)['queued']===1,'changed active label creates revision');
$new=$site->query('SELECT * FROM shop_coupon_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
function coupon_event(array $r): array { return ['type'=>'shop_coupon_meta_v1','source_id'=>$r['source_id'],'event_id'=>$r['event_id'],'revision'=>(int)$r['id'],'coupon'=>json_decode($r['snapshot'],true)]; }
$crm=new CouponMetaPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $crm->exec('CREATE TABLE crm_shop_coupon_meta(id INTEGER PRIMARY KEY,source_id TEXT,coupon_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,coupon_type TEXT,is_active INTEGER,received_at TEXT,UNIQUE(source_id,coupon_id))');
$data=coupon_event($new); $old=coupon_event($first); $hash=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES));
check(crm_shop_coupon_metadata_validate($data,$source) && crm_shop_coupon_sync_store($crm,$data,$hash)==='updated' && crm_shop_coupon_sync_store($crm,$data,$hash)==='duplicate' && crm_shop_coupon_sync_store($crm,$data,str_repeat('f',64))==='conflict','revision update, duplicate and conflict distinguished');
check(crm_shop_coupon_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','older message cannot overwrite new');
foreach (['code','value','used_count','expires_at'] as $field) { $bad=$data; $bad['coupon'][$field]='PRIVATE'; check(!crm_shop_coupon_metadata_validate($bad,$source),'extra field rejected'); }
$bad=$data; $bad['coupon']['is_active']=true; check(!crm_shop_coupon_metadata_validate($bad,$source),'strict active type'); $bad=$data; $bad['source_id']=str_repeat('b',32); check(!crm_shop_coupon_metadata_validate($bad,$source),'cross-source rejected');
check(shop_coupon_sync_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>1,'result'=>'stale'],200) && !shop_coupon_sync_ack($site,$new,['ok'=>true,'event_id'=>$new['event_id'],'revision'=>3,'result'=>'stale'],200),'only superseded stale events acknowledged');
$lost=true; $send=function($body) use($crm,$source,&$lost) { $e=json_decode($body,true); check(crm_shop_coupon_metadata_validate($e,$source) && !str_contains($body,'SECRET') && !str_contains($body,'COUPON'),'transport contains no code'); $result=crm_shop_coupon_sync_store($crm,$e,hash('sha256',$body)); if ($lost) { $lost=false; return [0,null]; } return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$result]]; };
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM shop_coupon_meta_outbox')->fetchColumn())+1;
$r=shop_coupon_sync_pump($site,$source,$send,$when); check($r['failed']===1 && $r['sent']===2,'lost response stays queued');
check(shop_coupon_sync_pump($site,$source,$send,$when+61)['sent']===1 && $site->query('SELECT snapshot FROM shop_coupon_meta_outbox WHERE id=1')->fetchColumn()===null,'retry succeeds and delivered snapshot cleared');
check((int)$crm->query('SELECT is_active FROM crm_shop_coupon_meta WHERE coupon_id=1')->fetchColumn()===0,'newer active label retained');
check(shop_coupon_sync_reconcile($site,$source,2)['queued']===0 && shop_coupon_sync_reconcile($site,$source,2)['sweep_complete'],'bounded reconciliation leaves existing coupons');
$site->exec('DELETE FROM discount_codes WHERE id=1'); check(shop_coupon_sync_reconcile($site,$source,2)['queued']===1,'known absent coupon queues marker');
$tomb=$site->query('SELECT * FROM shop_coupon_meta_outbox WHERE id=4')->fetch(PDO::FETCH_ASSOC); check(json_decode($tomb['snapshot'],true)===['id'=>1,'deleted'=>true],'marker is id only');
$marker=['type'=>'shop_coupon_meta_deleted_v1','source_id'=>$source,'event_id'=>$tomb['event_id'],'revision'=>4,'coupon'=>json_decode($tomb['snapshot'],true)];
$bad=$marker; $bad['coupon']['code']='SECRET'; check(!crm_shop_coupon_metadata_validate($bad,$source),'marker rejects secret extra fields');
$loseMarker=true; $sendMarker=function($body) use($send,&$loseMarker) { $r=$send($body); if ($loseMarker) { $loseMarker=false; return [0,null]; } return $r; };
check(shop_coupon_sync_pump($site,$source,$sendMarker,$when+120)['failed']===1 && shop_coupon_sync_pump($site,$source,$sendMarker,$when+181)['sent']===1,'lost marker response retried idempotently');
$row=$crm->query('SELECT * FROM crm_shop_coupon_meta WHERE coupon_id=1')->fetch(PDO::FETCH_ASSOC); check($row['coupon_type']==='' && (int)$row['is_active']===0 && (int)$row['revision']===4,'marker retains high-water revision and clears metadata');
check(crm_shop_coupon_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','old metadata cannot resurrect marker');
$bad=$marker; $bad['coupon']['deleted']=1; check(!crm_shop_coupon_metadata_validate($bad,$source),'deletion marker must be boolean');
$crm->exec("CREATE TRIGGER fail_coupon_replica BEFORE UPDATE ON crm_shop_coupon_meta BEGIN SELECT RAISE(ABORT,'replica'); END");
$future=$old; $future['revision']=20; $future['event_id']=str_repeat('d',32); try { crm_shop_coupon_sync_store($crm,$future,hash('sha256',json_encode($future))); check(false,'replica failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT revision FROM crm_shop_coupon_meta WHERE coupon_id=1')->fetchColumn()===4 && !$crm->inTransaction(),'replica failure rolls back'); $crm->exec('DROP TRIGGER fail_coupon_replica');
$site->exec("INSERT INTO discount_codes VALUES(1,'PRIVATE COUPON','percent','10.00',500,20,4,NULL,1)"); check(shop_coupon_sync_capture($site,$source,1),'reappearance creates newer revision');
check(shop_coupon_sync_pump($site,$source,$send,$when+242)['sent']===1 && crm_shop_coupon_sync_store($crm,$marker,hash('sha256',json_encode($marker)))==='stale','newer reappearance cannot be overwritten by old marker');
$site->exec("UPDATE discount_codes SET type='fixed' WHERE id=1"); $site->exec("CREATE TRIGGER fail_coupon_queue BEFORE INSERT ON shop_coupon_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
$before=$site->query('SELECT snapshot_hash FROM shop_coupon_meta_state WHERE coupon_id=1')->fetchColumn(); try { shop_coupon_sync_capture($site,$source,1); check(false,'queue failure'); } catch (PDOException $e) {}
check($site->query('SELECT snapshot_hash FROM shop_coupon_meta_state WHERE coupon_id=1')->fetchColumn()===$before && !$site->inTransaction(),'enqueue failure rolls back'); $site->exec('DROP TRIGGER fail_coupon_queue'); check(shop_coupon_sync_capture($site,$source,1),'enqueue retries');
$site->prepare('UPDATE shop_coupon_meta_outbox SET snapshot=? WHERE id=6')->execute([json_encode(['id'=>1,'type'=>'fixed','is_active'=>1,'code'=>'SECRET'])]); $called=false;
try { shop_coupon_sync_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+360); check(false,'tampered queue'); } catch (RuntimeException $e) { check(!$called,'tampered queue with code not sent'); }
