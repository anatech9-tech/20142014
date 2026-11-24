<?php
/** Two SQLite databases with simulated HTTP; MySQL locks, TLS and host cron not exercised. */
class CatalogTestPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,product_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_shop_catalog','INSERT OR IGNORE INTO crm_shop_catalog',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('k',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CATALOG_CRM_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmCatalogSync.php'; require_once __DIR__.'/../includes/shop_catalog_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SHOP_CRM_SOURCE_ID; $key=SHOP_CATALOG_CRM_KEY; $now=time();
check(shop_catalog_configured() && crm_shop_catalog_configured(),'separate catalogue key and opt-in enabled');
$sig=hash_hmac('sha256','shop-catalog-v1.'.$now.'.body',$key);
check(crm_shop_catalog_signature_valid($key,(string)$now,$sig,'body',$now),'catalogue signature accepted');
check(!crm_shop_catalog_signature_valid($key,(string)$now,$sig,'changed',$now) && !crm_shop_catalog_signature_valid($key,(string)$now,$sig,'body',$now+301) && !crm_shop_catalog_signature_valid(str_repeat('x',32),(string)$now,$sig,'body',$now),'tamper stale and wrong key rejected');
$site=new CatalogTestPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE products(id INTEGER PRIMARY KEY,name_fa TEXT,name_en TEXT,sku TEXT,brand TEXT,slug TEXT,category_id INTEGER,currency TEXT,price_toman INTEGER,price_usd TEXT,stock INTEGER,is_active INTEGER,images TEXT,description_fa TEXT)',
 'CREATE TABLE product_categories(id INTEGER PRIMARY KEY,name_fa TEXT)',
 'CREATE TABLE shop_catalog_cursor(source_id TEXT PRIMARY KEY,last_product_id INTEGER)',
 'CREATE TABLE shop_catalog_state(source_id TEXT,product_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,product_id))',
 'CREATE TABLE shop_catalog_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,product_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO product_categories VALUES(1,'Category')");
