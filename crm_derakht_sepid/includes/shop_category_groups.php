<?php
/** Internal grouping and manual references only; never writes website categories or products. */
require_once __DIR__.'/shop_category_metadata_sync.php';
function shop_category_groups_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_category_groups(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,is_active TINYINT NOT NULL,version INT UNSIGNED NOT NULL,updated_by INT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_category_group_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_category_group_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,group_id BIGINT UNSIGNED NOT NULL,version INT UNSIGNED NOT NULL,old_name VARCHAR(100) NULL,new_name VARCHAR(100) NOT NULL,old_active TINYINT NULL,new_active TINYINT NOT NULL,actor_id INT NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_category_group_event(group_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_category_group_links(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,source_id CHAR(32) NOT NULL,category_id BIGINT UNSIGNED NOT NULL,replica_id BIGINT UNSIGNED NOT NULL,group_id BIGINT UNSIGNED NULL,version INT UNSIGNED NOT NULL,source_revision BIGINT UNSIGNED NULL,group_version INT UNSIGNED NULL,updated_by INT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_category_group_link(source_id,category_id),KEY idx_category_group_target(group_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_category_group_link_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,link_id BIGINT UNSIGNED NOT NULL,version INT UNSIGNED NOT NULL,source_id CHAR(32) NOT NULL,category_id BIGINT UNSIGNED NOT NULL,replica_id BIGINT UNSIGNED NOT NULL,old_group_id BIGINT UNSIGNED NULL,new_group_id BIGINT UNSIGNED NULL,source_revision BIGINT UNSIGNED NULL,group_version INT UNSIGNED NULL,actor_id INT NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_category_group_link_event(link_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function shop_category_groups_ensure(PDO $pdo): bool {
    foreach (['crm_shop_category_groups','crm_shop_category_group_events','crm_shop_category_group_links','crm_shop_category_group_link_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { try { $schema=shop_category_groups_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Category grouping unavailable'); return false; } }
    }
    return true;
}
function shop_category_groups_require_innodb(PDO $pdo,array $tables): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixtures only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($tables as $table) { $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional grouping tables required'); }
}
function shop_category_groups_admin(PDO $pdo,int $actor,bool $admin): void {
    if (!$admin || $actor<=0) throw new DomainException('دسترسی مدیر معتبر نیست.');
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actor]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status']!=='active' || $row['role']!=='admin') throw new DomainException('دسترسی مدیر معتبر نیست.');
}
function shop_category_groups_name(string $name): string {
    if (preg_match('/[\x00-\x1f\x7f]/',$name)) throw new DomainException('نام گروه نامعتبر است.');
    $name=trim($name);
    if ($name==='' || !crm_shop_category_metadata_name($name)) throw new DomainException('نام گروه نامعتبر است.');
    return $name;
}
/** Caller enforces CSRF, read-only policy and explicit confirmation for changes. */
function shop_category_group_save(PDO $pdo,int $actor,bool $admin,int $id,int $expected,string $name,int $active,bool $confirmed): int {
    if (!$confirmed || $id<0 || $expected<0 || !in_array($active,[0,1],true) || ($id===0 && ($expected!==0 || $active!==1)) || ($id>0 && $expected<=0)) throw new DomainException('درخواست یا تأیید گروه معتبر نیست.');
    $name=shop_category_groups_name($name);
    shop_category_groups_require_innodb($pdo,['users','crm_shop_category_groups','crm_shop_category_group_events']); $pdo->beginTransaction();
    try {
        shop_category_groups_admin($pdo,$actor,$admin); $now=date('Y-m-d H:i:s'); $oldName=null; $oldActive=null;
        if ($id===0) {
            $pdo->prepare('INSERT INTO crm_shop_category_groups(name,is_active,version,updated_by,updated_at) VALUES (?,1,1,?,?)')->execute([$name,$actor,$now]); $id=(int)$pdo->lastInsertId();
            if ($id<=0) throw new RuntimeException('Group identity unavailable'); $version=1;
        } else {
            $st=$pdo->prepare('SELECT name,is_active,version FROM crm_shop_category_groups WHERE id=? FOR UPDATE'); $st->execute([$id]); $old=$st->fetch(PDO::FETCH_ASSOC);
            if (!$old || (int)$old['version']!==$expected) throw new DomainException('گروه تغییر کرده است؛ دوباره بارگذاری کنید.');
            if ($expected>=1000000) throw new DomainException('سقف نسخه گروه رسیده است.');
            $oldName=(string)$old['name']; $oldActive=(int)$old['is_active']; if ($oldName===$name && $oldActive===$active) throw new DomainException('گروه تغییری ندارد.');
            $version=$expected+1; $pdo->prepare('UPDATE crm_shop_category_groups SET name=?,is_active=?,version=?,updated_by=?,updated_at=? WHERE id=?')->execute([$name,$active,$version,$actor,$now,$id]);
        }
        $pdo->prepare('INSERT INTO crm_shop_category_group_events(group_id,version,old_name,new_name,old_active,new_active,actor_id,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$version,$oldName,$name,$oldActive,$active,$actor,$now]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Multiple shop categories may reference one group; revisions represent observed snapshots, not proof. */
function shop_category_group_link(PDO $pdo,int $actor,bool $admin,int $replicaId,int $revision,int $groupId,int $groupVersion,int $expectedLinkVersion,bool $confirmed): void {
    if (!$confirmed || !crm_shop_category_sync_configured() || $replicaId<=0 || $revision<=0 || $groupId<=0 || $groupVersion<=0 || $expectedLinkVersion<0) throw new DomainException('درخواست یا تأیید اتصال معتبر نیست.');
    shop_category_groups_require_innodb($pdo,['users','crm_shop_category_meta','crm_shop_category_groups','crm_shop_category_group_links','crm_shop_category_group_link_events']); $pdo->beginTransaction();
    try {
        shop_category_groups_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT source_id,category_id,revision,category_name,is_active FROM crm_shop_category_meta WHERE id=? FOR UPDATE'); $st->execute([$replicaId]); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['source_id']!==(string)SHOP_CRM_SOURCE_ID || !preg_match('/^[a-f0-9]{32}$/D',(string)$r['source_id']) || (int)$r['category_id']<=0 || (int)$r['revision']!==$revision || $r['category_name']===null || !crm_shop_category_metadata_name($r['category_name']) || !in_array((string)$r['is_active'],['0','1'],true)) throw new DomainException('دسته یا نسخه دریافتی معتبر نیست؛ دوباره بارگذاری کنید.');
        $st=$pdo->prepare('SELECT id,version,is_active FROM crm_shop_category_groups WHERE id=? FOR UPDATE'); $st->execute([$groupId]); $group=$st->fetch(PDO::FETCH_ASSOC);
        if (!$group || (int)$group['version']!==$groupVersion || (int)$group['is_active']!==1) throw new DomainException('گروه انتخاب‌شده تغییر کرده یا غیرفعال است.');
        $st=$pdo->prepare('SELECT * FROM crm_shop_category_group_links WHERE source_id=? AND category_id=? FOR UPDATE'); $st->execute([$r['source_id'],$r['category_id']]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if ((int)($old['version'] ?? 0)!==$expectedLinkVersion) throw new DomainException('اتصال تغییر کرده است؛ دوباره بارگذاری کنید.');
        if ($expectedLinkVersion>=1000000) throw new DomainException('سقف نسخه اتصال رسیده است.');
        $oldGroup=$old && $old['group_id']!==null?(int)$old['group_id']:null;
        if ($oldGroup===$groupId && (int)$old['replica_id']===$replicaId && (int)$old['source_revision']===$revision && (int)$old['group_version']===$groupVersion) throw new DomainException('اتصال تغییر نکرده است.');
        $now=date('Y-m-d H:i:s'); $version=$expectedLinkVersion+1;
        if ($old) {
            $id=(int)$old['id']; $pdo->prepare('UPDATE crm_shop_category_group_links SET replica_id=?,group_id=?,version=?,source_revision=?,group_version=?,updated_by=?,updated_at=? WHERE id=?')->execute([$replicaId,$groupId,$version,$revision,$groupVersion,$actor,$now,$id]);
        } else {
            $pdo->prepare('INSERT INTO crm_shop_category_group_links(source_id,category_id,replica_id,group_id,version,source_revision,group_version,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$r['source_id'],$r['category_id'],$replicaId,$groupId,$version,$revision,$groupVersion,$actor,$now]);
            $id=(int)$pdo->lastInsertId(); if ($id<=0) throw new RuntimeException('Link identity unavailable');
        }
        $pdo->prepare('INSERT INTO crm_shop_category_group_link_events(link_id,version,source_id,category_id,replica_id,old_group_id,new_group_id,source_revision,group_version,actor_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$version,$r['source_id'],$r['category_id'],$replicaId,$oldGroup,$groupId,$revision,$groupVersion,$actor,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Detachment remains possible without an active bridge, source replica or group. */
function shop_category_group_unlink(PDO $pdo,int $actor,bool $admin,int $linkId,int $expectedVersion,bool $confirmed): void {
    if (!$confirmed || $linkId<=0 || $expectedVersion<=0 || $expectedVersion>=1000000) throw new DomainException('درخواست یا تأیید قطع اتصال معتبر نیست.');
    shop_category_groups_require_innodb($pdo,['users','crm_shop_category_group_links','crm_shop_category_group_link_events']); $pdo->beginTransaction();
    try {
        shop_category_groups_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT * FROM crm_shop_category_group_links WHERE id=? FOR UPDATE'); $st->execute([$linkId]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if (!$old || (int)$old['version']!==$expectedVersion || $old['group_id']===null) throw new DomainException('اتصال تغییر کرده یا قطع شده است.');
        $version=$expectedVersion+1; $now=date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE crm_shop_category_group_links SET group_id=NULL,version=?,source_revision=NULL,group_version=NULL,updated_by=?,updated_at=? WHERE id=?')->execute([$version,$actor,$now,$linkId]);
        $pdo->prepare('INSERT INTO crm_shop_category_group_link_events(link_id,version,source_id,category_id,replica_id,old_group_id,new_group_id,source_revision,group_version,actor_id,created_at) VALUES (?,?,?,?,?,?,NULL,NULL,NULL,?,?)')->execute([$linkId,$version,$old['source_id'],$old['category_id'],$old['replica_id'],$old['group_id'],$actor,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
