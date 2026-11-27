<?php
/** Opt-in, read-only source projection with durable outbox. No coupon writes. */
require_once __DIR__.'/CrmCouponMetadataContract.php';
function shop_coupon_sync_configured(): bool {
    if (!defined('SHOP_COUPON_META_ENABLED') || SHOP_COUPON_META_ENABLED!==true || !defined('SHOP_COUPON_META_KEY') || strlen((string)SHOP_COUPON_META_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)
        || !defined('SHOP_COUPON_META_URL') || !filter_var(SHOP_COUPON_META_URL,FILTER_VALIDATE_URL) || strtolower((string)parse_url(SHOP_COUPON_META_URL,PHP_URL_SCHEME))!=='https') return false;
    foreach (['SHOP_CATEGORY_META_KEY','SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SITE_SHIPMENT_META_KEY','SITE_BLOG_META_KEY'] as $key) if (defined($key) && hash_equals((string)constant($key),(string)SHOP_COUPON_META_KEY)) return false;
    return true;
}
function shop_coupon_sync_deletion_enabled(): bool { return defined('SHOP_COUPON_META_DELETION_ENABLED') && SHOP_COUPON_META_DELETION_ENABLED===true; }
function shop_coupon_sync_schema(): array {
    return [
      "CREATE TABLE IF NOT EXISTS shop_coupon_meta_cursor(source_id CHAR(32) NOT NULL PRIMARY KEY,last_coupon_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS shop_coupon_meta_state(source_id CHAR(32) NOT NULL,coupon_id BIGINT UNSIGNED NOT NULL,snapshot_hash CHAR(64) NOT NULL,latest_revision BIGINT UNSIGNED NOT NULL,PRIMARY KEY(source_id,coupon_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS shop_coupon_meta_outbox(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,source_id CHAR(32) NOT NULL,coupon_id BIGINT UNSIGNED NOT NULL,event_id CHAR(32) NOT NULL,snapshot TEXT NULL,attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL,delivered_at DATETIME NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_coupon_meta_event(event_id),KEY idx_coupon_meta_pending(delivered_at,next_attempt_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
      "CREATE TABLE IF NOT EXISTS shop_coupon_meta_reconcile_cursor(source_id CHAR(32) NOT NULL PRIMARY KEY,last_coupon_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_coupon_sync_ensure(PDO $pdo): void {
    $tables=['shop_coupon_meta_cursor','shop_coupon_meta_state','shop_coupon_meta_outbox']; if (shop_coupon_sync_deletion_enabled()) $tables[]='shop_coupon_meta_reconcile_cursor';
    foreach ($tables as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { $schemas=shop_coupon_sync_schema(); $pdo->exec($schemas[$i]); }
    }
}
function shop_coupon_sync_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $tables=['discount_codes','shop_coupon_meta_cursor','shop_coupon_meta_state','shop_coupon_meta_outbox']; if (shop_coupon_sync_deletion_enabled()) $tables[]='shop_coupon_meta_reconcile_cursor';
    foreach ($tables as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required');
    }
    if (shop_coupon_sync_deletion_enabled()) {
        $st=$pdo->prepare("SELECT COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $st->execute(['discount_codes']); $primary=$st->fetchAll(PDO::FETCH_ASSOC);
        if (count($primary)!==1 || $primary[0]['COLUMN_NAME']!=='id' || $primary[0]['SUB_PART']!==null) throw new RuntimeException('Coupon primary key required for reconciliation');
    }
}
function shop_coupon_sync_capture(PDO $pdo,string $source,int $id): bool {
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id,type,is_active FROM discount_codes WHERE id=? FOR UPDATE'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->prepare('UPDATE shop_coupon_meta_cursor SET last_coupon_id=? WHERE source_id=?')->execute([$id,$source]); $pdo->commit(); return false; }
        $snapshot=json_encode(shop_coupon_metadata_snapshot($row),JSON_UNESCAPED_SLASHES); if ($snapshot===false) throw new RuntimeException('Invalid coupon metadata');
        $hash=hash('sha256',$snapshot); $st=$pdo->prepare('SELECT snapshot_hash FROM shop_coupon_meta_state WHERE source_id=? AND coupon_id=? FOR UPDATE'); $st->execute([$source,$id]); $old=$st->fetchColumn();
        $changed=$old===false || !hash_equals((string)$old,$hash);
        if ($changed) {
            $stamp=date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO shop_coupon_meta_outbox(source_id,coupon_id,event_id,snapshot,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$source,$id,bin2hex(random_bytes(16)),$snapshot,$stamp,$stamp]);
            $revision=shop_coupon_metadata_uint($pdo->lastInsertId());
            $pdo->prepare('INSERT INTO shop_coupon_meta_state(source_id,coupon_id,snapshot_hash,latest_revision) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)')->execute([$source,$id,$hash,$revision]);
        }
        $pdo->prepare('UPDATE shop_coupon_meta_cursor SET last_coupon_id=? WHERE source_id=?')->execute([$id,$source]); $pdo->commit(); return $changed;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Caller must serialize all scan/pump operations for this database. */
function shop_coupon_sync_scan(PDO $pdo,string $source,int $limit=50): array {
    $limit=max(1,min(500,$limit));
    $pdo->prepare('INSERT INTO shop_coupon_meta_cursor(source_id,last_coupon_id) VALUES (?,0) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)')->execute([$source]);
    $st=$pdo->prepare('SELECT last_coupon_id FROM shop_coupon_meta_cursor WHERE source_id=?'); $st->execute([$source]); $cursor=shop_coupon_metadata_uint($st->fetchColumn(),true);
    $st=$pdo->prepare("SELECT id FROM discount_codes WHERE id>? ORDER BY id LIMIT $limit"); $st->execute([$cursor]); $ids=$st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { $pdo->prepare('UPDATE shop_coupon_meta_cursor SET last_coupon_id=0 WHERE source_id=?')->execute([$source]); return ['scanned'=>0,'queued'=>0,'sweep_complete'=>true]; }
    $count=0; foreach ($ids as $id) if (shop_coupon_sync_capture($pdo,$source,shop_coupon_metadata_uint($id))) $count++;
    return ['scanned'=>count($ids),'queued'=>$count,'sweep_complete'=>false];
}
/** Lock source row/gap first, then state, in the same order as capture. */
function shop_coupon_sync_reconcile_one(PDO $pdo,string $source,int $id): bool {
    if (!shop_coupon_sync_deletion_enabled() || $id<=0) throw new DomainException('Coupon reconciliation disabled or invalid');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id FROM discount_codes WHERE id=? FOR UPDATE'); $st->execute([$id]); $exists=$st->fetchColumn()!==false;
        $st=$pdo->prepare('SELECT snapshot_hash FROM shop_coupon_meta_state WHERE source_id=? AND coupon_id=? FOR UPDATE'); $st->execute([$source,$id]); $old=$st->fetchColumn(); $queued=false;
        if (!$exists && $old!==false) {
            $payload=json_encode(['id'=>$id,'deleted'=>true],JSON_UNESCAPED_SLASHES); if ($payload===false) throw new RuntimeException('Invalid tombstone');
            $hash=hash('sha256',$payload);
            if (!hash_equals((string)$old,$hash)) {
                $stamp=date('Y-m-d H:i:s');
                $pdo->prepare('INSERT INTO shop_coupon_meta_outbox(source_id,coupon_id,event_id,snapshot,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$source,$id,bin2hex(random_bytes(16)),$payload,$stamp,$stamp]);
                $revision=shop_coupon_metadata_uint($pdo->lastInsertId());
                $pdo->prepare('UPDATE shop_coupon_meta_state SET snapshot_hash=?,latest_revision=? WHERE source_id=? AND coupon_id=?')->execute([$hash,$revision,$source,$id]); $queued=true;
            }
        }
        $pdo->prepare('UPDATE shop_coupon_meta_reconcile_cursor SET last_coupon_id=? WHERE source_id=?')->execute([$id,$source]); $pdo->commit(); return $queued;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Caller serializes scans, reconciliation and delivery with the same worker lock. */
function shop_coupon_sync_reconcile(PDO $pdo,string $source,int $limit=50): array {
    if (!shop_coupon_sync_deletion_enabled()) throw new DomainException('Coupon reconciliation disabled');
    $limit=max(1,min(500,$limit));
    $pdo->prepare('INSERT INTO shop_coupon_meta_reconcile_cursor(source_id,last_coupon_id) VALUES (?,0) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)')->execute([$source]);
    $st=$pdo->prepare('SELECT last_coupon_id FROM shop_coupon_meta_reconcile_cursor WHERE source_id=?'); $st->execute([$source]); $cursor=shop_coupon_metadata_uint($st->fetchColumn(),true);
    $st=$pdo->prepare("SELECT coupon_id FROM shop_coupon_meta_state WHERE source_id=? AND coupon_id>? ORDER BY coupon_id LIMIT $limit"); $st->execute([$source,$cursor]); $ids=$st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { $pdo->prepare('UPDATE shop_coupon_meta_reconcile_cursor SET last_coupon_id=0 WHERE source_id=?')->execute([$source]); return ['scanned'=>0,'queued'=>0,'sweep_complete'=>true]; }
    $count=0; foreach ($ids as $id) if (shop_coupon_sync_reconcile_one($pdo,$source,shop_coupon_metadata_uint($id))) $count++;
    return ['scanned'=>count($ids),'queued'=>$count,'sweep_complete'=>false];
}
function shop_coupon_sync_ack(PDO $pdo,array $row,$reply,int $http): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['event_id'] ?? null)!==$row['event_id']
        || ($reply['revision'] ?? null)!==shop_coupon_metadata_uint($row['id']) || !in_array($reply['result'] ?? null,['updated','duplicate','stale'],true)) return false;
    if ($reply['result']==='stale') {
        $st=$pdo->prepare('SELECT latest_revision FROM shop_coupon_meta_state WHERE source_id=? AND coupon_id=?'); $st->execute([$row['source_id'],$row['coupon_id']]); $latest=$st->fetchColumn();
        if ($latest===false || shop_coupon_metadata_uint($latest)<=shop_coupon_metadata_uint($row['id'])) return false;
    }
    return true;
}
function shop_coupon_sync_pump(PDO $pdo,string $source,callable $send,int $now,int $limit=20): array {
    $limit=max(1,min(50,$limit)); $st=$pdo->prepare("SELECT * FROM shop_coupon_meta_outbox WHERE source_id=? AND delivered_at IS NULL AND next_attempt_at<=? ORDER BY id LIMIT $limit");
    $st->execute([$source,date('Y-m-d H:i:s',$now)]); $result=['sent'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snapshot=json_decode((string)$row['snapshot'],true);
        if (!is_array($snapshot) || !is_int($snapshot['id'] ?? null) || $snapshot['id']<=0 || $snapshot['id']!==shop_coupon_metadata_uint($row['coupon_id']) || !is_string($row['event_id']) || !preg_match('/^[a-f0-9]{32}$/D',$row['event_id'])) throw new RuntimeException('Invalid queued coupon metadata');
        $deleted=array_keys($snapshot)===['id','deleted'] && ($snapshot['deleted'] ?? null)===true;
        if ($deleted) $event=['type'=>'shop_coupon_meta_deleted_v1','source_id'=>$source,'event_id'=>$row['event_id'],'revision'=>shop_coupon_metadata_uint($row['id']),'coupon'=>$snapshot];
        else {
            if (array_keys($snapshot)!==['id','type','is_active'] || !is_int($snapshot['is_active'] ?? null)) throw new RuntimeException('Invalid queued coupon metadata');
            $event=shop_coupon_metadata_event($source,$row['event_id'],shop_coupon_metadata_uint($row['id']),$snapshot);
        }
        // Existing tombstones continue to be sent if the scanner flag is later turned off.
        $body=json_encode($event,JSON_UNESCAPED_SLASHES); if ($body===false || strlen($body)>2048) throw new RuntimeException('Invalid queued coupon metadata');
        [$http,$reply]=$send($body);
        if (shop_coupon_sync_ack($pdo,$row,$reply,(int)$http)) { $pdo->prepare('UPDATE shop_coupon_meta_outbox SET delivered_at=?,snapshot=NULL WHERE id=? AND delivered_at IS NULL')->execute([date('Y-m-d H:i:s',$now),$row['id']]); $result['sent']++; }
        else { $attempt=(int)$row['attempts']+1; $delay=min(3600,30*(2 ** min($attempt,7))); $pdo->prepare('UPDATE shop_coupon_meta_outbox SET attempts=?,next_attempt_at=? WHERE id=? AND delivered_at IS NULL')->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['id']]); $result['failed']++; }
    }
    return $result;
}
function shop_coupon_sync_send(string $body): array {
    if (!shop_coupon_sync_configured() || !function_exists('curl_init')) throw new RuntimeException('Coupon sync disabled');
    $ts=(string)time(); $sig=hash_hmac('sha256','shop-coupon-meta-v1.'.$ts.'.'.$body,(string)SHOP_COUPON_META_KEY); $response=''; $ch=curl_init((string)SHOP_COUPON_META_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Coupon-Meta-Timestamp: '.$ts,'X-Coupon-Meta-Signature: '.$sig],
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$ok===false?0:$http,json_decode($response,true)];
}
