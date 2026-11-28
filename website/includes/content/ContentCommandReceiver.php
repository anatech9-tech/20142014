<?php
/** Opt-in CRM service/team text updates including independently opt-in service English. No create/delete, media, visibility or account changes. */
require_once __DIR__.'/ContentCommandContract.php';
require_once __DIR__.'/../ContentEditGuard.php';
function content_receiver_schema(): string {
    return "CREATE TABLE IF NOT EXISTS site_content_command_receipts(command_id CHAR(32) NOT NULL PRIMARY KEY,request_hash CHAR(64) NOT NULL,source_id CHAR(32) NOT NULL,kind VARCHAR(12) NOT NULL,object_id BIGINT UNSIGNED NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,result VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_content_receipt(kind,object_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function content_receiver_ensure(PDO $pdo): void { try { $pdo->query('SELECT 1 FROM site_content_command_receipts LIMIT 1'); } catch (Throwable $e) { $pdo->exec(content_receiver_schema()); } }
function content_receiver_require_innodb(PDO $pdo,string $kind): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ([content_edit_table($kind),'site_content_command_receipts'] as $t) { $st->execute([$t]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required'); }
}
/** Receiver checks authenticated source/config and InnoDB before calling. Same receipt+write transaction. */
function content_receiver_apply(PDO $pdo,array $c,string $source,string $hash,int $now): string {
    if (!content_command_validate($c,$source) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new InvalidArgumentException('Invalid command');
    $kind=content_command_kind($c); if (!content_command_kind_enabled(content_command_mode($c))) throw new RuntimeException('Action disabled');
    $table=content_edit_table($kind); $pdo->beginTransaction();
    try {
        $st=$pdo->prepare("INSERT INTO site_content_command_receipts(command_id,request_hash,source_id,kind,object_id,actor_id,result,created_at) VALUES (?,?,?,?,?,?,'processing',?) ON DUPLICATE KEY UPDATE command_id=command_id");
        $st->execute([$c['command_id'],$hash,$source,$kind,$c['object_id'],$c['actor_id'],date('Y-m-d H:i:s',$now)]);
        $st=$pdo->prepare('SELECT request_hash,result FROM site_content_command_receipts WHERE command_id=? FOR UPDATE'); $st->execute([$c['command_id']]); $old=$st->fetch(PDO::FETCH_ASSOC);
        if (!$old || !hash_equals((string)$old['request_hash'],$hash)) throw new DomainException('Receipt conflict');
        if ($old['result']!=='processing') { $pdo->commit(); return (string)$old['result']; }
        if ($c['issued_at']>$now+300 || $now>$c['expires_at']) $result='expired';
        else {
            $result='conflict'; $st=$pdo->prepare('SELECT '.implode(',',content_edit_fields($kind)).' FROM '.$table.' WHERE id=? FOR UPDATE'); $st->execute([$c['object_id']]); $row=$st->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals(content_edit_token($kind,$row),$c['expected_token'])) {
                $mode=content_command_mode($c); $f=$c['fields'];
                if ($mode==='service') { $st=$pdo->prepare('UPDATE services SET title_fa=?,description_fa=? WHERE id=?'); $args=[$f['title_fa'],$f['description_fa'],$c['object_id']]; }
                elseif ($mode==='service_en') { $st=$pdo->prepare('UPDATE services SET title_en=?,description_en=? WHERE id=?'); $args=[$f['title_en'],$f['description_en'],$c['object_id']]; }
                elseif ($mode==='team_profile') { $st=$pdo->prepare('UPDATE team_members SET handle=?,bio=? WHERE id=?'); $args=[$f['handle'],$f['bio'],$c['object_id']]; }
                else { $st=$pdo->prepare('UPDATE team_members SET name=?,role=? WHERE id=?'); $args=[$f['name'],$f['role'],$c['object_id']]; }
                $st->execute($args);
                $result='applied';
            }
        }
        $st=$pdo->prepare("UPDATE site_content_command_receipts SET result=?,completed_at=? WHERE command_id=? AND result='processing'"); $st->execute([$result,date('Y-m-d H:i:s',$now),$c['command_id']]);
        if ($st->rowCount()!==1) throw new RuntimeException('Receipt update failed');
        $pdo->commit(); return $result;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
