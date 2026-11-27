<?php
/** Without the site-only guard, new hide operations must fail closed. */
class GuardDisabledPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_review_command_receipts','INSERT OR IGNORE INTO shop_review_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_HIDE_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32));
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/sync'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(!shop_review_guard_configured() && !shop_review_command_action_enabled('shop_review_hide_v1'),'guard defaults off and blocks hiding despite transport and hide opt-ins');
$p=new GuardDisabledPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$p->exec('CREATE TABLE shop_review_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,review_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$c=['type'=>'shop_review_hide_v1','command_id'=>str_repeat('b',32),'source_id'=>SHOP_CRM_SOURCE_ID,'review_id'=>7,'revision'=>15,'snapshot_hash'=>str_repeat('c',64),'issued_at'=>1700000000,'expires_at'=>1700000900];
try { shop_review_command_apply($p,$c,SHOP_CRM_SOURCE_ID,1700000000,true); check(false,'new operation without guard'); } catch (RuntimeException $e) { check((int)$p->query('SELECT COUNT(*) FROM shop_review_command_receipts')->fetchColumn()===0,'even a caller-provided true cannot bypass guard; receipt rolls back'); }
$hash=hash('sha256',json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$p->prepare("INSERT INTO shop_review_command_receipts VALUES(?,?,7,'applied','2025-01-01','2025-01-01')")->execute([$c['command_id'],$hash]);
check(shop_review_command_apply($p,$c,SHOP_CRM_SOURCE_ID,1700000000,false)==='applied','historical receipt can still be retrieved with guard off');
