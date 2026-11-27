<?php
/** SQLite and simulated transport only; no live MySQL locks, cURL or CSRF. */
class ReviewCommandPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_review_command_receipts','INSERT OR IGNORE INTO shop_review_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_HIDE_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32)); define('SHOP_REVIEW_COMMAND_URL','https://example.test/cmd');
define('SHOP_REVIEW_CONTENT_GUARD_ENABLED',true); define('SHOP_REVIEW_CONTENT_GUARD_KEY',str_repeat('g',32));
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/sync'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php'; require_once __DIR__.'/../includes/shop_review_commands.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,$label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
function site(): ReviewCommandPDO {
 $p=new ReviewCommandPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 foreach ([
  'CREATE TABLE product_reviews(id INTEGER PRIMARY KEY,product_id INTEGER,rating INTEGER,is_active INTEGER,customer_id INTEGER,customer_name TEXT,body TEXT,created_at TEXT)',
  'CREATE TABLE shop_review_meta_state(source_id TEXT,review_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
  'CREATE TABLE shop_review_content_guard_state(source_id TEXT,review_id INTEGER,guard_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
  'CREATE TABLE shop_review_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,review_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)'
 ] as $sql) $p->exec($sql);
 $p->exec("INSERT INTO product_reviews VALUES(7,10,5,1,NULL,'PRIVATE NAME','PRIVATE BODY','2025-01-01 10:00:00')");
 $p->prepare('INSERT INTO shop_review_meta_state VALUES(?,?,?,?)')->execute([SHOP_CRM_SOURCE_ID,7,hash('sha256',json_encode(snapshot($p),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),15]);
 $p->prepare('INSERT INTO shop_review_content_guard_state VALUES(?,?,?,?)')->execute([SHOP_CRM_SOURCE_ID,7,shop_review_guard_hash(SHOP_CRM_SOURCE_ID,$p->query('SELECT * FROM product_reviews')->fetch(PDO::FETCH_ASSOC)),15]); return $p;
}
function snapshot(PDO $p): array { return shop_review_meta_snapshot($p->query('SELECT * FROM product_reviews WHERE id=7')->fetch(PDO::FETCH_ASSOC)); }
function cmd(PDO $p,int $id): array { return ['type'=>'shop_review_hide_v1','command_id'=>str_pad(dechex($id),32,'0',STR_PAD_LEFT),'source_id'=>SHOP_CRM_SOURCE_ID,'review_id'=>7,'revision'=>15,'snapshot_hash'=>hash('sha256',json_encode(snapshot($p),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),'issued_at'=>1700000000,'expires_at'=>1700000900]; }
$now=1700000000; $source=SHOP_CRM_SOURCE_ID; $s=site(); $c=cmd($s,1);
check(shop_review_command_configured() && crm_review_command_configured() && crm_review_hide_enabled() && shop_review_command_action_enabled($c['type']) && shop_review_command_validate($c,$source),'independent opt-ins and hide command');
$sig=hash_hmac('sha256','shop-review-command-v1.'.$now.'.body',SHOP_REVIEW_COMMAND_KEY);
check(shop_review_command_signature_valid(SHOP_REVIEW_COMMAND_KEY,(string)$now,$sig,'body',$now) && !shop_review_command_signature_valid(SHOP_REVIEW_COMMAND_KEY,(string)$now,$sig,'altered',$now) && !shop_review_command_signature_valid(SHOP_REVIEW_COMMAND_KEY,(string)$now,$sig,'body',$now+301),'HMAC rejects modification and expiry');
$bad=$c; $bad['type']='shop_review_show_v1'; check(shop_review_command_validate($bad,$source) && !shop_review_command_action_enabled($bad['type']) && !crm_review_show_enabled(),'publication protocol recognized but not enabled by hide opt-in');
$bad['type']='shop_review_delete_v1'; check(!shop_review_command_validate($bad,$source) && !shop_review_command_action_enabled($bad['type']),'deletion is not supported');
$bad=$c; $bad['body']='PRIVATE'; check(!shop_review_command_validate($bad,$source),'extra fields rejected');
$crm=new ReviewCommandPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,rating INTEGER,is_active INTEGER,received_at TEXT)',
 'CREATE TABLE crm_shop_review_commands(command_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,review_id INTEGER,replica_revision INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)',
 'CREATE TABLE crm_shop_review_command_events(id INTEGER PRIMARY KEY AUTOINCREMENT,command_id TEXT,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'
] as $sql) $crm->exec($sql);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$crm->prepare('INSERT INTO crm_shop_review_meta VALUES(1,?,7,10,15,?,?,5,1,?)')->execute([$source,str_repeat('b',32),str_repeat('c',64),'2025-01-01']);
denied(fn()=>crm_review_hide_enqueue($crm,1,2,true,15,$now,true),'forged role rejected'); denied(fn()=>crm_review_hide_enqueue($crm,1,3,true,15,$now,true),'inactive administrator rejected');
denied(fn()=>crm_review_hide_enqueue($crm,1,1,false,15,$now,true),'session role rejected'); denied(fn()=>crm_review_hide_enqueue($crm,1,1,true,15,$now,false),'confirmation required'); denied(fn()=>crm_review_hide_enqueue($crm,1,1,true,14,$now,true),'stale replica denied');
$crm->exec("CREATE TRIGGER fail_review_audit BEFORE INSERT ON crm_shop_review_command_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_review_hide_enqueue($crm,1,1,true,15,$now,true); check(false,'queue audit'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_review_commands')->fetchColumn()===0,'queue and audit rollback together'); $crm->exec('DROP TRIGGER fail_review_audit');
$id=crm_review_hide_enqueue($crm,1,1,true,15,$now,true); $row=$crm->query('SELECT * FROM crm_shop_review_commands')->fetch(PDO::FETCH_ASSOC); $queued=json_decode($row['payload'],true);
check(shop_review_command_validate($queued,$source) && !str_contains($row['payload'],'PRIVATE'),'command excludes review text and name');
denied(fn()=>crm_review_hide_enqueue($crm,1,1,true,15,$now,true),'unresolved command blocks another');
check(!crm_review_command_settle($crm,$row,['ok'=>true,'command_id'=>str_repeat('f',32),'result'=>'applied'],200,$now),'unmatched receipt cannot settle');
$crm->exec("CREATE TRIGGER fail_review_settle BEFORE INSERT ON crm_shop_review_command_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_review_command_settle($crm,$row,['ok'=>true,'command_id'=>$id,'result'=>'applied'],200,$now); check(false,'settle audit'); } catch (PDOException $e) {}
check($crm->query('SELECT state FROM crm_shop_review_commands')->fetchColumn()==='queued','failed settlement audit rolls back queue transition'); $crm->exec('DROP TRIGGER fail_review_settle');
$s->exec("CREATE TRIGGER fail_review_receipt BEFORE UPDATE ON shop_review_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { shop_review_command_apply($s,$queued,$source,$now,true); check(false,'receipt audit'); } catch (PDOException $e) {}
check((int)$s->query('SELECT is_active FROM product_reviews')->fetchColumn()===1 && (int)$s->query('SELECT COUNT(*) FROM shop_review_command_receipts')->fetchColumn()===0,'receipt failure rolls back visibility'); $s->exec('DROP TRIGGER fail_review_receipt');
$first=true; $send=function($body) use($s,$source,$now,&$first) { $c=json_decode($body,true); $r=shop_review_command_apply($s,$c,$source,$now,true); if ($first) { $first=false; return [0,null]; } return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$r]]; };
check(crm_review_command_pump($crm,$send,$now)['failed']===1 && (int)$s->query('SELECT is_active FROM product_reviews')->fetchColumn()===0 && $crm->query('SELECT state FROM crm_shop_review_commands')->fetchColumn()==='queued','lost reply remains unresolved');
check($s->query('SELECT body FROM product_reviews')->fetchColumn()==='PRIVATE BODY' && $s->query('SELECT customer_name FROM product_reviews')->fetchColumn()==='PRIVATE NAME','hide preserves text and customer name');
check(crm_review_command_pump($crm,$send,$now+61)['settled']===1 && $crm->query('SELECT state FROM crm_shop_review_commands')->fetchColumn()==='applied','retry settles existing receipt');
$s->exec('UPDATE product_reviews SET is_active=1'); check(shop_review_command_apply($s,$queued,$source,$now+5000,false)==='applied' && (int)$s->query('SELECT is_active FROM product_reviews')->fetchColumn()===1,'historical receipt cannot hide again');
$bad=$queued; $bad['revision']=16; denied(fn()=>shop_review_command_apply($s,$bad,$source,$now,true),'same id different command rejected');
foreach (['rating'=>'UPDATE product_reviews SET rating=4','product'=>'UPDATE product_reviews SET product_id=11','revision'=>'UPDATE shop_review_meta_state SET latest_revision=16','deleted'=>'DELETE FROM product_reviews'] as $name=>$sql) { $t=site(); $old=cmd($t,20); $t->exec($sql); check(shop_review_command_apply($t,$old,$source,$now,true)==='conflict',$name.' change prevents hiding'); }
$t=site(); $one=cmd($t,23); $two=cmd($t,24); check(shop_review_command_apply($t,$one,$source,$now,true)==='applied' && shop_review_command_apply($t,$two,$source,$now,true)==='conflict','second distinct command cannot reapply');
$t=site(); $old=cmd($t,25); check(shop_review_command_apply($t,$old,$source,$now+901,true)==='expired' && (int)$t->query('SELECT is_active FROM product_reviews')->fetchColumn()===1,'expired command does not hide');
$t=site(); $old=cmd($t,26); try { shop_review_command_apply($t,$old,$source,$now,false); check(false,'receiver disabled'); } catch (RuntimeException $e) {}
check((int)$t->query('SELECT COUNT(*) FROM shop_review_command_receipts')->fetchColumn()===0,'disabled receiver rolls back new receipt');
$t=site(); $old=cmd($t,28); $t->exec('DROP TABLE shop_review_content_guard_state');
try { shop_review_command_apply($t,$old,$source,$now,true); check(false,'missing guard table'); } catch (PDOException $e) { check(!$t->inTransaction() && (int)$t->query('SELECT is_active FROM product_reviews')->fetchColumn()===1,'missing guard table fails closed'); }
$t=site(); $old=cmd($t,27); $t->exec("UPDATE product_reviews SET body='CHANGED PRIVATE BODY'"); check(shop_review_command_apply($t,$old,$source,$now,true)==='conflict','changed body now blocks hide');

$crm->exec("UPDATE crm_shop_review_commands SET state='queued',payload='{\"type\":\"shop_review_hide_v1\",\"body\":\"SECRET\"}',next_attempt_at='2020-01-01'"); $called=false;
try { crm_review_command_pump($crm,function($body) use (&$called) { $called=true; return [200,null]; },$now+6000); check(false,'tampered queued command'); } catch (RuntimeException $e) { check(!$called,'tampered queued command is never sent'); }
