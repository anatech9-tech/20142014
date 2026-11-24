<?php
/** Shop-to-CRM read-only replication. Source DB and CRM DB remain separate. */
function shop_crm_configured(): bool {
    return defined('SHOP_CRM_BRIDGE_ENABLED') && SHOP_CRM_BRIDGE_ENABLED === true
        && defined('SHOP_CRM_BRIDGE_KEY') && strlen((string)SHOP_CRM_BRIDGE_KEY) >= 32
        && defined('SHOP_CRM_SOURCE_ID') && preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID) === 1
        && defined('SHOP_CRM_BRIDGE_URL') && filter_var(SHOP_CRM_BRIDGE_URL,FILTER_VALIDATE_URL)
        && strtolower((string)parse_url(SHOP_CRM_BRIDGE_URL,PHP_URL_SCHEME)) === 'https';
}
function shop_crm_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS shop_crm_cursor (source_id CHAR(32) NOT NULL PRIMARY KEY, last_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_crm_sync_state (source_id CHAR(32) NOT NULL, shop_order_id BIGINT UNSIGNED NOT NULL, snapshot_hash CHAR(64) NOT NULL, latest_revision BIGINT UNSIGNED NOT NULL, PRIMARY KEY(source_id,shop_order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS shop_crm_outbox (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source_id CHAR(32) NOT NULL, shop_order_id BIGINT UNSIGNED NOT NULL, event_id CHAR(32) NOT NULL, snapshot MEDIUMTEXT NULL, attempts INT NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL, delivered_at DATETIME NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_shop_event(event_id), KEY idx_shop_pending(delivered_at,next_attempt_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_crm_ensure(PDO $pdo): void {
    foreach (['shop_crm_cursor','shop_crm_sync_state','shop_crm_outbox'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) { $schemas=shop_crm_schema(); $pdo->exec($schemas[$i]); }
    }
}
function shop_crm_uint($value, bool $allowZero=true): int {
    $s=(string)$value;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$s) || strlen($s)>strlen((string)PHP_INT_MAX) || (strlen($s)===strlen((string)PHP_INT_MAX) && strcmp($s,(string)PHP_INT_MAX)>0)) throw new RuntimeException('Invalid numeric field');
    $n=(int)$s; if (!$allowZero && $n===0) throw new RuntimeException('Invalid identifier'); return $n;
}
/** Deliberately excludes addresses, notes, token, credentials and other unnecessary fields. */
function shop_crm_snapshot(array $o, array $items): array {
    if (count($items)>1000) throw new RuntimeException('Order exceeds item limit');
    $data=['id'=>shop_crm_uint($o['id'],false), 'order_no'=>(string)$o['order_no'],
        'customer_id'=>$o['customer_id'] === null ? null : shop_crm_uint($o['customer_id'],false),
        'customer_name'=>(string)$o['customer_name'],'customer_phone'=>(string)$o['customer_phone'], 'customer_email'=>(string)$o['customer_email'],
        'subtotal_toman'=>shop_crm_uint($o['subtotal_toman']), 'discount_toman'=>shop_crm_uint($o['discount_toman']),
        'shipping_fee'=>shop_crm_uint($o['shipping_fee']), 'total_toman'=>shop_crm_uint($o['total_toman']),
        'order_status'=>(string)$o['order_status'], 'payment_status'=>(string)$o['payment_status'], 'created_at'=>(string)$o['created_at'],
        'order_event_id'=>shop_crm_uint($o['order_event_id']), 'items'=>[]];
    foreach ($items as $i) $data['items'][]=['product_id'=>$i['product_id'] === null ? null : shop_crm_uint($i['product_id'],false),
        'product_name'=>(string)$i['product_name'], 'qty'=>shop_crm_uint($i['qty']), 'price_toman'=>shop_crm_uint($i['price_toman']), 'total_toman'=>shop_crm_uint($i['total_toman'])];
    return $data;
}
/** Capture each order while it is locked; cursor and durable event commit together. */
function shop_crm_capture(PDO $pdo, string $source, int $id): bool {
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT id,order_no,customer_id,customer_name,customer_phone,customer_email,subtotal_toman,discount_toman,shipping_fee,total_toman,order_status,payment_status,created_at FROM orders WHERE id=? FOR UPDATE');
        $st->execute([$id]); $order=$st->fetch(PDO::FETCH_ASSOC);
        if (!$order) { // Deletions are not replicated; an operator must reconcile them.
            $pdo->prepare('UPDATE shop_crm_cursor SET last_order_id=? WHERE source_id=?')->execute([$id,$source]);
            $pdo->commit(); return false;
        }
        // The row lock serializes all application writers that append order events.
        $st=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM order_events WHERE order_id=?');
        $st->execute([$id]); $order['order_event_id']=shop_crm_uint($st->fetchColumn());
        $st=$pdo->prepare('SELECT product_id,product_name,qty,price_toman,total_toman FROM order_items WHERE order_id=? ORDER BY id LIMIT 1001 FOR UPDATE');
        $st->execute([$id]); $snapshot=shop_crm_snapshot($order,$st->fetchAll(PDO::FETCH_ASSOC));
        $payload=json_encode($snapshot,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload===false || strlen($payload)>500000) throw new RuntimeException('Invalid or oversized order snapshot');
        $hash=hash('sha256',$payload);
        $st=$pdo->prepare('SELECT snapshot_hash FROM shop_crm_sync_state WHERE source_id=? AND shop_order_id=? FOR UPDATE');
        $st->execute([$source,$id]); $old=$st->fetchColumn(); $changed=$old===false || !hash_equals((string)$old,$hash);
        if ($changed) {
            $now=date('Y-m-d H:i:s');
            $pdo->prepare('INSERT INTO shop_crm_outbox(source_id,shop_order_id,event_id,snapshot,next_attempt_at,created_at) VALUES (?,?,?,?,?,?)')->execute([$source,$id,bin2hex(random_bytes(16)),$payload,$now,$now]);
            $revision=shop_crm_uint($pdo->lastInsertId(),false);
            $pdo->prepare('INSERT INTO shop_crm_sync_state(source_id,shop_order_id,snapshot_hash,latest_revision) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE snapshot_hash=VALUES(snapshot_hash),latest_revision=VALUES(latest_revision)')->execute([$source,$id,$hash,$revision]);
        }
        $pdo->prepare('UPDATE shop_crm_cursor SET last_order_id=? WHERE source_id=?')->execute([$id,$source]);
        $pdo->commit(); return $changed;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Keyset sweeps include old and new orders. One advisory lock serializes cron runs. */
function shop_crm_scan(PDO $pdo, string $source, int $limit=50): array {
    $limit=max(1,min(500,$limit));
    $pdo->prepare('INSERT INTO shop_crm_cursor(source_id,last_order_id) VALUES (?,0) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)')->execute([$source]);
    $st=$pdo->prepare('SELECT last_order_id FROM shop_crm_cursor WHERE source_id=?'); $st->execute([$source]); $cursor=(int)$st->fetchColumn();
    $st=$pdo->prepare("SELECT id FROM orders WHERE id>? ORDER BY id LIMIT $limit"); $st->execute([$cursor]); $ids=$st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { $pdo->prepare('UPDATE shop_crm_cursor SET last_order_id=0 WHERE source_id=?')->execute([$source]); return ['scanned'=>0,'queued'=>0,'sweep_complete'=>true]; }
    $queued=0;
    foreach ($ids as $id) {
        try { if (shop_crm_capture($pdo,$source,(int)$id)) $queued++; }
        catch (Throwable $e) { error_log('Shop CRM capture failed for order id '.(int)$id); throw $e; }
    }
    return ['scanned'=>count($ids),'queued'=>$queued,'sweep_complete'=>false];
}
/** A stale acknowledgement is safe only when a newer event for this order exists locally. */
function shop_crm_acknowledged(PDO $pdo, array $row, $decoded, int $http): bool {
    if ($http<200 || $http>=300 || !is_array($decoded) || empty($decoded['ok'])
        || ($decoded['event_id'] ?? '')!==$row['event_id'] || ($decoded['revision'] ?? null)!==(int)$row['id']
        || !in_array($decoded['result'] ?? '', ['updated','duplicate','stale'],true)) return false;
    if ($decoded['result'] === 'stale') {
        $check=$pdo->prepare('SELECT latest_revision FROM shop_crm_sync_state WHERE source_id=? AND shop_order_id=?');
        $check->execute([$row['source_id'],$row['shop_order_id']]);
        $local=$check->fetchColumn();
        if ($local===false || (int)$local <= (int)$row['id']) { error_log('Shop CRM revision regression for order id '.(int)$row['shop_order_id']); return false; }
    }
    return true;
}
function shop_crm_pump(PDO $pdo, int $limit=20): array {
    if (!shop_crm_configured() || !function_exists('curl_init')) throw new RuntimeException('Shop bridge not configured');
    $limit=max(1,min(50,$limit)); $now=date('Y-m-d H:i:s');
    $st=$pdo->prepare("SELECT id,source_id,shop_order_id,event_id,snapshot,attempts FROM shop_crm_outbox WHERE source_id=? AND delivered_at IS NULL AND next_attempt_at<=? ORDER BY id LIMIT $limit");
    $st->execute([(string)SHOP_CRM_SOURCE_ID,$now]); $result=['sent'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snapshot=json_decode((string)$row['snapshot'],true);
        if (!is_array($snapshot)) throw new RuntimeException('Invalid queued snapshot');
        $body=json_encode(['type'=>'shop_order','source_id'=>$row['source_id'],'event_id'=>$row['event_id'],'revision'=>shop_crm_uint($row['id'],false),'order'=>$snapshot],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body===false || strlen($body)>524288) throw new RuntimeException('Invalid queued payload');
        $ts=(string)time(); $sig=hash_hmac('sha256',$ts.'.'.$body,(string)SHOP_CRM_BRIDGE_KEY);
        $ch=curl_init((string)SHOP_CRM_BRIDGE_URL);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Site-Timestamp: '.$ts,'X-Site-Signature: '.$sig],
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $response=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $decoded=is_string($response)?json_decode($response,true):null;
        $accepted=shop_crm_acknowledged($pdo,$row,$decoded,$http);
        if ($accepted) {
            $pdo->prepare('UPDATE shop_crm_outbox SET delivered_at=?,snapshot=NULL WHERE id=? AND delivered_at IS NULL')->execute([date('Y-m-d H:i:s'),$row['id']]); $result['sent']++;
        } else {
            $attempts=(int)$row['attempts']+1; $delay=min(3600,30*(2 ** min($attempts,7)));
            $pdo->prepare('UPDATE shop_crm_outbox SET attempts=?,next_attempt_at=? WHERE id=? AND delivered_at IS NULL')->execute([$attempts,date('Y-m-d H:i:s',time()+$delay),$row['id']]); $result['failed']++;
        }
    }
    return $result;
}
