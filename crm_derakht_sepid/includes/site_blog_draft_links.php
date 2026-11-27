<?php
/** Manual CRM-only reference; no content import, publishing or website writes. */
require_once __DIR__.'/site_blog_metadata_sync.php'; require_once __DIR__.'/site_content_drafts.php';
function site_blog_draft_links_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_site_blog_draft_links(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,draft_id BIGINT UNSIGNED NOT NULL,source_id CHAR(32) NOT NULL,post_id BIGINT UNSIGNED NOT NULL,replica_id BIGINT UNSIGNED NOT NULL,draft_version INT UNSIGNED NOT NULL,post_revision BIGINT UNSIGNED NOT NULL,linked_by INT NOT NULL,linked_at DATETIME NOT NULL,UNIQUE KEY uq_blog_draft(draft_id),UNIQUE KEY uq_blog_post(source_id,post_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_site_blog_draft_link_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,link_id BIGINT UNSIGNED NOT NULL,draft_id BIGINT UNSIGNED NOT NULL,source_id CHAR(32) NOT NULL,post_id BIGINT UNSIGNED NOT NULL,replica_id BIGINT UNSIGNED NOT NULL,draft_version INT UNSIGNED NOT NULL,post_revision BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,action VARCHAR(10) NOT NULL,created_at DATETIME NOT NULL,KEY idx_blog_link_events(draft_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function site_blog_draft_links_ensure(PDO $pdo): bool {
    foreach (['crm_site_blog_draft_links','crm_site_blog_draft_link_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { try { $schema=site_blog_draft_links_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Blog draft links unavailable'); return false; } }
    }
    return true;
}
function site_blog_draft_links_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixtures only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_site_content_drafts','crm_site_blog_meta','crm_site_blog_draft_links','crm_site_blog_draft_link_events'] as $table) { $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional link tables required'); }
}
function site_blog_link_uint($value,bool $zero=false): int {
    if (!is_int($value) && !is_string($value)) throw new DomainException('شناسه نامعتبر است.');
    $value=(string)$value; $max=(string)PHP_INT_MAX;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$value) || strlen($value)>strlen($max) || (strlen($value)===strlen($max) && strcmp($value,$max)>0) || (!$zero && $value==='0')) throw new DomainException('شناسه نامعتبر است.');
    return (int)$value;
}
/** Caller enforces CSRF, session admin, read-only policy and explicit identity confirmation. */
function site_blog_draft_link(PDO $pdo,int $draft,int $draftVersion,int $replica,int $revision,int $actor,bool $admin,bool $confirmed): int {
    if (!crm_site_blog_sync_configured() || !$confirmed || $draft<=0 || $draftVersion<=0 || $replica<=0 || $revision<=0) throw new DomainException('تطبیق یا تأیید معتبر نیست.');
    site_blog_draft_links_require_innodb($pdo); $pdo->beginTransaction();
    try {
        content_draft_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT version FROM crm_site_content_drafts WHERE id=? FOR UPDATE'); $st->execute([$draft]); $current=$st->fetchColumn();
        if ($current===false || (int)$current!==$draftVersion) throw new DomainException('نسخه پیش‌نویس تغییر کرده است.');
        $st=$pdo->prepare('SELECT source_id,post_id,revision,is_published FROM crm_site_blog_meta WHERE id=? FOR UPDATE'); $st->execute([$replica]); $post=$st->fetch(PDO::FETCH_ASSOC);
        if (!$post || $post['source_id']!==(string)SITE_BLOG_SOURCE_ID || site_blog_link_uint($post['post_id'])<=0 || site_blog_link_uint($post['revision'])!==$revision || !in_array(site_blog_link_uint($post['is_published'],true),[0,1],true)) throw new DomainException('نسخه یا هویت مقاله معتبر نیست.');
        $st=$pdo->prepare('SELECT id FROM crm_site_blog_draft_links WHERE draft_id=? OR (source_id=? AND post_id=?) LIMIT 1'); $st->execute([$draft,$post['source_id'],$post['post_id']]);
        if ($st->fetchColumn()) throw new DomainException('پیش‌نویس یا مقاله قبلاً متصل شده است.');
        $now=date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO crm_site_blog_draft_links(draft_id,source_id,post_id,replica_id,draft_version,post_revision,linked_by,linked_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$draft,$post['source_id'],$post['post_id'],$replica,$draftVersion,$revision,$actor,$now]);
        $id=(int)$pdo->lastInsertId(); if ($id<=0) throw new RuntimeException('Link identity unavailable');
        $pdo->prepare('INSERT INTO crm_site_blog_draft_link_events(link_id,draft_id,source_id,post_id,replica_id,draft_version,post_revision,actor_id,action,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$id,$draft,$post['source_id'],$post['post_id'],$replica,$draftVersion,$revision,$actor,'link',$now]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Existing links can be detached even if the source or bridge is unavailable. */
function site_blog_draft_unlink(PDO $pdo,int $draft,int $draftVersion,int $link,int $actor,bool $admin,bool $confirmed): void {
    if (!$confirmed || $draft<=0 || $draftVersion<=0 || $link<=0) throw new DomainException('درخواست یا تأیید معتبر نیست.');
    site_blog_draft_links_require_innodb($pdo); $pdo->beginTransaction();
    try {
        content_draft_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT version FROM crm_site_content_drafts WHERE id=? FOR UPDATE'); $st->execute([$draft]); $version=$st->fetchColumn();
        if ($version===false || (int)$version!==$draftVersion) throw new DomainException('نسخه پیش‌نویس تغییر کرده است.');
        $st=$pdo->prepare('SELECT * FROM crm_site_blog_draft_links WHERE id=? AND draft_id=? FOR UPDATE'); $st->execute([$link,$draft]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('اتصال یافت نشد یا تغییر کرده است.');
        $st=$pdo->prepare('DELETE FROM crm_site_blog_draft_links WHERE id=? AND draft_id=?'); $st->execute([$link,$draft]); if ($st->rowCount()!==1) throw new RuntimeException('Unlink conflict');
        $pdo->prepare('INSERT INTO crm_site_blog_draft_link_events(link_id,draft_id,source_id,post_id,replica_id,draft_version,post_revision,actor_id,action,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$link,$draft,$row['source_id'],$row['post_id'],$row['replica_id'],$row['draft_version'],$row['post_revision'],$actor,'unlink',date('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
