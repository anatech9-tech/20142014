<?php
/** Cron only. Scan in bounded batches, then retry queued order snapshots. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$private=dirname(__DIR__,2).'/site-crm.local.php';
if (!is_file($private)) { fwrite(STDERR,"Shop CRM bridge is not configured\n"); exit(1); }
require_once $private;
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../includes/shop/CrmOrderSync.php';
try {
    if (!shop_crm_configured()) throw new RuntimeException('Shop bridge not configured');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    shop_crm_ensure($pdo);
    $lock=$pdo->query("SELECT GET_LOCK('wttp_shop_crm_sync',0)")->fetchColumn();
    if ((int)$lock!==1) { echo "shop sync already running\n"; exit(0); }
    try {
        $other=$pdo->prepare('SELECT COUNT(*) FROM shop_crm_outbox WHERE delivered_at IS NULL AND source_id<>?');
        $other->execute([(string)SHOP_CRM_SOURCE_ID]);
        if ((int)$other->fetchColumn()>0) throw new RuntimeException('Pending events from another source ID');
        $scanFailed=false;
        try { $scan=shop_crm_scan($pdo,(string)SHOP_CRM_SOURCE_ID,50); }
        catch (Throwable $e) { $scanFailed=true; $scan=['scanned'=>0,'queued'=>0,'sweep_complete'=>false]; error_log('Shop CRM scan stopped; pending delivery will still be attempted'); }
        $send=shop_crm_pump($pdo,20);
        $pending=(int)$pdo->query('SELECT COUNT(*) FROM shop_crm_outbox WHERE delivered_at IS NULL')->fetchColumn();
        echo 'scanned='.$scan['scanned'].' queued='.$scan['queued'].' sweep_complete='.(int)$scan['sweep_complete'].' sent='.$send['sent'].' failed='.$send['failed'].' pending='.$pending.' scan_failed='.(int)$scanFailed."\n";
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_shop_crm_sync')"); } catch (Throwable $e) {} }
    if ($send['failed'] || $scanFailed) exit(1);
} catch (Throwable $e) { error_log('Shop CRM sync failed'); fwrite(STDERR,"Shop CRM sync failed; check logs, configuration and connectivity\n"); exit(1); }
