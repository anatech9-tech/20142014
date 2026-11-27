<?php
/** Private CRM tracking note. No carrier API, public tracking, order status or shop writes. */
require_once __DIR__.'/shop_order_requests.php';
function shop_shipping_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_shipping_tracking (
            replica_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, source_id CHAR(32) NOT NULL,
            shop_order_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
            carrier VARCHAR(80) NULL, tracking_code VARCHAR(100) NULL,
            replica_revision BIGINT UNSIGNED NOT NULL, updated_by INT NOT NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_shipping_tracking_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, replica_id BIGINT UNSIGNED NOT NULL,
            source_id CHAR(32) NOT NULL, shop_order_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL, replica_revision BIGINT UNSIGNED NOT NULL,
            action VARCHAR(12) NOT NULL, actor_id INT NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_shipping_events(replica_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_shipping_ensure(PDO $pdo): bool {
    foreach (['crm_shop_shipping_tracking','crm_shop_shipping_tracking_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) { try { $schema=shop_shipping_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Shop shipping tracking schema unavailable'); return false; } }
    }
    return true;
}
function shop_shipping_require_transactions(PDO $pdo,bool $checkPackages=false): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixture only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $tables=['users','crm_shop_orders','crm_shop_shipping_tracking','crm_shop_shipping_tracking_events'];
    if ($checkPackages) $tables[]='crm_shop_shipping_packages';
    foreach ($tables as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new DomainException('جدول‌های رهگیری باید تراکنشی و InnoDB باشند.');
    }
}
/** Save or clear under actor, replica and tracking locks. No historical codes in the event log. */
function shop_shipping_apply(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,int $version,string $action,string $carrier='',string $tracking=''): void {
    if ($replica<=0 || $revision<=0 || $version<0 || $version>=4294967295 || !in_array($action,['save','clear'],true)) throw new DomainException('درخواست نامعتبر است.');
    if ($action==='save') {
        $carrier=trim($carrier); $tracking=trim($tracking);
        if (!preg_match('/^[\p{L}\p{M}\p{N} .&()\-]{2,80}$/uD',$carrier) || !preg_match('/^[\p{L}\p{M}\p{N} ._\/-]{3,100}$/uD',$tracking)) throw new DomainException('نام شرکت یا کد رهگیری معتبر نیست.');
    } else { $carrier=null; $tracking=null; }
    shop_shipping_require_transactions($pdo,$action==='save'); $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT source_id,shop_order_id,revision,payload FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replica]); $order=$st->fetch(PDO::FETCH_ASSOC);
        $snapshot=$order ? json_decode((string)$order['payload'],true) : null;
        if (!$order || (int)$order['revision']!==$revision || !preg_match('/^[a-f0-9]{32}$/D',(string)$order['source_id'])
            || !is_array($snapshot) || !is_int($snapshot['id'] ?? null) || $snapshot['id']<=0 || $snapshot['id']!==(int)$order['shop_order_id']) throw new DomainException('نسخه یا شناسهٔ سفارش معتبر نیست؛ صفحه را دوباره بارگذاری کنید.');
        if ($action==='save' && !in_array($snapshot['order_status'] ?? '',['packed','shipped','delivered'],true)) throw new DomainException('ثبت کد در این وضعیت مجاز نیست؛ وضعیت فروشگاه را جداگانه بررسی کنید.');
        $st=$pdo->prepare('SELECT * FROM crm_shop_shipping_tracking WHERE replica_id=? FOR UPDATE'); $st->execute([$replica]); $current=$st->fetch(PDO::FETCH_ASSOC);
        if ($current && ($current['source_id']!==$order['source_id'] || (int)$current['shop_order_id']!==(int)$order['shop_order_id'])) throw new DomainException('هویت سفارش با رهگیری ثبت‌شده متفاوت است؛ تطبیق دستی لازم است.');
        if ((int)($current['version'] ?? 0)!==$version) throw new DomainException('اطلاعات رهگیری تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        if ($action==='save') {
            // The order lock serializes this check with package writes. Compare exact bytes in PHP,
            // not database collation; do not claim to detect format/case/Unicode equivalents.
            $st=$pdo->prepare('SELECT source_id,shop_order_id,tracking_code FROM crm_shop_shipping_packages WHERE replica_id=? FOR UPDATE'); $st->execute([$replica]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['source_id']!==$order['source_id'] || (int)$row['shop_order_id']!==(int)$order['shop_order_id']) throw new DomainException('هویت بسته با سفارش متفاوت است؛ تطبیق دستی لازم است.');
                if ($row['tracking_code']!==null && $row['tracking_code']===$tracking) throw new DomainException('این کد در بسته دیگری از همین سفارش ثبت شده است.');
            }
        }
        if ($action==='clear' && (!$current || $current['tracking_code']===null)) throw new DomainException('کد رهگیری فعالی ثبت نشده است.');
        if ($action==='save' && $current && $current['carrier']===$carrier && $current['tracking_code']===$tracking) throw new DomainException('اطلاعات رهگیری تغییری ندارد.');
        $now=date('Y-m-d H:i:s'); $next=$version+1;
        if ($current) {
            $st=$pdo->prepare('UPDATE crm_shop_shipping_tracking SET carrier=?,tracking_code=?,version=?,replica_revision=?,updated_by=?,updated_at=? WHERE replica_id=?');
            $st->execute([$carrier,$tracking,$next,$revision,$actor,$now,$replica]);
        } else {
            $st=$pdo->prepare('INSERT INTO crm_shop_shipping_tracking(replica_id,source_id,shop_order_id,version,carrier,tracking_code,replica_revision,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
            $st->execute([$replica,$order['source_id'],$order['shop_order_id'],$next,$carrier,$tracking,$revision,$actor,$now]);
        }
        $pdo->prepare('INSERT INTO crm_shop_shipping_tracking_events(replica_id,source_id,shop_order_id,version,replica_revision,action,actor_id,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$replica,$order['source_id'],$order['shop_order_id'],$next,$revision,$action,$actor,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
