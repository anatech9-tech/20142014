<?php
/** Run every five minutes via host cron. Never exposed via HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$private=dirname(__DIR__, 2).'/site-crm.local.php';
if (!is_file($private)) { fwrite(STDERR,"Site CRM bridge not configured\n"); exit(1); }
require_once $private;
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/SiteCrmBridge.php';
try {
    $pdo=site_bridge_pdo(); site_bridge_ensure($pdo); $r=site_bridge_pump($pdo,10);
    $pending=(int)$pdo->query('SELECT COUNT(*) FROM website_crm_outbox WHERE delivered_at IS NULL')->fetchColumn();
    echo 'sent='.$r['sent'].' failed='.$r['failed'].' pending='.$pending."\n";
    if ($r['failed']) exit(1);
} catch (Throwable $e) { error_log('Site bridge cron failed'); fwrite(STDERR,"Site CRM bridge failed; check configuration/connectivity\n"); exit(1); }
