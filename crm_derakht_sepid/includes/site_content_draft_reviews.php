<?php
/** Internal manual acknowledgements only; not approval, publication or proof of reading. */
require_once __DIR__.'/site_content_drafts.php';
function content_draft_review_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_site_content_draft_reviews(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,draft_id BIGINT UNSIGNED NOT NULL,draft_version INT UNSIGNED NOT NULL,actor_id INT NOT NULL,created_at DATETIME NOT NULL,UNIQUE KEY uq_content_draft_review(draft_id,draft_version,actor_id),KEY idx_content_draft_reviews(draft_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function content_draft_review_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_site_content_draft_reviews LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(content_draft_review_schema()); return true; } catch (Throwable $e) { error_log('Content draft reviews unavailable'); return false; } }
}
function content_draft_review_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixtures only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_site_content_drafts','crm_site_content_draft_versions','crm_site_content_draft_reviews'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional review tables required');
    }
}
/** Caller enforces CSRF, session authorization, read-only policy and explicit acknowledgement. */
function content_draft_review_record(PDO $pdo,int $actor,bool $admin,int $draft,int $expected,bool $confirmed): void {
    if (!$confirmed || $draft<=0 || $expected<=0) throw new DomainException('نسخه یا تأیید بررسی معتبر نیست.');
    content_draft_review_require_innodb($pdo); $pdo->beginTransaction();
    try {
        content_draft_check_admin($pdo,$actor,$admin);
        $st=$pdo->prepare('SELECT version,title,body FROM crm_site_content_drafts WHERE id=? FOR UPDATE'); $st->execute([$draft]); $current=$st->fetch(PDO::FETCH_ASSOC);
        if (!$current || (int)$current['version']!==$expected) throw new DomainException('نسخه پیش‌نویس تغییر کرده است؛ دوباره بارگذاری کنید.');
        $st=$pdo->prepare('SELECT title,body FROM crm_site_content_draft_versions WHERE draft_id=? AND version=? FOR UPDATE'); $st->execute([$draft,$expected]); $version=$st->fetch(PDO::FETCH_ASSOC);
        if (!$version || $version['title']!==$current['title'] || $version['body']!==$current['body']) throw new DomainException('تاریخچه با پیش‌نویس جاری سازگار نیست؛ بررسی فنی لازم است.');
        $st=$pdo->prepare('SELECT COUNT(*) FROM crm_site_content_draft_reviews WHERE draft_id=?'); $st->execute([$draft]);
        if ((int)$st->fetchColumn()>=500) throw new DomainException('سقف ۵۰۰ ثبت بررسی برای این پیش‌نویس رسیده است.');
        $st=$pdo->prepare('SELECT id FROM crm_site_content_draft_reviews WHERE draft_id=? AND draft_version=? AND actor_id=?'); $st->execute([$draft,$expected,$actor]);
        if ($st->fetchColumn()!==false) throw new DomainException('بررسی این نسخه توسط شما قبلاً ثبت شده است.');
        $pdo->prepare('INSERT INTO crm_site_content_draft_reviews(draft_id,draft_version,actor_id,created_at) VALUES (?,?,?,?)')->execute([$draft,$expected,$actor,date('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
