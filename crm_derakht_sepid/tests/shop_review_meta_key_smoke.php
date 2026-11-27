<?php
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('k',32)); define($argv[1] ?? 'SHOP_CATALOG_CRM_KEY',str_repeat('k',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/');
require_once __DIR__.'/../../website/includes/shop/CrmReviewMetaSync.php'; require_once __DIR__.'/../includes/shop_review_meta_sync.php';
if (shop_review_meta_configured() || crm_review_meta_configured()) { fwrite(STDERR,"FAIL reused bridge key\n"); exit(1); }
echo "PASS review bridge rejects a reused key on both sides\n";
