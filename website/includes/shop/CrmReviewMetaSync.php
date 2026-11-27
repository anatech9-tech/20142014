<?php
/** Read-only minimal review metadata; does not copy personal fields or moderate reviews. */
require_once __DIR__.'/CrmOrderSync.php';
function shop_review_meta_configured(): bool {
    if (!defined('SHOP_REVIEW_META_CRM_ENABLED') || SHOP_REVIEW_META_CRM_ENABLED!==true || !defined('SHOP_REVIEW_META_CRM_KEY') || strlen((string)SHOP_REVIEW_META_CRM_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)
        || !defined('SHOP_REVIEW_META_CRM_URL') || !filter_var(SHOP_REVIEW_META_CRM_URL,FILTER_VALIDATE_URL)
        || strtolower((string)parse_url(SHOP_REVIEW_META_CRM_URL,PHP_URL_SCHEME))!=='https') return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_REVIEW_META_CRM_KEY)) return false;
    return true;
}
function shop_review_meta_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS shop_review_meta_cursor(source_id CHAR(32) NOT NULL PRIMARY KEY,last_review_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_review_meta_state(source_id CHAR(32) NOT NULL,review_id BIGINT UNSIGNED NOT NULL,snapshot_hash CHAR(64) NOT NULL,latest_revision BIGINT UNSIGNED NOT NULL,PRIMARY KEY(source_id,review_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_review_meta_outbox(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,source_id CHAR(32) NOT NULL,review_id BIGINT UNSIGNED NOT NULL,event_id CHAR(32) NOT NULL,snapshot TEXT NULL,attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL,delivered_at DATETIME NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_review_meta_event(event_id),KEY idx_review_meta_pending(delivered_at,next_attempt_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_review_meta_ensure(PDO $pdo): void {
    foreach (['shop_review_meta_cursor','shop_review_meta_state','shop_review_meta_outbox'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) { $schemas=shop_review_meta_schema(); $pdo->exec($schemas[$i]); }
    }
}
function shop_review_meta_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['product_reviews','shop_review_meta_cursor','shop_review_meta_state','shop_review_meta_outbox'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional review metadata tables required');
    }
}
/** No review text, customer name or customer identity leaves the site. */
function shop_review_meta_snapshot(array $r): array {
    $out=['id'=>shop_crm_uint($r['id'],false),'product_id'=>shop_crm_uint($r['product_id'],false),
        'rating'=>shop_crm_uint($r['rating']),'is_active'=>shop_crm_uint($r['is_active'])];
    if ($out['rating']<1 || $out['rating']>5 || !in_array($out['is_active'],[0,1],true)) throw new RuntimeException('Invalid review metadata');
    return $out;
}
/** Only metadata and durable delivery state are written; site reviews remain untouched. */
function shop_review_meta_capture(PDO $pdo,string $source,int $id): bool {
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id,product_id,rating,is_active FROM product_reviews WHERE id=? FOR UPDATE');
        $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->prepare('UPDATE shop_review_meta_cursor SET last_review_id=? WHERE source_id=?')->execute([$id,$source]); $pdo->commit(); return false; }
        $payload=json_encode(shop_review_meta_snapshot($row),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload===false || strlen($payload)>1024) throw new RuntimeException('Invalid review metadata');
        $hash=hash('sha256',$payload); $st=$pdo->prepare('SELECT snapshot_hash FROM shop_review_meta_state WHERE source_id=? AND review_id=? FOR UPDATE');
        $st->execute([$source,$id]); $old=$st->fetchColumn(); $changed=$old===false || !hash_equals((string)$old,$hash);
        if ($changed) {
            $now=date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO shop_review_meta_outbox(source_id,review_id,event_id,snapshot,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$source,$id,bin2hex(random_bytes(16)),$payload,$now,$now]);
            $revision=shop_crm_uint($pdo->lastInsertId(),false);
            $pdo->prepare('INSERT INTO shop_review_meta_state(source_id,review_id,snapshot_hash,latest_revision) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)')->execute([$source,$id,$hash,$revision]);
        }
        $pdo->prepare('UPDATE shop_review_meta_cursor SET last_review_id=? WHERE source_id=?')->execute([$id,$source]);
        $pdo->commit(); return $changed;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function shop_review_meta_scan(PDO $pdo,string $source,int $limit=50): array {
    $limit=max(1,min(500,$limit));
    $pdo->prepare('INSERT INTO shop_review_meta_cursor(source_id,last_review_id) VALUES (?,0) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)')->execute([$source]);
    $st=$pdo->prepare('SELECT last_review_id FROM shop_review_meta_cursor WHERE source_id=?'); $st->execute([$source]); $cursor=(int)$st->fetchColumn();
    $st=$pdo->prepare("SELECT id FROM product_reviews WHERE id>? ORDER BY id LIMIT $limit"); $st->execute([$cursor]); $ids=$st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { $pdo->prepare('UPDATE shop_review_meta_cursor SET last_review_id=0 WHERE source_id=?')->execute([$source]); return ['scanned'=>0,'queued'=>0,'sweep_complete'=>true]; }
    $count=0; foreach ($ids as $id) { try { if (shop_review_meta_capture($pdo,$source,(int)$id)) $count++; } catch (Throwable $e) { error_log('Shop review metadata capture failed for review id '.(int)$id); throw $e; } }
    return ['scanned'=>count($ids),'queued'=>$count,'sweep_complete'=>false];
}
function shop_review_meta_ack(PDO $pdo,array $row,$reply,int $http): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['event_id'] ?? '')!==$row['event_id']
        || ($reply['revision'] ?? null)!==(int)$row['id'] || !in_array($reply['result'] ?? '',['updated','duplicate','stale'],true)) return false;
    if ($reply['result']==='stale') {
        $st=$pdo->prepare('SELECT latest_revision FROM shop_review_meta_state WHERE source_id=? AND review_id=?'); $st->execute([$row['source_id'],$row['review_id']]); $latest=$st->fetchColumn();
        if ($latest===false || (int)$latest<=(int)$row['id']) { error_log('Shop review metadata revision regression for review id '.(int)$row['review_id']); return false; }
    }
    return true;
}
/** Caller serializes workers using an advisory lock. */
function shop_review_meta_pump(PDO $pdo,string $source,callable $send,int $now,int $limit=20): array {
    $limit=max(1,min(50,$limit));
    $st=$pdo->prepare("SELECT * FROM shop_review_meta_outbox WHERE source_id=? AND delivered_at IS NULL AND next_attempt_at<=? ORDER BY id LIMIT $limit"); $st->execute([$source,date('Y-m-d H:i:s',$now)]); $result=['sent'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $review=json_decode((string)$row['snapshot'],true);
        if (!is_string($row['event_id']) || !preg_match('/^[a-f0-9]{32}$/D',$row['event_id']) || !is_array($review) || array_keys($review)!==['id','product_id','rating','is_active'] || !is_int($review['id']) || $review['id']!==(int)$row['review_id']
            || !is_int($review['product_id']) || $review['product_id']<=0 || !is_int($review['rating']) || $review['rating']<1 || $review['rating']>5
            || !is_int($review['is_active']) || !in_array($review['is_active'],[0,1],true)) throw new RuntimeException('Invalid queued review metadata');
        $body=json_encode(['type'=>'shop_review_meta','source_id'=>$source,'event_id'=>$row['event_id'],'revision'=>(int)$row['id'],'review'=>$review],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body===false || strlen($body)>2048) throw new RuntimeException('Oversized review metadata event');
        [$http,$reply]=$send($body);
        if (shop_review_meta_ack($pdo,$row,$reply,(int)$http)) { $pdo->prepare('UPDATE shop_review_meta_outbox SET delivered_at=?,snapshot=NULL WHERE id=? AND delivered_at IS NULL')->execute([date('Y-m-d H:i:s',$now),$row['id']]); $result['sent']++; }
        else { $attempt=(int)$row['attempts']+1; $delay=min(3600,30*(2 ** min($attempt,7))); $pdo->prepare('UPDATE shop_review_meta_outbox SET attempts=?,next_attempt_at=? WHERE id=? AND delivered_at IS NULL')->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['id']]); $result['failed']++; }
    }
    return $result;
}
function shop_review_meta_send(string $body): array {
    if (!shop_review_meta_configured() || !function_exists('curl_init')) throw new RuntimeException('Review metadata bridge disabled');
    $ts=(string)time(); $sig=hash_hmac('sha256','shop-review-meta-v1.'.$ts.'.'.$body,(string)SHOP_REVIEW_META_CRM_KEY); $response='';
    $ch=curl_init((string)SHOP_REVIEW_META_CRM_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Review-Meta-Timestamp: '.$ts,'X-Review-Meta-Signature: '.$sig],
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$ok===false?0:$http,json_decode($response,true)];
}
