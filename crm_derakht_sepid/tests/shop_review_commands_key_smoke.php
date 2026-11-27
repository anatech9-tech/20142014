<?php
define('SHOP_REVIEW_COMMAND_ENABLED',true); define('SHOP_REVIEW_COMMAND_KEY',str_repeat('h',32)); define('SHOP_REVIEW_COMMAND_URL','https://example.test/cmd'); define('SHOP_CRM_SOURCE_ID',str_repeat('a',32)); define($argv[1] ?? 'SHOP_REVIEW_META_CRM_KEY',str_repeat('h',32));
require_once __DIR__.'/../../website/includes/shop/CrmReviewCommands.php'; require_once __DIR__.'/../includes/shop_review_commands.php';
if (crm_review_command_configured() || shop_review_command_configured()) { fwrite(STDERR,"FAIL reused key\n"); exit(1); }
echo "PASS reused review command key rejected on both sides\n";
