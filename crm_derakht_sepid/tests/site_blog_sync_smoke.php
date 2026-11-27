<?php
/** SQLite fixtures and simulated HTTP; not a test of MySQL locks or TLS. */
class BlogTestPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,post_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_site_blog_meta','INSERT OR IGNORE INTO crm_site_blog_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_DELETION_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32)); define('SITE_BLOG_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php'; require_once __DIR__.'/../includes/site_blog_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SITE_BLOG_SOURCE_ID; $key=SITE_BLOG_META_KEY; $now=time();
check(site_blog_sync_configured() && crm_site_blog_sync_configured() && site_blog_sync_deletion_enabled() && crm_site_blog_sync_deletion_enabled(),'independent bridge and reconciliation opt-ins');
$sig=hash_hmac('sha256','site-blog-meta-v1.'.$now.'.body',$key); check(crm_site_blog_sync_signature_valid($key,(string)$now,$sig,'body',$now) && !crm_site_blog_sync_signature_valid($key,(string)$now,$sig,'altered',$now) && !crm_site_blog_sync_signature_valid($key,(string)$now,$sig,'body',$now+301) && !crm_site_blog_sync_signature_valid(str_repeat('x',32),(string)$now,$sig,'body',$now),'signature, tamper, expiry and key');
$site=new BlogTestPDO('sqlite::memory:'); $site->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE blog_posts(id INTEGER PRIMARY KEY,is_published INTEGER,title_fa TEXT,content_fa TEXT,slug TEXT)',
 'CREATE TABLE site_blog_meta_cursor(source_id TEXT PRIMARY KEY,last_post_id INTEGER)',
 'CREATE TABLE site_blog_meta_reconcile_cursor(source_id TEXT PRIMARY KEY,last_post_id INTEGER)',
 'CREATE TABLE site_blog_meta_state(source_id TEXT,post_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,post_id))',
 'CREATE TABLE site_blog_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,post_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $site->exec($sql);
$site->exec("INSERT INTO blog_posts VALUES(1,1,'PRIVATE TITLE','PRIVATE BODY','PRIVATE SLUG'),(2,0,'OTHER TITLE','OTHER BODY','OTHER SLUG')");
check(site_blog_sync_scan($site,$source,1)['queued']===1 && site_blog_sync_scan($site,$source,1)['queued']===1 && site_blog_sync_scan($site,$source,1)['sweep_complete'],'bounded scan');
check(site_blog_sync_scan($site,$source,2)['queued']===0,'unchanged snapshots deduplicated'); $first=$site->query('SELECT * FROM site_blog_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check(json_decode($first['snapshot'],true)===['id'=>1,'is_published'=>1] && !str_contains($first['snapshot'],'PRIVATE'),'post text excluded');
site_blog_sync_scan($site,$source,2); $site->exec("UPDATE blog_posts SET content_fa='SECRET CHANGED',title_fa='SECRET TITLE' WHERE id=1"); check(site_blog_sync_scan($site,$source,2)['queued']===0,'text change does not update metadata revision');
site_blog_sync_scan($site,$source,2); $site->exec('UPDATE blog_posts SET is_published=0 WHERE id=1'); check(site_blog_sync_scan($site,$source,2)['queued']===1,'publication label changes revision'); $new=$site->query('SELECT * FROM site_blog_meta_outbox WHERE id=3')->fetch(PDO::FETCH_ASSOC);
function post_event(array $r): array { return ['type'=>'site_blog_meta_v1','source_id'=>$r['source_id'],'event_id'=>$r['event_id'],'revision'=>(int)$r['id'],'post'=>json_decode($r['snapshot'],true)]; }
$crm=new BlogTestPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $crm->exec('CREATE TABLE crm_site_blog_meta(id INTEGER PRIMARY KEY,source_id TEXT,post_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,is_published INTEGER,received_at TEXT,UNIQUE(source_id,post_id))');
$data=post_event($new); $old=post_event($first); $hash=hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES));
check(crm_site_blog_metadata_validate($data,$source) && crm_site_blog_sync_store($crm,$data,$hash)==='updated' && crm_site_blog_sync_store($crm,$data,$hash)==='duplicate' && crm_site_blog_sync_store($crm,$data,str_repeat('f',64))==='conflict' && crm_site_blog_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','revision idempotency, conflict and ordering');
foreach (['title_fa','content_fa','slug'] as $field) { $bad=$data; $bad['post'][$field]='PRIVATE'; check(!crm_site_blog_metadata_validate($bad,$source),'extra text field rejected'); }
$bad=$data; $bad['post']['is_published']=true; check(!crm_site_blog_metadata_validate($bad,$source),'strict label type');
$lost=true; $send=function($body) use($crm,$source,&$lost) { $e=json_decode($body,true); check(crm_site_blog_metadata_validate($e,$source) && !str_contains($body,'PRIVATE') && !str_contains($body,'SECRET'),'safe payload'); $r=crm_site_blog_sync_store($crm,$e,hash('sha256',$body)); if ($lost) { $lost=false; return [0,null]; } return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$r]]; };
$when=strtotime((string)$site->query('SELECT MAX(next_attempt_at) FROM site_blog_meta_outbox')->fetchColumn())+1;
$r=site_blog_sync_pump($site,$source,$send,$when); check($r['failed']===1 && $r['sent']===2 && site_blog_sync_pump($site,$source,$send,$when+61)['sent']===1,'lost reply and retry');
check(site_blog_sync_reconcile($site,$source,2)['queued']===0 && site_blog_sync_reconcile($site,$source,2)['sweep_complete'],'bounded reconciliation'); $site->exec('DELETE FROM blog_posts WHERE id=1'); check(site_blog_sync_reconcile($site,$source,2)['queued']===1,'absence marker queued');
$tomb=$site->query('SELECT * FROM site_blog_meta_outbox WHERE id=4')->fetch(PDO::FETCH_ASSOC); check(json_decode($tomb['snapshot'],true)===['id'=>1,'deleted'=>true],'absence marker excludes content');
$marker=['type'=>'site_blog_meta_deleted_v1','source_id'=>$source,'event_id'=>$tomb['event_id'],'revision'=>4,'post'=>json_decode($tomb['snapshot'],true)]; check(site_blog_sync_pump($site,$source,$send,$when+120)['sent']===1 && (int)$crm->query('SELECT is_published FROM crm_site_blog_meta WHERE post_id=1')->fetchColumn()===2,'marker stored, preserving revision');
check(crm_site_blog_sync_store($crm,$old,hash('sha256',json_encode($old)))==='stale','old event cannot resurrect');
$site->exec("INSERT INTO blog_posts VALUES(1,1,'PRIVATE TITLE','PRIVATE BODY','PRIVATE SLUG')"); check(site_blog_sync_capture($site,$source,1) && site_blog_sync_pump($site,$source,$send,$when+180)['sent']===1 && crm_site_blog_sync_store($crm,$marker,hash('sha256',json_encode($marker)))==='stale','later reappearance and stale marker');
$site->exec('UPDATE blog_posts SET is_published=0 WHERE id=1'); $site->exec("CREATE TRIGGER fail_blog BEFORE INSERT ON site_blog_meta_outbox BEGIN SELECT RAISE(ABORT,'queue'); END");
$before=$site->query('SELECT snapshot_hash FROM site_blog_meta_state WHERE post_id=1')->fetchColumn(); try { site_blog_sync_capture($site,$source,1); check(false,'queue failure'); } catch (PDOException $e) {}
check($site->query('SELECT snapshot_hash FROM site_blog_meta_state WHERE post_id=1')->fetchColumn()===$before && !$site->inTransaction(),'queue failure rolls back'); $site->exec('DROP TRIGGER fail_blog'); check(site_blog_sync_capture($site,$source,1),'capture retries');
$site->prepare('UPDATE site_blog_meta_outbox SET snapshot=? WHERE id=6')->execute([json_encode(['id'=>1,'is_published'=>0,'content_fa'=>'PRIVATE'])]); $called=false;
try { site_blog_sync_pump($site,$source,function($body) use (&$called) { $called=true; return [200,null]; },$when+240); check(false,'tampered queue'); } catch (RuntimeException $e) { check(!$called,'extra content never sent'); }
