<?php
/** SQLite harness for order assignment/link authorization; MySQL locks and real sessions still require testing. */
class WorkTestPDO extends PDO {
    public function prepare(string $sql, array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/shop_order_work.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f, string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new WorkTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
    'CREATE TABLE users(id INTEGER PRIMARY KEY,team_id INTEGER,status TEXT,full_name TEXT,role TEXT,username TEXT)',
    'CREATE TABLE teams(id INTEGER PRIMARY KEY,manager_user_id INTEGER)',
    'CREATE TABLE crm_customers(id INTEGER PRIMARY KEY,name TEXT,owner_user_id INTEGER,created_by INTEGER,team_id INTEGER,deleted_at TEXT)',
    'CREATE TABLE crm_shop_orders(id INTEGER PRIMARY KEY)',
    'CREATE TABLE crm_shop_order_work(replica_id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER,crm_customer_id INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT)',
    'CREATE TABLE crm_shop_order_work_events(id INTEGER PRIMARY KEY AUTOINCREMENT,replica_id INTEGER,actor_id INTEGER,action TEXT,old_value INTEGER,new_value INTEGER,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES(1,10,'active','Manager','user','manager'),(2,10,'active','A','user','a'),(3,20,'active','B','user','b'),(4,10,'active','C','user','c'),(5,10,'inactive','Inactive','user','off'),(9,20,'active','Manager2','user','manager2'),(10,10,'active','Restricted','karfarma','restricted'),(99,NULL,'active','Admin','admin','admin')");
$pdo->exec('INSERT INTO teams VALUES(10,1),(20,9)');
$pdo->exec("INSERT INTO crm_customers VALUES(101,'A customer',2,2,10,NULL),(102,'B customer',3,3,20,NULL),(103,'Deleted',2,2,10,'2025-01-01'),(104,'Team by creator',3,2,20,NULL)");
$pdo->exec('INSERT INTO crm_shop_orders VALUES(1001),(1002)');
check(!shop_work_can_view([],1,false,[10]) && shop_work_can_view([],99,true,[]) && !shop_work_can_view([],0,true,[]),'unassigned order admin only');
denied(fn()=>shop_work_apply($pdo,1001,1,false,[10],'assign',2,0),'manager cannot claim unassigned');
denied(fn()=>shop_work_apply($pdo,1001,1,true,[10],'assign',2,0),'stale admin role denied');
denied(fn()=>shop_work_apply($pdo,1001,5,false,[10],'assign',2,0),'inactive actor denied');
$pdo->exec("UPDATE users SET role='nazer' WHERE id=99");
denied(fn()=>shop_work_apply($pdo,1001,99,true,[],'assign',2,0),'viewer cannot write with stale session');
$pdo->exec("UPDATE users SET role='admin' WHERE id=99");
shop_work_apply($pdo,1001,99,true,[],'assign',2,0);
$work=$pdo->query('SELECT * FROM crm_shop_order_work WHERE replica_id=1001')->fetch(PDO::FETCH_ASSOC);
check((int)$work['owner_user_id']===2 && (int)$work['team_id']===10 && (int)$work['version']===1,'admin initial assignment');
check(shop_work_can_view($work,2,false,[]) && shop_work_can_view($work,1,false,[10]) && !shop_work_can_view($work,3,false,[]) && !shop_work_can_view($work,9,false,[20]),'owner and team scope');
denied(fn()=>shop_work_apply($pdo,1001,2,false,[10],'assign',4,1),'forged managed team cannot reassign');
denied(fn()=>shop_work_apply($pdo,1001,2,false,[],'assign',4,1),'expert cannot reassign');
denied(fn()=>shop_work_apply($pdo,1001,1,false,[10],'assign',3,1),'manager cannot assign other team');
denied(fn()=>shop_work_apply($pdo,1001,1,false,[10],'assign',5,1),'inactive assignee denied');
denied(fn()=>shop_work_apply($pdo,1001,99,true,[],'assign',10,1),'restricted assignee denied');
shop_work_apply($pdo,1001,1,false,[10],'assign',4,1);
check((int)$pdo->query('SELECT owner_user_id FROM crm_shop_order_work')->fetchColumn()===4,'team manager reassignment');
denied(fn()=>shop_work_apply($pdo,1001,4,false,[],'link',102,2),'cannot link inaccessible customer');
check(shop_visible_customer($pdo,104,1,false,[10])!==null && shop_visible_customer($pdo,101,4,false,[])===null && shop_visible_customer($pdo,103,99,true,[])===null,'customer visibility mirrors customer page');
shop_work_apply($pdo,1001,1,false,[10],'link',101,2);
check((int)$pdo->query('SELECT crm_customer_id FROM crm_shop_order_work')->fetchColumn()===101,'manager links visible customer');
denied(fn()=>shop_work_apply($pdo,1001,4,false,[],'link',0,3),'expert cannot unlink hidden customer');
shop_work_apply($pdo,1001,99,true,[],'assign',3,3);
$work=$pdo->query('SELECT * FROM crm_shop_order_work')->fetch(PDO::FETCH_ASSOC);
check(!shop_work_can_view($work,1,false,[10]) && shop_work_can_view($work,3,false,[]) && shop_visible_customer($pdo,101,3,false,[])===null,'cross-team reassignment hides previous customer');
denied(fn()=>shop_work_apply($pdo,1001,3,false,[],'link',102,4),'hidden existing link cannot be replaced by expert');
shop_work_apply($pdo,1001,99,true,[],'link',102,4);
check(shop_visible_customer($pdo,102,3,false,[])!==null,'admin can link correct customer');
denied(fn()=>shop_work_apply($pdo,1001,99,true,[],'assign',2,4),'stale version denied');
[$scope,$args]=shop_work_scope(1,false,[10]); $st=$pdo->prepare("SELECT r.id FROM crm_shop_orders r LEFT JOIN crm_shop_order_work w ON w.replica_id=r.id WHERE ($scope)");$st->execute($args); check($st->fetchAll(PDO::FETCH_COLUMN)===[],'team list excludes other team and unassigned');
[$scope,$args]=shop_work_scope(3,false,[]); $st=$pdo->prepare("SELECT r.id FROM crm_shop_orders r JOIN crm_shop_order_work w ON w.replica_id=r.id WHERE w.crm_customer_id=? AND ($scope)");$st->execute(array_merge([102],$args)); check(array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN))===[1001],'customer history requires order scope');
$pdo->exec("CREATE TRIGGER fail_work_event BEFORE INSERT ON crm_shop_order_work_events BEGIN SELECT RAISE(ABORT,'fail'); END");
try { shop_work_apply($pdo,1001,99,true,[],'assign',2,5); check(false,'audit failure must throw'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT owner_user_id FROM crm_shop_order_work')->fetchColumn()===3 && (int)$pdo->query('SELECT version FROM crm_shop_order_work')->fetchColumn()===5,'audit failure rolls back mutation');

$pdo->exec('DROP TRIGGER fail_work_event');
shop_work_apply($pdo,1002,99,true,[],'assign',2,0);
shop_work_apply($pdo,1002,99,true,[],'link',101,1);
$pdo->exec("UPDATE crm_customers SET deleted_at='2025-01-01' WHERE id=101");
denied(fn()=>shop_work_apply($pdo,1002,2,false,[],'link',0,2),'expert cannot change archived customer link');
shop_work_apply($pdo,1002,99,true,[],'link',0,2);
check($pdo->query('SELECT crm_customer_id FROM crm_shop_order_work WHERE replica_id=1002')->fetchColumn()===null,'admin can unlink archived customer');
// Compare the independent customer-scope helper with the actual customer page query.
$sourceText=file_get_contents(__DIR__.'/../pages/crm_customers.php');
$start=strpos($sourceText,'if ($canAll) { [$scopeSql, $scopeParams]');
$end=strpos($sourceText,'// ═══════════ نمایش تک‌رکورد',$start);
$customerScopeSource=substr($sourceText,$start,$end-$start);
foreach ([[2,false,[]],[1,false,[10]],[9,false,[20]],[99,true,[]]] as [$person,$isAdmin,$teams]) {
    $me=$person; $canAll=$isAdmin; $managed=$teams; eval($customerScopeSource);
    $a=$pdo->prepare("SELECT c.id FROM crm_customers c WHERE c.deleted_at IS NULL AND ($scopeSql) ORDER BY c.id"); $a->execute($scopeParams);
    [$helperSql,$helperArgs]=shop_customer_scope($person,$isAdmin,$teams);
    $b=$pdo->prepare("SELECT c.id FROM crm_customers c WHERE c.deleted_at IS NULL AND ($helperSql) ORDER BY c.id"); $b->execute($helperArgs);
    check($a->fetchAll(PDO::FETCH_COLUMN)===$b->fetchAll(PDO::FETCH_COLUMN),'customer scope parity for user '.$person);
}
