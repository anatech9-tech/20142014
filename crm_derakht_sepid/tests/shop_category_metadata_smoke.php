<?php
/** Isolated SQLite and mock transport; no real MySQL, HTTPS, lock or cron. */
class CategoryMetaPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,category_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_shop_category_meta','INSERT OR IGNORE INTO crm_shop_category_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_DELETION_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CATEGORY_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmCategoryMetadataSync.php'; require_once __DIR__.'/../includes/shop_category_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SHOP_CRM_SOURCE_ID; $key=SHOP_CATEGORY_META_KEY; $now=time();
check(shop_category_sync_configured() && crm_shop_category_sync_configured() && shop_category_sync_deletion_enabled() && crm_shop_category_sync_deletion_enabled(),'two distinct category opt-ins enabled');
$sig=hash_hmac('sha256','shop-category-meta-v1.'.$now.'.body',$key);
check(crm_shop_category_sync_signature_valid($key,(string)$now,$sig,'body',$now) && !crm_shop_category_sync_signature_valid($key,(string)$now,$sig,'altered',$now) && !crm_shop_category_sync_signature_valid($key,(string)$now,$sig,'body',$now+301) && !crm_shop_category_sync_signature_valid(str_repeat('x',32),(string)$now,$sig,'body',$now),'HMAC rejects wrong key, altered payload and expired request');
$site=new CategoryMetaPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE product_categories(id INTEGER PRIMARY KEY,name_fa TEXT,name_en TEXT,slug TEXT,icon TEXT,is_active INTEGER,display_order INTEGER)',
 'CREATE TABLE shop_category_meta_cursor(source_id TEXT PRIMARY KEY,last_category_id INTEGER)',
 'CREATE TABLE shop_category_meta_reconcile_cursor(source_id TEXT PRIMARY KEY,last_category_id INTEGER)',
 'CREATE TABLE shop_category_meta_state(source_id TEXT,category_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,category_id))',
 'CREATE TABLE shop_category_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,category_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO product_categories VALUES(1,'دسته یک','PRIVATE ENGLISH','PRIVATE SLUG','PRIVATE ICON',1,1),(2,'دسته خالی','','empty','',0,2)");
