<?php
/** Cross-domain key reuse fails closed. */
define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('c',32)); define('SITE_SHIPMENT_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_CATEGORY_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmCategoryMetadataSync.php'; require_once __DIR__.'/../includes/shop_category_metadata_sync.php';
if (shop_category_sync_configured() || crm_shop_category_sync_configured()) { fwrite(STDERR,"FAIL duplicate key accepted\n"); exit(1); } echo "PASS reused shipment key disables category bridge on both sides\n";
