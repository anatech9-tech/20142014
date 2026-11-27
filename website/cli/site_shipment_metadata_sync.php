<?php
/** Cron only; a single scanner/sender for this site database. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$private=dirname(__DIR__,2).'/site-crm.local.php';
if (!is_file($private)) { fwrite(STDERR,"Shipment metadata bridge not configured\n"); exit(1); }
require_once $private; require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/shipments/ShipmentMetadataSync.php';
try {
    if (!site_shipment_sync_configured()) throw new RuntimeException('Shipment sync disabled');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    site_shipment_sync_ensure($pdo); site_shipment_sync_require_innodb($pdo);
    if ((int)$pdo->query("SELECT GET_LOCK('wttp_site_shipment_meta_sync',0)")->fetchColumn()!==1) { echo "shipment metadata sync already running\n"; exit(0); }
    try {
        $st=$pdo->prepare('SELECT COUNT(*) FROM site_shipment_meta_outbox WHERE delivered_at IS NULL AND source_id<>?'); $st->execute([(string)SITE_SHIPMENT_SOURCE_ID]);
        if ((int)$st->fetchColumn()>0) throw new RuntimeException('Pending events from another source');
        $scanFailed=false; try { $scan=site_shipment_sync_scan($pdo,(string)SITE_SHIPMENT_SOURCE_ID,50); }
        catch (Throwable $e) { $scanFailed=true; $scan=['scanned'=>0,'queued'=>0,'sweep_complete'=>false]; error_log('Shipment metadata scan stopped; pending delivery continues'); }
        $sent=site_shipment_sync_pump($pdo,(string)SITE_SHIPMENT_SOURCE_ID,'site_shipment_sync_send',time());
        $pending=(int)$pdo->query('SELECT COUNT(*) FROM site_shipment_meta_outbox WHERE delivered_at IS NULL')->fetchColumn();
        echo 'scanned='.$scan['scanned'].' queued='.$scan['queued'].' sweep_complete='.(int)$scan['sweep_complete'].' sent='.$sent['sent'].' failed='.$sent['failed'].' pending='.$pending.' scan_failed='.(int)$scanFailed."\n";
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_site_shipment_meta_sync')"); } catch (Throwable $e) {} }
    if ($scanFailed || $sent['failed']) exit(1);
} catch (Throwable $e) { error_log('Shipment metadata sync failed'); fwrite(STDERR,"Shipment metadata sync failed; inspect configuration privately\n"); exit(1); }
