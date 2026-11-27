<?php
/** Enabling transport alone must not enable review hiding. */
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32)); define('SHOP_REVIEW_COMMAND_URL','https://example.test/cmd');
define('SHOP_REVIEW_META_CRM_ENABLED',true); define('SHOP_REVIEW_META_CRM_KEY',str_repeat('r',32)); define('SHOP_REVIEW_META_CRM_URL','https://example.test/sync'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php'; require_once __DIR__.'/../includes/shop_review_commands.php';
if (!crm_review_command_configured() || !shop_review_command_configured() || crm_review_hide_enabled() || shop_review_command_action_enabled('shop_review_hide_v1')) { fwrite(STDERR,"FAIL hide not independently disabled\n"); exit(1); }
$p=new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
try { crm_review_hide_enqueue($p,1,1,true,1,1700000000,true); fwrite(STDERR,"FAIL sender accepted disabled hide\n"); exit(1); } catch (DomainException $e) {}
echo "PASS review hide independently disabled on both sides\n";
