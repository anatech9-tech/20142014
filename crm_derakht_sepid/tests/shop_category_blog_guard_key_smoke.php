<?php
/** Blog content guard and category bridge must reject a shared site key. */
define('SHOP_CATEGORY_META_ENABLED',true); define('SHOP_CATEGORY_META_KEY',str_repeat('x',32)); define('SHOP_CATEGORY_META_URL','https://example.test/categories'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
define('SITE_BLOG_CONTENT_GUARD_ENABLED',true); define('SITE_BLOG_CONTENT_GUARD_KEY',str_repeat('x',32));
require_once __DIR__.'/../../website/includes/shop/CrmCategoryMetadataSync.php'; require_once __DIR__.'/../includes/shop_category_metadata_sync.php'; require_once __DIR__.'/../../website/includes/blog/CrmBlogContentGuard.php';
if (shop_category_sync_configured() || crm_shop_category_sync_configured() || site_blog_guard_configured()) { fwrite(STDERR,"FAIL category and blog guard keys reused\n"); exit(1); }
echo "PASS category and blog guard key reuse rejected\n";
