<?php
/** Isolated read-only shop product replicas; no creation or modification of CRM products. */
function crm_shop_catalog_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_shop_catalog (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source_id CHAR(32) NOT NULL,
        shop_product_id BIGINT UNSIGNED NOT NULL, revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_id CHAR(32) NOT NULL DEFAULT '', payload_hash CHAR(64) NOT NULL DEFAULT '',
        name_fa VARCHAR(200) NOT NULL DEFAULT '', sku VARCHAR(60) NOT NULL DEFAULT '',
        is_active TINYINT NOT NULL DEFAULT 0, payload TEXT NOT NULL, received_at DATETIME NOT NULL,
        UNIQUE KEY uq_catalog_source_product(source_id,shop_product_id), KEY idx_catalog_active(is_active,id), KEY idx_catalog_name(name_fa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function crm_shop_catalog_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_shop_catalog LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(crm_shop_catalog_schema()); return true; } catch (Throwable $e) { error_log('Shop catalogue schema unavailable'); return false; } }
}
function crm_shop_catalog_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute(['crm_shop_catalog']);
    if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional catalogue replica required');
}
function crm_shop_catalog_configured(): bool {
    if (!defined('SHOP_CATALOG_CRM_ENABLED') || SHOP_CATALOG_CRM_ENABLED!==true || !defined('SHOP_CATALOG_CRM_KEY') || strlen((string)SHOP_CATALOG_CRM_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_CATALOG_CRM_KEY)) return false;
    return true;
}
function crm_shop_catalog_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300
        && preg_match('/^[a-f0-9]{64}$/D',$sig)===1 && hash_equals(hash_hmac('sha256','shop-catalog-v1.'.$ts.'.'.$body,$key),$sig);
}
function crm_shop_catalog_validate($data,string $source): bool {
    if (!is_array($data) || array_diff(array_keys($data),['type','source_id','event_id','revision','product']) || ($data['type'] ?? '')!=='shop_product'
        || ($data['source_id'] ?? '')!==$source || !preg_match('/^[a-f0-9]{32}$/D',$source) || !is_string($data['event_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$data['event_id'])
        || !is_int($data['revision'] ?? null) || $data['revision']<=0) return false;
    $p=$data['product'] ?? null;
    if (!is_array($p) || array_diff(array_keys($p),['id','name_fa','name_en','sku','brand','slug','category_id','category_name','currency','price_toman','price_usd','stock','is_active']) || !is_int($p['id'] ?? null) || $p['id']<=0) return false;
    foreach (['name_fa'=>200,'name_en'=>200,'sku'=>60,'brand'=>100,'slug'=>220] as $key=>$max) if (!is_string($p[$key] ?? null) || mb_strlen($p[$key],'UTF-8')>$max || preg_match('/[\x00-\x1F\x7F]/',$p[$key])) return false;
    if ($p['name_fa']==='' || $p['slug']==='' || !array_key_exists('category_id',$p) || ($p['category_id']!==null && (!is_int($p['category_id']) || $p['category_id']<=0))
        || !array_key_exists('category_name',$p) || ($p['category_name']!==null && (!is_string($p['category_name']) || mb_strlen($p['category_name'],'UTF-8')>100 || preg_match('/[\x00-\x1F\x7F]/',$p['category_name'])))
        || !array_key_exists('price_toman',$p) || ($p['price_toman']!==null && (!is_int($p['price_toman']) || $p['price_toman']<0))
        || !array_key_exists('price_usd',$p) || ($p['price_usd']!==null && (!is_string($p['price_usd']) || !preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D',$p['price_usd'])))
        || !is_int($p['stock'] ?? null) || $p['stock']<0 || !is_int($p['is_active'] ?? null) || !in_array($p['is_active'],[0,1],true)
        || !in_array($p['currency'] ?? '',['toman','usd'],true)) return false;
    return true;
}
function crm_shop_catalog_store(PDO $pdo,array $data,string $hash): string {
    $p=$data['product']; $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO crm_shop_catalog(source_id,shop_product_id,revision,event_id,payload_hash,name_fa,sku,is_active,payload,received_at) VALUES (?,?,0,'','','','',0,'',?) ON DUPLICATE KEY UPDATE id=id");
        $st->execute([$data['source_id'],$p['id'],date('Y-m-d H:i:s')]);
        $st=$pdo->prepare('SELECT revision,event_id,payload_hash FROM crm_shop_catalog WHERE source_id=? AND shop_product_id=? FOR UPDATE'); $st->execute([$data['source_id'],$p['id']]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Catalogue row unavailable');
        $old=(int)$row['revision']; $rev=$data['revision'];
        if ($rev<$old) $result='stale';
        elseif ($rev===$old) $result=hash_equals((string)$row['event_id'],$data['event_id']) && hash_equals((string)$row['payload_hash'],$hash) ? 'duplicate':'conflict';
        else {
            $payload=json_encode($p,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($payload===false) throw new RuntimeException('Encoding failed');
            $st=$pdo->prepare('UPDATE crm_shop_catalog SET revision=?,event_id=?,payload_hash=?,name_fa=?,sku=?,is_active=?,payload=?,received_at=? WHERE source_id=? AND shop_product_id=?');
            $st->execute([$rev,$data['event_id'],$hash,$p['name_fa'],$p['sku'],$p['is_active'],$payload,date('Y-m-d H:i:s'),$data['source_id'],$p['id']]); $result='updated';
        }
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
