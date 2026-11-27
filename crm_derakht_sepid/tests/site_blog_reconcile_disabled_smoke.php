<?php
define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32)); define('SITE_BLOG_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php'; require_once __DIR__.'/../includes/site_blog_metadata_sync.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(site_blog_sync_configured() && crm_site_blog_sync_configured() && !site_blog_sync_deletion_enabled() && !crm_site_blog_sync_deletion_enabled(),'absence flags separately off');
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { site_blog_sync_reconcile($p,SITE_BLOG_SOURCE_ID); check(false,'scan'); } catch (DomainException $e) { check(true,'disabled reconciliation refuses scan'); }
$e=['type'=>'site_blog_meta_deleted_v1','source_id'=>SITE_BLOG_SOURCE_ID,'event_id'=>str_repeat('c',32),'revision'=>1,'post'=>['id'=>7,'deleted'=>true]];
try { crm_site_blog_sync_store($p,$e,str_repeat('f',64)); check(false,'receiver'); } catch (DomainException $e) { check(true,'disabled receiver refuses absence marker'); }
$p->exec('CREATE TABLE site_blog_meta_outbox(id INTEGER PRIMARY KEY,source_id TEXT,post_id INTEGER,event_id TEXT,snapshot TEXT,attempts INTEGER,next_attempt_at TEXT,delivered_at TEXT)');
$p->prepare('INSERT INTO site_blog_meta_outbox VALUES(1,?,7,?,?,0,?,NULL)')->execute([SITE_BLOG_SOURCE_ID,str_repeat('c',32),json_encode(['id'=>7,'deleted'=>true]),'2000-01-01']);
$called=false; $r=site_blog_sync_pump($p,SITE_BLOG_SOURCE_ID,function($body) use (&$called) { $called=true; return [503,null]; },time()); check($called && $r['failed']===1 && $p->query('SELECT delivered_at FROM site_blog_meta_outbox')->fetchColumn()===null,'queued marker still sent after scanner opt-out and retained on failure');
