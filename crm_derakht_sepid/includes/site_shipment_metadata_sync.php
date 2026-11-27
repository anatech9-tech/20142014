<?php
/** Optional, isolated metadata replica; no order link or shipment writes. */
require_once __DIR__.'/site_shipment_metadata_contract.php';
function crm_site_shipment_sync_configured(): bool {
    if (!defined('SITE_SHIPMENT_META_ENABLED') || SITE_SHIPMENT_META_ENABLED!==true || !defined('SITE_SHIPMENT_META_KEY') || strlen((string)SITE_SHIPMENT_META_KEY)<32
        || !defined('SITE_SHIPMENT_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SITE_SHIPMENT_SOURCE_ID)) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SHOP_COUPON_META_KEY','SITE_BLOG_META_KEY'] as $key) if (defined($key) && hash_equals((string)constant($key),(string)SITE_SHIPMENT_META_KEY)) return false;
    return true;
}
function crm_site_shipment_sync_deletion_enabled(): bool { return defined('SITE_SHIPMENT_META_DELETION_ENABLED') && SITE_SHIPMENT_META_DELETION_ENABLED===true; }
function crm_site_shipment_sync_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1
        && hash_equals(hash_hmac('sha256','site-shipment-meta-v1.'.$ts.'.'.$body,$key),$sig);
}
function crm_site_shipment_sync_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_site_shipment_meta(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,source_id CHAR(32) NOT NULL,shipment_id BIGINT UNSIGNED NOT NULL,revision BIGINT UNSIGNED NOT NULL DEFAULT 0,event_id CHAR(32) NOT NULL DEFAULT '',payload_hash CHAR(64) NOT NULL DEFAULT '',service_type VARCHAR(20) NOT NULL DEFAULT '',status VARCHAR(32) NOT NULL DEFAULT '',is_public TINYINT NOT NULL DEFAULT 0,received_at DATETIME NOT NULL,UNIQUE KEY uq_site_shipment_source(source_id,shipment_id),KEY idx_site_shipment_status(status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function crm_site_shipment_sync_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_site_shipment_meta LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(crm_site_shipment_sync_schema()); return true; } catch (Throwable $e) { error_log('Shipment metadata schema unavailable'); return false; } }
}
function crm_site_shipment_sync_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $st->execute(['crm_site_shipment_meta']);
    if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required');
}
function crm_site_shipment_sync_store(PDO $pdo,array $data,string $hash): string {
    if (!crm_site_shipment_sync_configured() || !crm_site_shipment_metadata_validate($data,(string)SITE_SHIPMENT_SOURCE_ID) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new DomainException('Invalid shipment event');
    $r=$data['shipment']; $deleted=$data['type']==='site_shipment_meta_deleted_v1';
    if ($deleted && !crm_site_shipment_sync_deletion_enabled()) throw new DomainException('Shipment reconciliation disabled');
    $service=$deleted?'':$r['service_type']; $status=$deleted?'':$r['status']; $public=$deleted?0:$r['is_public']; $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO crm_site_shipment_meta(source_id,shipment_id,revision,event_id,payload_hash,service_type,status,is_public,received_at) VALUES (?,?,0,'','','','',0,?) ON DUPLICATE KEY UPDATE id=id");
        $st->execute([$data['source_id'],$r['id'],date('Y-m-d H:i:s')]);
        $st=$pdo->prepare('SELECT revision,event_id,payload_hash FROM crm_site_shipment_meta WHERE source_id=? AND shipment_id=? FOR UPDATE'); $st->execute([$data['source_id'],$r['id']]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new RuntimeException('Shipment metadata unavailable');
        if ($data['revision']<(int)$old['revision']) $result='stale';
        elseif ($data['revision']===(int)$old['revision']) $result=hash_equals((string)$old['event_id'],$data['event_id']) && hash_equals((string)$old['payload_hash'],$hash)?'duplicate':'conflict';
        else {
            $st=$pdo->prepare('UPDATE crm_site_shipment_meta SET revision=?,event_id=?,payload_hash=?,service_type=?,status=?,is_public=?,received_at=? WHERE source_id=? AND shipment_id=?');
            $st->execute([$data['revision'],$data['event_id'],$hash,$service,$status,$public,date('Y-m-d H:i:s'),$data['source_id'],$r['id']]); $result='updated';
        }
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
