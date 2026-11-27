<?php
/** Cron only; a single scanner/sender for this site database. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$private=dirname(__DIR__,2).'/site-crm.local.php';
if (!is_file($private)) { fwrite(STDERR,"Coupon metadata bridge not configured\n"); exit(1); }
require_once $private; require_once __DIR__.'/../config.php'; require_once __DIR__.'/../includes/shop/CrmCouponMetadataSync.php';
try {
    if (!shop_coupon_sync_configured()) throw new RuntimeException('Coupon sync disabled');
    $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    shop_coupon_sync_ensure($pdo); shop_coupon_sync_require_innodb($pdo);
    if ((int)$pdo->query("SELECT GET_LOCK('wttp_shop_coupon_meta_sync',0)")->fetchColumn()!==1) { echo "coupon metadata sync already running\n"; exit(0); }
    try {
        $st=$pdo->prepare('SELECT COUNT(*) FROM shop_coupon_meta_outbox WHERE delivered_at IS NULL AND source_id<>?'); $st->execute([(string)SHOP_CRM_SOURCE_ID]);
        if ((int)$st->fetchColumn()>0) throw new RuntimeException('Pending events from another source');
        $scanFailed=false; try { $scan=shop_coupon_sync_scan($pdo,(string)SHOP_CRM_SOURCE_ID,50); }
        catch (Throwable $e) { $scanFailed=true; $scan=['scanned'=>0,'queued'=>0,'sweep_complete'=>false]; error_log('Coupon metadata scan stopped; pending delivery continues'); }
        $reconcile=['scanned'=>0,'queued'=>0,'sweep_complete'=>false]; $reconcileFailed=false;
        if (shop_coupon_sync_deletion_enabled()) {
            try { $reconcile=shop_coupon_sync_reconcile($pdo,(string)SHOP_CRM_SOURCE_ID,50); }
            catch (Throwable $e) { $reconcileFailed=true; error_log('Coupon reconciliation stopped; pending delivery continues'); }
        }
        $sent=shop_coupon_sync_pump($pdo,(string)SHOP_CRM_SOURCE_ID,'shop_coupon_sync_send',time());
        $pending=(int)$pdo->query('SELECT COUNT(*) FROM shop_coupon_meta_outbox WHERE delivered_at IS NULL')->fetchColumn();
        echo 'scanned='.$scan['scanned'].' queued='.$scan['queued'].' sweep_complete='.(int)$scan['sweep_complete'].' sent='.$sent['sent'].' failed='.$sent['failed'].' pending='.$pending.' scan_failed='.(int)$scanFailed.' reconcile_enabled='.(int)shop_coupon_sync_deletion_enabled().' reconcile_scanned='.$reconcile['scanned'].' reconcile_queued='.$reconcile['queued'].' reconcile_sweep_complete='.(int)$reconcile['sweep_complete'].' reconcile_failed='.(int)$reconcileFailed."\n";
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_shop_coupon_meta_sync')"); } catch (Throwable $e) {} }
    if ($scanFailed || $reconcileFailed || $sent['failed']) exit(1);
} catch (Throwable $e) { error_log('Coupon metadata sync failed'); fwrite(STDERR,"Coupon metadata sync failed; inspect configuration privately\n"); exit(1); }
