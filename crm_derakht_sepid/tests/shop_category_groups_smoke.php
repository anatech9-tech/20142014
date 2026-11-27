<?php
/** SQLite fixtures: not MySQL locking, HTTP, browser or proof of identity. */
class CategoryGroupPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../includes/shop_category_groups.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$p=new CategoryGroupPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_category_meta(id INTEGER PRIMARY KEY,source_id TEXT,category_id INTEGER,revision INTEGER,category_name TEXT,is_active INTEGER,received_at TEXT,UNIQUE(source_id,category_id))',
 'CREATE TABLE crm_shop_category_groups(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE,is_active INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT)',
 'CREATE TABLE crm_shop_category_group_events(id INTEGER PRIMARY KEY AUTOINCREMENT,group_id INTEGER,version INTEGER,old_name TEXT,new_name TEXT,old_active INTEGER,new_active INTEGER,actor_id INTEGER,created_at TEXT,UNIQUE(group_id,version))',
 'CREATE TABLE crm_shop_category_group_links(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,category_id INTEGER,replica_id INTEGER,group_id INTEGER,version INTEGER,source_revision INTEGER,group_version INTEGER,updated_by INTEGER,updated_at TEXT,UNIQUE(source_id,category_id))',
 'CREATE TABLE crm_shop_category_group_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,version INTEGER,source_id TEXT,category_id INTEGER,replica_id INTEGER,old_group_id INTEGER,new_group_id INTEGER,source_revision INTEGER,group_version INTEGER,actor_id INTEGER,created_at TEXT,UNIQUE(link_id,version))',
 'CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT)'
] as $sql) $p->exec($sql);
$p->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin'),(4,'active','admin'); INSERT INTO crm_products VALUES(1,'unchanged')");
$st=$p->prepare('INSERT INTO crm_shop_category_meta VALUES(?,?,?,?,?,?,?)'); $st->execute([11,SHOP_CRM_SOURCE_ID,7,1,'category',1,'2025-01-01']); $st->execute([12,SHOP_CRM_SOURCE_ID,8,1,'second',0,'2025-01-01']); $st->execute([13,str_repeat('b',32),7,1,'other',1,'2025-01-01']); $st->execute([14,SHOP_CRM_SOURCE_ID,9,1,null,0,'2025-01-01']);
check(shop_category_groups_ensure($p),'group tables available');
foreach (['','  ',str_repeat('x',101),"bad\x00", "\xff"] as $name) denied(fn()=>shop_category_group_save($p,1,true,0,0,$name,1,true),'invalid group name rejected');
denied(fn()=>shop_category_group_save($p,2,true,0,0,'name',1,true),'database role enforced'); denied(fn()=>shop_category_group_save($p,3,true,0,0,'name',1,true),'inactive admin rejected'); denied(fn()=>shop_category_group_save($p,1,false,0,0,'name',1,true),'session role required'); denied(fn()=>shop_category_group_save($p,1,true,0,0,'name',1,false),'confirmation required');
$p->exec("CREATE TRIGGER fail_group_event BEFORE INSERT ON crm_shop_category_group_events BEGIN SELECT RAISE(ABORT,'fail'); END");
try { shop_category_group_save($p,1,true,0,0,'group',1,true); check(false,'event failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT COUNT(*) FROM crm_shop_category_groups')->fetchColumn()===0 && !$p->inTransaction(),'group event failure rolls back'); $p->exec('DROP TRIGGER fail_group_event');
$id=shop_category_group_save($p,1,true,0,0,' گروه ',1,true); check($id===1 && $p->query('SELECT name FROM crm_shop_category_groups')->fetchColumn()==='گروه','internal group created and trimmed');
denied(fn()=>shop_category_group_save($p,1,true,1,2,'x',1,true),'stale group edit rejected'); denied(fn()=>shop_category_group_save($p,1,true,1,1,'گروه',1,true),'group no-op rejected');
denied(fn()=>shop_category_group_link($p,1,true,11,1,1,1,0,false),'two confirmations required by caller'); denied(fn()=>shop_category_group_link($p,2,true,11,1,1,1,0,true),'link checks actor role'); denied(fn()=>shop_category_group_link($p,1,true,11,2,1,1,0,true),'stale category revision rejected'); denied(fn()=>shop_category_group_link($p,1,true,13,1,1,1,0,true),'other source rejected'); denied(fn()=>shop_category_group_link($p,1,true,14,1,1,1,0,true),'absence marker cannot be mapped'); denied(fn()=>shop_category_group_link($p,1,true,11,1,1,2,0,true),'stale target group rejected');
$p->exec("CREATE TRIGGER fail_link_event BEFORE INSERT ON crm_shop_category_group_link_events BEGIN SELECT RAISE(ABORT,'fail'); END");
try { shop_category_group_link($p,1,true,11,1,1,1,0,true); check(false,'link event failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT COUNT(*) FROM crm_shop_category_group_links')->fetchColumn()===0 && !$p->inTransaction(),'link event failure rolls back'); $p->exec('DROP TRIGGER fail_link_event');
shop_category_group_link($p,1,true,11,1,1,1,0,true); shop_category_group_link($p,4,true,12,1,1,1,0,true);
check((int)$p->query('SELECT COUNT(*) FROM crm_shop_category_group_links WHERE group_id=1')->fetchColumn()===2,'multiple shop categories can reference same internal group');
denied(fn()=>shop_category_group_link($p,1,true,11,1,1,1,0,true),'stale link version rejected'); denied(fn()=>shop_category_group_link($p,1,true,11,1,1,1,1,true),'unchanged link rejected');
shop_category_group_save($p,1,true,1,1,'گروه تازه',0,true); denied(fn()=>shop_category_group_link($p,1,true,11,1,1,2,1,true),'inactive group cannot be linked');
shop_category_group_save($p,1,true,1,2,'گروه تازه',1,true); $p->exec('UPDATE crm_shop_category_meta SET revision=2 WHERE id=11');
denied(fn()=>shop_category_group_link($p,1,true,11,1,1,3,1,true),'updated source requires expected revision');
shop_category_group_link($p,1,true,11,2,1,3,1,true); $row=$p->query('SELECT * FROM crm_shop_category_group_links WHERE category_id=7')->fetch(PDO::FETCH_ASSOC);
check((int)$row['version']===2 && (int)$row['source_revision']===2 && (int)$row['group_version']===3,'explicit reconfirmation updates both revisions');
$p->exec('UPDATE crm_shop_category_meta SET id=15 WHERE id=11'); shop_category_group_link($p,1,true,15,2,1,3,2,true); $row=$p->query('SELECT * FROM crm_shop_category_group_links WHERE category_id=7')->fetch(PDO::FETCH_ASSOC);
check((int)$row['replica_id']===15 && (int)$row['version']===3,'changed replica identity requires explicit reconfirmation');
$p->exec("CREATE TRIGGER fail_link_event BEFORE INSERT ON crm_shop_category_group_link_events BEGIN SELECT RAISE(ABORT,'fail'); END");
try { shop_category_group_unlink($p,1,true,(int)$row['id'],3,true); check(false,'unlink event failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT version FROM crm_shop_category_group_links WHERE category_id=7')->fetchColumn()===3,'failed unlink audit rolls back'); $p->exec('DROP TRIGGER fail_link_event');
denied(fn()=>shop_category_group_unlink($p,1,true,(int)$row['id'],2,true),'stale unlink rejected'); denied(fn()=>shop_category_group_unlink($p,1,true,(int)$row['id'],3,false),'unlink confirmation required');
$p->exec('DELETE FROM crm_shop_category_meta WHERE id=15'); shop_category_group_unlink($p,1,true,(int)$row['id'],3,true);
$row=$p->query('SELECT * FROM crm_shop_category_group_links WHERE category_id=7')->fetch(PDO::FETCH_ASSOC);
check($row['group_id']===null && (int)$row['version']===4 && $row['source_revision']===null,'unlink after missing source keeps version tombstone');
check((int)$p->query('SELECT COUNT(*) FROM crm_shop_category_group_link_events')->fetchColumn()===5 && $p->query('SELECT name FROM crm_products')->fetchColumn()==='unchanged','atomic history recorded and CRM products untouched');
