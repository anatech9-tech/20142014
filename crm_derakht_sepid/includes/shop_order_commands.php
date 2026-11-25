<?php
/** Explicitly approved commands; restricted cancellation has an independent opt-in. */
function crm_shop_command_configured(): bool {
    if (!defined('SHOP_CRM_COMMAND_ENABLED') || SHOP_CRM_COMMAND_ENABLED !== true
        || !defined('SHOP_CRM_COMMAND_KEY') || strlen((string)SHOP_CRM_COMMAND_KEY)<32
        || !defined('SHOP_CRM_SOURCE_ID') || !preg_match('/^[a-f0-9]{32}$/D',(string)SHOP_CRM_SOURCE_ID)
        || !defined('SHOP_CRM_COMMAND_URL') || !filter_var(SHOP_CRM_COMMAND_URL,FILTER_VALIDATE_URL)
        || strtolower((string)parse_url(SHOP_CRM_COMMAND_URL,PHP_URL_SCHEME))!=='https') return false;
    foreach (['SHOP_CRM_BRIDGE_KEY','SITE_CRM_BRIDGE_KEY'] as $other) {
        if (defined($other) && hash_equals((string)constant($other),(string)SHOP_CRM_COMMAND_KEY)) return false;
    }
    return true;
}
function crm_shop_cancel_enabled(): bool { return defined('SHOP_CRM_CANCEL_ENABLED') && SHOP_CRM_CANCEL_ENABLED===true; }
function crm_shop_command_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_shop_order_commands (
        command_id CHAR(32) NOT NULL PRIMARY KEY, request_id BIGINT UNSIGNED NOT NULL,
        replica_id BIGINT UNSIGNED NOT NULL, actor_id INT NOT NULL, payload TEXT NOT NULL,
        state VARCHAR(12) NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NOT NULL, created_at DATETIME NOT NULL, completed_at DATETIME NULL,
        UNIQUE KEY uq_command_request(request_id), KEY idx_command_queue(state,next_attempt_at,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function crm_shop_command_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_shop_order_commands LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(crm_shop_command_schema()); return true; } catch (Throwable $e) { error_log('Shop command schema unavailable'); return false; } }
}
/** Production command processing requires transactional CRM tables. */
function crm_shop_command_require_innodb(PDO $pdo): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new RuntimeException('MySQL required');
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach (['users','crm_shop_orders','crm_shop_status_requests','crm_shop_status_request_events','crm_shop_order_commands'] as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new RuntimeException('Transactional CRM tables required');
    }
}
/** Call only after csrf and strict administrator checks. No network call in this transaction. */
function crm_shop_command_enqueue(PDO $pdo,int $replica,int $request,int $actor,bool $strictAdmin,int $now,string $source,bool $cancelConfirmed=false): string {
    if (!crm_shop_command_configured() || $source!==(string)SHOP_CRM_SOURCE_ID || $replica<=0 || $request<=0 || $now<=0) throw new DomainException('فرمان غیرفعال یا نامعتبر است.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql') crm_shop_command_require_innodb($pdo);
    $pdo->beginTransaction();
    try {
        shop_status_request_check_admin($pdo,$actor,$strictAdmin);
        $st=$pdo->prepare('SELECT source_id,shop_order_id,revision,payload FROM crm_shop_orders WHERE id=? FOR UPDATE'); $st->execute([$replica]); $row=$st->fetch(PDO::FETCH_ASSOC);
        $st=$pdo->prepare('SELECT * FROM crm_shop_status_requests WHERE id=? AND replica_id=? FOR UPDATE'); $st->execute([$request,$replica]); $r=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$r || $r['state']!=='open' || $source!==$row['source_id'] || $source!==$r['source_id'] || (int)$row['shop_order_id']!==(int)$r['shop_order_id'] || (int)$row['revision']!==(int)$r['replica_revision']) throw new DomainException('درخواست یا نسخهٔ سفارش تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
        $o=json_decode((string)$row['payload'],true);
        $normal=($r['expected_status']==='pending' && $r['requested_status']==='confirmed') || ($r['expected_status']==='confirmed' && $r['requested_status']==='packed');
        $cancel=crm_shop_cancel_enabled() && $cancelConfirmed && $r['requested_status']==='cancelled' && $r['expected_payment']==='pending' && in_array($r['expected_status'],['pending','confirmed','packed'],true);
        if (!is_array($o) || ($o['id'] ?? null)!==(int)$row['shop_order_id'] || !is_int($o['order_event_id'] ?? null) || $o['order_event_id']<=0
            || ($o['order_status'] ?? '')!==$r['expected_status'] || ($o['payment_status'] ?? '')!==$r['expected_payment']
            || !($normal || $cancel)) throw new DomainException('این تغییر یا نسخهٔ مبدأ برای فرمان پشتیبانی نمی‌شود.');
        $snapshot=json_encode($o,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($snapshot===false || strlen($snapshot)>500000) throw new DomainException('نسخه سفارش معتبر نیست.');
        $st=$pdo->prepare('SELECT command_id FROM crm_shop_order_commands WHERE request_id=?'); $st->execute([$request]);
        if ($st->fetchColumn()) throw new DomainException('برای این درخواست قبلاً فرمان ثبت شده است.');
        $id=bin2hex(random_bytes(16));
        $command=['type'=>$cancel?'shop_cancel_v1':'shop_status_v1','command_id'=>$id,'source_id'=>$source,'order_id'=>(int)$row['shop_order_id'],
            'expected_event_id'=>$o['order_event_id'],'expected_status'=>$r['expected_status'],'expected_payment'=>$r['expected_payment'],
            'target_status'=>$r['requested_status'],'snapshot_hash'=>hash('sha256',$snapshot),'issued_at'=>$now,'expires_at'=>$now+900];
        $payload=json_encode($command,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload===false) throw new RuntimeException('Command encoding failed');
        $st=$pdo->prepare("INSERT INTO crm_shop_order_commands(command_id,request_id,replica_id,actor_id,payload,state,attempts,next_attempt_at,created_at) VALUES (?,?,?,?,?,'queued',0,?,?)");
        $stamp=date('Y-m-d H:i:s',$now); $st->execute([$id,$request,$replica,$actor,$payload,$stamp,$stamp]);
        $pdo->prepare('INSERT INTO crm_shop_status_request_events(request_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$request,$replica,$actor,'send',$stamp]);
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Response may only settle the matching queued command, with an event in the same transaction. */
function crm_shop_command_settle(PDO $pdo,array $row,$reply,int $http,int $now): bool {
    if ($http<200 || $http>=300 || !is_array($reply) || ($reply['ok'] ?? null)!==true || ($reply['command_id'] ?? null)!==$row['command_id']
        || !in_array($reply['result'] ?? '',['applied','conflict','expired'],true)) return false;
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT state,request_id,replica_id,actor_id FROM crm_shop_order_commands WHERE command_id=? FOR UPDATE'); $st->execute([$row['command_id']]); $current=$st->fetch(PDO::FETCH_ASSOC);
        if (!$current) throw new RuntimeException('Command missing');
        if ($current['state']!=='queued') { $pdo->commit(); return $current['state']===$reply['result']; }
        $stamp=date('Y-m-d H:i:s',$now);
        $pdo->prepare("UPDATE crm_shop_order_commands SET state=?,completed_at=? WHERE command_id=? AND state='queued'")->execute([$reply['result'],$stamp,$row['command_id']]);
        $pdo->prepare('INSERT INTO crm_shop_status_request_events(request_id,replica_id,actor_id,action,created_at) VALUES (?,?,?,?,?)')->execute([$current['request_id'],$current['replica_id'],$current['actor_id'],$reply['result'],$stamp]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Caller must hold the worker advisory lock. callback returns [HTTP status, decoded reply]. */
function crm_shop_command_pump(PDO $pdo,callable $send,int $now,int $limit=10): array {
    $limit=max(1,min(20,$limit));
    $st=$pdo->prepare("SELECT * FROM crm_shop_order_commands WHERE state='queued' AND next_attempt_at<=? ORDER BY created_at,command_id LIMIT $limit");
    $st->execute([date('Y-m-d H:i:s',$now)]); $result=['settled'=>0,'failed'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        [$http,$reply]=$send((string)$row['payload']);
        if (crm_shop_command_settle($pdo,$row,$reply,(int)$http,$now)) $result['settled']++;
        else {
            $attempt=(int)$row['attempts']+1; $delay=min(1800,30*(2 ** min($attempt,6)));
            $pdo->prepare("UPDATE crm_shop_order_commands SET attempts=?,next_attempt_at=? WHERE command_id=? AND state='queued'")->execute([$attempt,date('Y-m-d H:i:s',$now+$delay),$row['command_id']]); $result['failed']++;
        }
    }
    return $result;
}
function crm_shop_command_send(string $body): array {
    if (!crm_shop_command_configured() || !function_exists('curl_init')) throw new RuntimeException('Command transport disabled');
    $ts=(string)time(); $signature=hash_hmac('sha256','shop-command-v1.'.$ts.'.'.$body,(string)SHOP_CRM_COMMAND_KEY);
    $response=''; $ch=curl_init((string)SHOP_CRM_COMMAND_URL);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Command-Timestamp: '.$ts,'X-Command-Signature: '.$signature],
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$response) { if (strlen($response)+strlen($chunk)>8192) return 0; $response.=$chunk; return strlen($chunk); }]);
    $ok=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$ok===false?0:$http,json_decode($response,true)];
}
