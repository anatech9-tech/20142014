<?php
/** Independently opt-in command to hide one active product. Never changes price or stock. */
require_once __DIR__.'/CrmCatalogSync.php';
function shop_product_command_configured(): bool {
    if (!defined('SHOP_PRODUCT_COMMAND_ENABLED') || SHOP_PRODUCT_COMMAND_ENABLED!==true || !defined('SHOP_PRODUCT_COMMAND_KEY') || strlen((string)SHOP_PRODUCT_COMMAND_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_PRODUCT_COMMAND_KEY)) return false;
    return true;
}
function shop_product_command_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1
        && hash_equals(hash_hmac('sha256','shop-product-command-v1.'.$ts.'.'.$body,$key),$sig);
}
function shop_product_command_schema(): string {
    return "CREATE TABLE IF NOT EXISTS shop_product_command_receipts(command_id CHAR(32) NOT NULL PRIMARY KEY,request_hash CHAR(64) NOT NULL,product_id BIGINT UNSIGNED NOT NULL,result VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_product_receipt(product_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function shop_product_command_ensure(PDO $pdo): void {
    try { $pdo->query('SELECT 1 FROM shop_product_command_receipts LIMIT 1'); } catch (Throwable $e) { $pdo->exec(shop_product_command_schema()); }
}
function shop_product_command_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['products','product_categories','shop_catalog_state','shop_product_command_receipts'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional product tables required');
    }
}
function shop_product_command_validate($c,string $source): bool {
    if (!is_array($c) || array_diff(array_keys($c),['type','command_id','source_id','product_id','revision','snapshot_hash','issued_at','expires_at'])
        || ($c['type'] ?? '')!=='shop_product_hide_v1' || ($c['source_id'] ?? '')!==$source || !preg_match('/^[a-f0-9]{32}$/D',$source)
        || !is_string($c['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$c['command_id'])
        || !is_string($c['snapshot_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$c['snapshot_hash'])) return false;
    foreach (['product_id','revision','issued_at','expires_at'] as $k) if (!is_int($c[$k] ?? null) || $c[$k]<=0) return false;
    return $c['issued_at']<=PHP_INT_MAX-900 && $c['expires_at']===$c['issued_at']+900;
}
/** Order: receipt, product, category, source state. Status and receipt commit together. */
function shop_product_command_apply(PDO $pdo,array $c,string $source,int $now,bool $enabled): string {
    if (!shop_product_command_validate($c,$source)) throw new InvalidArgumentException('Invalid product command');
    $body=json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($body===false) throw new InvalidArgumentException('Invalid encoding');
    $hash=hash('sha256',$body); $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO shop_product_command_receipts(command_id,request_hash,product_id,result,created_at) VALUES (?,?,?,'processing',?) ON DUPLICATE KEY UPDATE command_id=command_id");
        $st->execute([$c['command_id'],$hash,$c['product_id'],date('Y-m-d H:i:s',$now)]);
        $st=$pdo->prepare('SELECT request_hash,result FROM shop_product_command_receipts WHERE command_id=? FOR UPDATE'); $st->execute([$c['command_id']]); $receipt=$st->fetch(PDO::FETCH_ASSOC);
        if (!$receipt || !hash_equals((string)$receipt['request_hash'],$hash)) throw new DomainException('Command id conflict');
        if ($receipt['result']!=='processing') { $pdo->commit(); return (string)$receipt['result']; }
        if (!$enabled) throw new RuntimeException('Product command disabled');
        if ($c['issued_at']>$now+300 || $now>$c['expires_at']) $result='expired';
        else {
            $result='conflict';
            $st=$pdo->prepare('SELECT id,name_fa,name_en,sku,brand,slug,category_id,currency,price_toman,price_usd,stock,is_active FROM products WHERE id=? FOR UPDATE');
            $st->execute([$c['product_id']]); $p=$st->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $category=null;
                if ($p['category_id']!==null) { $st=$pdo->prepare('SELECT name_fa FROM product_categories WHERE id=? FOR UPDATE'); $st->execute([$p['category_id']]); $value=$st->fetchColumn(); $category=$value===false?null:(string)$value; }
                $st=$pdo->prepare('SELECT snapshot_hash,latest_revision FROM shop_catalog_state WHERE source_id=? AND product_id=? FOR UPDATE'); $st->execute([$source,$c['product_id']]); $state=$st->fetch(PDO::FETCH_ASSOC);
                $snapshot=json_encode(shop_catalog_snapshot($p,$category),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($snapshot===false || strlen($snapshot)>8192) throw new RuntimeException('Product snapshot unavailable');
                if ((int)$p['is_active']===1 && $state && (int)$state['latest_revision']===$c['revision']
                    && hash_equals((string)$state['snapshot_hash'],$c['snapshot_hash']) && hash_equals(hash('sha256',$snapshot),$c['snapshot_hash'])) {
                    $st=$pdo->prepare('UPDATE products SET is_active=0 WHERE id=? AND is_active=1'); $st->execute([$c['product_id']]);
                    if ($st->rowCount()!==1) throw new RuntimeException('Product update failed');
                    $result='applied';
                }
            }
        }
        $st=$pdo->prepare('UPDATE shop_product_command_receipts SET result=?,completed_at=? WHERE command_id=? AND result=?');
        $st->execute([$result,date('Y-m-d H:i:s',$now),$c['command_id'],'processing']); if ($st->rowCount()!==1) throw new RuntimeException('Receipt update failed');
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
