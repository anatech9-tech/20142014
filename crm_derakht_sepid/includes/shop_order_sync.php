<?php
/** Read-only shop replicas. The shop remains the source of truth. */
function crm_shop_orders_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_shop_orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        source_id CHAR(32) NOT NULL, shop_order_id BIGINT UNSIGNED NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0, event_id CHAR(32) NOT NULL DEFAULT '',
        payload_hash CHAR(64) NOT NULL DEFAULT '', payload MEDIUMTEXT NOT NULL,
        received_at DATETIME NOT NULL, UNIQUE KEY uq_shop_source_order(source_id,shop_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function crm_shop_orders_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_shop_orders LIMIT 1'); return true; } catch (Throwable $e) {}
    try { $pdo->exec(crm_shop_orders_schema()); return true; }
    catch (Throwable $e) { error_log('Shop replica schema unavailable'); return false; }
}
function crm_shop_order_validate($data, string $expectedSource): bool {
    if (!is_array($data) || ($data['type'] ?? '') !== 'shop_order' || !preg_match('/^[a-f0-9]{32}$/D', $expectedSource)
        || ($data['source_id'] ?? '') !== $expectedSource || !is_string($data['event_id'] ?? null)
        || !preg_match('/^[a-f0-9]{32}$/D', $data['event_id']) || !is_int($data['revision'] ?? null) || $data['revision'] <= 0) return false;
    if (array_diff(array_keys($data), ['type','source_id','event_id','revision','order'])) return false;
    $o=$data['order'] ?? null;
    if (!is_array($o) || !is_int($o['id'] ?? null) || $o['id'] <= 0) return false;
    if (array_diff(array_keys($o), ['id','order_no','customer_id','customer_name','customer_phone','customer_email','subtotal_toman','discount_toman','shipping_fee','total_toman','order_status','payment_status','created_at','order_event_id','items'])) return false;
    foreach (['order_no'=>30,'customer_name'=>100,'customer_phone'=>30,'customer_email'=>200] as $field=>$max) {
        if (!is_string($o[$field] ?? null) || mb_strlen($o[$field], 'UTF-8') > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $o[$field])) return false;
    }
    if ($o['order_no'] === '' || !is_string($o['created_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $o['created_at'])) return false;
    if (!in_array($o['order_status'] ?? '', ['pending','confirmed','packed','shipped','delivered','cancelled'], true)
        || !in_array($o['payment_status'] ?? '', ['pending','paid','failed','refunded'], true)) return false;
    foreach (['subtotal_toman','discount_toman','shipping_fee','total_toman'] as $field) {
        if (!is_int($o[$field] ?? null) || $o[$field] < 0) return false;
    }
    // Optional for queued events from older shop code; zero means no source event yet.
    if (array_key_exists('order_event_id',$o) && (!is_int($o['order_event_id']) || $o['order_event_id'] < 0)) return false;
    if (!array_key_exists('customer_id', $o) || ($o['customer_id'] !== null && (!is_int($o['customer_id']) || $o['customer_id'] <= 0))) return false;
    if (!isset($o['items']) || !is_array($o['items']) || !array_is_list($o['items']) || count($o['items']) > 1000) return false;
    foreach ($o['items'] as $item) {
        if (!is_array($item) || !array_key_exists('product_id',$item) || ($item['product_id'] !== null && (!is_int($item['product_id']) || $item['product_id'] <= 0))
            || !is_string($item['product_name'] ?? null) || mb_strlen($item['product_name'], 'UTF-8') > 200
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $item['product_name'])) return false;
        if (array_diff(array_keys($item), ['product_id','product_name','qty','price_toman','total_toman'])) return false;
        foreach (['qty','price_toman','total_toman'] as $field) if (!is_int($item[$field] ?? null) || $item[$field] < 0) return false;
    }
    return true;
}
/** Lock the target row; older snapshots may arrive later and must never overwrite newer ones. */
function crm_shop_order_store(PDO $pdo, array $data, string $hash): string {
    $o=$data['order']; $source=$data['source_id']; $id=$o['id'];
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO crm_shop_orders(source_id,shop_order_id,revision,event_id,payload_hash,payload,received_at) VALUES (?,?,0,'','','',?) ON DUPLICATE KEY UPDATE id=id");
        $st->execute([$source,$id,date('Y-m-d H:i:s')]);
        $st=$pdo->prepare('SELECT revision,event_id,payload_hash FROM crm_shop_orders WHERE source_id=? AND shop_order_id=? FOR UPDATE');
        $st->execute([$source,$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Replica row unavailable');
        $previous=(int)$row['revision']; $revision=$data['revision'];
        if ($revision < $previous) $result='stale';
        elseif ($revision === $previous) $result=hash_equals((string)$row['payload_hash'],$hash) && hash_equals((string)$row['event_id'],$data['event_id']) ? 'duplicate' : 'conflict';
        else {
            $payload=json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) throw new RuntimeException('Encoding failed');
            $up=$pdo->prepare('UPDATE crm_shop_orders SET revision=?,event_id=?,payload_hash=?,payload=?,received_at=? WHERE source_id=? AND shop_order_id=?');
            $up->execute([$revision,$data['event_id'],$hash,$payload,date('Y-m-d H:i:s'),$source,$id]);
            $result='updated';
        }
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
