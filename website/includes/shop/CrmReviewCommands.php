<?php
/** Separately opt-in visibility commands; never edit text or delete. */
require_once __DIR__.'/CrmReviewMetaSync.php';
function shop_review_command_configured(): bool {
    if (!defined('SHOP_REVIEW_COMMAND_ENABLED') || SHOP_REVIEW_COMMAND_ENABLED!==true || !defined('SHOP_REVIEW_COMMAND_KEY') || strlen((string)SHOP_REVIEW_COMMAND_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)) return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_REVIEW_COMMAND_KEY)) return false;
    return true;
}
function shop_review_command_action_enabled(string $type): bool {
    if ($type==='shop_review_hide_v1') return defined('SHOP_REVIEW_HIDE_ENABLED') && SHOP_REVIEW_HIDE_ENABLED===true && shop_review_meta_configured() && shop_review_guard_configured();
    if ($type==='shop_review_show_v1') return defined('SHOP_REVIEW_SHOW_ENABLED') && SHOP_REVIEW_SHOW_ENABLED===true && shop_review_meta_configured() && shop_review_guard_configured();
    return false;
}
function shop_review_command_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1
        && hash_equals(hash_hmac('sha256','shop-review-command-v1.'.$ts.'.'.$body,$key),$sig);
}
function shop_review_command_schema(): string {
    return "CREATE TABLE IF NOT EXISTS shop_review_command_receipts(command_id CHAR(32) NOT NULL PRIMARY KEY,request_hash CHAR(64) NOT NULL,review_id BIGINT UNSIGNED NOT NULL,result VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_review_receipt(review_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function shop_review_command_ensure(PDO $pdo): void {
    try { $pdo->query('SELECT 1 FROM shop_review_command_receipts LIMIT 1'); } catch (Throwable $e) { $pdo->exec(shop_review_command_schema()); }
}
function shop_review_command_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $tables=['product_reviews','shop_review_meta_state','shop_review_command_receipts'];
    if (shop_review_guard_configured()) $tables[]='shop_review_content_guard_state';
    foreach ($tables as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional review tables required');
    }
}
function shop_review_command_validate($c,string $source): bool {
    if (!is_array($c) || array_diff(array_keys($c),['type','command_id','source_id','review_id','revision','snapshot_hash','issued_at','expires_at'])
        || !in_array($c['type'] ?? '',['shop_review_hide_v1','shop_review_show_v1'],true) || ($c['source_id'] ?? '')!==$source || !preg_match('/^[a-f0-9]{32}$/D',$source)
        || !is_string($c['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$c['command_id'])
        || !is_string($c['snapshot_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$c['snapshot_hash'])) return false;
    foreach (['review_id','revision','issued_at','expires_at'] as $k) if (!is_int($c[$k] ?? null) || $c[$k]<=0) return false;
    return $c['issued_at']<=PHP_INT_MAX-900 && $c['expires_at']===$c['issued_at']+900;
}
/** Receipt, review and source-state locks; visibility and receipt commit together. */
function shop_review_command_apply(PDO $pdo,array $c,string $source,int $now,bool $enabled): string {
    if (!shop_review_command_validate($c,$source)) throw new InvalidArgumentException('Invalid review command');
    $body=json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($body===false) throw new InvalidArgumentException('Invalid encoding');
    $hash=hash('sha256',$body); $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO shop_review_command_receipts(command_id,request_hash,review_id,result,created_at) VALUES (?,?,?,'processing',?) ON DUPLICATE KEY UPDATE command_id=command_id");
        $st->execute([$c['command_id'],$hash,$c['review_id'],date('Y-m-d H:i:s',$now)]);
        $st=$pdo->prepare('SELECT request_hash,result FROM shop_review_command_receipts WHERE command_id=? FOR UPDATE'); $st->execute([$c['command_id']]); $receipt=$st->fetch(PDO::FETCH_ASSOC);
        if (!$receipt || !hash_equals((string)$receipt['request_hash'],$hash)) throw new DomainException('Command id conflict');
        if ($receipt['result']!=='processing') { $pdo->commit(); return (string)$receipt['result']; }
        if (!$enabled || !shop_review_guard_configured()) throw new RuntimeException('Review command or content guard disabled');
        if ($c['issued_at']>$now+300 || $now>$c['expires_at']) $result='expired';
        else {
            $result='conflict';
            $st=$pdo->prepare('SELECT id,product_id,rating,is_active,customer_id,customer_name,body,created_at FROM product_reviews WHERE id=? FOR UPDATE');
            $st->execute([$c['review_id']]); $review=$st->fetch(PDO::FETCH_ASSOC);
            if ($review) {
                $st=$pdo->prepare('SELECT snapshot_hash,latest_revision FROM shop_review_meta_state WHERE source_id=? AND review_id=? FOR UPDATE');
                $st->execute([$source,$c['review_id']]); $state=$st->fetch(PDO::FETCH_ASSOC);
                $st=$pdo->prepare('SELECT guard_hash,latest_revision FROM shop_review_content_guard_state WHERE source_id=? AND review_id=? FOR UPDATE');
                $st->execute([$source,$c['review_id']]); $guard=$st->fetch(PDO::FETCH_ASSOC);
                $currentGuard=shop_review_guard_hash($source,$review);
                $snapshot=json_encode(shop_review_meta_snapshot($review),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($snapshot===false || strlen($snapshot)>1024) throw new RuntimeException('Review snapshot unavailable');
                $show=$c['type']==='shop_review_show_v1'; $expected=$show?0:1; $target=$show?1:0;
                if ((int)$review['is_active']===$expected && $state && (int)$state['latest_revision']===$c['revision']
                    && $guard && (int)$guard['latest_revision']===$c['revision'] && hash_equals((string)$guard['guard_hash'],$currentGuard)
                    && hash_equals((string)$state['snapshot_hash'],$c['snapshot_hash']) && hash_equals(hash('sha256',$snapshot),$c['snapshot_hash'])) {
                    $st=$pdo->prepare('UPDATE product_reviews SET is_active=? WHERE id=? AND is_active=?'); $st->execute([$target,$c['review_id'],$expected]);
                    if ($st->rowCount()!==1) throw new RuntimeException('Review update failed');
                    $result='applied';
                }
            }
        }
        $st=$pdo->prepare('UPDATE shop_review_command_receipts SET result=?,completed_at=? WHERE command_id=? AND result=?');
        $st->execute([$result,date('Y-m-d H:i:s',$now),$c['command_id'],'processing']); if ($st->rowCount()!==1) throw new RuntimeException('Receipt update failed');
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
