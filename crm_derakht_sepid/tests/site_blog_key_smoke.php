<?php
define('SITE_BLOG_META_ENABLED',true); define('SITE_BLOG_META_KEY',str_repeat('b',32)); define('SHOP_COUPON_META_KEY',str_repeat('b',32)); define('SITE_BLOG_SOURCE_ID',str_repeat('a',32)); define('SITE_BLOG_META_URL','https://example.test/api');
require_once __DIR__.'/../../website/includes/blog/CrmBlogMetadataSync.php'; require_once __DIR__.'/../includes/site_blog_metadata_sync.php';
if (site_blog_sync_configured() || crm_site_blog_sync_configured()) { fwrite(STDERR,"FAIL duplicate key accepted\n"); exit(1); } echo "PASS reused coupon key disables both blog bridge sides\n";
