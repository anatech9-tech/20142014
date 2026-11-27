<?php
/** CRM outbox for separately opt-in hide and restricted publication commands. */
require_once __DIR__.'/shop_catalog_sync.php';
function crm_product_command_configured(): bool {
    if (!defined('SHOP_PRODUCT_COMMAND_ENABLED') || SHOP_PRODUCT_COMMAND_ENABLED!==true || !defined('SHOP_PRODUCT_COMMAND_KEY') || strlen((string)SHOP_PRODUCT_COMMAND_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)
        || !defined('SHOP_PRODUCT_COMMAND_URL') || !filter_var(SHOP_PRODUCT_COMMAND_URL,FILTER_VALIDATE_URL) || strtolower((string)parse_url(SHOP_PRODUCT_COMMAND_URL,PHP_URL_SCHEME))!=='https') return false;
    foreach (['SITE_CRM_BRIDGE_KEY','SHOP_CRM_BRIDGE_KEY','SHOP_CRM_COMMAND_KEY','SHOP_CATALOG_CRM_KEY'] as $other) if (defined($other) && hash_equals((string)constant($other),(string)SHOP_PRODUCT_COMMAND_KEY)) return false;
    return true;
}
function crm_product_hide_enabled(): bool { return defined('SHOP_PRODUCT_HIDE_ENABLED') && SHOP_PRODUCT_HIDE_ENABLED===true; }
function crm_product_show_enabled(): bool { return defined('SHOP_PRODUCT_SHOW_ENABLED') && SHOP_PRODUCT_SHOW_ENABLED===true; }
function crm_product_command_schema(): array {
    return [
        "CREATE TABLE IF NOT EXISTS crm_shop_product_commands(command_id CHAR(32) NOT NULL PRIMARY KEY,replica_id BIGINT UNSIGNED NOT NULL,source_id CHAR(32) NOT NULL,product_id BIGINT UNSIGNED NOT NULL,replica_revision BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,payload TEXT NOT NULL,state VARCHAR(12) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_attempt_at DATETIME NOT NULL,created_at DATETIME NOT NULL,completed_at DATETIME NULL,KEY idx_product_queue(state,next_attempt_at,created_at),KEY idx_product_replica(replica_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS crm_shop_product_command_events(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,command_id CHAR(32) NOT NULL,replica_id BIGINT UNSIGNED NOT NULL,actor_id INT NOT NULL,action VARCHAR(12) NOT NULL,created_at DATETIME NOT NULL,KEY idx_product_command_event(command_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
}
function crm_product_command_ensure(PDO $pdo): bool {
    foreach (['crm_shop_product_commands','crm_shop_product_command_events'] as $i=>$table) {
        try { $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1'); } catch (Throwable $e) { try { $schema=crm_product_command_schema(); $pdo->exec($schema[$i]); } catch (Throwable $e) { error_log('Product command schema unavailable'); return false; } }
    }
    return true;
}
function crm_product_command_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_shop_catalog','crm_shop_product_commands','crm_shop_product_command_events'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional product command tables required');
    }
}
/** Caller performs CSRF checks and must receive an explicit confirmation. */
function crm_product_hide_enqueue(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,int $now,bool $confirmed): string {
    return crm_product_visibility_enqueue($pdo,$replica,$actor,$admin,$revision,$now,$confirmed,'hide');
}
function crm_product_show_enqueue(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,int $now,bool $confirmed): string {
    return crm_product_visibility_enqueue($pdo,$replica,$actor,$admin,$revision,$now,$confirmed,'show');
}
function crm_product_visibility_enqueue(PDO $pdo,int $replica,int $actor,bool $admin,int $revision,int $now,bool $confirmed,string $action): string {
    if (!in_array($action,['hide','show'],true) || !crm_product_command_configured() || !($action==='hide'?crm_product_hide_enabled():crm_product_show_enabled()) || !crm_shop_catalog_configured() || !$confirmed || !$admin || $replica<=0 || $actor<=0 || $revision<=0 || $now<=0 || $now>PHP_INT_MAX-900) throw new DomainException('فرمان یا تأیید مدیر معتبر نیست.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') crm_product_command_require_innodb($pdo);
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actor]); $user=$st->fetch(PDO::FETCH_ASSOC);
        if (!$user || $user['status']!=='active' || $user['role']!=='admin') throw new DomainException('دسترسی مدیر معتبر نیست.');
        $st=$pdo->prepare('SELECT * FROM crm_shop_catalog WHERE id=? FOR UPDATE'); $st->execute([$replica]); $r=$st->fetch(PDO::FETCH_ASSOC);
        $p=$r ? json_decode((string)$r['payload'],true) : null;
        $expected=$action==='hide'?1:0;
        if (!$r || $r['source_id']!==(string)SHOP_CRM_SOURCE_ID || (int)$r['revision']!==$revision || (int)$r['is_active']!==$expected
            || !is_array($p) || ($p['id'] ?? null)!==(int)$r['shop_product_id'] || ($p['is_active'] ?? null)!==$expected
            || !crm_shop_catalog_validate(['type'=>'shop_product','source_id'=>$r['source_id'],'event_id'=>$r['event_id'],'revision'=>$revision,'product'=>$p],(string)SHOP_CRM_SOURCE_ID)) throw new DomainException('نسخه یا هویت کالا معتبر نیست؛ مجدداً تطبیق دهید.');
        if ($action==='show' && (($p['currency'] ?? '')!=='toman' || !is_int($p['price_toman'] ?? null) || $p['price_toman']<=0)) throw new DomainException('انتشار فقط برای کالای با قیمت تومانی مثبت مجاز است.');
        $st=$pdo->prepare("SELECT command_id FROM crm_shop_product_commands WHERE replica_id=? AND state='queued' LIMIT 1"); $st->execute([$replica]);
        if ($st->fetchColumn()) throw new DomainException('فرمان قبلی هنوز تعیین تکلیف نشده است.');
        $snapshot=json_encode($p,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($snapshot===false || strlen($snapshot)>8192) throw new RuntimeException('Invalid product snapshot');
        $id=bin2hex(random_bytes(16)); $command=['type'=>$action==='hide'?'shop_product_hide_v1':'shop_product_show_v1','command_id'=>$id,'source_id'=>$r['source_id'],'product_id'=>(int)$r['shop_product_id'],'revision'=>$revision,'snapshot_hash'=>hash('sha256',$snapshot),'issued_at'=>$now,'expires_at'=>$now+900];
        $payload=json_encode($command,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); if ($payload===false) throw new RuntimeException('Encoding failed');
        $stamp=date('Y-m-d H:i:s',$now);
        $pdo->prepare("INSERT INTO crm_shop_product_commands(command_id,replica_id,source_id,product_id,replica_revision,actor_id,payload,state,attempts,next_attempt_at,created_at) VALUES (?,?,?,?,?,?,?,'queued',0,?,?)")->execute([$id,$replica,$r['source_id'],$r['shop_product_id'],$revision,$actor,$payload,$stamp,$stamp]);
        $pdo->prepare('INSERT INTO crm_shop_product_command_events(command_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$id,$replica,$actor,'send',$stamp]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function crm_product_command_settle(PDO $pdo,array $row,$reply,int $http,int $now): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['command_id'] ?? null)!==$row['command_id'] || !in_array($reply['result'] ?? '',['applied','conflict','expired'],true)) return false;
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT state,replica_id,actor_id FROM crm_shop_product_commands WHERE command_id=? FOR UPDATE'); $st->execute([$row['command_id']]); $c=$st->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new RuntimeException('Command missing');
        if ($c['state']!=='queued') { $pdo->commit(); return $c['state']===$reply['result']; }
        $stamp=date('Y-m-d H:i:s',$now);
        $st=$pdo->prepare("UPDATE crm_shop_product_commands SET state=?,completed_at=? WHERE command_id=? AND state='queued'"); $st->execute([$reply['result'],$stamp,$row['command_id']]);
        if ($st->rowCount()!==1) throw new RuntimeException('Command settlement failed');
        $pdo->prepare('INSERT INTO crm_shop_product_command_events(command_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$row['command_id'],$c['replica_id'],$c['actor_id'],$reply['result'],$stamp]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Caller holds a worker advisory lock; a lost response remains queued. */
function crm_product_command_pump(PDO $pdo,callable $send,int $now,int $limit=10): array {
    $limit=max(1,min(20,$limit)); $st=$pdo->prepare("SELECT * FROM crm_shop_product_commands WHERE state='queued' AND next_attempt_at<=? ORDER BY created_at,command_id LIMIT $limit");
    $st->execute([date('Y-m-d H:i:s',$now)]); $result=['settled'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        [$http,$reply]=$send((string)$row['payload']);
        if (crm_product_command_settle($pdo,$row,$reply,(int)$http,$now)) $result['settled']++;
        else { $attempt=(int)$row['attempts']+1; $delay=min(1800,30*(2 ** min($attempt,6))); $pdo->prepare("UPDATE crm_shop_product_commands SET attempts=?,next_attempt_at=? WHERE command_id=? AND state='queued'")->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['command_id']]); $result['failed']++; }
    }
    return $result;
}
function crm_product_command_send(string $body): array {
    if (!crm_product_command_configured() || !function_exists('curl_init')) throw new RuntimeException('Product command transport disabled');
    $ts=(string)time(); $signature=hash_hmac('sha256','shop-product-command-v1.'.$ts.'.'.$body,(string)SHOP_PRODUCT_COMMAND_KEY); $response='';
    $ch=curl_init((string)SHOP_PRODUCT_COMMAND_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Product-Command-Timestamp: '.$ts,'X-Product-Command-Signature: '.$signature],
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$ok===false?0:$http,json_decode($response,true)];
}
