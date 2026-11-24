<?php
/** Internal handoffs. Delivery requires a separate explicit command approval. */
require_once __DIR__ . '/shop_order_commands.php';
function shop_status_request_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_status_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            replica_id BIGINT UNSIGNED NOT NULL, source_id CHAR(32) NOT NULL,
            shop_order_id BIGINT UNSIGNED NOT NULL, replica_revision BIGINT UNSIGNED NOT NULL,
            expected_status VARCHAR(20) NOT NULL, expected_payment VARCHAR(20) NOT NULL,
            requested_status VARCHAR(20) NOT NULL, state VARCHAR(12) NOT NULL DEFAULT 'open',
            created_by INT NOT NULL, created_at DATETIME NOT NULL,
            closed_by INT NULL, closed_at DATETIME NULL,
            KEY idx_status_request_order(replica_id,id), KEY idx_status_request_state(state,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_status_request_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id BIGINT UNSIGNED NOT NULL, replica_id BIGINT UNSIGNED NOT NULL,
            actor_id INT NOT NULL, action VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_status_request_event(request_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_status_request_ensure(PDO $pdo): bool {
    foreach (['crm_shop_status_requests','crm_shop_status_request_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) {
            try { $schema=shop_status_request_schema(); $pdo->exec($schema[$i]); }
            catch (Throwable $e) { error_log('Shop status request schema unavailable'); return false; }
        }
    }
    return true;
}
/** Only next normal step or cancellation before shipment; payment is never changed. */
function shop_status_request_targets(string $current): array {
    return match ($current) {
        'pending' => ['confirmed','cancelled'],
        'confirmed' => ['packed','cancelled'],
        'packed' => ['shipped','cancelled'],
        'shipped' => ['delivered'],
        default => [],
    };
}
function shop_status_request_check_admin(PDO $pdo, int $actor, bool $strictAdmin): void {
    if ($actor <= 0 || !$strictAdmin) throw new DomainException('فقط مدیر کل مجاز است.');
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE');
    $st->execute([$actor]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status']!=='active' || $row['role']!=='admin') throw new DomainException('دسترسی مدیر معتبر نیست.');
}
/** Serialize against replication and other request creators by locking the replica row. */
function shop_status_request_create(PDO $pdo, int $replica, int $actor, bool $strictAdmin, int $revision, string $target): int {
    if ($replica<=0 || $revision<=0) throw new DomainException('درخواست نامعتبر است.');
    $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$strictAdmin);
        $st=$pdo->prepare('SELECT source_id,shop_order_id,revision,payload FROM crm_shop_orders WHERE id=? FOR UPDATE');
        $st->execute([$replica]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['revision']!==$revision) throw new DomainException('نسخه سفارش تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        $order=json_decode((string)$row['payload'],true);
        if (!is_array($order) || !preg_match('/^[a-f0-9]{32}$/D',(string)$row['source_id'])
            || (int)($order['id'] ?? 0)!==(int)$row['shop_order_id'] || !in_array($order['payment_status'] ?? '',['pending','paid','failed','refunded'],true)
            || !in_array($target,shop_status_request_targets((string)($order['order_status'] ?? '')),true)) throw new DomainException('این درخواست برای وضعیت فعلی مجاز نیست.');
        $st=$pdo->prepare("SELECT id FROM crm_shop_status_requests WHERE replica_id=? AND state='open' LIMIT 1");
        $st->execute([$replica]);
        if ($st->fetchColumn()) throw new DomainException('برای این سفارش یک درخواست باز وجود دارد؛ ابتدا آن را بررسی و ببندید.');
        $now=date('Y-m-d H:i:s');
        $st=$pdo->prepare("INSERT INTO crm_shop_status_requests(replica_id,source_id,shop_order_id,replica_revision,expected_status,expected_payment,requested_status,state,created_by,created_at) VALUES (?,?,?,?,?,?,?,'open',?,?)");
        $st->execute([$replica,$row['source_id'],$row['shop_order_id'],$revision,$order['order_status'],$order['payment_status'],$target,$actor,$now]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO crm_shop_status_request_events(request_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$id,$replica,$actor,'create',$now]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Closing is an administrative acknowledgement, not proof of fulfilment. */
function shop_status_request_close(PDO $pdo, int $replica, int $request, int $actor, bool $strictAdmin): void {
    if ($replica<=0 || $request<=0) throw new DomainException('درخواست نامعتبر است.');
    if (!crm_shop_command_ensure($pdo)) throw new DomainException('بررسی صف فرمان ممکن نیست.');
    $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$strictAdmin);
        $st=$pdo->prepare('SELECT id FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replica]);
        if (!$st->fetchColumn()) throw new DomainException('سفارش یافت نشد.');
        $st=$pdo->prepare('SELECT state FROM crm_shop_status_requests WHERE id=? AND replica_id=? FOR UPDATE'); $st->execute([$request,$replica]);
        if ($st->fetchColumn()!=='open') throw new DomainException('درخواست باز یافت نشد.');
        $st=$pdo->prepare("SELECT command_id FROM crm_shop_order_commands WHERE request_id=? AND state='queued' LIMIT 1"); $st->execute([$request]);
        if ($st->fetchColumn()) throw new DomainException('فرمان در صف است؛ پیش از بستن، نتیجه و وضعیت فروشگاه را بررسی کنید.');
        $now=date('Y-m-d H:i:s');
        $st=$pdo->prepare("UPDATE crm_shop_status_requests SET state='closed',closed_by=?,closed_at=? WHERE id=? AND state='open'");
        $st->execute([$actor,$now,$request]);
        if ($st->rowCount()!==1) throw new RuntimeException('Request closure conflict');
        $pdo->prepare('INSERT INTO crm_shop_status_request_events(request_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$request,$replica,$actor,'close',$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
