<?php
/** SQLite fixture, mock sender; no real MySQL concurrency or HTTPS. */
class ReviewGuardPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)','ON CONFLICT(source_id) DO NOTHING',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,review_id) DO UPDATE SET snapshot_hash=excluded.snapshot_hash,latest_revision=excluded.latest_revision',$sql);
  $sql=str_replace('ON DUPLICATE KEY UPDATE guard_hash=VALUES(guard_hash),latest_revision=VALUES(latest_revision)','ON CONFLICT(source_id,review_id) DO UPDATE SET guard_hash=excluded.guard_hash,latest_revision=excluded.latest_revision',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_review_command_receipts','INSERT OR IGNORE INTO shop_review_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_CONTENT_GUARD_ENABLED',true); define('SHOP_REVIEW_CONTENT_GUARD_KEY',str_repeat('g',32));
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_HIDE_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32));
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/sync'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
$p=new ReviewGuardPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
foreach ([
 'CREATE TABLE product_reviews(id INTEGER PRIMARY KEY,product_id INTEGER,rating INTEGER,is_active INTEGER,customer_id INTEGER,customer_name TEXT,body TEXT,created_at TEXT)',
 'CREATE TABLE shop_review_meta_cursor(source_id TEXT PRIMARY KEY,last_review_id INTEGER)',
 'CREATE TABLE shop_review_meta_state(source_id TEXT,review_id INTEGER,snapshot_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
 'CREATE TABLE shop_review_content_guard_state(source_id TEXT,review_id INTEGER,guard_hash TEXT,latest_revision INTEGER,PRIMARY KEY(source_id,review_id))',
 'CREATE TABLE shop_review_meta_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,source_id TEXT,review_id INTEGER,event_id TEXT UNIQUE,snapshot TEXT,attempts INTEGER DEFAULT 0,next_attempt_at TEXT,delivered_at TEXT,created_at TEXT)',
 'CREATE TABLE shop_review_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,review_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)'
] as $sql) $p->exec($sql);
$p->exec("INSERT INTO product_reviews VALUES(7,10,5,1,123,'PRIVATE NAME','PRIVATE BODY','2025-01-01 10:00:00')"); $source=SHOP_CRM_SOURCE_ID;
check(shop_review_guard_configured() && shop_review_command_action_enabled('shop_review_hide_v1'),'site-only independent guard enabled');
check(shop_review_meta_scan($p,$source,20)['queued']===1,'capture includes initial guard and metadata revision');
$first=$p->query('SELECT * FROM shop_review_meta_outbox WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$guard=$p->query('SELECT * FROM shop_review_content_guard_state')->fetch(PDO::FETCH_ASSOC);
check((int)$guard['latest_revision']===1 && strlen($guard['guard_hash'])===64 && !str_contains($first['snapshot'],'PRIVATE') && !str_contains($first['snapshot'],$guard['guard_hash']),'guard and personal content never enter metadata queue');
function make_cmd(PDO $p,int $id): array { $state=$p->query('SELECT * FROM shop_review_meta_state')->fetch(PDO::FETCH_ASSOC); return ['type'=>'shop_review_hide_v1','command_id'=>str_pad(dechex($id),32,'0',STR_PAD_LEFT),'source_id'=>SHOP_CRM_SOURCE_ID,'review_id'=>7,'revision'=>(int)$state['latest_revision'],'snapshot_hash'=>$state['snapshot_hash'],'issued_at'=>1700000000,'expires_at'=>1700000900]; }
$old=make_cmd($p,1); $p->exec("UPDATE product_reviews SET body='CHANGED PRIVATE BODY'");
check(shop_review_command_apply($p,$old,$source,1700000000,true)==='conflict' && (int)$p->query('SELECT is_active FROM product_reviews')->fetchColumn()===1,'body changed after capture prevents hiding');
check(shop_review_meta_scan($p,$source,20)['sweep_complete'] && shop_review_meta_scan($p,$source,20)['queued']===1,'body change gets a newer metadata revision without copying body');
$second=$p->query('SELECT * FROM shop_review_meta_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC); $guard2=$p->query('SELECT * FROM shop_review_content_guard_state')->fetch(PDO::FETCH_ASSOC);
check($first['snapshot']===$second['snapshot'] && (int)$guard2['latest_revision']===2 && $guard['guard_hash']!==$guard2['guard_hash'],'body-only change reuses safe metadata but refreshes guard and revision');
$oldLater=make_cmd($p,2); $p->exec("UPDATE product_reviews SET customer_name='CHANGED NAME'");
check(shop_review_command_apply($p,$oldLater,$source,1700000000,true)==='conflict','changed customer name prevents hiding');
shop_review_meta_scan($p,$source,20); check(shop_review_meta_scan($p,$source,20)['queued']===1,'changed identity refreshes revision');
$oldLater=make_cmd($p,3); $p->exec('UPDATE product_reviews SET customer_id=124');
check(shop_review_command_apply($p,$oldLater,$source,1700000000,true)==='conflict','changed customer id prevents hiding');
shop_review_meta_scan($p,$source,20); shop_review_meta_scan($p,$source,20);
$before=$p->query('SELECT latest_revision FROM shop_review_meta_state')->fetchColumn(); $p->exec("UPDATE product_reviews SET body='ANOTHER SECRET'");
$p->exec("CREATE TRIGGER fail_guard BEFORE UPDATE ON shop_review_content_guard_state BEGIN SELECT RAISE(ABORT,'guard failure'); END");
try { shop_review_meta_capture($p,$source,7); check(false,'guard write failure'); } catch (PDOException $e) {}
check($p->query('SELECT latest_revision FROM shop_review_meta_state')->fetchColumn()===$before && !$p->inTransaction(),'guard failure rolls back snapshot and outbox');
$p->exec('DROP TRIGGER fail_guard'); check(shop_review_meta_capture($p,$source,7),'guard capture retries');
$prev=make_cmd($p,4); $p->exec("UPDATE shop_review_content_guard_state SET guard_hash='0000000000000000000000000000000000000000000000000000000000000000'");
check(shop_review_command_apply($p,$prev,$source,1700000000,true)==='conflict','changed or rotated guard hash blocks an old command');
check(shop_review_meta_capture($p,$source,7),'stale guard hash is recaptured as a newer revision');
$new=make_cmd($p,5); check(shop_review_command_apply($p,$new,$source,1700000000,true)==='applied','fresh guard permits confirmed hide');
check($p->query('SELECT body FROM product_reviews')->fetchColumn()==='ANOTHER SECRET','hide does not edit content');
