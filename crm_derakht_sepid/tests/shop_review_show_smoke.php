<?php
/** Review publication: SQLite fixtures and simulated transport only. */
class ReviewShowPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,review_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE guard_hash=VALUES(guard_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,review_id) DO UPDATE SET guard_hash=excluded.guard_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_review_command_receipts','INSERT OR IGNORE INTO shop_review_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_SHOW_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32)); define('SHOP_REVIEW_COMMAND_URL','https://example.test/cmd');
define('SHOP_REVIEW_CONTENT_GUARD_ENABLED',true); define('SHOP_REVIEW_CONTENT_GUARD_KEY',str_repeat('g',32));
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/sync'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php'; require_once __DIR__.'/../includes/shop_review_commands.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
function denied(callable $f,$label) { try { $f(); check(false,$label); } catch (DomainException $e) { check(true,$label); } }
function site(): ReviewShowPDO {
 $p=new ReviewShowPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
 foreach ([
  'CREATE TABLE product_reviews(id INTEGER PRIMARY KEY,product_id INTEGER,rating INTEGER,is_active INTEGER,customer_id INTEGER,customer_name TEXT,body TEXT,created_at TEXT)',
  'CREATE TABLE shop_review_meta_cursor(source_id TEXT PRIMARY KEY,last_review_id INTEGER)',
  'CREATE TABLE shop_review_meta_state(source_id TEXT,review_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
  'CREATE TABLE shop_review_content_guard_state(source_id TEXT,review_id INTEGER,guard_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
  'CREATE TABLE shop_review_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,review_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)',
  'CREATE TABLE shop_review_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,review_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)'
 ] as $sql) $p->exec($sql);
 $p->exec("INSERT INTO shop_review_meta_outbox(id) VALUES(15)"); $p->exec('DELETE FROM shop_review_meta_outbox');
 $p->exec("INSERT INTO product_reviews VALUES(7,10,4,0,123,'PRIVATE NAME','PRIVATE BODY','2025-01-01 10:00:00')");
 $row=$p->query('SELECT * FROM product_reviews')->fetch(PDO::FETCH_ASSOC);
 $hash=hash('sha256',json_encode(shop_review_meta_snapshot($row),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
 $p->prepare('INSERT INTO shop_review_meta_state VALUES(?,?,?,15)')->execute([SHOP_CRM_SOURCE_ID,7,$hash]);
 $p->prepare('INSERT INTO shop_review_content_guard_state VALUES(?,?,?,15)')->execute([SHOP_CRM_SOURCE_ID,7,shop_review_guard_hash(SHOP_CRM_SOURCE_ID,$row)]); return $p;
}
function cmd(PDO $p,int $id): array { return ['type'=>'shop_review_show_v1','command_id'=>str_pad(dechex($id),32,'0',STR_PAD_LEFT),'source_id'=>SHOP_CRM_SOURCE_ID,'review_id'=>7,'revision'=>15,'snapshot_hash'=>$p->query('SELECT snapshot_hash FROM shop_review_meta_state')->fetchColumn(),'issued_at'=>1700000000,'expires_at'=>1700000900]; }
$now=1700000000; $source=SHOP_CRM_SOURCE_ID; $s=site(); $c=cmd($s,1);
check(crm_review_show_enabled() && !crm_review_hide_enabled() && shop_review_command_action_enabled($c['type']) && !shop_review_command_action_enabled('shop_review_hide_v1') && shop_review_command_validate($c,$source),'publication opt-in does not enable hiding');
$bad=$c; $bad['type']='shop_review_delete_v1'; check(!shop_review_command_validate($bad,$source),'deletion not supported');
$crm=new ReviewShowPDO('sqlite::memory:'); $crm->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE users(id INTEGER PRIMARY KEY,status TEXT,role TEXT)',
 'CREATE TABLE crm_shop_review_meta(id INTEGER PRIMARY KEY,source_id TEXT,review_id INTEGER,product_id INTEGER,revision INTEGER,event_id TEXT,payload_hash TEXT,rating INTEGER,is_active INTEGER,received_at TEXT)',
 'CREATE TABLE crm_shop_review_commands(command_id TEXT PRIMARY KEY,replica_id INTEGER,source_id TEXT,review_id INTEGER,replica_revision INTEGER,actor_id INTEGER,payload TEXT,state TEXT,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,completed_at TEXT)',
 'CREATE TABLE crm_shop_review_command_events(id INTEGER PRIMARY KEY AUTOINCREMENT,command_id TEXT,replica_id INTEGER,actor_id INTEGER,action TEXT,created_at TEXT)'
] as $sql) $crm->exec($sql);
$crm->exec("INSERT INTO users VALUES(1,'active','admin'),(2,'active','user'),(3,'inactive','admin')");
$crm->prepare('INSERT INTO crm_shop_review_meta VALUES(1,?,7,10,15,?,?,4,0,?)')->execute([$source,str_repeat('b',32),str_repeat('c',64),'2025-01-01']);
denied(fn()=>crm_review_show_enqueue($crm,1,2,true,15,$now,true),'forged role denied'); denied(fn()=>crm_review_show_enqueue($crm,1,3,true,15,$now,true),'inactive admin denied'); denied(fn()=>crm_review_show_enqueue($crm,1,1,false,15,$now,true),'session role denied');
denied(fn()=>crm_review_show_enqueue($crm,1,1,true,15,$now,false),'explicit confirmations required'); denied(fn()=>crm_review_show_enqueue($crm,1,1,true,14,$now,true),'stale snapshot denied');
$crm->exec('UPDATE crm_shop_review_meta SET product_id=0,rating=0'); denied(fn()=>crm_review_show_enqueue($crm,1,1,true,15,$now,true),'absence marker cannot be published'); $crm->exec('UPDATE crm_shop_review_meta SET product_id=10,rating=4');
$crm->exec("CREATE TRIGGER fail_review_show_event BEFORE INSERT ON crm_shop_review_command_events BEGIN SELECT RAISE(ABORT,'audit'); END");
try { crm_review_show_enqueue($crm,1,1,true,15,$now,true); check(false,'failed audit'); } catch (PDOException $e) {}
check((int)$crm->query('SELECT COUNT(*) FROM crm_shop_review_commands')->fetchColumn()===0,'audit failure rolls back enqueue'); $crm->exec('DROP TRIGGER fail_review_show_event');
$id=crm_review_show_enqueue($crm,1,1,true,15,$now,true); $row=$crm->query('SELECT * FROM crm_shop_review_commands')->fetch(PDO::FETCH_ASSOC); $queued=json_decode($row['payload'],true);
check(shop_review_command_validate($queued,$source) && crm_review_command_payload_valid($queued,$row) && !str_contains($row['payload'],'PRIVATE'),'publication command excludes review text');
denied(fn()=>crm_review_show_enqueue($crm,1,1,true,15,$now,true),'unresolved command blocks new publication');
$s->exec("CREATE TRIGGER fail_review_show_receipt BEFORE UPDATE ON shop_review_command_receipts BEGIN SELECT RAISE(ABORT,'receipt'); END");
try { shop_review_command_apply($s,$queued,$source,$now,true); check(false,'receipt failure'); } catch (PDOException $e) {}
check((int)$s->query('SELECT is_active FROM product_reviews')->fetchColumn()===0 && (int)$s->query('SELECT COUNT(*) FROM shop_review_command_receipts')->fetchColumn()===0,'receipt failure rolls back publication'); $s->exec('DROP TRIGGER fail_review_show_receipt');
$first=true; $send=function($body) use($s,$source,$now,&$first) { $c=json_decode($body,true); $r=shop_review_command_apply($s,$c,$source,$now,true); if ($first) { $first=false; return [0,null]; } return [200,['ok'=>true,'command_id'=>$c['command_id'],'result'=>$r]]; };
check(crm_review_command_pump($crm,$send,$now)['failed']===1 && (int)$s->query('SELECT is_active FROM product_reviews')->fetchColumn()===1 && $crm->query('SELECT state FROM crm_shop_review_commands')->fetchColumn()==='queued','lost response leaves publication outcome unknown');
check($s->query('SELECT body FROM product_reviews')->fetchColumn()==='PRIVATE BODY' && $s->query('SELECT customer_name FROM product_reviews')->fetchColumn()==='PRIVATE NAME','publication does not change content or identity');
check(crm_review_command_pump($crm,$send,$now+61)['settled']===1 && $crm->query('SELECT state FROM crm_shop_review_commands')->fetchColumn()==='applied','retry retrieves matching receipt');
check(shop_review_meta_capture($s,$source,7),'metadata capture observes published review'); $snapshot=$s->query('SELECT snapshot FROM shop_review_meta_outbox ORDER BY id DESC LIMIT 1')->fetchColumn(); $meta=json_decode($snapshot,true); check(($meta['is_active'] ?? null)===1 && !str_contains($snapshot,'PRIVATE') && (int)$s->query('SELECT latest_revision FROM shop_review_meta_state')->fetchColumn()===16,'new metadata revision contains status only, not private content');
$s->exec('UPDATE product_reviews SET is_active=0'); check(shop_review_command_apply($s,$queued,$source,$now+5000,false)==='applied' && (int)$s->query('SELECT is_active FROM product_reviews')->fetchColumn()===0,'old receipt cannot republish manually hidden review');
foreach (['body'=>"UPDATE product_reviews SET body='CHANGED'",'name'=>"UPDATE product_reviews SET customer_name='CHANGED'",'customer'=>'UPDATE product_reviews SET customer_id=321','rating'=>'UPDATE product_reviews SET rating=3','product'=>'UPDATE product_reviews SET product_id=11','revision'=>'UPDATE shop_review_meta_state SET latest_revision=16','guard revision'=>'UPDATE shop_review_content_guard_state SET latest_revision=16','deleted'=>'DELETE FROM product_reviews'] as $label=>$sql) { $t=site(); $old=cmd($t,20); $t->exec($sql); check(shop_review_command_apply($t,$old,$source,$now,true)==='conflict',$label.' change blocks publication'); }
$t=site(); $old=cmd($t,22); try { shop_review_command_apply($t,$old,$source,$now,false); check(false,'disabled receiver'); } catch (RuntimeException $e) {} check((int)$t->query('SELECT COUNT(*) FROM shop_review_command_receipts')->fetchColumn()===0,'disabled receiver cannot publish or retain new receipt');
$t=site(); $old=cmd($t,23); check(shop_review_command_apply($t,$old,$source,$now+901,true)==='expired' && (int)$t->query('SELECT is_active FROM product_reviews')->fetchColumn()===0,'expired command does not publish');
$t=site(); $one=cmd($t,24); $two=cmd($t,25); check(shop_review_command_apply($t,$one,$source,$now,true)==='applied' && shop_review_command_apply($t,$two,$source,$now,true)==='conflict','different command cannot publish already active review');
