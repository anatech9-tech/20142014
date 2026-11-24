<?php
/** Human-approved identity mapping only. Never writes shop prices, stock or publishing. */
function shop_product_mapping_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_product_links (
            source_id CHAR(32) NOT NULL, shop_product_id BIGINT UNSIGNED NOT NULL,
            crm_product_id INT NULL, version INT UNSIGNED NOT NULL DEFAULT 0,
            updated_by INT NULL, updated_at DATETIME NULL,
            PRIMARY KEY (source_id,shop_product_id), KEY idx_shop_product_link_crm(crm_product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_product_link_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source_id CHAR(32) NOT NULL, shop_product_id BIGINT UNSIGNED NOT NULL,
            replica_id BIGINT UNSIGNED NOT NULL, actor_id INT NOT NULL,
            old_crm_product_id INT NULL, new_crm_product_id INT NULL, created_at DATETIME NOT NULL,
            KEY idx_shop_product_events(source_id,shop_product_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_product_mapping_ensure(PDO $pdo): bool {
    foreach (['crm_shop_product_links','crm_shop_product_link_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) {
            try { $schemas=shop_product_mapping_schema(); $pdo->exec($schemas[$i]); }
            catch (Throwable $e) { error_log('Shop product mapping schema unavailable'); return false; }
        }
    }
    // The independent CRM product bank may not be installed yet.
    try { $pdo->query('SELECT id,name FROM crm_products LIMIT 1'); return true; }
    catch (Throwable $e) { error_log('CRM product bank unavailable'); return false; }
}
/** Only accept an actual shop product ID from the locked order snapshot. */
function shop_order_contains_product(array $snapshot, int $shopProductId): bool {
    if ($shopProductId <= 0 || !isset($snapshot['items']) || !is_array($snapshot['items'])) return false;
    foreach ($snapshot['items'] as $item) {
        if (is_array($item) && isset($item['product_id']) && is_int($item['product_id']) && $item['product_id']===$shopProductId) return true;
    }
    return false;
}
function shop_product_links_for_order(PDO $pdo, string $source, array $snapshot): array {
    $ids=[];
    foreach (($snapshot['items'] ?? []) as $item) {
        if (is_array($item) && isset($item['product_id']) && is_int($item['product_id']) && $item['product_id']>0) $ids[$item['product_id']]=$item['product_id'];
    }
    if (!$ids) return [];
    $st=$pdo->prepare('SELECT l.*,p.name AS crm_product_name FROM crm_shop_product_links l LEFT JOIN crm_products p ON p.id=l.crm_product_id WHERE l.source_id=? AND l.shop_product_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $st->execute(array_merge([$source],array_values($ids))); $out=[];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(int)$row['shop_product_id']]=$row;
    return $out;
}
/** Map/unmap under the current administrator, with optimistic version and transactional history. */
function shop_product_mapping_apply(PDO $pdo, int $replicaId, int $actorId, bool $sessionAdmin, int $shopProductId, int $crmProductId, int $expectedVersion): void {
    if (!$sessionAdmin || $actorId<=0 || $replicaId<=0 || $shopProductId<=0 || $crmProductId<0 || $expectedVersion<0) throw new DomainException('درخواست یا دسترسی نامعتبر است.');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actorId]); $actor=$st->fetch(PDO::FETCH_ASSOC);
        if (!$actor || ($actor['status'] ?? '')!=='active' || ($actor['role'] ?? '')!=='admin') throw new DomainException('فقط مدیر کل فعال می‌تواند اتصال کالا را تغییر دهد.');
        $st=$pdo->prepare('SELECT source_id,payload FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replicaId]); $order=$st->fetch(PDO::FETCH_ASSOC);
        $snapshot=$order ? json_decode((string)$order['payload'],true) : null;
        if (!$order || !is_array($snapshot) || !preg_match('/^[a-f0-9]{32}$/D',(string)$order['source_id']) || !shop_order_contains_product($snapshot,$shopProductId)) throw new DomainException('کالا در نسخهٔ فعلی این سفارش موجود نیست.');
        $source=(string)$order['source_id'];
        $st=$pdo->prepare('SELECT * FROM crm_shop_product_links WHERE source_id=? AND shop_product_id=? FOR UPDATE'); $st->execute([$source,$shopProductId]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if ((int)($row['version'] ?? 0)!==$expectedVersion) throw new DomainException('اتصال کالا تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        if ($crmProductId>0) {
            $st=$pdo->prepare('SELECT id FROM crm_products WHERE id=? FOR UPDATE'); $st->execute([$crmProductId]);
            if (!$st->fetchColumn()) throw new DomainException('کالای CRM یافت نشد.');
        }
        $old=(int)($row['crm_product_id'] ?? 0) ?: null; $new=$crmProductId ?: null; $now=date('Y-m-d H:i:s');
        if ($row) {
            $st=$pdo->prepare('UPDATE crm_shop_product_links SET crm_product_id=?,version=version+1,updated_by=?,updated_at=? WHERE source_id=? AND shop_product_id=?');
            $st->execute([$new,$actorId,$now,$source,$shopProductId]);
        } else {
            $st=$pdo->prepare('INSERT INTO crm_shop_product_links(source_id,shop_product_id,crm_product_id,version,updated_by,updated_at) VALUES (?,?,?,1,?,?)');
            $st->execute([$source,$shopProductId,$new,$actorId,$now]);
        }
        $pdo->prepare('INSERT INTO crm_shop_product_link_events(source_id,shop_product_id,replica_id,actor_id,old_crm_product_id,new_crm_product_id,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$source,$shopProductId,$replicaId,$actorId,$old,$new,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Allow the admin to remove a stale mapping from the bank, even if no current order contains it. */
function shop_product_unlink_from_bank(PDO $pdo, int $actorId, bool $sessionAdmin, string $source, int $shopProductId, int $crmProductId, int $expectedVersion): void {
    if (!$sessionAdmin || $actorId<=0 || $crmProductId<=0 || $shopProductId<=0 || $expectedVersion<=0 || !preg_match('/^[a-f0-9]{32}$/D',$source)) throw new DomainException('درخواست یا دسترسی نامعتبر است.');
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actorId]); $actor=$st->fetch(PDO::FETCH_ASSOC);
        if (!$actor || ($actor['status'] ?? '')!=='active' || ($actor['role'] ?? '')!=='admin') throw new DomainException('فقط مدیر کل فعال می‌تواند اتصال کالا را تغییر دهد.');
        $st=$pdo->prepare('SELECT * FROM crm_shop_product_links WHERE source_id=? AND shop_product_id=? FOR UPDATE'); $st->execute([$source,$shopProductId]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['version']!==$expectedVersion || (int)($row['crm_product_id'] ?? 0)!==$crmProductId) throw new DomainException('اتصال کالا تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        $now=date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE crm_shop_product_links SET crm_product_id=NULL,version=version+1,updated_by=?,updated_at=? WHERE source_id=? AND shop_product_id=?')->execute([$actorId,$now,$source,$shopProductId]);
        $pdo->prepare('INSERT INTO crm_shop_product_link_events(source_id,shop_product_id,replica_id,actor_id,old_crm_product_id,new_crm_product_id,created_at) VALUES (?,?,0,?,?,NULL,?)')->execute([$source,$shopProductId,$actorId,$crmProductId,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/**
 * Manual identity mapping from a catalogue replica, including products never ordered.
 * A replica is not proof that the source still exists; the operator must verify it.
 */
function shop_catalog_product_mapping_apply(PDO $pdo, int $replicaId, int $actorId, bool $sessionAdmin, int $crmProductId, int $expectedVersion, int $expectedRevision): void {
    if (!$sessionAdmin || $actorId<=0 || $replicaId<=0 || $crmProductId<0 || $expectedVersion<0 || $expectedRevision<=0) throw new DomainException('درخواست یا دسترسی نامعتبر است.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') {
        $eng=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        foreach (['users','crm_products','crm_shop_catalog','crm_shop_product_links','crm_shop_product_link_events'] as $table) {
            $eng->execute([$table]); if (strtoupper((string)$eng->fetchColumn())!=='INNODB') throw new DomainException('جدول‌های اتصال باید تراکنشی و InnoDB باشند.');
        }
    }
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actorId]); $actor=$st->fetch(PDO::FETCH_ASSOC);
        if (!$actor || ($actor['status'] ?? '')!=='active' || ($actor['role'] ?? '')!=='admin') throw new DomainException('فقط مدیر کل فعال می‌تواند اتصال کالا را تغییر دهد.');
        $st=$pdo->prepare('SELECT source_id,shop_product_id,revision,payload FROM crm_shop_catalog WHERE id=? FOR UPDATE'); $st->execute([$replicaId]); $replica=$st->fetch(PDO::FETCH_ASSOC);
        $snapshot=$replica ? json_decode((string)$replica['payload'],true) : null;
        if (!$replica || !is_array($snapshot) || !preg_match('/^[a-f0-9]{32}$/D',(string)$replica['source_id'])
            || !is_int($snapshot['id'] ?? null) || $snapshot['id']<=0 || $snapshot['id']!==(int)$replica['shop_product_id'] || (int)$replica['revision']!==$expectedRevision) throw new DomainException('نسخه کالای فروشگاه تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        $source=(string)$replica['source_id']; $shopId=(int)$replica['shop_product_id'];
        $st=$pdo->prepare('SELECT * FROM crm_shop_product_links WHERE source_id=? AND shop_product_id=? FOR UPDATE'); $st->execute([$source,$shopId]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if ((int)($row['version'] ?? 0)!==$expectedVersion) throw new DomainException('اتصال کالا تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        $old=(int)($row['crm_product_id'] ?? 0) ?: null; $new=$crmProductId ?: null;
        if ($old===$new) throw new DomainException('اتصال انتخاب‌شده تغییری ندارد.');
        if ($new!==null) {
            $st=$pdo->prepare('SELECT id FROM crm_products WHERE id=? FOR UPDATE'); $st->execute([$new]);
            if (!$st->fetchColumn()) throw new DomainException('کالای CRM یافت نشد.');
        }
        $now=date('Y-m-d H:i:s');
        if ($row) $pdo->prepare('UPDATE crm_shop_product_links SET crm_product_id=?,version=version+1,updated_by=?,updated_at=? WHERE source_id=? AND shop_product_id=?')->execute([$new,$actorId,$now,$source,$shopId]);
        else $pdo->prepare('INSERT INTO crm_shop_product_links(source_id,shop_product_id,crm_product_id,version,updated_by,updated_at) VALUES (?,?,?,1,?,?)')->execute([$source,$shopId,$new,$actorId,$now]);
        // replica_id=0 means this event did not originate from an order.
        $pdo->prepare('INSERT INTO crm_shop_product_link_events(source_id,shop_product_id,replica_id,actor_id,old_crm_product_id,new_crm_product_id,created_at) VALUES (?,?,0,?,?,?,?)')->execute([$source,$shopId,$actorId,$old,$new,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
