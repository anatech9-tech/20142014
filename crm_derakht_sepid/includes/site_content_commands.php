<?php
/** Manual, opt-in CRM command outbox for selected service/team fields. */
require_once __DIR__.'/site_content_command_contract.php';
function crm_content_command_schema(): array { return [
 "CREATE TABLE IF NOT EXISTS crm_site_content_command_targets(source_id CHAR(32) NOT NULL,kind VARCHAR(12) NOT NULL,object_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(source_id,kind,object_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS crm_site_content_commands(command_id CHAR(32) NOT NULL PRIMARY KEY,source_id CHAR(32) NOT NULL,kind VARCHAR(12) NOT NULL,object_id BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,payload TEXT NOT NULL,payload_hash CHAR(64) NOT NULL,state VARCHAR(12) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_content_queue(state,next_attempt_at,created_at),KEY idx_content_target(source_id,kind,object_id,state)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
 "CREATE TABLE IF NOT EXISTS crm_site_content_command_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,command_id CHAR(32) NOT NULL,actor_id INT NOT NULL,action VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,KEY idx_content_event(command_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
]; }
function crm_content_command_ensure(PDO $pdo): bool {
    foreach (['crm_site_content_command_targets','crm_site_content_commands','crm_site_content_command_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { try { $schema=crm_content_command_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Content command schema unavailable'); return false; } }
    } return true;
}
function crm_content_command_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_site_content_command_targets','crm_site_content_commands','crm_site_content_command_events'] as $name) { $st->execute([$name]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('InnoDB required'); }
}
/** Caller enforces login, CSRF, read-only policy and explicit review/identity confirmations. */
function crm_content_command_enqueue(PDO $pdo,int $actor,bool $admin,string $kind,int $object,string $token,array $fields,int $now,bool $confirmed): string {
    if (!content_command_configured(true) || !content_command_kind_enabled($kind) || !$confirmed || !$admin || $actor<=0 || $object<=0 || $now<=0 || $now>PHP_INT_MAX-900) throw new DomainException('دسترسی، پیکربندی یا تأیید معتبر نیست.');
    $c=['type'=>content_command_type($kind),'command_id'=>bin2hex(random_bytes(16)),'source_id'=>SITE_CONTENT_COMMAND_SOURCE_ID,'object_id'=>$object,'actor_id'=>$actor,'expected_token'=>$token,'issued_at'=>$now,'expires_at'=>$now+900,'fields'=>$fields];
    $targetKind=content_command_kind($c);
    if (!content_command_validate($c,SITE_CONTENT_COMMAND_SOURCE_ID)) throw new DomainException('فیلدها یا نسخهٔ سایت معتبر نیستند.');
    $body=json_encode($c,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($body===false || strlen($body)>8192) throw new DomainException('داده بیش از حد مجاز است.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') crm_content_command_require_innodb($pdo);
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actor]); $user=$st->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['status']!=='active' || $user['role']!=='admin') throw new DomainException('دسترسی مدیر معتبر نیست.');
        $st=$pdo->prepare('INSERT INTO crm_site_content_command_targets(source_id,kind,object_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE object_id=object_id'); $st->execute([$c['source_id'],$targetKind,$object]);
        $st=$pdo->prepare('SELECT object_id FROM crm_site_content_command_targets WHERE source_id=? AND kind=? AND object_id=? FOR UPDATE'); $st->execute([$c['source_id'],$targetKind,$object]); if ($st->fetchColumn()===false) throw new RuntimeException('Target unavailable');
        $st=$pdo->prepare("SELECT command_id FROM crm_site_content_commands WHERE source_id=? AND kind=? AND object_id=? AND state='queued' LIMIT 1"); $st->execute([$c['source_id'],$targetKind,$object]);
        if ($st->fetchColumn()!==false) throw new DomainException('فرمان قبلی این رکورد هنوز تعیین تکلیف نشده است.');
        $stamp=date('Y-m-d H:i:s',$now); $st=$pdo->prepare("INSERT INTO crm_site_content_commands(command_id,source_id,kind,object_id,actor_id,payload,payload_hash,state,attempts,next_attempt_at,created_at) VALUES (?,?,?,?,?,?,?,'queued',0,?,?)");
        $st->execute([$c['command_id'],$c['source_id'],$targetKind,$object,$actor,$body,hash('sha256',$body),$stamp,$stamp]);
        $pdo->prepare('INSERT INTO crm_site_content_command_events(command_id,actor_id,action,created_at) VALUES (?,?,?,?)')->execute([$c['command_id'],$actor,'queued',$stamp]);
        $pdo->commit(); return $c['command_id'];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function crm_content_command_settle(PDO $pdo,array $row,$reply,int $http,int $now): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['command_id'] ?? null)!==$row['command_id'] || !in_array($reply['result'] ?? null,['applied','conflict','expired'],true)) return false;
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT state,actor_id FROM crm_site_content_commands WHERE command_id=? FOR UPDATE'); $st->execute([$row['command_id']]); $c=$st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new RuntimeException('Command missing');
        if ($c['state']!=='queued') { $pdo->commit(); return $c['state']===$reply['result']; }
        $stamp=date('Y-m-d H:i:s',$now); $st=$pdo->prepare("UPDATE crm_site_content_commands SET state=?,completed_at=? WHERE command_id=? AND state='queued'"); $st->execute([$reply['result'],$stamp,$row['command_id']]); if ($st->rowCount()!==1) throw new RuntimeException('Settlement failed');
        $pdo->prepare('INSERT INTO crm_site_content_command_events(command_id,actor_id,action,created_at) VALUES (?,?,?,?)')->execute([$row['command_id'],$c['actor_id'],$reply['result'],$stamp]); $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Single worker must hold advisory lock; disabled kind is not sent. */
