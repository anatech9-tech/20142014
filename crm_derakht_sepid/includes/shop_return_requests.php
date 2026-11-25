<?php
/** Internal return-review tickets only: no shop command, refund, inventory, or customer notification. */
require_once __DIR__.'/shop_order_requests.php';
function shop_return_request_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_return_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            replica_id BIGINT UNSIGNED NOT NULL, source_id CHAR(32) NOT NULL,
            shop_order_id BIGINT UNSIGNED NOT NULL, replica_revision BIGINT UNSIGNED NOT NULL,
            observed_status VARCHAR(20) NOT NULL, reason VARCHAR(24) NOT NULL,
            state VARCHAR(12) NOT NULL DEFAULT 'open', created_by INT NOT NULL, created_at DATETIME NOT NULL,
            closed_by INT NULL, closed_at DATETIME NULL,
            KEY idx_return_order(replica_id,state,id), KEY idx_return_open(state,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_return_request_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id BIGINT UNSIGNED NOT NULL, replica_id BIGINT UNSIGNED NOT NULL,
            actor_id INT NOT NULL, action VARCHAR(12) NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_return_events(request_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_return_request_ensure(PDO $pdo): bool {
    foreach (['crm_shop_return_requests','crm_shop_return_request_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) {
            try { $schema=shop_return_request_schema(); $pdo->exec($schema[$i]); }
            catch (Throwable $e) { error_log('Shop return request schema unavailable'); return false; }
        }
    }
    return true;
}
function shop_return_require_transactions(PDO $pdo): void {
    // SQLite is used only in offline tests; production access uses MySQL.
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return;
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_shop_orders','crm_shop_return_requests','crm_shop_return_request_events'] as $table) {
        $st->execute([$table]);
        if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new DomainException('جدول‌های مرجوعی باید تراکنشی و InnoDB باشند.');
    }
}
function shop_return_reasons(): array {
    return ['damaged'=>'آسیب‌دیدگی یا نقص', 'wrong_item'=>'کالای اشتباه', 'other'=>'سایر موارد'];
}
/** Lock actor then replica, serializing both creation and closure with replica updates. */
function shop_return_request_create(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,string $reason): int {
    if ($replica<=0 || $revision<=0 || !array_key_exists($reason,shop_return_reasons())) throw new DomainException('درخواست نامعتبر است.');
    shop_return_require_transactions($pdo);
    $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT source_id,shop_order_id,revision,payload FROM crm_shop_orders WHERE id=? FOR UPDATE');
        $st->execute([$replica]); $row=$st->fetch(PDO::FETCH_ASSOC);
        $order=$row ? json_decode((string)$row['payload'],true) : null;
        if (!$row || (int)$row['revision']!==$revision || !preg_match('/^[a-f0-9]{32}$/D',(string)$row['source_id'])
            || !is_array($order) || !is_int($order['id'] ?? null) || $order['id']<=0 || $order['id']!==(int)$row['shop_order_id']
            || !in_array($order['order_status'] ?? '',['shipped','delivered'],true)) throw new DomainException('نسخه یا وضعیت سفارش معتبر نیست؛ صفحه را دوباره بارگذاری کنید.');
        $st=$pdo->prepare("SELECT id FROM crm_shop_return_requests WHERE replica_id=? AND state='open' LIMIT 1"); $st->execute([$replica]);
        if ($st->fetchColumn()) throw new DomainException('یک درخواست بررسی مرجوعی باز وجود دارد.');
        $now=date('Y-m-d H:i:s');
        $st=$pdo->prepare("INSERT INTO crm_shop_return_requests(replica_id,source_id,shop_order_id,replica_revision,observed_status,reason,state,created_by,created_at) VALUES (?,?,?,?,?,?,'open',?,?)");
        $st->execute([$replica,$row['source_id'],$row['shop_order_id'],$revision,$order['order_status'],$reason,$actor,$now]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO crm_shop_return_request_events(request_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$id,$replica,$actor,'create',$now]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Closing only ends CRM follow-up, and is never proof of return or refund. */
function shop_return_request_close(PDO $pdo,int $replica,int $request,int $actor,bool $admin): void {
    if ($replica<=0 || $request<=0) throw new DomainException('درخواست نامعتبر است.');
    shop_return_require_transactions($pdo);
    $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT id FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replica]);
        if (!$st->fetchColumn()) throw new DomainException('سفارش یافت نشد.');
        $st=$pdo->prepare('SELECT state FROM crm_shop_return_requests WHERE id=? AND replica_id=? FOR UPDATE'); $st->execute([$request,$replica]);
        if ($st->fetchColumn()!=='open') throw new DomainException('درخواست باز یافت نشد.');
        $now=date('Y-m-d H:i:s');
        $st=$pdo->prepare("UPDATE crm_shop_return_requests SET state='closed',closed_by=?,closed_at=? WHERE id=? AND replica_id=? AND state='open'");
        $st->execute([$actor,$now,$request,$replica]);
        if ($st->rowCount()!==1) throw new RuntimeException('Return request closure conflict');
        $pdo->prepare('INSERT INTO crm_shop_return_request_events(request_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$request,$replica,$actor,'close',$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
