<?php
/** CRM outbox for an independently opt-in, hide-only review command. */
require_once __DIR__.'/shop_review_meta_sync.php';
function crm_review_command_configured(): bool {
    if (!defined('SHOP_REVIEW_COMMAND_ENABLED') || SHOP_REVIEW_COMMAND_ENABLED!==true || !defined('SHOP_REVIEW_COMMAND_KEY') || strlen((string)SHOP_REVIEW_COMMAND_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)
        || !defined('SHOP_REVIEW_COMMAND_URL') || !filter_var(SHOP_REVIEW_COMMAND_URL,FILTER_VALIDATE_URL) || strtolower((string)parse_url(SHOP_REVIEW_COMMAND_URL,PHP_URL_SCHEME))!=='https') return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY','SHOP_PRODUCT_COMMAND_KEY','SHOP_REVIEW_META_CRM_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_REVIEW_COMMAND_KEY)) return false;
    return true;
}
function crm_review_hide_enabled(): bool { return defined('SHOP_REVIEW_HIDE_ENABLED') && SHOP_REVIEW_HIDE_ENABLED===true; }
function crm_review_command_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_review_commands(command_id CHAR(32) NOT NULL PRIMARY KEY,replica_id BIGINT UNSIGNED NOT NULL,source_id CHAR(32) NOT NULL,review_id BIGINT UNSIGNED NOT NULL,replica_revision BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,payload TEXT NOT NULL,state VARCHAR(12) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_review_queue(state,next_attempt_at,created_at),KEY idx_review_replica(replica_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_review_command_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,command_id CHAR(32) NOT NULL,replica_id BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,action VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,KEY idx_review_command_event(command_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function crm_review_command_ensure(PDO $pdo): bool {
    foreach (['crm_shop_review_commands','crm_shop_review_command_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { try { $schema=crm_review_command_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Review command schema unavailable'); return false; } }
    }
    return true;
}
function crm_review_command_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_shop_review_meta','crm_shop_review_commands','crm_shop_review_command_events'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional review command tables required');
    }
}
/** Reject overflow or malformed persisted identifiers rather than coercing them. */
function crm_review_uint($value,bool $zero=false): int {
    if (!is_int($value) && !is_string($value)) throw new DomainException('شناسه دیدگاه نامعتبر است.');
    $s=(string)$value; $max=(string)PHP_INT_MAX;
    if (!preg_match('/^(0|[1-9][0-9]*)$/D',$s) || strlen($s)>strlen($max) || (strlen($s)===strlen($max) && strcmp($s,$max)>0) || (!$zero && $s==='0')) throw new DomainException('شناسه دیدگاه نامعتبر است.');
    return (int)$s;
}
/** Caller checks CSRF and two confirmations; no review text is carried in the queue. */
function crm_review_hide_enqueue(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,int $now,bool $confirmed): string {
    if (!crm_review_command_configured() || !crm_review_hide_enabled() || !crm_review_meta_configured() || !$confirmed || !$admin || $replica<=0 || $actor<=0 || $revision<=0 || $now<=0 || $now>PHP_INT_MAX-900) throw new DomainException('فرمان یا تأیید مدیر معتبر نیست.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') crm_review_command_require_innodb($pdo);
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actor]); $user=$st->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['status']!=='active' || $user['role']!=='admin') throw new DomainException('دسترسی مدیر معتبر نیست.');
        $st=$pdo->prepare('SELECT * FROM crm_shop_review_meta WHERE id=? FOR UPDATE'); $st->execute([$replica]); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['source_id']!==(string)SHOP_CRM_SOURCE_ID || crm_review_uint($r['revision'])!==$revision || crm_review_uint($r['is_active'],true)!==1) throw new DomainException('نسخه یا هویت دیدگاه معتبر نیست؛ مجدداً تطبیق دهید.');
        $review=['id'=>crm_review_uint($r['review_id']),'product_id'=>crm_review_uint($r['product_id']),'rating'=>crm_review_uint($r['rating']),'is_active'=>1];
        if ($review['rating']>5) throw new DomainException('امتیاز دیدگاه معتبر نیست.');
        $st=$pdo->prepare("SELECT command_id FROM crm_shop_review_commands WHERE replica_id=? AND state='queued' LIMIT 1"); $st->execute([$replica]);
        if ($st->fetchColumn()) throw new DomainException('فرمان قبلی هنوز تعیین تکلیف نشده است.');
        $snapshot=json_encode($review,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($snapshot===false) throw new RuntimeException('Invalid review snapshot');
        $id=bin2hex(random_bytes(16)); $command=['type'=>'shop_review_hide_v1','command_id'=>$id,'source_id'=>$r['source_id'],'review_id'=>(int)$r['review_id'],'revision'=>$revision,'snapshot_hash'=>hash('sha256',$snapshot),'issued_at'=>$now,'expires_at'=>$now+900];
        $payload=json_encode($command,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($payload===false) throw new RuntimeException('Encoding failed');
        $stamp=date('Y-m-d H:i:s',$now);
        $pdo->prepare("INSERT INTO crm_shop_review_commands(command_id,replica_id,source_id,review_id,replica_revision,actor_id,payload,state,attempts,next_attempt_at,created_at) VALUES (?,?,?,?,?,?,?,'queued',0,?,?)")->execute([$id,$replica,$r['source_id'],$r['review_id'],$revision,$actor,$payload,$stamp,$stamp]);
        $pdo->prepare('INSERT INTO crm_shop_review_command_events(command_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$id,$replica,$actor,'send',$stamp]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function crm_review_command_settle(PDO $pdo,array $row,$reply,int $http,int $now): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['command_id'] ?? null)!==$row['command_id'] || !in_array($reply['result'] ?? '',['applied','conflict','expired'],true)) return false;
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT state,replica_id,actor_id FROM crm_shop_review_commands WHERE command_id=? FOR UPDATE'); $st->execute([$row['command_id']]); $c=$st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new RuntimeException('Command missing');
        if ($c['state']!=='queued') { $pdo->commit(); return $c['state']===$reply['result']; }
        $stamp=date('Y-m-d H:i:s',$now);
        $st=$pdo->prepare("UPDATE crm_shop_review_commands SET state=?,completed_at=? WHERE command_id=? AND state='queued'"); $st->execute([$reply['result'],$stamp,$row['command_id']]);
        if ($st->rowCount()!==1) throw new RuntimeException('Command settlement failed');
        $pdo->prepare('INSERT INTO crm_shop_review_command_events(command_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$row['command_id'],$c['replica_id'],$c['actor_id'],$reply['result'],$stamp]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Treat outbox as untrusted: do not transmit added fields or mismatched identifiers. */
function crm_review_command_payload_valid($c,array $row): bool {
    if (!is_array($c) || count($c)!==8 || array_diff(array_keys($c),['type','command_id','source_id','review_id','revision','snapshot_hash','issued_at','expires_at'])
        || ($c['type'] ?? null)!=='shop_review_hide_v1' || ($c['source_id'] ?? null)!==(string)SHOP_CRM_SOURCE_ID || ($c['source_id'] ?? null)!==$row['source_id']
        || !is_string($c['command_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$c['command_id']) || $c['command_id']!==$row['command_id']
        || !is_string($c['snapshot_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$c['snapshot_hash'])) return false;
    foreach (['review_id','revision','issued_at','expires_at'] as $k) if (!is_int($c[$k] ?? null) || $c[$k]<=0) return false;
    try { if ($c['review_id']!==crm_review_uint($row['review_id']) || $c['revision']!==crm_review_uint($row['replica_revision'])) return false; } catch (DomainException $e) { return false; }
    return $c['issued_at']<=PHP_INT_MAX-900 && $c['expires_at']===$c['issued_at']+900;
}
/** Caller holds a worker advisory lock; a lost response remains queued. */
function crm_review_command_pump(PDO $pdo,callable $send,int $now,int $limit=10): array {
    $limit=max(1,min(20,$limit)); $st=$pdo->prepare("SELECT * FROM crm_shop_review_commands WHERE state='queued' AND next_attempt_at<=? ORDER BY created_at,command_id LIMIT $limit");
    $st->execute([date('Y-m-d H:i:s',$now)]); $result=['settled'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload=(string)$row['payload'];
        if (strlen($payload)>2048 || !crm_review_command_payload_valid(json_decode($payload,true),$row)) throw new RuntimeException('Invalid queued review command');
        [$http,$reply]=$send($payload);
        if (crm_review_command_settle($pdo,$row,$reply,(int)$http,$now)) $result['settled']++;
        else { $attempt=(int)$row['attempts']+1; $delay=min(1800,30*(2 ** min($attempt,6))); $pdo->prepare("UPDATE crm_shop_review_commands SET attempts=?,next_attempt_at=? WHERE command_id=? AND state='queued'")->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['command_id']]); $result['failed']++; }
    }
    return $result;
}
function crm_review_command_send(string $body): array {
    if (!crm_review_command_configured() || !function_exists('curl_init')) throw new RuntimeException('Review command transport disabled');
    $ts=(string)time(); $signature=hash_hmac('sha256','shop-review-command-v1.'.$ts.'.'.$body,(string)SHOP_REVIEW_COMMAND_KEY); $response='';
    $ch=curl_init((string)SHOP_REVIEW_COMMAND_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Review-Command-Timestamp: '.$ts,'X-Review-Command-Signature: '.$signature],
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$ok===false?0:$http,json_decode($response,true)];
}
