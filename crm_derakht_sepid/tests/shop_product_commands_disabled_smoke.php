<?php
/** Independent process confirms the hide opt-in is absent by default. */
define('SHOP_PRODUCT_COMMAND_ENABLED',true); define('SHOP_PRODUCT_COMMAND_KEY',str_repeat('p',32));
define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('c',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_PRODUCT_COMMAND_URL','https://example.test/cmd');
require_once __DIR__.'/../../website/includes/shop/CrmProductCommands.php'; require_once __DIR__.'/../includes/shop_product_commands.php';
function check($v,$s) { if (!$v) { fwrite(STDERR,"FAIL $s\n"); exit(1); } echo "PASS $s\n"; }
check(crm_product_command_configured() && shop_product_command_configured() && !crm_product_hide_enabled() && !crm_product_show_enabled(),'transport opt-in does not enable visibility commands');
$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { crm_product_hide_enqueue($pdo,1,1,true,1,1700000000,true); check(false,'hide disabled'); } catch (DomainException $e) { check(true,'hide enqueue denied by default'); }
