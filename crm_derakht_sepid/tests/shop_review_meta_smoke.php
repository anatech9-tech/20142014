<?php
/** Two isolated SQLite databases with simulated HTTP. No MySQL locks, cURL/TLS or cron are tested. */
class ReviewTestPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,review_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_shop_review_meta','INSERT OR IGNORE INTO crm_shop_review_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmReviewMetaSync.php'; require_once __DIR__.'/../includes/shop_review_meta_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SHOP_CRM_SOURCE_ID; $key=SHOP_REVIEW_META_CRM_KEY; $now=time();
check(shop_review_meta_configured() && crm_review_meta_configured(),'explicit independent review bridge enabled');
$sig=hash_hmac('sha256','shop-review-meta-v1.'.$now.'.body',$key);
check(crm_review_meta_signature_valid($key,(string)$now,$sig,'body',$now),'signature accepted');
check(!crm_review_meta_signature_valid($key,(string)$now,$sig,'changed',$now) && !crm_review_meta_signature_valid($key,(string)$now,$sig,'body',$now+301) && !crm_review_meta_signature_valid(str_repeat('x',32),(string)$now,$sig,'body',$now),'tamper, wrong key and expired signature rejected');
$site=new ReviewTestPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE product_reviews(id INTEGER PRIMARY KEY,product_id INTEGER,rating INTEGER,is_active INTEGER,customer_id INTEGER,customer_name TEXT,body TEXT,created_at TEXT)',
 'CREATE TABLE shop_review_meta_cursor(source_id TEXT PRIMARY KEY,last_review_id INTEGER)',
 'CREATE TABLE shop_review_meta_state(source_id TEXT,review_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
 'CREATE TABLE shop_review_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,review_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO product_reviews VALUES(1,10,5,1,123,'PRIVATE NAME','PRIVATE MESSAGE','2025-01-01'),(2,10,2,0,124,'OTHER NAME','OTHER MESSAGE','2025-02-01')");
