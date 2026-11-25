<?php
/** Offline SQLite fixture; not a real MySQL concurrency, session or browser test. */
class InboxScopeTestPDO extends PDO {
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
require_once __DIR__.'/../includes/site_inquiries.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,string $label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
$pdo=new InboxScopeTestPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT,team_id INTEGER,username TEXT)',
 'CREATE TABLE crm_leads(id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER)',
 'CREATE TABLE crm_site_inquiries(id INTEGER PRIMARY KEY,owner_user_id INTEGER,team_id INTEGER,lead_id INTEGER,assigned_at TEXT,assigned_by INTEGER)',
 'CREATE TABLE crm_site_inquiry_owner_events(id INTEGER PRIMARY KEY AUTOINCREMENT,inquiry_id INTEGER,lead_id INTEGER,actor_id INTEGER,old_owner INTEGER,old_team INTEGER,new_owner INTEGER,new_team INTEGER,created_at TEXT)'
] as $sql) $pdo->exec($sql);
$pdo->exec("INSERT INTO users VALUES (1,'active','admin',NULL,'admin'),(2,'active','user',10,'old'),(3,'active','user',20,'new'),(4,'inactive','admin',NULL,'inactive'),(5,'inactive','user',20,'inactive2')");
$pdo->exec('INSERT INTO crm_leads VALUES (10,2,10),(11,3,20),(12,5,20),(13,3,10)');
$pdo->exec('INSERT INTO crm_site_inquiries VALUES (1,2,10,NULL,NULL,NULL),(2,2,10,10,NULL,NULL),(3,2,10,11,NULL,NULL),(4,2,10,99,NULL,NULL),(5,NULL,NULL,NULL,NULL,NULL),(6,2,10,12,NULL,NULL),(7,2,10,13,NULL,NULL)');
function ids(PDO $pdo,int $me,bool $admin,array $teams): array {
    [$scope,$args]=site_inquiry_scope($me,$admin,$teams); $st=$pdo->prepare("SELECT i.id FROM crm_site_inquiries i WHERE $scope ORDER BY i.id"); $st->execute($args); return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}
check(ids($pdo,1,true,[])===[1,2,3,4,5,6,7],'admin sees all including missing lead');
check(ids($pdo,2,false,[])===[1,2],'old owner loses access after lead reassignment or deletion');
check(ids($pdo,3,false,[])===[],'new lead owner waits for explicit inbox reconciliation');
check(ids($pdo,7,false,[10])===[1,2,7],'team manager sees only intersection of both scopes');
foreach ([[2,false,[]],[3,false,[]],[7,false,[10]],[1,true,[]]] as [$me,$admin,$teams]) {
 $actual=ids($pdo,$me,$admin,$teams); $expected=[];
 foreach ($pdo->query('SELECT * FROM crm_site_inquiries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $st=$pdo->prepare('SELECT * FROM crm_leads WHERE id=?'); $st->execute([$row['lead_id']]); $lead=$st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (site_inquiry_in_scope($row,$lead,$me,$admin,$teams)) $expected[]=(int)$row['id'];
 }
 check($actual===$expected,'SQL scope and locked-row check agree for user '.$me);
}
denied(fn()=>site_inquiry_reconcile($pdo,3,2,false,11,3,20),'non-admin denied');
denied(fn()=>site_inquiry_reconcile($pdo,3,2,true,11,3,20),'forged admin denied');
denied(fn()=>site_inquiry_reconcile($pdo,3,4,true,11,3,20),'inactive admin denied');
denied(fn()=>site_inquiry_reconcile($pdo,3,1,true,11,2,20),'stale lead owner denied');
denied(fn()=>site_inquiry_reconcile($pdo,4,1,true,99,3,20),'missing lead denied');
denied(fn()=>site_inquiry_reconcile($pdo,6,1,true,12,5,20),'inactive target denied');
denied(fn()=>site_inquiry_reconcile($pdo,7,1,true,13,3,10),'target team must match lead');
$pdo->exec("UPDATE users SET role='karfarma' WHERE id=3");
denied(fn()=>site_inquiry_reconcile($pdo,3,1,true,11,3,20),'restricted customer account cannot receive inbox');
$pdo->exec("UPDATE users SET role='user',username='yaser' WHERE id=3");
denied(fn()=>site_inquiry_reconcile($pdo,3,1,true,11,3,20),'special restricted account cannot receive inbox');
$pdo->exec("UPDATE users SET username='new' WHERE id=3");
check(ids($pdo,0,false,[])===[] && !site_inquiry_in_scope(['owner_user_id'=>null,'lead_id'=>null],null,0,false,[]),'missing actor has no scope');
$pdo->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON crm_site_inquiry_owner_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { site_inquiry_reconcile($pdo,3,1,true,11,3,20); check(false,'audit failure'); } catch (PDOException $e) {}
check((int)$pdo->query('SELECT owner_user_id FROM crm_site_inquiries WHERE id=3')->fetchColumn()===2,'audit failure rolls back reassignment');
$pdo->exec('DROP TRIGGER fail_audit'); site_inquiry_reconcile($pdo,3,1,true,11,3,20);
check(ids($pdo,2,false,[])===[1,2] && ids($pdo,3,false,[])===[3],'manual admin reconciliation transfers access');
$event=$pdo->query('SELECT * FROM crm_site_inquiry_owner_events')->fetch(PDO::FETCH_ASSOC);
check((int)$event['old_owner']===2 && (int)$event['new_owner']===3 && (int)$event['lead_id']===11,'identity reconciliation recorded atomically');
denied(fn()=>site_inquiry_reconcile($pdo,3,1,true,11,3,20),'unchanged reconciliation denied');
$pdo->exec('DELETE FROM crm_leads WHERE id=11'); check(ids($pdo,3,false,[])===[],'deleting linked lead fails closed');
