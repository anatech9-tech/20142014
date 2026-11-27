<?php
/** SQLite fixture only; not a MySQL concurrency or real user review test. */
class ReviewPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
require_once __DIR__.'/../includes/site_content_draft_reviews.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $fn,$label) { try { $fn(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$p=new ReviewPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,body TEXT,version INTEGER,created_by INTEGER,updated_by INTEGER,created_at TEXT,updated_at TEXT)',
 'CREATE TABLE crm_site_content_draft_versions(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER,version INTEGER,title TEXT,body TEXT,actor_id INTEGER,action TEXT,restored_version INTEGER,created_at TEXT,UNIQUE(draft_id,version))',
 'CREATE TABLE crm_site_content_draft_reviews(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER,draft_version INTEGER,actor_id INTEGER,created_at TEXT,UNIQUE(draft_id,draft_version,actor_id))'
] as $sql) $p->exec($sql);
$p->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin'),(4,'active','admin')");
$id=content_draft_write($p,1,true,0,0,'create','عنوان','secret content');
check(content_draft_review_ensure($p),'review table available');
denied(fn()=>content_draft_review_record($p,1,true,$id,1,false),'confirmation required');
denied(fn()=>content_draft_review_record($p,1,false,$id,1,true),'session authorization required');
denied(fn()=>content_draft_review_record($p,2,true,$id,1,true),'database role required');
denied(fn()=>content_draft_review_record($p,3,true,$id,1,true),'active account required');
denied(fn()=>content_draft_review_record($p,1,true,99,1,true),'missing draft rejected');
denied(fn()=>content_draft_review_record($p,1,true,$id,2,true),'stale version rejected');
$p->exec("CREATE TRIGGER fail_review BEFORE INSERT ON crm_site_content_draft_reviews BEGIN SELECT RAISE(ABORT,'fail'); END");
try { content_draft_review_record($p,1,true,$id,1,true); check(false,'insert failure'); } catch (PDOException $e) {}
check(!$p->inTransaction() && (int)$p->query('SELECT COUNT(*) FROM crm_site_content_draft_reviews')->fetchColumn()===0,'insert failure rolls back'); $p->exec('DROP TRIGGER fail_review');
content_draft_review_record($p,1,true,$id,1,true); content_draft_review_record($p,4,true,$id,1,true);
$rows=$p->query('SELECT * FROM crm_site_content_draft_reviews')->fetchAll(PDO::FETCH_ASSOC);
check(count($rows)===2 && (int)$rows[0]['draft_version']===1 && !str_contains(json_encode($rows),'secret content'),'independent administrators record metadata only');
denied(fn()=>content_draft_review_record($p,1,true,$id,1,true),'duplicate same actor/version rejected');
content_draft_write($p,1,true,$id,1,'edit','changed','body');
denied(fn()=>content_draft_review_record($p,1,true,$id,1,true),'old version acknowledgement rejected after edit');
check((int)$p->query('SELECT COUNT(*) FROM crm_site_content_draft_reviews WHERE draft_version=2')->fetchColumn()===0,'new version does not inherit prior acknowledgement');
$p->exec("UPDATE crm_site_content_drafts SET body='tampered' WHERE id=1");
denied(fn()=>content_draft_review_record($p,1,true,$id,2,true),'mismatch between current draft and history rejected');
$p->exec("UPDATE crm_site_content_drafts SET body='body' WHERE id=1"); content_draft_review_record($p,1,true,$id,2,true);
$p->exec('DELETE FROM crm_site_content_draft_versions WHERE draft_id=1 AND version=2'); denied(fn()=>content_draft_review_record($p,4,true,$id,2,true),'missing history fails closed');
$p->exec("INSERT INTO crm_site_content_draft_versions(draft_id,version,title,body,actor_id,action,created_at) VALUES(1,2,'changed','body',1,'edit','2025-01-01')");
for ($i=5;$i<502;$i++) $p->prepare("INSERT INTO crm_site_content_draft_reviews(draft_id,draft_version,actor_id,created_at) VALUES(1,1,?,'2025-01-01')")->execute([$i]);
check((int)$p->query('SELECT COUNT(*) FROM crm_site_content_draft_reviews')->fetchColumn()===500,'fixture at capacity');
denied(fn()=>content_draft_review_record($p,4,true,$id,2,true),'500 review limit enforced');
check((int)$p->query('SELECT COUNT(*) FROM crm_site_content_draft_reviews')->fetchColumn()===500 && !$p->inTransaction(),'denied operations leave review ledger unchanged');
