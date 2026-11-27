<?php
/** Both existing coupon and new category bridges must reject a shared key. */
define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('x',32)); define('SHOP_CATEGORY_META_URL','https://example.test/categories');
define('SHOP_COUPON_META_ENABLED',true); define('SHOP_COUPON_META_KEY',str_repeat('x',32)); define('SHOP_COUPON_META_URL','https://example.test/coupons'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmCategoryMetadataSync.php'; require_once __DIR__.'/../includes/shop_category_metadata_sync.php';
require_once __DIR__.'/../../website/includes/shop/CrmCouponMetadataSync.php'; require_once __DIR__.'/../includes/shop_coupon_metadata_sync.php';
if (shop_category_sync_configured() || crm_shop_category_sync_configured() || shop_coupon_sync_configured() || crm_shop_coupon_sync_configured()) { fwrite(STDERR,"FAIL category and coupon keys reused\n"); exit(1); }
echo "PASS category and coupon key reuse rejected on both sides\n";
