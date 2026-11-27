<?php
define('SHOP_REVIEW_CONTENT_GUARD_ENABLED',true); define('SHOP_REVIEW_CONTENT_GUARD_KEY',str_repeat('k',32)); define($argv[1] ?? 'SHOP_REVIEW_META_CRM_KEY',str_repeat('k',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewMetaSync.php';
if (!shop_review_guard_requested() || shop_review_guard_configured()) { fwrite(STDERR,"FAIL guard key collision accepted\n"); exit(1); }
$pdo=new PDO('sqlite::memory:');
try { shop_review_meta_ensure($pdo); fwrite(STDERR,"FAIL invalid guard silently downgraded\n"); exit(1); } catch (RuntimeException $e) {}
echo "PASS invalid guard key fails closed\n";
