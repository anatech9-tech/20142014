<?php
/** SQLite tests for manual identity mapping; not MySQL locking or a real web-session test. */
class ProductMapTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_product_mapping.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new ProductMapTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
    'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
    'CREATE TABLE crm_products(id INTEGER PRIMARY KEY,name TEXT,factory_price INTEGER,customer_price INTEGER)',
    'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY,source_id TEXT,payload TEXT)',
    'CREATE TABLE crm_shop_product_links(source_id TEXT,shop_product_id INTEGER,crm_product_id INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT,PRIMARY KEY(source_id,shop_product_id))',
    'CREATE TABLE crm_shop_product_link_events(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,shop_product_id INTEGER,replica_id INTEGER,actor_id INTEGER,old_crm_product_id INTEGER,new_crm_product_id INTEGER,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES(99,'active','admin'),(2,'active','user'),(98,'inactive','admin'),(97,'active','user'); INSERT INTO crm_products VALUES(1,'Product One',110,120),(2,'Product Two',210,220)");
$a=str_repeat('a',32); $b=str_repeat('b',32);
$snapshot=['items'=>[['product_id'=>7,'product_name'=>'Item','qty'=>1,'price_toman'=>100,'total_toman'=>100],['product_id'=>null,'product_name'=>'Deleted product']]];
$insert=$pdo->prepare('INSERT INTO crm_shop_orders(id,source_id,payload) VALUES (?,?,?)'); foreach ([[1,$a],[2,$b],[3,$a]] as [$id,$source]) $insert->execute([$id,$source,json_encode($snapshot)]);
check(shop_order_contains_product($snapshot,7) && !shop_order_contains_product($snapshot,8) && !shop_order_contains_product(['items'=>[['product_id'=>'7']]],7),'order membership strictly checked');
denied(fn()=>shop_product_mapping_apply($pdo,1,2,false,7,1,0),'expert cannot map');
denied(fn()=>shop_product_mapping_apply($pdo,1,97,true,7,1,0),'stale admin role rejected');
denied(fn()=>shop_product_mapping_apply($pdo,1,98,true,7,1,0),'inactive admin rejected');
denied(fn()=>shop_product_mapping_apply($pdo,1,99,true,8,1,0),'product not in order rejected');
denied(fn()=>shop_product_mapping_apply($pdo,1,99,true,7,9,0),'missing CRM product rejected');
shop_product_mapping_apply($pdo,1,99,true,7,1,0);
$links=shop_product_links_for_order($pdo,$a,$snapshot);
check((int)$links[7]['crm_product_id']===1 && $links[7]['crm_product_name']==='Product One' && (int)$links[7]['version']===1,'manual mapping saved');
check(shop_product_links_for_order($pdo,$b,$snapshot)===[],'different source is isolated');
check((int)shop_product_links_for_order($pdo,$a,$snapshot)[7]['crm_product_id']===1,'shared product mapping visible in another order from same source');
denied(fn()=>shop_product_mapping_apply($pdo,3,99,true,7,2,0),'stale mapping version rejected');
shop_product_mapping_apply($pdo,3,99,true,7,2,1);
check((int)$pdo->query('SELECT crm_product_id FROM crm_shop_product_links')->fetchColumn()===2,'manager can remap');
check((int)$pdo->query('SELECT factory_price FROM crm_products WHERE id=1')->fetchColumn()===110 && (int)$pdo->query('SELECT customer_price FROM crm_products WHERE id=2')->fetchColumn()===220,'mapping never changes prices');
$events=$pdo->query('SELECT old_crm_product_id,new_crm_product_id,replica_id FROM crm_shop_product_link_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(count($events)===2 && (int)$events[1]['old_crm_product_id']===1 && (int)$events[1]['new_crm_product_id']===2 && (int)$events[1]['replica_id']===3,'mapping audit retains old and new IDs');
$pdo->exec("CREATE TRIGGER fail_mapping BEFORE INSERT ON crm_shop_product_link_events BEGIN SELECT RAISE(ABORT,'fail'); END");
try { shop_product_mapping_apply($pdo,1,99,true,7,0,2); check(false,'audit failure throws'); } catch (PDOException $e) {}
$row=$pdo->query('SELECT * FROM crm_shop_product_links')->fetch(PDO::FETCH_ASSOC);
check((int)$row['crm_product_id']===2 && (int)$row['version']===2,'audit failure rolls back mapping');
$pdo->exec('DROP TRIGGER fail_mapping'); shop_product_mapping_apply($pdo,1,99,true,7,0,2);
$row=$pdo->query('SELECT * FROM crm_shop_product_links')->fetch(PDO::FETCH_ASSOC);
check($row['crm_product_id']===null && (int)$row['version']===3,'unmapping retains version');
denied(fn()=>shop_product_mapping_apply($pdo,1,99,true,7,1,0),'old create version cannot overwrite tombstone');
shop_product_mapping_apply($pdo,1,99,true,7,1,3);
$pdo->exec('DELETE FROM crm_shop_orders');
denied(fn()=>shop_product_unlink_from_bank($pdo,2,false,$a,7,1,4),'only admin can unlink from bank');
denied(fn()=>shop_product_unlink_from_bank($pdo,99,true,$a,7,2,4),'bank product ID must match');
denied(fn()=>shop_product_unlink_from_bank($pdo,99,true,$a,7,1,3),'stale bank unlink denied');
shop_product_unlink_from_bank($pdo,99,true,$a,7,1,4);
$row=$pdo->query('SELECT * FROM crm_shop_product_links')->fetch(PDO::FETCH_ASSOC);
check($row['crm_product_id']===null && (int)$row['version']===5,'admin can unlink after order deletion');
check((int)$pdo->query('SELECT replica_id FROM crm_shop_product_link_events ORDER BY id DESC LIMIT 1')->fetchColumn()===0,'bank unlink is audited without an order');

// Catalogue-based mapping also works without any order and uses both revisions.
$pdo->exec('CREATE TABLE crm_shop_catalog(id INTEGER PRIMARY KEY,source_id TEXT,shop_product_id INTEGER,revision INTEGER,payload TEXT)');
$st=$pdo->prepare('INSERT INTO crm_shop_catalog VALUES(?,?,?,?,?)');
$st->execute([11,$a,8,3,json_encode(['id'=>8,'name_fa'=>'New shop item'])]);
$st->execute([12,$b,8,1,json_encode(['id'=>8,'name_fa'=>'Other shop'])]);
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,2,false,1,0,3),'catalogue mapping denies non-admin');
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,98,true,1,0,3),'catalogue mapping denies inactive admin');
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,99,true,1,0,2),'catalogue mapping rejects stale snapshot');
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,99,true,999,0,3),'catalogue mapping rejects missing CRM product');
shop_catalog_product_mapping_apply($pdo,11,99,true,1,0,3);
$st=$pdo->prepare('SELECT * FROM crm_shop_product_links WHERE source_id=? AND shop_product_id=8'); $st->execute([$a]); $link=$st->fetch(PDO::FETCH_ASSOC);
check((int)$link['crm_product_id']===1 && (int)$link['version']===1,'catalogue product without orders mapped');
check((int)shop_product_links_for_order($pdo,$a,['items'=>[['product_id'=>8]]])[8]['crm_product_id']===1,'catalogue mapping shared with orders from same source');
check(shop_product_links_for_order($pdo,$b,['items'=>[['product_id'=>8]]])===[],'catalogue mapping isolates sources');
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,99,true,2,0,3),'stale link version rejected');
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,99,true,1,1,3),'unchanged mapping rejected');
$pdo->exec("CREATE TRIGGER fail_catalog_map BEFORE INSERT ON crm_shop_product_link_events BEGIN SELECT RAISE(ABORT,'fail'); END");
try { shop_catalog_product_mapping_apply($pdo,11,99,true,2,1,3); check(false,'catalogue audit failure'); } catch (PDOException $e) {}
$st->execute([$a]); $link=$st->fetch(PDO::FETCH_ASSOC);
check((int)$link['crm_product_id']===1 && (int)$link['version']===1,'catalogue audit failure rolls back');
$pdo->exec('DROP TRIGGER fail_catalog_map');
shop_catalog_product_mapping_apply($pdo,11,99,true,0,1,3);
$st->execute([$a]); $link=$st->fetch(PDO::FETCH_ASSOC);
check($link['crm_product_id']===null && (int)$link['version']===2,'catalogue unlink preserves version');
shop_catalog_product_mapping_apply($pdo,12,99,true,2,0,1);
check((int)$pdo->query('SELECT COUNT(*) FROM crm_shop_product_links WHERE shop_product_id=8 AND crm_product_id IS NOT NULL')->fetchColumn()===1,'second source maps independently');
check((int)$pdo->query('SELECT replica_id FROM crm_shop_product_link_events ORDER BY id DESC LIMIT 1')->fetchColumn()===0,'catalogue mapping audit denotes no order');
// The same optimistic version protects mappings edited from the order and catalogue paths.
$st=$pdo->prepare('INSERT INTO crm_shop_orders(id,source_id,payload) VALUES (?,?,?)'); $st->execute([4,$a,json_encode(['items'=>[['product_id'=>8]]])]);
shop_product_mapping_apply($pdo,4,99,true,8,2,2);
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,99,true,1,2,3),'stale catalogue form rejected after order mapping changes');
shop_catalog_product_mapping_apply($pdo,11,99,true,1,3,3);
denied(fn()=>shop_product_mapping_apply($pdo,4,99,true,8,2,3),'stale order form rejected after catalogue mapping changes');
$pdo->prepare('UPDATE crm_shop_catalog SET payload=? WHERE id=11')->execute([json_encode(['id'=>0])]);
denied(fn()=>shop_catalog_product_mapping_apply($pdo,11,99,true,2,4,3),'invalid catalogue snapshot rejected');
