<?php
/** Hiding and publication have separate opt-ins on both sides. */
class ReviewShowDisabledPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_review_command_receipts','INSERT OR IGNORE INTO shop_review_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_HIDE_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32)); define('SHOP_REVIEW_COMMAND_URL','https://example.test/cmd');
define('SHOP_REVIEW_CONTENT_GUARD_ENABLED',true); define('SHOP_REVIEW_CONTENT_GUARD_KEY',str_repeat('g',32)); define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/sync'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php'; require_once __DIR__.'/../includes/shop_review_commands.php';
function check($ok,$label) { if (!$ok) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(crm_review_command_configured() && crm_review_hide_enabled() && !crm_review_show_enabled() && shop_review_command_action_enabled('shop_review_hide_v1') && !shop_review_command_action_enabled('shop_review_show_v1'),'hide opt-in does not enable publication on either side');
$p=new ReviewShowDisabledPDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { crm_review_show_enqueue($p,1,1,true,1,1700000000,true); check(false,'disabled sender'); } catch (DomainException $e) { check(true,'disabled sender refuses publication'); }
$p->exec('CREATE TABLE shop_review_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,review_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$c=['type'=>'shop_review_show_v1','command_id'=>str_repeat('b',32),'source_id'=>SHOP_CRM_SOURCE_ID,'review_id'=>7,'revision'=>1,'snapshot_hash'=>str_repeat('d',64),'issued_at'=>1700000000,'expires_at'=>1700000900];
check(shop_review_command_validate($c,SHOP_CRM_SOURCE_ID),'protocol recognized while action disabled');
try { shop_review_command_apply($p,$c,SHOP_CRM_SOURCE_ID,1700000000,shop_review_command_action_enabled($c['type'])); check(false,'disabled receiver'); } catch (RuntimeException $e) { check((int)$p->query('SELECT COUNT(*) FROM shop_review_command_receipts')->fetchColumn()===0,'disabled receiver rolls back new receipt'); }
$hash=hash('sha256',json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); $p->prepare("INSERT INTO shop_review_command_receipts VALUES(?,?,7,'applied','2025-01-01','2025-01-01')")->execute([$c['command_id'],$hash]);
check(shop_review_command_apply($p,$c,SHOP_CRM_SOURCE_ID,1700000000,shop_review_command_action_enabled($c['type']))==='applied','prior historical receipt remains retrievable while publication is disabled');
