<?php
/** SQLite fixture only; does not test MySQL locks, source site or real sessions. */
class BlogLinkPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../includes/site_blog_draft_links.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $fn,$label) { try { $fn(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$p=new BlogLinkPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY,title TEXT,body TEXT,version INTEGER)',
 'CREATE TABLE crm_site_blog_meta(id INTEGER PRIMARY KEY,source_id TEXT,post_id INTEGER,revision INTEGER,is_published INTEGER)',
 'CREATE TABLE crm_site_blog_draft_links(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER UNIQUE,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,linked_by INTEGER,linked_at TEXT,UNIQUE(source_id,post_id))',
 'CREATE TABLE crm_site_blog_draft_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,draft_id INTEGER,source_id TEXT,post_id INTEGER,replica_id INTEGER,draft_version INTEGER,post_revision INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'
] as $sql) $p->exec($sql);
$p->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$p->exec("INSERT INTO crm_site_content_drafts VALUES(1,'PRIVATE TITLE','PRIVATE BODY',3),(2,'OTHER','OTHER',1)");
$p->prepare('INSERT INTO crm_site_blog_meta VALUES(4,?,7,15,1)')->execute([SITE_BLOG_SOURCE_ID]);
denied(fn()=>site_blog_draft_link($p,1,3,4,15,2,true,true),'forged role denied'); denied(fn()=>site_blog_draft_link($p,1,3,4,15,3,true,true),'inactive admin denied'); denied(fn()=>site_blog_draft_link($p,1,3,4,15,1,false,true),'session check denied'); denied(fn()=>site_blog_draft_link($p,1,3,4,15,1,true,false),'explicit confirmation required');
denied(fn()=>site_blog_draft_link($p,1,2,4,15,1,true,true),'stale draft denied'); denied(fn()=>site_blog_draft_link($p,1,3,4,14,1,true,true),'stale article metadata denied');
$p->exec('UPDATE crm_site_blog_meta SET is_published=2'); denied(fn()=>site_blog_draft_link($p,1,3,4,15,1,true,true),'absence marker cannot be linked');
$p->exec("UPDATE crm_site_blog_meta SET is_published='bogus'"); denied(fn()=>site_blog_draft_link($p,1,3,4,15,1,true,true),'corrupt marker cannot be linked');
$p->prepare('UPDATE crm_site_blog_meta SET is_published=0,source_id=?')->execute([str_repeat('c',32)]); denied(fn()=>site_blog_draft_link($p,1,3,4,15,1,true,true),'other source denied'); $p->prepare('UPDATE crm_site_blog_meta SET source_id=?')->execute([SITE_BLOG_SOURCE_ID]);
$p->exec("CREATE TRIGGER fail_link_event BEFORE INSERT ON crm_site_blog_draft_link_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { site_blog_draft_link($p,1,3,4,15,1,true,true); check(false,'audit failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_links')->fetchColumn()===0 && !$p->inTransaction(),'failed event rolls back link'); $p->exec('DROP TRIGGER fail_link_event');
$id=site_blog_draft_link($p,1,3,4,15,1,true,true); check($id>0 && (int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_link_events')->fetchColumn()===1,'manual link and audit recorded atomically');
check(!str_contains(json_encode($p->query('SELECT * FROM crm_site_blog_draft_links')->fetch(PDO::FETCH_ASSOC)),'PRIVATE'),'link does not copy draft text');
denied(fn()=>site_blog_draft_link($p,1,3,4,15,1,true,true),'draft cannot have duplicate link'); denied(fn()=>site_blog_draft_link($p,2,1,4,15,1,true,true),'site article cannot be linked to another draft');
denied(fn()=>site_blog_draft_unlink($p,1,2,$id,1,true,true),'stale draft cannot unlink'); denied(fn()=>site_blog_draft_unlink($p,1,3,$id,1,true,false),'unlink confirmation required');
$p->exec("CREATE TRIGGER fail_unlink_event BEFORE INSERT ON crm_site_blog_draft_link_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { site_blog_draft_unlink($p,1,3,$id,1,true,true); check(false,'unlink audit failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_links')->fetchColumn()===1,'failed event rolls back unlink'); $p->exec('DROP TRIGGER fail_unlink_event');
$p->exec('UPDATE crm_site_blog_meta SET is_published=2,revision=16'); site_blog_draft_unlink($p,1,3,$id,1,true,true);
check((int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_links')->fetchColumn()===0 && (int)$p->query('SELECT COUNT(*) FROM crm_site_blog_draft_link_events')->fetchColumn()===2 && $p->query('SELECT body FROM crm_site_content_drafts WHERE id=1')->fetchColumn()==='PRIVATE BODY','unlink remains possible after missing marker and leaves content untouched');
