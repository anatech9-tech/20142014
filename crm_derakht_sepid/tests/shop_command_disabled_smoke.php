<?php
require_once __DIR__.'/../../website/includes/shop/CrmOrderCommands.php';
require_once __DIR__.'/../includes/shop_order_commands.php';
function check($x,$s) { if (!$x) { fwrite(STDERR,"FAIL $s\n"); exit(1); } echo "PASS $s\n"; }
check(!shop_command_configured() && !crm_shop_command_configured(),'disabled without configuration');
define('SHOP_CRM_COMMAND_ENABLED',true); define('SHOP_CRM_COMMAND_KEY',str_repeat('a',32)); define('SHOP_CRM_BRIDGE_KEY',str_repeat('a',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('b',32)); define('SHOP_CRM_COMMAND_URL','https://example.test/endpoint');
check(!shop_command_configured() && !crm_shop_command_configured(),'reused read-only bridge key refused');
