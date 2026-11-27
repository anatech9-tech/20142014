<?php
/** SQLite fixture only: no real MySQL/InnoDB concurrency, website or browser. */
class ContentDraftPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
require_once __DIR__.'/../includes/site_content_drafts.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $fn,$label) { try { $fn(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$p=new ContentDraftPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)'); $p->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$p->exec('CREATE TABLE crm_site_content_drafts(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT,body TEXT,version INTEGER,created_by INTEGER,updated_by INTEGER,created_at TEXT,updated_at TEXT)');
$p->exec('CREATE TABLE crm_site_content_draft_versions(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER,version INTEGER,title TEXT,body TEXT,actor_id INTEGER,action TEXT,restored_version INTEGER,created_at TEXT,UNIQUE(draft_id,version))');
denied(fn()=>content_draft_write($p,2,true,0,0,'create','x','y'),'forged admin denied'); denied(fn()=>content_draft_write($p,3,true,0,0,'create','x','y'),'inactive admin denied'); denied(fn()=>content_draft_write($p,1,false,0,0,'create','x','y'),'session admin flag denied');
foreach (['','   ',str_repeat('a',201),"bad\x00", "\xff"] as $title) denied(fn()=>content_draft_write($p,1,true,0,0,'create',$title,'body'),'invalid title rejected');
foreach ([str_repeat('a',32001),"bad\x00", "\xff"] as $body) denied(fn()=>content_draft_write($p,1,true,0,0,'create','title',$body),'invalid body rejected');
denied(fn()=>content_draft_write($p,1,true,0,0,'publish','x','y'),'publication not supported');
$p->exec("CREATE TRIGGER draft_history_failure BEFORE INSERT ON crm_site_content_draft_versions BEGIN SELECT RAISE(ABORT,'history'); END");
try { content_draft_write($p,1,true,0,0,'create','title','body'); check(false,'history fail'); } catch (PDOException $e) {}
check((int)$p->query('SELECT COUNT(*) FROM crm_site_content_drafts')->fetchColumn()===0 && !$p->inTransaction(),'history failure rolls back creation'); $p->exec('DROP TRIGGER draft_history_failure');
$id=content_draft_write($p,1,true,0,0,'create',' عنوان ','<script>not executed</script>');
$row=$p->query('SELECT * FROM crm_site_content_drafts')->fetch(PDO::FETCH_ASSOC);
check($id===1 && $row['title']==='عنوان' && (int)$row['version']===1 && (int)$p->query('SELECT COUNT(*) FROM crm_site_content_draft_versions')->fetchColumn()===1,'internal draft and initial immutable version created together');
check(content_draft_write($p,1,true,$id,1,'edit','عنوان دوم',"plain\nمتن")===1 && (int)$p->query('SELECT version FROM crm_site_content_drafts')->fetchColumn()===2,'edit advances version');
denied(fn()=>content_draft_write($p,1,true,$id,1,'edit','stale','stale'),'stale edit denied'); denied(fn()=>content_draft_write($p,1,true,$id,2,'restore','','',99),'missing historical version denied');
$p->exec("CREATE TRIGGER draft_history_failure BEFORE INSERT ON crm_site_content_draft_versions BEGIN SELECT RAISE(ABORT,'history'); END");
try { content_draft_write($p,1,true,$id,2,'restore','','',1); check(false,'restore fail'); } catch (PDOException $e) {}
check((int)$p->query('SELECT version FROM crm_site_content_drafts')->fetchColumn()===2 && $p->query('SELECT title FROM crm_site_content_drafts')->fetchColumn()==='عنوان دوم','failed history rolls back restore'); $p->exec('DROP TRIGGER draft_history_failure');
content_draft_write($p,1,true,$id,2,'restore','','',1); $current=$p->query('SELECT * FROM crm_site_content_drafts')->fetch(PDO::FETCH_ASSOC); $history=$p->query('SELECT * FROM crm_site_content_draft_versions WHERE version=3')->fetch(PDO::FETCH_ASSOC);
check((int)$current['version']===3 && $current['body']==='<script>not executed</script>' && $history['action']==='restore' && (int)$history['restored_version']===1,'restore creates new version without deleting earlier versions');
denied(fn()=>content_draft_write($p,1,true,$id,2,'restore','','',1),'replayed stale restore denied');
$p->exec('UPDATE crm_site_content_drafts SET version=100 WHERE id=1'); denied(fn()=>content_draft_write($p,1,true,$id,100,'edit','x','y'),'version limit enforced');
check((int)$p->query('SELECT COUNT(*) FROM crm_site_content_draft_versions')->fetchColumn()===3 && !$p->inTransaction(),'version limit does not alter history');
