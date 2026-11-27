<?php
/** SQLite fixture and mock transport only; no MySQL locking, HTTP or content approval proof. */
class BlogGuardPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,post_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE guard_hash=VALUES(guard_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,post_id) DO UPDATE SET guard_hash=excluded.guard_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE id=id')) { $sql=str_replace('INSERT INTO crm_site_blog_meta','INSERT OR IGNORE INTO crm_site_blog_meta',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE id=id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SITE_BLOG_META_URL','https://example.test/api'); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32)); define('SITE_BLOG_CONTENT_GUARD_ENABLED',true); define('SITE_BLOG_CONTENT_GUARD_KEY',str_repeat('g',32));
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php'; require_once __DIR__.'/../includes/site_blog_draft_links.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$source=SITE_BLOG_SOURCE_ID; $p=new BlogGuardPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE blog_posts(id INTEGER PRIMARY KEY,is_published INTEGER,category_id INTEGER,author_id INTEGER,is_featured INTEGER,slug TEXT,title_fa TEXT,title_en TEXT,excerpt_fa TEXT,excerpt_en TEXT,content_fa TEXT,content_en TEXT,cover_image TEXT,published_at TEXT,meta_title TEXT,meta_description TEXT,meta_keywords TEXT,created_at TEXT,views INTEGER,updated_at TEXT)',
 'CREATE TABLE site_blog_meta_cursor(source_id TEXT PRIMARY KEY,last_post_id INTEGER)',
 'CREATE TABLE site_blog_meta_state(source_id TEXT,post_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,post_id))',
 'CREATE TABLE site_blog_content_guard_state(source_id TEXT,post_id INTEGER,guard_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,post_id))',
 'CREATE TABLE site_blog_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,post_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
] as $sql) $p->exec($sql);
$p->exec("INSERT INTO blog_posts VALUES(7,1,4,5,0,'PRIVATE SLUG','PRIVATE TITLE',NULL,'PRIVATE EXCERPT',NULL,'PRIVATE BODY',NULL,'PRIVATE IMAGE',NULL,'PRIVATE META',NULL,NULL,'2025-01-01',0,'2025-01-01')");
check(site_blog_guard_configured() && site_blog_sync_configured(),'separate site-only guard enabled');
check(site_blog_sync_scan($p,$source,20)['queued']===1,'capture writes guard and metadata revision');
$first=$p->query('SELECT * FROM site_blog_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC); $guard=$p->query('SELECT * FROM site_blog_content_guard_state')->fetch(PDO::FETCH_ASSOC);
check(json_decode($first['snapshot'],true)===['id'=>7,'is_published'=>1] && (int)$guard['latest_revision']===1 && strlen($guard['guard_hash'])===64 && !str_contains($first['snapshot'],'PRIVATE') && !str_contains($first['snapshot'],$guard['guard_hash']),'guard and content stay out of metadata queue');
$row=$p->query('SELECT * FROM blog_posts')->fetch(PDO::FETCH_ASSOC); $base=site_blog_guard_hash($source,$row);
foreach (['slug','title_fa','title_en','excerpt_fa','excerpt_en','content_fa','content_en','cover_image','published_at','meta_title','meta_description','meta_keywords','created_at'] as $field) { $changed=$row; $changed[$field]='CHANGED'; check(site_blog_guard_hash($source,$changed)!==$base,$field.' contributes to guard'); }
foreach (['category_id','author_id','is_featured'] as $field) { $changed=$row; $changed[$field]=$field==='is_featured'?1:9; check(site_blog_guard_hash($source,$changed)!==$base,$field.' contributes to guard'); }
$empty=$row; $empty['title_en']=''; check(site_blog_guard_hash($source,$empty)!==$base,'null and empty remain distinct');
$a=$row; $a['slug']='a'; $a['title_fa']='bc'; $b=$row; $b['slug']='ab'; $b['title_fa']='c'; check(site_blog_guard_hash($source,$a)!==site_blog_guard_hash($source,$b),'field length framing prevents concatenation ambiguity');
$p->exec("UPDATE blog_posts SET content_fa='CHANGED PRIVATE BODY'"); check(site_blog_sync_scan($p,$source,20)['sweep_complete'] && site_blog_sync_scan($p,$source,20)['queued']===1,'text-only change produces newer metadata revision');
$second=$p->query('SELECT * FROM site_blog_meta_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC); $guard2=$p->query('SELECT * FROM site_blog_content_guard_state')->fetch(PDO::FETCH_ASSOC);
check($first['snapshot']===$second['snapshot'] && (int)$guard2['latest_revision']===2 && $guard['guard_hash']!==$guard2['guard_hash'],'text-only change leaves metadata identical but changes guard');
$crm=new BlogGuardPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $crm->exec('CREATE TABLE crm_site_blog_meta(id INTEGER PRIMARY KEY,source_id TEXT,post_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,is_published INTEGER,received_at TEXT,UNIQUE(source_id,post_id))');
$sent=[]; $send=function($body) use($crm,$source,&$sent,$guard,$guard2) { $sent[]=$body; $e=json_decode($body,true); check(crm_site_blog_metadata_validate($e,$source) && !str_contains($body,'PRIVATE') && !str_contains($body,$guard['guard_hash']) && !str_contains($body,$guard2['guard_hash']),'wire event carries neither content nor fingerprint'); $r=crm_site_blog_sync_store($crm,$e,hash('sha256',$body)); return [200,['ok'=>true,'event_id'=>$e['event_id'],'revision'=>$e['revision'],'result'=>$r]]; };
$when=strtotime((string)$p->query('SELECT MAX(next_attempt_at) FROM site_blog_meta_outbox')->fetchColumn())+1;
check(site_blog_sync_pump($p,$source,$send,$when)['sent']===2 && (int)$crm->query('SELECT revision FROM crm_site_blog_meta')->fetchColumn()===2,'CRM receives newer revision for later warning');
foreach (['CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY,version INTEGER)',
 'CREATE TABLE crm_site_blog_draft_links(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER UNIQUE,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,linked_by INTEGER,linked_at TEXT,UNIQUE(source_id,post_id))',
 'CREATE TABLE crm_site_blog_draft_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,draft_id INTEGER,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'] as $sql) $crm->exec($sql);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'); INSERT INTO crm_site_content_drafts VALUES(1,1)");
site_blog_draft_link($crm,1,1,1,2,1,true,true); check((int)$crm->query('SELECT post_revision FROM crm_site_blog_draft_links')->fetchColumn()===2,'manual link records observed revision');
$before=$p->query('SELECT latest_revision FROM site_blog_meta_state')->fetchColumn(); $p->exec("UPDATE blog_posts SET title_fa='NEW PRIVATE TITLE'");
$p->exec("CREATE TRIGGER fail_blog_guard BEFORE UPDATE ON site_blog_content_guard_state BEGIN SELECT RAISE(ABORT,'guard'); END");
try { site_blog_sync_capture($p,$source,7); check(false,'guard write failure'); } catch (PDOException $e) {}
check($p->query('SELECT latest_revision FROM site_blog_meta_state')->fetchColumn()===$before && (int)$p->query('SELECT COUNT(*) FROM site_blog_meta_outbox')->fetchColumn()===2 && !$p->inTransaction(),'guard failure rolls back metadata and outbox');
$p->exec('DROP TRIGGER fail_blog_guard'); check(site_blog_sync_capture($p,$source,7),'failed guard capture retries');
check(site_blog_sync_pump($p,$source,$send,$when+61)['sent']===1 && (int)$crm->query('SELECT revision FROM crm_site_blog_meta')->fetchColumn()===3 && (int)$crm->query('SELECT post_revision FROM crm_site_blog_draft_links')->fetchColumn()===2,'guarded title update makes earlier link revision stale after delivery');
$p->exec('UPDATE blog_posts SET views=views+1,updated_at=\'2026-01-01\''); check(!site_blog_sync_capture($p,$source,7),'views and updated_at do not create revisions');
$prev=$p->query('SELECT guard_hash FROM site_blog_content_guard_state')->fetchColumn(); $p->exec("UPDATE blog_posts SET title_fa='TEMPORARY' WHERE id=7"); $p->exec("UPDATE blog_posts SET title_fa='NEW PRIVATE TITLE' WHERE id=7");
check(!site_blog_sync_capture($p,$source,7) && $p->query('SELECT guard_hash FROM site_blog_content_guard_state')->fetchColumn()===$prev,'change and return between scans cannot be detected');
$p->exec("UPDATE site_blog_content_guard_state SET guard_hash='0000000000000000000000000000000000000000000000000000000000000000'"); check(site_blog_sync_capture($p,$source,7),'mismatched guard creates newer revision');
$bad=$row; $bad['content_fa']=str_repeat('x',1048577); try { site_blog_guard_hash($source,$bad); check(false,'oversized content'); } catch (RuntimeException $e) { check(!str_contains($e->getMessage(),'PRIVATE'),'oversized content fails without disclosure'); }
$bad=$row; $bad['title_fa']=null; try { site_blog_guard_hash($source,$bad); check(false,'missing title'); } catch (RuntimeException $e) { check(true,'invalid content fails closed'); }

$p->exec('DROP TABLE site_blog_content_guard_state'); $before=$p->query('SELECT latest_revision FROM site_blog_meta_state')->fetchColumn(); $outbox=(int)$p->query('SELECT COUNT(*) FROM site_blog_meta_outbox')->fetchColumn(); $p->exec("UPDATE blog_posts SET title_fa='UNSEEN CHANGE'");
try { site_blog_sync_capture($p,$source,7); check(false,'missing guard state'); } catch (PDOException $e) { check(!$p->inTransaction() && $p->query('SELECT latest_revision FROM site_blog_meta_state')->fetchColumn()===$before && (int)$p->query('SELECT COUNT(*) FROM site_blog_meta_outbox')->fetchColumn()===$outbox,'missing guard table fails closed without metadata update'); }
