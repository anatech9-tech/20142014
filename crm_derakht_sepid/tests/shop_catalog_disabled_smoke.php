<?php
require_once __DIR__.'/../../website/includes/shop/CrmCatalogSync.php';
require_once __DIR__.'/../includes/shop_catalog_sync.php';
function check($x,$s) { if (!$x) { fwrite(STDERR,"FAIL $s\n"); exit(1); } echo "PASS $s\n"; }
check(!shop_catalog_configured() && !crm_shop_catalog_configured(),'catalogue bridge disabled by default');
define('SHOP_CATALOG_CRM_ENABLED',true); define('SHOP_CATALOG_CRM_KEY',str_repeat('k',32)); define('SHOP_CRM_BRIDGE_KEY',str_repeat('k',32));
define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CATALOG_CRM_URL','https://example.test/api');
check(!shop_catalog_configured() && !crm_shop_catalog_configured(),'reused order bridge key rejected');
