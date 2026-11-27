<?php
/** Unlink must remain available with the receiver disabled and without replica/group tables. */
class CategoryDisabledPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
require_once __DIR__.'/../includes/shop_category_groups.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$p=new CategoryDisabledPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT); INSERT INTO users VALUES(1,'active','admin')");
$p->exec('CREATE TABLE crm_shop_category_group_links(id INTEGER PRIMARY KEY,source_id TEXT,category_id INTEGER,replica_id INTEGER,group_id INTEGER,version INTEGER,source_revision INTEGER,group_version INTEGER,updated_by INTEGER,updated_at TEXT)');
$p->exec('CREATE TABLE crm_shop_category_group_link_events(id INTEGER PRIMARY KEY,link_id INTEGER,version INTEGER,source_id TEXT,category_id INTEGER,replica_id INTEGER,old_group_id INTEGER,new_group_id INTEGER,source_revision INTEGER,group_version INTEGER,actor_id INTEGER,created_at TEXT)');
$p->prepare('INSERT INTO crm_shop_category_group_links VALUES(1,?,7,9,3,2,4,1,1,?)')->execute([str_repeat('a',32),'2025-01-01']);
try { shop_category_group_link($p,1,true,9,4,3,1,2,true); check(false,'link with bridge disabled'); } catch (DomainException $e) { check(true,'new link disabled'); }
shop_category_group_unlink($p,1,true,1,2,true);
$row=$p->query('SELECT * FROM crm_shop_category_group_links')->fetch(PDO::FETCH_ASSOC);
check($row['group_id']===null && (int)$row['version']===3 && (int)$p->query('SELECT COUNT(*) FROM crm_shop_category_group_link_events')->fetchColumn()===1,'unlink works without bridge, replica or target table');