$site->exec("INSERT INTO products VALUES(1,'One','One','A1','Brand','one',1,'toman',100,NULL,4,1,'PRIVATE IMAGE','PRIVATE DESC'),(2,'Two','Two','B2','','two',NULL,'usd',NULL,'12.50',0,0,'PRIVATE IMAGE','PRIVATE DESC')");
$r=shop_catalog_scan($site,$source,1); check($r['scanned']===1 && $r['queued']===1,'incremental catalogue scan begins');
$r=shop_catalog_scan($site,$source,1); check($r['scanned']===1 && $r['queued']===1,'inactive products replicated');
check(shop_catalog_scan($site,$source,1)['sweep_complete'],'sweep resets');
$r=shop_catalog_scan($site,$source,2); check($r['queued']===0 && (int)$site->query('SELECT COUNT(*) FROM shop_catalog_outbox')->fetchColumn()===2,'unchanged products deduplicated');
$first=$site->query('SELECT * FROM shop_catalog_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(strpos($first['snapshot'],'PRIVATE')===false,'images and descriptions excluded');
$second=$site->query('SELECT * FROM shop_catalog_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC);
$p=json_decode($second['snapshot'],true); check($p['price_usd']==='12.50' && $p['stock']===0 && $p['is_active']===0,'native USD decimal and inactive state preserved');
shop_catalog_scan($site,$source,2); $site->exec("UPDATE products SET stock=2 WHERE id=1; UPDATE product_categories SET name_fa='New Category' WHERE id=1");
$r=shop_catalog_scan($site,$source,2); check($r['queued']===1 && (int)$site->query('SELECT COUNT(*) FROM shop_catalog_outbox')->fetchColumn()===3,'stock and category changes queued');
$crm=new CatalogTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$crm->exec('CREATE TABLE crm_shop_catalog(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,name_fa TEXT,sku TEXT,is_active INTEGER,payload TEXT,received_at TEXT,UNIQUE(source_id,shop_product_id))');
function event(array $row): array { return ['type'=>'shop_product','source_id'=>$row['source_id'],'event_id'=>$row['event_id'],'revision'=>(int)$row['id'],'product'=>json_decode($row['snapshot'],true)]; }
$new=$site->query('SELECT * FROM shop_catalog_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC); $newData=event($new); $oldData=event($first);
check(crm_shop_catalog_validate($newData,$source),'new product snapshot validates');
$bad=$newData; $bad['product']['images']=['secret']; check(!crm_shop_catalog_validate($bad,$source),'extra fields rejected');
$bad=$newData; $bad['product']['stock']=-1; check(!crm_shop_catalog_validate($bad,$source),'invalid stock rejected');
$bad=$newData; $bad['product']['price_usd']=1.25; check(!crm_shop_catalog_validate($bad,$source),'numeric float price rejected');
$bad=$newData; $bad['source_id']=str_repeat('b',32); check(!crm_shop_catalog_validate($bad,$source),'different source denied');
$body=json_encode($newData,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $hash=hash('sha256',$body);
check(crm_shop_catalog_store($crm,$newData,$hash)==='updated','new revision stored');
check(crm_shop_catalog_store($crm,$newData,$hash)==='duplicate' && crm_shop_catalog_store($crm,$newData,str_repeat('f',64))==='conflict','duplicates accepted and revision conflict rejected');
check(crm_shop_catalog_store($crm,$oldData,hash('sha256',json_encode($oldData)))==='stale','out-of-order older event does not overwrite');
check(shop_catalog_ack($site,$first,['ok'=>true,'event_id'=>$first['event_id'],'revision'=>1,'result'=>'stale'],200),'older stale acknowledgement requires newer local revision');
check(!shop_catalog_ack($site,$new,['ok'=>true,'event_id'=>$new['event_id'],'revision'=>3,'result'=>'stale'],200),'latest stale acknowledgement flags restore regression');
// Worker delivery, including a lost response and retry; existing received rows remain idempotent.
$firstAttempt=true;
$sender=function($body) use ($crm,$source,&$firstAttempt) {
 $event=json_decode($body,true); if (!crm_shop_catalog_validate($event,$source)) return [422,null];
 $result=crm_shop_catalog_store($crm,$event,hash('sha256',$body));
 if ($firstAttempt) { $firstAttempt=false; return [0,null]; }
 return [200,['ok'=>true,'event_id'=>$event['event_id'],'revision'=>$event['revision'],'result'=>$result]];
};
$r=shop_catalog_pump($site,$source,$sender,$now); check($r['failed']===1 && $r['sent']===2,'lost response leaves retry pending while other products deliver');
$r=shop_catalog_pump($site,$source,$sender,$now+61); check($r['sent']===1 && $r['failed']===0,'retry completes without overwriting newer product');
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_catalog')->fetchColumn()===2 && (int)$crm->query('SELECT is_active FROM crm_shop_catalog WHERE shop_product_id=2')->fetchColumn()===0,'CRM retains active and inactive replicas');
check(strpos((string)$site->query('SELECT snapshot FROM shop_catalog_outbox WHERE id=1')->fetchColumn(),'One')===false,'delivered payload removed from source outbox');
$site->exec('DELETE FROM products WHERE id=2'); check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_catalog WHERE shop_product_id=2')->fetchColumn()===1,'source deletion not propagated');
$site->exec("CREATE TRIGGER fail_catalog_event BEFORE INSERT ON shop_catalog_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
shop_catalog_scan($site,$source,2); $site->exec('UPDATE products SET stock=1 WHERE id=1');
try { shop_catalog_scan($site,$source,2); check(false,'capture failure'); } catch (PDOException $e) {}
check((int)$site->query('SELECT last_product_id FROM shop_catalog_cursor')->fetchColumn()===0 && (int)$site->query('SELECT COUNT(*) FROM shop_catalog_outbox')->fetchColumn()===3,'queue failure rolls back state and cursor');
