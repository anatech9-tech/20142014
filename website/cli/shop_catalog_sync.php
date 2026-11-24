<?php
/** Cron only, with one scanner/sender per site database. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$private=dirname(__DIR__,2).'/site-crm.local.php';
if (!is_file($private)) { fwrite(STDERR,"Catalogue bridge not configured\n"); exit(1); }
require_once $private; require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/shop/CrmCatalogSync.php';
try {
    if (!shop_catalog_configured()) throw new RuntimeException('Catalogue bridge not configured');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    shop_catalog_ensure($pdo); shop_catalog_require_innodb($pdo);
    if ((int)$pdo->query("SELECT GET_LOCK('wttp_shop_catalog_sync',0)")->fetchColumn()!==1) { echo "catalogue sync already running\n"; exit(0); }
    try {
        $st=$pdo->prepare('SELECT COUNT(*) FROM shop_catalog_outbox WHERE delivered_at IS NULL AND source_id<>?'); $st->execute([(string)SHOP_CRM_SOURCE_ID]);
        if ((int)$st->fetchColumn()>0) throw new RuntimeException('Pending events from another source');
        $scanFailed=false; try { $scan=shop_catalog_scan($pdo,(string)SHOP_CRM_SOURCE_ID,50); }
        catch (Throwable $e) { $scanFailed=true; $scan=['scanned'=>0,'queued'=>0,'sweep_complete'=>false]; error_log('Shop catalogue scan stopped; pending delivery continues'); }
        $sent=shop_catalog_pump($pdo,(string)SHOP_CRM_SOURCE_ID,'shop_catalog_send',time());
        $pending=(int)$pdo->query('SELECT COUNT(*) FROM shop_catalog_outbox WHERE delivered_at IS NULL')->fetchColumn();
        echo 'scanned='.$scan['scanned'].' queued='.$scan['queued'].' sweep_complete='.(int)$scan['sweep_complete'].' sent='.$sent['sent'].' failed='.$sent['failed'].' pending='.$pending.' scan_failed='.(int)$scanFailed."\n";
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_shop_catalog_sync')"); } catch (Throwable $e) {} }
    if ($scanFailed || $sent['failed']) exit(1);
} catch (Throwable $e) { error_log('Shop catalogue sync failed'); fwrite(STDERR,"Catalogue sync failed; inspect logs and configuration\n"); exit(1); }