check(shop_category_sync_scan($site,$source,1)['queued']===1 && shop_category_sync_scan($site,$source,1)['queued']===1 && shop_category_sync_scan($site,$source,1)['sweep_complete'],'bounded scan captures active and inactive metadata');
check(shop_category_sync_scan($site,$source,2)['queued']===0,'unchanged deduplicated');
$first=$site->query('SELECT * FROM shop_category_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(json_decode($first['snapshot'],true)===['id'=>1,'name_fa'=>'دسته یک','is_active'=>1] && !str_contains($first['snapshot'],'PRIVATE'),'excluded category fields stay out of snapshot');
shop_category_sync_scan($site,$source,2); $site->exec("UPDATE product_categories SET name_en='CHANGED SECRET',display_order=9 WHERE id=1"); check(shop_category_sync_scan($site,$source,2)['queued']===0,'changes to excluded fields do not create snapshot');
shop_category_sync_scan($site,$source,2); $site->exec('UPDATE product_categories SET is_active=0 WHERE id=1'); check(shop_category_sync_scan($site,$source,2)['queued']===1,'changed active label creates revision');
$new=$site->query('SELECT * FROM shop_category_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
function category_event(array $r): array { return ['type'=>'shop_category_meta_v1','source_id'=>$r['source_id'],'event_id'=>$r['event_id'],'revision'=>(int)$r['id'],'category'=>json_decode($r['snapshot'],true)]; }
$crm=new CategoryMetaPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $crm->exec('CREATE TABLE crm_shop_category_meta(id INTEGER PRIMARY KEY,source_id TEXT,category_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,category_name TEXT,is_active INTEGER,received_at TEXT,UNIQUE(source_id,category_id))');
$data=category_event($new); $old=category_event($first); $hash=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES));
check(crm_shop_category_metadata_validate($data,$source) && crm_shop_category_sync_store($crm,$data,$hash)==='updated' && crm_shop_category_sync_store($crm,$data,$hash)==='duplicate' && crm_shop_category_sync_store($crm,$data,str_repeat('f',64))==='conflict','revision update, duplicate and conflict distinguished');
check(crm_shop_category_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','older message cannot overwrite new');
foreach (['slug','name_en','icon','display_order'] as $field) { $bad=$data; $bad['category'][$field]='PRIVATE'; check(!crm_shop_category_metadata_validate($bad,$source),'extra field rejected'); }
foreach ([str_repeat('a',101), "bad\x00", "\xff"] as $invalid) { $bad=$data; $bad['category']['name_fa']=$invalid; check(!crm_shop_category_metadata_validate($bad,$source) && !shop_category_metadata_name($invalid),'invalid category names rejected on both sides'); }
foreach (['',str_repeat('د',100),str_repeat('😀',100)] as $valid) { $bad=$data; $bad['category']['name_fa']=$valid; check(crm_shop_category_metadata_validate($bad,$source) && shop_category_metadata_name($valid) && strlen(json_encode($bad,JSON_UNESCAPED_SLASHES))<=2048,'valid unicode and empty name fit transport limit'); }
$bad=$data; $bad['category']['is_active']=true; check(!crm_shop_category_metadata_validate($bad,$source),'strict active type'); $bad=$data; $bad['source_id']=str_repeat('b',32); check(!crm_shop_category_metadata_validate($bad,$source),'cross-source rejected');
check(shop_category_sync_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>1,'result'=>'stale'],200) && !shop_category_sync_ack($site,$new,['ok'=>true,'event_id'=>$new['event_id'],'revision'=>3,'result'=>'stale'],200),'only superseded stale events acknowledged');
$lost=true; $send=function($body) use($crm,$source,&$lost) { $e=json_decode($body,true); check(crm_shop_category_metadata_validate($e,$source) && !str_contains($body,'SECRET') && !str_contains($body,'CATEGORY'),'transport excludes unrelated fields'); $result=crm_shop_category_sync_store($crm,$e,hash('sha256',$body)); if ($lost) { $lost=false; return [0,null]; } return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$result]]; };
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM shop_category_meta_outbox')->fetchColumn())+1;
$r=shop_category_sync_pump($site,$source,$send,$when); check($r['failed']===1 && $r['sent']===2,'lost response stays queued');
check(shop_category_sync_pump($site,$source,$send,$when+61)['sent']===1 && $site->query('SELECT snapshot FROM shop_category_meta_outbox WHERE id=1')->fetchColumn()===null,'retry succeeds and delivered snapshot cleared');
check((int)$crm->query('SELECT is_active FROM crm_shop_category_meta WHERE category_id=1')->fetchColumn()===0,'newer active label retained');
check($crm->query('SELECT category_name FROM crm_shop_category_meta WHERE category_id=2')->fetchColumn()==='دسته خالی','empty category captured independently of products');
check(shop_category_sync_reconcile($site,$source,2)['queued']===0 && shop_category_sync_reconcile($site,$source,2)['sweep_complete'],'bounded reconciliation leaves existing categorys');
$site->exec('DELETE FROM product_categories WHERE id=1'); check(shop_category_sync_reconcile($site,$source,2)['queued']===1,'known absent category queues marker');
$tomb=$site->query('SELECT * FROM shop_category_meta_outbox WHERE id=4')->fetch(PDO::FETCH_ASSOC); check(json_decode($tomb['snapshot'],true)===['id'=>1,'deleted'=>true],'marker is id only');
$marker=['type'=>'shop_category_meta_deleted_v1','source_id'=>$source,'event_id'=>$tomb['event_id'],'revision'=>4,'category'=>json_decode($tomb['snapshot'],true)];
$bad=$marker; $bad['category']['slug']='SECRET'; check(!crm_shop_category_metadata_validate($bad,$source),'marker rejects secret extra fields');
$loseMarker=true; $sendMarker=function($body) use($send,&$loseMarker) { $r=$send($body); if ($loseMarker) { $loseMarker=false; return [0,null]; } return $r; };
check(shop_category_sync_pump($site,$source,$sendMarker,$when+120)['failed']===1 && shop_category_sync_pump($site,$source,$sendMarker,$when+181)['sent']===1,'lost marker response retried idempotently');
$row=$crm->query('SELECT * FROM crm_shop_category_meta WHERE category_id=1')->fetch(PDO::FETCH_ASSOC); check($row['category_name']===null && (int)$row['is_active']===0 && (int)$row['revision']===4,'marker retains high-water revision and clears metadata');
check(crm_shop_category_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','old metadata cannot resurrect marker');
$bad=$marker; $bad['category']['deleted']=1; check(!crm_shop_category_metadata_validate($bad,$source),'deletion marker must be boolean');
$crm->exec("CREATE TRIGGER fail_category_replica BEFORE UPDATE ON crm_shop_category_meta BEGIN SELECT RAISE(ABORT,'replica'); END");
$future=$old; $future['revision']=20; $future['event_id']=str_repeat('d',32); try { crm_shop_category_sync_store($crm,$future,hash('sha256',json_encode($future))); check(false,'replica failure'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT revision FROM crm_shop_category_meta WHERE category_id=1')->fetchColumn()===4 && !$crm->inTransaction(),'replica failure rolls back'); $crm->exec('DROP TRIGGER fail_category_replica');
$site->exec("INSERT INTO product_categories VALUES(1,'دسته بازگشته','PRIVATE ENGLISH','private','',1,0)"); check(shop_category_sync_capture($site,$source,1),'reappearance creates newer revision');
check(shop_category_sync_pump($site,$source,$send,$when+242)['sent']===1 && crm_shop_category_sync_store($crm,$marker,hash('sha256',json_encode($marker)))==='stale','newer reappearance cannot be overwritten by old marker');
$site->exec("UPDATE product_categories SET name_fa='نام جدید' WHERE id=1"); $site->exec("CREATE TRIGGER fail_category_queue BEFORE INSERT ON shop_category_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
$before=$site->query('SELECT snapshot_hash FROM shop_category_meta_state WHERE category_id=1')->fetchColumn(); try { shop_category_sync_capture($site,$source,1); check(false,'queue failure'); } catch (PDOException $e) {}
check($site->query('SELECT snapshot_hash FROM shop_category_meta_state WHERE category_id=1')->fetchColumn()===$before && !$site->inTransaction(),'enqueue failure rolls back'); $site->exec('DROP TRIGGER fail_category_queue'); check(shop_category_sync_capture($site,$source,1),'enqueue retries');
$site->prepare('UPDATE shop_category_meta_outbox SET snapshot=? WHERE id=6')->execute([json_encode(['id'=>1,'name_fa'=>'نام جدید','is_active'=>1,'slug'=>'SECRET'])]); $called=false;
try { shop_category_sync_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+360); check(false,'tampered queue'); } catch (RuntimeException $e) { check(!$called,'tampered queue with extra field not sent'); }
