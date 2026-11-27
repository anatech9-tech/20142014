<?php
/** Additional private tracking notes, independent of the original single-code record.
 * No carrier calls, shop writes, status changes or proof of physical shipment. */
require_once __DIR__.'/shop_order_requests.php';
function shop_packages_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_shipping_packages (
            package_id CHAR(32) NOT NULL PRIMARY KEY, replica_id BIGINT UNSIGNED NOT NULL,
            source_id CHAR(32) NOT NULL, shop_order_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL, carrier VARCHAR(80) NULL, tracking_code VARCHAR(100) NULL,
            replica_revision BIGINT UNSIGNED NOT NULL, updated_by INT NOT NULL, updated_at DATETIME NOT NULL,
            KEY idx_packages_replica(replica_id,package_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_shipping_package_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, package_id CHAR(32) NOT NULL,
            replica_id BIGINT UNSIGNED NOT NULL, source_id CHAR(32) NOT NULL, shop_order_id BIGINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL, replica_revision BIGINT UNSIGNED NOT NULL,
            action VARCHAR(12) NOT NULL, actor_id INT NOT NULL, created_at DATETIME NOT NULL,
            KEY idx_package_events(replica_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_packages_ensure(PDO $pdo): bool {
    foreach (['crm_shop_shipping_packages','crm_shop_shipping_package_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) { try { $schema=shop_packages_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Shop shipping packages schema unavailable'); return false; } }
    }
    return true;
}
function shop_packages_require_transactions(PDO $pdo,bool $checkTracking=false): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixture only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $tables=['users','crm_shop_orders','crm_shop_shipping_packages','crm_shop_shipping_package_events'];
    if ($checkTracking) $tables[]='crm_shop_shipping_tracking';
    foreach ($tables as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new DomainException('جدول‌های بسته‌ها باید تراکنشی و InnoDB باشند.');
    }
}
/** Actor and order locks serialize all package operations per order. A random package id
 * supplied by the form makes resubmission of a creation fail rather than duplicate it. */
function shop_packages_apply(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,string $package,int $version,string $action,string $carrier='',string $tracking=''): void {
    if ($replica<=0 || $revision<=0 || !preg_match('/^[a-f0-9]{32}$/D',$package) || $version<0 || $version>=4294967295 || !in_array($action,['create','save','clear'],true)) throw new DomainException('درخواست بسته نامعتبر است.');
    if (($action==='create')!==($version===0)) throw new DomainException('نسخه بسته نامعتبر است.');
    if ($action!=='clear') {
        $carrier=trim($carrier); $tracking=trim($tracking);
        if (!preg_match('/^[\p{L}\p{M}\p{N} .&()\-]{2,80}$/uD',$carrier) || !preg_match('/^[\p{L}\p{M}\p{N} ._\/-]{3,100}$/uD',$tracking)) throw new DomainException('نام شرکت یا کد رهگیری معتبر نیست.');
    } else { $carrier=null; $tracking=null; }
    shop_packages_require_transactions($pdo,$action!=='clear'); $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT source_id,shop_order_id,revision,payload FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replica]); $order=$st->fetch(PDO::FETCH_ASSOC);
        $snapshot=$order ? json_decode((string)$order['payload'],true) : null;
        if (!$order || (int)$order['revision']!==$revision || !preg_match('/^[a-f0-9]{32}$/D',(string)$order['source_id']) || !is_array($snapshot)
            || !is_int($snapshot['id'] ?? null) || $snapshot['id']<=0 || $snapshot['id']!==(int)$order['shop_order_id']) throw new DomainException('نسخه یا شناسه سفارش معتبر نیست؛ صفحه را دوباره بارگذاری کنید.');
        if ($action!=='clear' && !in_array($snapshot['order_status'] ?? '',['packed','shipped','delivered'],true)) throw new DomainException('ثبت کد در این وضعیت مجاز نیست.');
        if ($action!=='clear') {
            // Both write paths first lock the same order, so a concurrent primary-code save
            // cannot pass the duplicate check while this transaction is creating a package.
            $st=$pdo->prepare('SELECT source_id,shop_order_id,tracking_code FROM crm_shop_shipping_tracking WHERE replica_id=? FOR UPDATE'); $st->execute([$replica]); $primary=$st->fetch(PDO::FETCH_ASSOC);
            if ($primary && ($primary['source_id']!==$order['source_id'] || (int)$primary['shop_order_id']!==(int)$order['shop_order_id'])) throw new DomainException('هویت رهگیری با سفارش متفاوت است؛ تطبیق دستی لازم است.');
            if ($primary && $primary['tracking_code']!==null && $primary['tracking_code']===$tracking) throw new DomainException('این کد در رهگیری اصلی همین سفارش ثبت شده است.');
        }
        $st=$pdo->prepare('SELECT package_id,source_id,shop_order_id,version,carrier,tracking_code FROM crm_shop_shipping_packages WHERE replica_id=? FOR UPDATE');
        $st->execute([$replica]); $rows=$st->fetchAll(PDO::FETCH_ASSOC); $current=null; $active=0;
        foreach ($rows as $row) {
            if ($row['source_id']!==$order['source_id'] || (int)$row['shop_order_id']!==(int)$order['shop_order_id']) throw new DomainException('هویت بسته با سفارش متفاوت است؛ تطبیق دستی لازم است.');
            if ($row['tracking_code']!==null) {
                $active++;
                if ($action!=='clear' && $row['package_id']!==$package && $row['tracking_code']===$tracking) throw new DomainException('این کد در بسته دیگری از همین سفارش ثبت شده است.');
            }
            if ($row['package_id']===$package) $current=$row;
        }
        if ($action==='create') {
            if ($current || count($rows)>=100 || $active>=20) throw new DomainException('بسته تکراری است یا سقف بسته‌ها پر شده است.');
            $st=$pdo->prepare('SELECT package_id FROM crm_shop_shipping_packages WHERE package_id=? FOR UPDATE'); $st->execute([$package]);
            if ($st->fetchColumn()) throw new DomainException('شناسه بسته قبلاً ثبت شده است.');
        } elseif (!$current || (int)$current['version']!==$version || $current['tracking_code']===null) throw new DomainException('بسته یا نسخه آن معتبر نیست؛ صفحه را دوباره بارگذاری کنید.');
        if ($action==='save' && $current['carrier']===$carrier && $current['tracking_code']===$tracking) throw new DomainException('اطلاعات بسته تغییری ندارد.');
        $now=date('Y-m-d H:i:s'); $next=$version+1;
        if ($action==='create') {
            $pdo->prepare('INSERT INTO crm_shop_shipping_packages(package_id,replica_id,source_id,shop_order_id,version,carrier,tracking_code,replica_revision,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$package,$replica,$order['source_id'],$order['shop_order_id'],$next,$carrier,$tracking,$revision,$actor,$now]);
        } else {
            $pdo->prepare('UPDATE crm_shop_shipping_packages SET version=?,carrier=?,tracking_code=?,replica_revision=?,updated_by=?,updated_at=? WHERE package_id=? AND replica_id=?')->execute([$next,$carrier,$tracking,$revision,$actor,$now,$package,$replica]);
        }
        $pdo->prepare('INSERT INTO crm_shop_shipping_package_events(package_id,replica_id,source_id,shop_order_id,version,replica_revision,action,actor_id,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$package,$replica,$order['source_id'],$order['shop_order_id'],$next,$revision,$action,$actor,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
