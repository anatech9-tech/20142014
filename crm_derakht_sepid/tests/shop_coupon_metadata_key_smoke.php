<?php
/** Cross-domain key reuse fails closed. */
define('SHOP_COUPON_META_ENABLED',true); define('SHOP_COUPON_META_KEY',str_repeat('c',32)); define('SITE_SHIPMENT_META_KEY',str_repeat('c',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_COUPON_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/shop/CrmCouponMetadataSync.php'; require_once __DIR__.'/../includes/shop_coupon_metadata_sync.php';
if (shop_coupon_sync_configured() || crm_shop_coupon_sync_configured()) { fwrite(STDERR,"FAIL duplicate key accepted\n"); exit(1); } echo "PASS reused shipment key disables coupon bridge on both sides\n";
