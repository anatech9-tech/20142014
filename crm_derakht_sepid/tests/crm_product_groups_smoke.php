<?php
/** SQLite fixtures only; no real MySQL locking, browser, shop or live data. */
class ProductGroupPDO extends PDO { public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); } }
require_once __DIR__.'/../includes/crm_product_groups.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $fn,string $label) { try { $fn(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$p=new ProductGroupPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT,factory_price INTEGER)',
 'CREATE TABLE crm_shop_category_groups(id INTEGER PRIMARY KEY,name TEXT,version INTEGER,is_active INTEGER)',
 'CREATE TABLE crm_product_group_links(id INTEGER PRIMARY KEY AUTOINCREMENT,product_id INTEGER,group_id INTEGER,is_linked INTEGER,version INTEGER,group_version INTEGER,product_name TEXT,updated_by INTEGER,updated_at TEXT,UNIQUE(product_id,group_id))',
 'CREATE TABLE crm_product_group_events(id INTEGER PRIMARY KEY AUTOINCREMENT,link_id INTEGER,product_id INTEGER,group_id INTEGER,version INTEGER,group_version INTEGER,action TEXT,actor_id INTEGER,created_at TEXT,UNIQUE(link_id,version))'
] as $sql) $p->exec($sql);
$p->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin'),(4,'active','admin'); INSERT INTO crm_products VALUES(1,'کالا',123),(2,'other',321); INSERT INTO crm_shop_category_groups VALUES(1,'group',1,1),(2,'inactive',1,0),(3,'another',1,1)");
check(crm_product_groups_ensure($p),'product grouping tables available');
foreach (['',str_repeat('a',201),"a\x00", "\xff"] as $invalid) denied(fn()=>crm_product_group_link($p,1,true,1,$invalid,1,1,0,true),'invalid product name refused');
denied(fn()=>crm_product_group_link($p,1,false,1,'کالا',1,1,0,true),'session role required'); denied(fn()=>crm_product_group_link($p,2,true,1,'کالا',1,1,0,true),'database role required'); denied(fn()=>crm_product_group_link($p,3,true,1,'کالا',1,1,0,true),'inactive admin refused'); denied(fn()=>crm_product_group_link($p,1,true,1,'کالا',1,1,0,false),'confirmation required');
denied(fn()=>crm_product_group_link($p,1,true,1,'stale',1,1,0,true),'stale product name rejected'); denied(fn()=>crm_product_group_link($p,1,true,99,'missing',1,1,0,true),'missing product rejected'); denied(fn()=>crm_product_group_link($p,1,true,1,'کالا',2,1,0,true),'inactive group rejected'); denied(fn()=>crm_product_group_link($p,1,true,1,'کالا',1,2,0,true),'stale group version rejected');
$p->exec("CREATE TRIGGER fail_event BEFORE INSERT ON crm_product_group_events BEGIN SELECT RAISE(ABORT,'history'); END");
try { crm_product_group_link($p,1,true,1,'کالا',1,1,0,true); check(false,'history failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT COUNT(*) FROM crm_product_group_links')->fetchColumn()===0 && !$p->inTransaction(),'event failure rolls back link'); $p->exec('DROP TRIGGER fail_event');
crm_product_group_link($p,1,true,1,'کالا',1,1,0,true); crm_product_group_link($p,4,true,1,'کالا',3,1,0,true); crm_product_group_link($p,1,true,2,'other',1,1,0,true);
check((int)$p->query('SELECT COUNT(*) FROM crm_product_group_links WHERE is_linked=1')->fetchColumn()===3,'multiple groups and products supported');
check((int)$p->query('SELECT factory_price FROM crm_products WHERE id=1')->fetchColumn()===123,'product fields remain unchanged');
denied(fn()=>crm_product_group_link($p,1,true,1,'کالا',1,1,0,true),'stale link version rejected'); denied(fn()=>crm_product_group_link($p,1,true,1,'کالا',1,1,1,true),'unchanged link rejected');
$p->exec("UPDATE crm_products SET name='نام تازه' WHERE id=1; UPDATE crm_shop_category_groups SET version=2 WHERE id=1");
denied(fn()=>crm_product_group_link($p,1,true,1,'کالا',1,2,1,true),'name change requires new confirmation');
crm_product_group_link($p,1,true,1,'نام تازه',1,2,1,true); $row=$p->query('SELECT * FROM crm_product_group_links WHERE product_id=1 AND group_id=1')->fetch(PDO::FETCH_ASSOC);
check((int)$row['version']===2 && (int)$row['group_version']===2 && $row['product_name']==='نام تازه','explicit reconfirmation captures name and group revision');
$p->exec("CREATE TRIGGER fail_event BEFORE INSERT ON crm_product_group_events BEGIN SELECT RAISE(ABORT,'history'); END");
try { crm_product_group_unlink($p,1,true,(int)$row['id'],2,true); check(false,'unlink failure'); } catch (PDOException $e) {}
check((int)$p->query('SELECT version FROM crm_product_group_links WHERE id=1')->fetchColumn()===2,'unlink and event are atomic'); $p->exec('DROP TRIGGER fail_event');
denied(fn()=>crm_product_group_unlink($p,1,true,1,1,true),'stale unlink refused'); denied(fn()=>crm_product_group_unlink($p,1,true,1,2,false),'unlink confirmation required');
$p->exec('DELETE FROM crm_products WHERE id=1; DELETE FROM crm_shop_category_groups WHERE id=1'); crm_product_group_unlink($p,1,true,1,2,true);
$row=$p->query('SELECT * FROM crm_product_group_links WHERE id=1')->fetch(PDO::FETCH_ASSOC);
check((int)$row['is_linked']===0 && (int)$row['version']===3 && $row['product_name']===null && $row['group_version']===null,'unlink works without product/group and retains tombstone');
$p->exec("INSERT INTO crm_products VALUES(1,'returned',123); INSERT INTO crm_shop_category_groups VALUES(1,'returned',3,1)");
denied(fn()=>crm_product_group_link($p,1,true,1,'returned',1,3,0,true),'old create cannot overwrite tombstone'); crm_product_group_link($p,1,true,1,'returned',1,3,3,true);
check((int)$p->query('SELECT version FROM crm_product_group_links WHERE id=1')->fetchColumn()===4 && (int)$p->query('SELECT COUNT(*) FROM crm_product_group_events')->fetchColumn()===6,'relink uses tombstone version and has history');
$p->exec('UPDATE crm_product_group_links SET version=1000000 WHERE id=1'); denied(fn()=>crm_product_group_unlink($p,1,true,1,1000000,true),'version ceiling enforced');
for ($i=10;$i<208;$i++) $p->prepare("INSERT INTO crm_product_group_links(product_id,group_id,is_linked,version,updated_by,updated_at) VALUES(1,?,0,1,1,'2025-01-01')")->execute([$i]);
check((int)$p->query('SELECT COUNT(*) FROM crm_product_group_links WHERE product_id=1')->fetchColumn()===200,'fixture at lifetime cap'); $p->exec("INSERT INTO crm_shop_category_groups VALUES(500,'new',1,1)");
denied(fn()=>crm_product_group_link($p,1,true,1,'returned',500,1,0,true),'lifetime cap includes tombstones');
check(!$p->inTransaction(),'failed operations leave no transaction open');
