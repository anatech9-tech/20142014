<?php
/** Internal manual product grouping; no shop write, auto-matching or pricing change. */
require_once __DIR__.'/shop_category_groups.php';
function crm_product_groups_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_product_group_links(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,product_id INT NOT NULL,group_id BIGINT UNSIGNED NOT NULL,is_linked TINYINT NOT NULL,version INT UNSIGNED NOT NULL,group_version INT UNSIGNED NULL,product_name VARCHAR(200) NULL,updated_by INT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_crm_product_group(product_id,group_id),KEY idx_crm_product_group_target(group_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_product_group_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,link_id BIGINT UNSIGNED NOT NULL,product_id INT NOT NULL,group_id BIGINT UNSIGNED NOT NULL,version INT UNSIGNED NOT NULL,group_version INT UNSIGNED NULL,action VARCHAR(12) NOT NULL,actor_id INT NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_crm_product_group_event(link_id,version),KEY idx_crm_product_group_history(product_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function crm_product_groups_ensure(PDO $pdo): bool {
    foreach (['crm_product_group_links','crm_product_group_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { try { $schema=crm_product_groups_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Product grouping unavailable'); return false; } }
    }
    return true;
}
function crm_product_groups_valid_name($name): bool {
    return is_string($name) && $name!=='' && strlen($name)<=800 && preg_match('//u',$name)===1 && preg_match('/[\x00-\x1f\x7f]/',$name)!==1 && preg_match_all('/./us',$name)<=200;
}
/** Caller enforces CSRF, active admin session, read-only policy and confirmations. */
function crm_product_group_link(PDO $pdo,int $actor,bool $admin,int $product,string $expectedName,int $group,int $groupVersion,int $expectedVersion,bool $confirmed): void {
    if (!$confirmed || $product<=0 || $group<=0 || $groupVersion<=0 || $expectedVersion<0 || $expectedVersion>=1000000 || !crm_product_groups_valid_name($expectedName)) throw new DomainException('درخواست یا تأیید نامعتبر است.');
    shop_category_groups_require_innodb($pdo,['users','crm_products','crm_shop_category_groups','crm_product_group_links','crm_product_group_events']); $pdo->beginTransaction();
    try {
        shop_category_groups_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT name FROM crm_products WHERE id=? FOR UPDATE'); $st->execute([$product]); $name=$st->fetchColumn();
        if ($name===false || !crm_product_groups_valid_name($name) || $name!==$expectedName) throw new DomainException('کالا یا نام آن تغییر کرده است؛ دوباره بارگذاری کنید.');
        $st=$pdo->prepare('SELECT version,is_active FROM crm_shop_category_groups WHERE id=? FOR UPDATE'); $st->execute([$group]); $target=$st->fetch(PDO::FETCH_ASSOC);
        if (!$target || (int)$target['version']!==$groupVersion || (int)$target['is_active']!==1) throw new DomainException('گروه تغییر کرده یا غیرفعال است.');
        $st=$pdo->prepare('SELECT * FROM crm_product_group_links WHERE product_id=? AND group_id=? FOR UPDATE'); $st->execute([$product,$group]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if ((int)($old['version'] ?? 0)!==$expectedVersion) throw new DomainException('اتصال تغییر کرده است؛ دوباره بارگذاری کنید.');
        if ($old && (int)$old['is_linked']===1 && (int)$old['group_version']===$groupVersion && $old['product_name']===$name) throw new DomainException('اتصال تغییری ندارد.');
        if (!$old) {
            $st=$pdo->prepare('SELECT COUNT(*) FROM crm_product_group_links WHERE product_id=?'); $st->execute([$product]);
            if ((int)$st->fetchColumn()>=200) throw new DomainException('سقف ۲۰۰ اتصال مادام‌العمر این کالا، شامل اتصال‌های قطع‌شده، رسیده است.');
        }
        $now=date('Y-m-d H:i:s'); $version=$expectedVersion+1;
        if ($old) { $id=(int)$old['id']; $pdo->prepare('UPDATE crm_product_group_links SET is_linked=1,version=?,group_version=?,product_name=?,updated_by=?,updated_at=? WHERE id=?')->execute([$version,$groupVersion,$name,$actor,$now,$id]); }
        else { $pdo->prepare('INSERT INTO crm_product_group_links(product_id,group_id,is_linked,version,group_version,product_name,updated_by,updated_at) VALUES (?,?,1,?,?,?,?,?)')->execute([$product,$group,$version,$groupVersion,$name,$actor,$now]); $id=(int)$pdo->lastInsertId(); if ($id<=0) throw new RuntimeException('Product group identity unavailable'); }
        $pdo->prepare('INSERT INTO crm_product_group_events(link_id,product_id,group_id,version,group_version,action,actor_id,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$product,$group,$version,$groupVersion,'link',$actor,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Unlink by tombstone even when the product or group no longer exists. */
function crm_product_group_unlink(PDO $pdo,int $actor,bool $admin,int $link,int $expectedVersion,bool $confirmed): void {
    if (!$confirmed || $link<=0 || $expectedVersion<=0 || $expectedVersion>=1000000) throw new DomainException('درخواست یا تأیید نامعتبر است.');
    shop_category_groups_require_innodb($pdo,['users','crm_product_group_links','crm_product_group_events']); $pdo->beginTransaction();
    try {
        shop_category_groups_admin($pdo,$actor,$admin); $st=$pdo->prepare('SELECT * FROM crm_product_group_links WHERE id=? FOR UPDATE'); $st->execute([$link]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if (!$old || (int)$old['is_linked']!==1 || (int)$old['version']!==$expectedVersion) throw new DomainException('اتصال تغییر کرده یا قطع شده است.');
        $now=date('Y-m-d H:i:s'); $version=$expectedVersion+1;
        $pdo->prepare('UPDATE crm_product_group_links SET is_linked=0,version=?,group_version=NULL,product_name=NULL,updated_by=?,updated_at=? WHERE id=?')->execute([$version,$actor,$now,$link]);
        $pdo->prepare('INSERT INTO crm_product_group_events(link_id,product_id,group_id,version,group_version,action,actor_id,created_at) VALUES (?,?,?,?,NULL,?,?,?)')->execute([$link,$old['product_id'],$old['group_id'],$version,'unlink',$actor,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
