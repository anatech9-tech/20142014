<?php
define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/'); define('SHOP_CATALOG_CRM_ENABLED',true);
require_once __DIR__.'/../../website/includes/shop/CrmReviewMetaSync.php'; require_once __DIR__.'/../includes/shop_review_meta_sync.php';
if (shop_review_meta_configured() || crm_review_meta_configured()) { fwrite(STDERR,"FAIL review bridge must default off\n"); exit(1); }
echo "PASS review bridge defaults off on both sides\n";
