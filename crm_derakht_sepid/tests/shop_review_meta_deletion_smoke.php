<?php
/** SQLite fixtures and simulated delivery; does not establish MySQL gap locks or real HTTP. */
class ReviewDeletionPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,review_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_shop_review_meta','INSERT OR IGNORE INTO crm_shop_review_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_DELETION_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmReviewMetaSync.php'; require_once __DIR__.'/../includes/shop_review_meta_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SHOP_CRM_SOURCE_ID; check(shop_review_meta_deletion_enabled() && crm_review_meta_deletion_enabled(),'both independent deletion flags enabled');
$site=new ReviewDeletionPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE product_reviews(id INTEGER PRIMARY KEY,product_id INTEGER,rating INTEGER,is_active INTEGER,body TEXT,customer_name TEXT)',
 'CREATE TABLE shop_review_meta_cursor(source_id TEXT PRIMARY KEY,last_review_id INTEGER)',
 'CREATE TABLE shop_review_meta_reconcile_cursor(source_id TEXT PRIMARY KEY,last_review_id INTEGER)',
 'CREATE TABLE shop_review_meta_state(source_id TEXT,review_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
 'CREATE TABLE shop_review_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,review_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO product_reviews VALUES(1,10,5,1,'PRIVATE','PRIVATE'),(2,20,2,0,'PRIVATE','PRIVATE')");
check(shop_review_meta_scan($site,$source,2)['queued']===2,'source baseline captured');
$first=$site->query('SELECT * FROM shop_review_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$crm=new ReviewDeletionPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,rating INTEGER,is_active INTEGER,received_at TEXT,UNIQUE(source_id,review_id))');
$sender=function($body) use($crm,$source) { $e=json_decode($body,true); if (!crm_review_meta_validate($e,$source)) return [422,null]; $result=crm_review_meta_store($crm,$e,hash('sha256',$body)); return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$result]]; };
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM shop_review_meta_outbox')->fetchColumn())+1;
check(shop_review_meta_pump($site,$source,$sender,$when)['sent']===2,'baseline delivered');
check(shop_review_meta_reconcile($site,$source,1)['queued']===0 && shop_review_meta_reconcile($site,$source,1)['queued']===0 && shop_review_meta_reconcile($site,$source,1)['sweep_complete'],'bounded reconciliation does not flag existing reviews');
$site->exec('DELETE FROM product_reviews WHERE id=1');
$r=shop_review_meta_reconcile($site,$source,1); check($r['scanned']===1 && $r['queued']===1,'missing known review generates tombstone');
$tomb=$site->query('SELECT * FROM shop_review_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
check(json_decode($tomb['snapshot'],true)===['id'=>1,'deleted'=>true] && !str_contains($tomb['snapshot'],'PRIVATE'),'tombstone contains only review id and marker');
check(shop_review_meta_reconcile($site,$source,1)['queued']===0 && shop_review_meta_reconcile($site,$source,1)['sweep_complete'] && shop_review_meta_reconcile($site,$source,2)['queued']===0,'repeated absence does not generate new events');
$event=['type'=>'shop_review_meta_deleted','source_id'=>$source,'event_id'=>$tomb['event_id'],'revision'=>3,'review'=>json_decode($tomb['snapshot'],true)];
check(crm_review_meta_validate($event,$source),'id-only tombstone validates');
foreach (['body','product_id','customer_id'] as $key) { $bad=$event; $bad['review'][$key]='PRIVATE'; check(!crm_review_meta_validate($bad,$source),'tombstone rejects extra field '.$key); }
$bad=$event; $bad['review']['deleted']=1; check(!crm_review_meta_validate($bad,$source),'marker must be boolean true');
// Receiver may encounter the tombstone before any older metadata event.
$empty=new ReviewDeletionPDO('sqlite::memory:'); $empty->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$empty->exec('CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,rating INTEGER,is_active INTEGER,received_at TEXT,UNIQUE(source_id,review_id))');
check(crm_review_meta_store($empty,$event,hash('sha256',json_encode($event)))==='updated' && (int)$empty->query('SELECT product_id FROM crm_shop_review_meta')->fetchColumn()===0,'tombstone before initial event creates protected marker');
$oldFirst=['type'=>'shop_review_meta','source_id'=>$source,'event_id'=>$first['event_id'],'revision'=>1,'review'=>json_decode($first['snapshot'],true)];
check(crm_review_meta_store($empty,$oldFirst,hash('sha256',json_encode($oldFirst)))==='stale','late initial event cannot resurrect pre-existing tombstone');
$lost=true; $lostSender=function($body) use($sender,&$lost) { $result=$sender($body); if ($lost) { $lost=false; return [0,null]; } return $result; };
$when=max($when+62,strtotime($tomb['next_attempt_at'])+1);
check(shop_review_meta_pump($site,$source,$lostSender,$when)['failed']===1,'lost tombstone reply remains pending');
$row=$crm->query('SELECT * FROM crm_shop_review_meta WHERE review_id=1')->fetch(PDO::FETCH_ASSOC);
check((int)$row['product_id']===0 && (int)$row['rating']===0 && (int)$row['is_active']===0 && (int)$row['revision']===3,'tombstone drops display metadata but retains identity and high-water revision');
check(shop_review_meta_pump($site,$source,$lostSender,$when+61)['sent']===1,'retry acknowledges duplicate tombstone');
$old=['type'=>'shop_review_meta','source_id'=>$source,'event_id'=>$first['event_id'],'revision'=>1,'review'=>json_decode($first['snapshot'],true)];
check(crm_review_meta_store($crm,$old,hash('sha256',json_encode($old)))==='stale','old queued metadata cannot resurrect tombstone');
$site->exec("INSERT INTO product_reviews VALUES(1,10,5,1,'PRIVATE','PRIVATE')");
check(shop_review_meta_capture($site,$source,1),'reappearance creates later event even with same metadata');
check(shop_review_meta_pump($site,$source,$sender,$when+120)['sent']===1,'reappearance delivered');
check((int)$crm->query('SELECT product_id FROM crm_shop_review_meta WHERE review_id=1')->fetchColumn()===10,'later observed reappearance replaces tombstone');
check(crm_review_meta_store($crm,$event,hash('sha256',json_encode($event)))==='stale','old tombstone cannot hide newer reappearance');
$site->exec('DELETE FROM product_reviews WHERE id=2');
$site->exec("CREATE TRIGGER fail_tombstone BEFORE INSERT ON shop_review_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
$cursorBefore=$site->query('SELECT last_review_id FROM shop_review_meta_reconcile_cursor')->fetchColumn(); $stateBefore=$site->query('SELECT snapshot_hash FROM shop_review_meta_state WHERE review_id=2')->fetchColumn();
try { shop_review_meta_reconcile_one($site,$source,2); check(false,'failed tombstone'); } catch (PDOException $e) {}
check($site->query('SELECT last_review_id FROM shop_review_meta_reconcile_cursor')->fetchColumn()===$cursorBefore && $site->query('SELECT snapshot_hash FROM shop_review_meta_state WHERE review_id=2')->fetchColumn()===$stateBefore && !$site->inTransaction(),'failed queue rolls back reconciliation and cursor');
$site->exec('DROP TRIGGER fail_tombstone'); check(shop_review_meta_reconcile_one($site,$source,2),'failed tombstone can be retried');
check(!$site->inTransaction() && $site->query('SELECT body FROM product_reviews WHERE id=1')->fetchColumn()==='PRIVATE','source review body remains untouched');