function crm_content_command_pump(PDO $pdo,callable $send,int $now,int $limit=10): array {
    if (!content_command_configured(true)) throw new RuntimeException('Transport disabled');
    $limit=max(1,min(20,$limit)); $st=$pdo->prepare("SELECT * FROM crm_site_content_commands WHERE state='queued' AND next_attempt_at<=? ORDER BY created_at,command_id LIMIT $limit"); $st->execute([date('Y-m-d H:i:s',$now)]); $result=['settled'=>0,'failed'=>0,'blocked'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $body=(string)$row['payload']; $c=json_decode($body,true);
        if (strlen($body)>8192 || !is_string($row['payload_hash']) || !hash_equals($row['payload_hash'],hash('sha256',$body)) || !content_command_validate($c,SITE_CONTENT_COMMAND_SOURCE_ID) || $c['command_id']!==$row['command_id'] || content_command_kind($c)!==$row['kind'] || $c['object_id']!==(int)$row['object_id'] || $c['actor_id']!==(int)$row['actor_id'] || $c['source_id']!==$row['source_id']) throw new RuntimeException('Invalid queued command');
        if (!content_command_kind_enabled(content_command_mode($c))) { $result['blocked']++; continue; }
        [$http,$reply]=$send($body);
        if (crm_content_command_settle($pdo,$row,$reply,(int)$http,$now)) $result['settled']++;
        else { $attempt=(int)$row['attempts']+1; $delay=min(1800,30*(2 ** min($attempt,6))); $pdo->prepare("UPDATE crm_site_content_commands SET attempts=?,next_attempt_at=? WHERE command_id=? AND state='queued'")->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['command_id']]); $result['failed']++; }
    } return $result;
}
function crm_content_command_send(string $body): array {
    if (!content_command_configured(true) || !function_exists('curl_init')) throw new RuntimeException('Transport disabled');
    $ts=(string)time(); $sig=hash_hmac('sha256','site-content-command-v1.'.$ts.'.'.$body,SITE_CONTENT_COMMAND_KEY); $response=''; $ch=curl_init(SITE_CONTENT_COMMAND_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Content-Command-Timestamp: '.$ts,'X-Content-Command-Signature: '.$sig],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$ok===false?0:$http,json_decode($response,true)];
}
