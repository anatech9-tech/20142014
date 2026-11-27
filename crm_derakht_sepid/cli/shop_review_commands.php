<?php
/** Cron only; serialize workers. A lost response must remain queued for retry. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/shop_review_commands.php';
try {
    if (!crm_review_command_configured() || !crm_review_command_ensure($pdo)) throw new RuntimeException('Review command transport not configured');
    crm_review_command_require_innodb($pdo);
    if ((int)$pdo->query("SELECT GET_LOCK('wttp_crm_review_commands',0)")->fetchColumn()!==1) { echo "review commands already running\n"; exit(0); }
    try {
        $st=$pdo->prepare("SELECT COUNT(*) FROM crm_shop_review_commands WHERE state='queued' AND source_id<>?"); $st->execute([(string)SHOP_CRM_SOURCE_ID]);
        if ((int)$st->fetchColumn()>0) throw new RuntimeException('Pending commands from another source');
        $result=crm_review_command_pump($pdo,'crm_review_command_send',time());
        $pending=(int)$pdo->query("SELECT COUNT(*) FROM crm_shop_review_commands WHERE state='queued'")->fetchColumn();
        echo 'settled='.$result['settled'].' failed='.$result['failed'].' pending='.$pending."\n";
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_crm_review_commands')"); } catch (Throwable $e) {} }
    if ($result['failed']) exit(1);
} catch (Throwable $e) { error_log('CRM review command worker failed'); fwrite(STDERR,"Review command worker failed; inspect configuration and logs\n"); exit(1); }
