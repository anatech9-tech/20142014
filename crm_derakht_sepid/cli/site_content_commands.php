<?php
/** Cron only. A lost response remains queued; lock serializes workers. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/site_content_commands.php';
try {
    if (!content_command_configured(true) || !crm_content_command_ensure($pdo)) throw new RuntimeException('Disabled'); crm_content_command_require_innodb($pdo);
    if (!crm_content_command_lock($pdo)) { echo "content worker already running\n"; exit(0); }
    try {
        $st=$pdo->prepare("SELECT COUNT(*) FROM crm_site_content_commands WHERE state='queued' AND source_id<>?"); $st->execute([SITE_CONTENT_COMMAND_SOURCE_ID]); if ((int)$st->fetchColumn()>0) throw new RuntimeException('Foreign source');
        $r=crm_content_command_pump($pdo,'crm_content_command_send',time()); $pending=(int)$pdo->query("SELECT COUNT(*) FROM crm_site_content_commands WHERE state='queued'")->fetchColumn(); $stopped=(int)$pdo->query("SELECT COUNT(*) FROM crm_site_content_commands WHERE state='stopped'")->fetchColumn();
        echo 'settled='.$r['settled'].' failed='.$r['failed'].' blocked='.$r['blocked'].' pending='.$pending.' stopped='.$stopped."\n";
    } finally { try { crm_content_command_unlock($pdo); } catch (Throwable $e) {} }
    if ($r['failed'] || $r['blocked'] || $stopped) exit(1);
} catch (Throwable $e) { error_log('CRM content worker failed'); fwrite(STDERR,"Content worker failed; inspect configuration privately\n"); exit(1); }
