<?php
/** Key separation must hold even if other opt-ins are enabled. */
define('SHOP_PRODUCT_COMMAND_ENABLED',true); define('SHOP_PRODUCT_HIDE_ENABLED',true); define('SHOP_PRODUCT_COMMAND_KEY',str_repeat('c',32));
define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('c',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_PRODUCT_COMMAND_URL','https://example.test/cmd');
require_once __DIR__.'/../../website/includes/shop/CrmProductCommands.php'; require_once __DIR__.'/../includes/shop_product_commands.php';
if (crm_product_command_configured() || shop_product_command_configured()) { fwrite(STDERR,"FAIL reused catalogue key accepted\n"); exit(1); }
echo "PASS reused catalogue bridge key rejected by both sides\n";
