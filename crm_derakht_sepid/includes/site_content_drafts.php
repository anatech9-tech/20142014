<?php
/** Internal text drafts only: no website connection, publication, uploads or deletion. */
function content_draft_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_site_content_drafts(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,title VARCHAR(200) NOT NULL,body TEXT NOT NULL,version INT UNSIGNED NOT NULL,created_by INT NOT NULL,updated_by INT NOT NULL,created_at DATETIME NOT NULL,updated_at DATETIME NOT NULL,KEY idx_content_draft_updated(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_site_content_draft_versions(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,draft_id BIGINT UNSIGNED NOT NULL,version INT UNSIGNED NOT NULL,title VARCHAR(200) NOT NULL,body TEXT NOT NULL,actor_id INT NOT NULL,action VARCHAR(12) NOT NULL,restored_version INT UNSIGNED NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_content_draft_version(draft_id,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function content_draft_ensure(PDO $pdo): bool {
    foreach (['crm_site_content_drafts','crm_site_content_draft_versions'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); }
        catch (Throwable $e) { try { $schema=content_draft_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Content draft schema unavailable'); return false; } }
    }
    return true;
}
function content_draft_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixtures only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_site_content_drafts','crm_site_content_draft_versions'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional content tables required');
    }
}
function content_draft_text(string $title,string $body): array {
    if (preg_match('/[\x00-\x1f\x7f]/',$title)) throw new DomainException('عنوان یا متن پیش‌نویس نامعتبر یا بیش از حد مجاز است.');
    $title=trim($title);
    if ($title==='' || strlen($title)>800 || strlen($body)>32000 || preg_match('//u',$title)!==1 || preg_match('//u',$body)!==1
        || preg_match('/[\x00-\x1f\x7f]/',$title) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$body)
        || preg_match_all('/./us',$title)>200) throw new DomainException('عنوان یا متن پیش‌نویس نامعتبر یا بیش از حد مجاز است.');
    return [$title,$body];
}
function content_draft_check_admin(PDO $pdo,int $actor,bool $admin): void {
    if (!$admin || $actor<=0) throw new DomainException('دسترسی مدیر معتبر نیست.');
    $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actor]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status']!=='active' || $row['role']!=='admin') throw new DomainException('دسترسی مدیر معتبر نیست.');
}
/** Caller must enforce CSRF, session authorization, read-only policy, and explicit restore confirmation. */
function content_draft_write(PDO $pdo,int $actor,bool $admin,int $draft,int $expected,string $action,string $title='',string $body='',int $restoreVersion=0): int {
    if (!in_array($action,['create','edit','restore'],true) || ($action==='create' && ($draft!==0 || $expected!==0))
        || ($action!=='create' && ($draft<=0 || $expected<=0)) || ($action==='restore' && $restoreVersion<=0)) throw new DomainException('درخواست پیش‌نویس معتبر نیست.');
    if ($action!=='restore') [$title,$body]=content_draft_text($title,$body);
    content_draft_require_innodb($pdo); $pdo->beginTransaction();
    try {
        content_draft_check_admin($pdo,$actor,$admin); $now=date('Y-m-d H:i:s');
        if ($action==='create') {
            $pdo->prepare('INSERT INTO crm_site_content_drafts(title,body,version,created_by,updated_by,created_at,updated_at) VALUES (?,?,1,?,?,?,?)')->execute([$title,$body,$actor,$actor,$now,$now]);
            $draft=(int)$pdo->lastInsertId(); if ($draft<=0) throw new RuntimeException('Draft identity unavailable'); $version=1;
        } else {
            $st=$pdo->prepare('SELECT version FROM crm_site_content_drafts WHERE id=? FOR UPDATE'); $st->execute([$draft]); $current=$st->fetchColumn();
            if ($current===false || (int)$current!==$expected) throw new DomainException('پیش‌نویس تغییر کرده است؛ دوباره بارگذاری کنید.');
            if ($expected>=100) throw new DomainException('سقف ۱۰۰ نسخهٔ این پیش‌نویس رسیده است؛ طرح نگهداری و آرشیو لازم است.');
            if ($action==='restore') {
                $st=$pdo->prepare('SELECT title,body FROM crm_site_content_draft_versions WHERE draft_id=? AND version=? FOR UPDATE'); $st->execute([$draft,$restoreVersion]); $old=$st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new DomainException('نسخهٔ مورد نظر یافت نشد.');
                [$title,$body]=content_draft_text((string)$old['title'],(string)$old['body']);
            }
            $version=$expected+1;
            $st=$pdo->prepare('UPDATE crm_site_content_drafts SET title=?,body=?,version=?,updated_by=?,updated_at=? WHERE id=? AND version=?'); $st->execute([$title,$body,$version,$actor,$now,$draft,$expected]);
            if ($st->rowCount()!==1) throw new RuntimeException('Draft version conflict');
        }
        $pdo->prepare('INSERT INTO crm_site_content_draft_versions(draft_id,version,title,body,actor_id,action,restored_version,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$draft,$version,$title,$body,$actor,$action,$action==='restore'?$restoreVersion:null,$now]);
        $pdo->commit(); return $draft;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