check(shop_review_meta_scan($site,$source,1)['queued']===1 && shop_review_meta_scan($site,$source,1)['queued']===1 && shop_review_meta_scan($site,$source,1)['sweep_complete'],'bounded sweep captures published and hidden reviews');
check(shop_review_meta_scan($site,$source,2)['queued']===0,'unchanged metadata deduplicated');
$first=$site->query('SELECT * FROM shop_review_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(json_decode($first['snapshot'],true)===['id'=>1,'product_id'=>10,'rating'=>5,'is_active'=>1] && !str_contains($first['snapshot'],'PRIVATE'),'snapshot contains only approved numeric fields');
shop_review_meta_scan($site,$source,2); $site->exec("UPDATE product_reviews SET body='CHANGED PRIVATE TEXT',customer_name='CHANGED PRIVATE NAME' WHERE id=1");
check(shop_review_meta_scan($site,$source,2)['queued']===0,'changes to excluded text and name do not queue');
shop_review_meta_scan($site,$source,2); $site->exec('UPDATE product_reviews SET is_active=0 WHERE id=1');
check(shop_review_meta_scan($site,$source,2)['queued']===1,'changed visibility creates a new revision');
$new=$site->query('SELECT * FROM shop_review_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
function event(array $r): array { return ['type'=>'shop_review_meta','source_id'=>$r['source_id'],'event_id'=>$r['event_id'],'revision'=>(int)$r['id'],'review'=>json_decode($r['snapshot'],true)]; }
$crm=new ReviewTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,rating INTEGER,is_active INTEGER,received_at TEXT,UNIQUE(source_id,review_id))');
$data=event($new); $old=event($first); check(crm_review_meta_validate($data,$source),'metadata validates');
foreach (['body','customer_name','customer_id'] as $field) { $bad=$data; $bad['review'][$field]='PRIVATE'; check(!crm_review_meta_validate($bad,$source),'extra personal field rejected: '.$field); }
$bad=$data; $bad['review']['rating']=6; check(!crm_review_meta_validate($bad,$source),'invalid rating rejected');
$bad=$data; $bad['review']['product_id']='10'; check(!crm_review_meta_validate($bad,$source),'numeric strings rejected');
$bad=$data; $bad['source_id']=str_repeat('b',32); check(!crm_review_meta_validate($bad,$source),'foreign source rejected');
$body=json_encode($data,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $hash=hash('sha256',$body);
check(crm_review_meta_store($crm,$data,$hash)==='updated' && crm_review_meta_store($crm,$data,$hash)==='duplicate' && crm_review_meta_store($crm,$data,str_repeat('f',64))==='conflict','new, duplicate and conflicting revisions distinguished');
check(crm_review_meta_store($crm,$old,hash('sha256',json_encode($old)))==='stale','older event cannot overwrite newer');
check(shop_review_meta_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>1,'result'=>'stale'],200),'only superseded stale event acknowledged');
check(!shop_review_meta_ack($site,$new,['ok'=>true,'event_id'=>$new['event_id'],'revision'=>3,'result'=>'stale'],200),'latest stale event detects regression');
$lost=true; $sender=function($body) use($crm,$source,&$lost) {
 $e=json_decode($body,true); if (!crm_review_meta_validate($e,$source)) return [422,null];
 $result=crm_review_meta_store($crm,$e,hash('sha256',$body)); if ($lost) { $lost=false; return [0,null]; }
 return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$result]];
};
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM shop_review_meta_outbox')->fetchColumn())+1;
$r=shop_review_meta_pump($site,$source,$sender,$when); check($r['failed']===1 && $r['sent']===2,'lost response stays queued');
$r=shop_review_meta_pump($site,$source,$sender,$when+61); check($r['sent']===1 && $r['failed']===0,'retry is idempotent');
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_review_meta')->fetchColumn()===2 && (int)$crm->query('SELECT is_active FROM crm_shop_review_meta WHERE review_id=1')->fetchColumn()===0,'latest state retained in isolated replica');
check($site->query('SELECT snapshot FROM shop_review_meta_outbox WHERE id=1')->fetchColumn()===null,'delivered source payload removed');
$site->exec('DELETE FROM product_reviews WHERE id=2'); check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_review_meta WHERE review_id=2')->fetchColumn()===1,'source deletion is explicitly not propagated');
shop_review_meta_scan($site,$source,2); $site->exec('UPDATE product_reviews SET rating=1 WHERE id=1');
$site->exec("CREATE TRIGGER fail_review_event BEFORE INSERT ON shop_review_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
try { shop_review_meta_scan($site,$source,2); check(false,'queue failure'); } catch (PDOException $e) {}
check((int)$site->query('SELECT last_review_id FROM shop_review_meta_cursor')->fetchColumn()===0 && (int)$site->query('SELECT COUNT(*) FROM shop_review_meta_outbox')->fetchColumn()===3,'queue failure rolls back capture and cursor');
$site->exec('DROP TRIGGER fail_review_event'); check(shop_review_meta_scan($site,$source,2)['queued']===1,'failed capture retried');
$site->exec('UPDATE product_reviews SET rating=0 WHERE id=1');
try { shop_review_meta_capture($site,$source,1); check(false,'invalid review value'); } catch (RuntimeException $e) { check(!$site->inTransaction(),'invalid source rating rolls back capture'); }
$replay=event($new); $replay['revision']=10; $replay['event_id']=str_repeat('e',32); $replay['review']['rating']=3;
$crm->exec("CREATE TRIGGER fail_review_update BEFORE UPDATE ON crm_shop_review_meta BEGIN SELECT RAISE(ABORT,'replica failure'); END");
try { crm_review_meta_store($crm,$replay,hash('sha256',json_encode($replay))); check(false,'replica write failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT revision FROM crm_shop_review_meta WHERE review_id=1')->fetchColumn()===3 && !$crm->inTransaction(),'replica write failure rolls back');
$site->exec("UPDATE shop_review_meta_outbox SET event_id='SECRET' WHERE id=4"); $called=false;
try { shop_review_meta_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+200); check(false,'invalid queued event'); } catch (RuntimeException $e) { check(!$called,'invalid queued event id is not sent'); }
$site->prepare('UPDATE shop_review_meta_outbox SET event_id=? WHERE id=4')->execute([str_repeat('d',32)]);
$site->exec("UPDATE shop_review_meta_outbox SET snapshot='{\"id\":1,\"product_id\":10,\"rating\":\"SECRET\",\"is_active\":1}' WHERE id=4"); $called=false;
try { shop_review_meta_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+200); check(false,'tampered queue'); } catch (RuntimeException $e) { check(!$called,'invalid queued data is not sent'); }
