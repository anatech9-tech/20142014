<?php
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php'; require_once __DIR__.'/../includes/site_blog_draft_links.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!site_blog_sync_configured() && !crm_site_blog_sync_configured() && !site_blog_sync_deletion_enabled() && !crm_site_blog_sync_deletion_enabled(),'blog bridge and reconciliation default disabled');
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { site_blog_draft_link($p,1,1,1,1,1,true,true); check(false,'disabled link'); } catch (DomainException $e) { check(true,'disabled bridge refuses new links'); }
try { site_blog_sync_send('{}'); check(false,'disabled sender'); } catch (RuntimeException $e) { check(true,'disabled sender refuses network'); }
try { crm_site_blog_sync_store($p,[],str_repeat('f',64)); check(false,'disabled receiver'); } catch (DomainException $e) { check(true,'disabled receiver refuses store'); }
check((int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,'disabled actions create no tables');
class BlogDisabledPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
$p=new BlogDisabledPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach (['CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)','CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY,version INTEGER)',
 'CREATE TABLE crm_site_blog_draft_links(id INTEGER PRIMARY KEY,draft_id INTEGER,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,linked_by INTEGER,linked_at TEXT)',
 'CREATE TABLE crm_site_blog_draft_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,draft_id INTEGER,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'] as $sql) $p->exec($sql);
$p->exec("INSERT INTO users VALUES(1,'active','admin'); INSERT INTO crm_site_content_drafts VALUES(1,1); INSERT INTO crm_site_blog_draft_links VALUES(1,1,'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',7,2,1,3,1,'2025-01-01')");
site_blog_draft_unlink($p,1,1,1,1,true,true); check((int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_links')->fetchColumn()===0 && (int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_link_events')->fetchColumn()===1,'existing link can be detached while bridge disabled');
