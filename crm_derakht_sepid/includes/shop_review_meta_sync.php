<?php
/** Isolated review metadata replicas; no review text, identity, moderation or product writes. */
function crm_review_meta_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_shop_review_meta (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source_id CHAR(32) NOT NULL,
        review_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0, event_id CHAR(32) NOT NULL DEFAULT '', payload_hash CHAR(64) NOT NULL DEFAULT '',
        rating TINYINT UNSIGNED NOT NULL DEFAULT 0, is_active TINYINT NOT NULL DEFAULT 0, received_at DATETIME NOT NULL,
        UNIQUE KEY uq_review_source(source_id,review_id), KEY idx_review_product(source_id,product_id,id), KEY idx_review_status(is_active,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function crm_review_meta_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_shop_review_meta LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(crm_review_meta_schema()); return true; } catch (Throwable $e) { error_log('Review metadata schema unavailable'); return false; } }
}
function crm_review_meta_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $st->execute(['crm_shop_review_meta']);
    if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional review metadata table required');
}
function crm_review_meta_configured(): bool {
    if (!defined('SHOP_REVIEW_META_CRM_ENABLED') || SHOP_REVIEW_META_CRM_ENABLED!==true || !defined('SHOP_REVIEW_META_CRM_KEY') || strlen((string)SHOP_REVIEW_META_CRM_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_REVIEW_META_CRM_KEY)) return false;
    return true;
}
function crm_review_meta_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1
        && hash_equals(hash_hmac('sha256','shop-review-meta-v1.'.$ts.'.'.$body,$key),$sig);
}
function crm_review_meta_validate($data,string $source): bool {
    if (!is_array($data) || array_diff(array_keys($data),['type','source_id','event_id','revision','review']) || ($data['type'] ?? '')!=='shop_review_meta'
        || ($data['source_id'] ?? '')!==$source || !preg_match('/^[a-f0-9]{32}$/D',$source) || !is_string($data['event_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$data['event_id'])
        || !is_int($data['revision'] ?? null) || $data['revision']<=0) return false;
    $r=$data['review'] ?? null;
    if (!is_array($r) || array_diff(array_keys($r),['id','product_id','rating','is_active']) || count($r)!==4) return false;
    if (!is_int($r['id'] ?? null) || $r['id']<=0 || !is_int($r['product_id'] ?? null) || $r['product_id']<=0 || !is_int($r['rating'] ?? null) || $r['rating']<1 || $r['rating']>5
        || !is_int($r['is_active'] ?? null) || !in_array($r['is_active'],[0,1],true)) return false;
    return true;
}
function crm_review_meta_store(PDO $pdo,array $data,string $hash): string {
    $r=$data['review']; $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO crm_shop_review_meta(source_id,review_id,product_id,revision,event_id,payload_hash,rating,is_active,received_at) VALUES (?,?,?,0,'','',0,0,?) ON DUPLICATE KEY UPDATE id=id");
        $st->execute([$data['source_id'],$r['id'],$r['product_id'],date('Y-m-d H:i:s')]);
        $st=$pdo->prepare('SELECT revision,event_id,payload_hash FROM crm_shop_review_meta WHERE source_id=? AND review_id=? FOR UPDATE'); $st->execute([$data['source_id'],$r['id']]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Review metadata unavailable');
        $old=(int)$row['revision']; $rev=$data['revision'];
        if ($rev<$old) $result='stale';
        elseif ($rev===$old) $result=hash_equals((string)$row['event_id'],$data['event_id']) && hash_equals((string)$row['payload_hash'],$hash) ? 'duplicate':'conflict';
        else {
            $st=$pdo->prepare('UPDATE crm_shop_review_meta SET revision=?,event_id=?,payload_hash=?,product_id=?,rating=?,is_active=?,received_at=? WHERE source_id=? AND review_id=?');
            $st->execute([$rev,$data['event_id'],$hash,$r['product_id'],$r['rating'],$r['is_active'],date('Y-m-d H:i:s'),$data['source_id'],$r['id']]); $result='updated';
        }
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
