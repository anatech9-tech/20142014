<?php
/** Separate process: turning on hiding must not turn on publication. */
class ShowDisabledPDO extends PDO {
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  $sql=str_replace(' FOR UPDATE','',$sql);
  if (str_contains($sql,'ON DUPLICATE KEY UPDATE command_id=command_id')) { $sql=str_replace('INSERT INTO shop_product_command_receipts','INSERT OR IGNORE INTO shop_product_command_receipts',$sql); $sql=str_replace(' ON DUPLICATE KEY UPDATE command_id=command_id','',$sql); }
  return parent::prepare($sql,$options);
 }
}
define('SHOP_PRODUCT_COMMAND_ENABLED',true); define('SHOP_PRODUCT_HIDE_ENABLED',true); define('SHOP_PRODUCT_COMMAND_KEY',str_repeat('p',32));
define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_PRODUCT_COMMAND_URL','https://example.test/cmd');
require_once __DIR__.'/../../website/includes/shop/CrmProductCommands.php'; require_once __DIR__.'/../includes/shop_product_commands.php';
function check($v,$label) { if (!$v) { fwrite(STDERR,"FAIL $label\n"); exit(1); } echo "PASS $label\n"; }
check(crm_product_command_configured() && crm_product_hide_enabled() && !crm_product_show_enabled() && shop_product_command_action_enabled('shop_product_hide_v1') && !shop_product_command_action_enabled('shop_product_show_v1') && !shop_product_command_action_enabled('unknown'),'hide opt-in does not enable publishing on either side');
$pdo=new ShowDisabledPDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { crm_product_show_enqueue($pdo,1,1,true,1,1700000000,true); check(false,'disabled sender'); } catch (DomainException $e) { check(true,'disabled sender refuses publication'); }
$pdo->exec('CREATE TABLE shop_product_command_receipts(command_id TEXT PRIMARY KEY,request_hash TEXT,product_id INTEGER,result TEXT,created_at TEXT,completed_at TEXT)');
$c=['type'=>'shop_product_show_v1','command_id'=>str_repeat('b',32),'source_id'=>SHOP_CRM_SOURCE_ID,'product_id'=>7,'revision'=>1,'snapshot_hash'=>str_repeat('d',64),'issued_at'=>1700000000,'expires_at'=>1700000900];
check(shop_product_command_validate($c,SHOP_CRM_SOURCE_ID),'protocol recognized even while disabled');
try { shop_product_command_apply($pdo,$c,SHOP_CRM_SOURCE_ID,1700000000,shop_product_command_action_enabled($c['type'])); check(false,'disabled receiver'); } catch (RuntimeException $e) { check(true,'disabled receiver refuses new publication'); }
check((int)$pdo->query('SELECT COUNT(*) FROM shop_product_command_receipts')->fetchColumn()===0,'disabled receiver rolls back new receipt');
$hash=hash('sha256',json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$pdo->prepare("INSERT INTO shop_product_command_receipts VALUES(?,?,7,'applied','2025-01-01','2025-01-01')")->execute([$c['command_id'],$hash]);
check(shop_product_command_apply($pdo,$c,SHOP_CRM_SOURCE_ID,1700000000,false)==='applied','prior receipt remains retrievable after disabling');
