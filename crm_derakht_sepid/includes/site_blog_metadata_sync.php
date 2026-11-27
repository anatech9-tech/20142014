<?php
/** Isolated, opt-in blog metadata replica: no title, content, image or website writes. */
require_once __DIR__.'/site_blog_metadata_contract.php';
function crm_site_blog_sync_configured(): bool {
    if (!defined('SITE_BLOG_META_ENABLED') || SITE_BLOG_META_ENABLED!==true || !defined('SITE_BLOG_META_KEY') || strlen((string)SITE_BLOG_META_KEY)<32
        || !defined('SITE_BLOG_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SITE_BLOG_SOURCE_ID)) return false;
    foreach (['SHOP_CATEGORY_META_KEY','SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY','SHOP_REVIEW_COMMAND_KEY','SHOP_REVIEW_CONTENT_GUARD_KEY','SITE_SHIPMENT_META_KEY','SHOP_COUPON_META_KEY'] as $key) if (defined($key) && hash_equals((string)constant($key),(string)SITE_BLOG_META_KEY)) return false;
    return true;
}
function crm_site_blog_sync_deletion_enabled(): bool { return defined('SITE_BLOG_META_DELETION_ENABLED') && SITE_BLOG_META_DELETION_ENABLED===true; }
function crm_site_blog_sync_signature_valid(string $key,string $ts,string $sig,string $body,int $now): bool {
    return strlen($key)>=32 && preg_match('/^[0-9]{10}$/D',$ts)===1 && abs($now-(int)$ts)<=300 && preg_match('/^[a-f0-9]{64}$/D',$sig)===1
        && hash_equals(hash_hmac('sha256','site-blog-meta-v1.'.$ts.'.'.$body,$key),$sig);
}
function crm_site_blog_sync_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_site_blog_meta(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,source_id CHAR(32) NOT NULL,post_id BIGINT UNSIGNED NOT NULL,revision BIGINT UNSIGNED NOT NULL DEFAULT 0,event_id CHAR(32) NOT NULL DEFAULT '',payload_hash CHAR(64) NOT NULL DEFAULT '',is_published TINYINT NOT NULL DEFAULT 2,received_at DATETIME NOT NULL,UNIQUE KEY uq_blog_source(source_id,post_id),KEY idx_blog_status(is_published,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function crm_site_blog_sync_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_site_blog_meta LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(crm_site_blog_sync_schema()); return true; } catch (Throwable $e) { error_log('Blog metadata schema unavailable'); return false; } }
}
function crm_site_blog_sync_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $st->execute(['crm_site_blog_meta']);
    if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required');
}
function crm_site_blog_sync_store(PDO $pdo,array $data,string $hash): string {
    if (!crm_site_blog_sync_configured() || !crm_site_blog_metadata_validate($data,(string)SITE_BLOG_SOURCE_ID) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new DomainException('Invalid blog event');
    $r=$data['post']; $deleted=$data['type']==='site_blog_meta_deleted_v1';
    if ($deleted && !crm_site_blog_sync_deletion_enabled()) throw new DomainException('Blog reconciliation disabled');
    $published=$deleted?2:$r['is_published']; $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO crm_site_blog_meta(source_id,post_id,revision,event_id,payload_hash,is_published,received_at) VALUES (?,?,0,'','',2,?) ON DUPLICATE KEY UPDATE id=id");
        $st->execute([$data['source_id'],$r['id'],date('Y-m-d H:i:s')]);
        $st=$pdo->prepare('SELECT revision,event_id,payload_hash FROM crm_site_blog_meta WHERE source_id=? AND post_id=? FOR UPDATE'); $st->execute([$data['source_id'],$r['id']]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new RuntimeException('Blog metadata unavailable');
        if ($data['revision']<(int)$old['revision']) $result='stale';
        elseif ($data['revision']===(int)$old['revision']) $result=hash_equals((string)$old['event_id'],$data['event_id']) && hash_equals((string)$old['payload_hash'],$hash)?'duplicate':'conflict';
        else {
            $st=$pdo->prepare('UPDATE crm_site_blog_meta SET revision=?,event_id=?,payload_hash=?,is_published=?,received_at=? WHERE source_id=? AND post_id=?');
            $st->execute([$data['revision'],$data['event_id'],$hash,$published,date('Y-m-d H:i:s'),$data['source_id'],$r['id']]); $result='updated';
        }
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
