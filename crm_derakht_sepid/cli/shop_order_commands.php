<?php
/** Cron only; one sender at a time. A lost acknowledgement is retried safely. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/shop_order_commands.php';
try {
    if (!crm_shop_command_configured() || !crm_shop_command_ensure($pdo)) throw new RuntimeException('Command transport not configured');
    crm_shop_command_require_innodb($pdo);
    if ((int)$pdo->query("SELECT GET_LOCK('wttp_crm_shop_commands',0)")->fetchColumn()!==1) { echo "shop commands already running\n"; exit(0); }
    try {
        $result=crm_shop_command_pump($pdo,'crm_shop_command_send',time());
        $pending=(int)$pdo->query("SELECT COUNT(*) FROM crm_shop_order_commands WHERE state='queued'")->fetchColumn();
        echo 'settled='.$result['settled'].' failed='.$result['failed'].' pending='.$pending."\n";
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_crm_shop_commands')"); } catch (Throwable $e) {} }
    if ($result['failed']) exit(1);
} catch (Throwable $e) { error_log('CRM shop command worker failed'); fwrite(STDERR,"Shop command worker failed; inspect configuration and logs\n"); exit(1); }
