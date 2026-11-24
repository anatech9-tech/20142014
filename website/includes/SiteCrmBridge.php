<?php
/** Durable website-to-CRM outbox; usable from contact.php and CLI. */
function site_bridge_configured(): bool {
    return defined('SITE_CRM_BRIDGE_ENABLED') && SITE_CRM_BRIDGE_ENABLED === true
        && defined('SITE_CRM_BRIDGE_KEY') && strlen((string)SITE_CRM_BRIDGE_KEY) >= 32
        && defined('SITE_CRM_BRIDGE_URL') && filter_var(SITE_CRM_BRIDGE_URL, FILTER_VALIDATE_URL)
        && strtolower((string)parse_url(SITE_CRM_BRIDGE_URL, PHP_URL_SCHEME)) === 'https';
}
function site_bridge_pdo(): PDO {
    return new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
function site_bridge_ensure(PDO $pdo): void {
    try { $pdo->query('SELECT 1 FROM website_crm_outbox LIMIT 1'); return; } catch (Throwable $e) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS website_crm_outbox (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id CHAR(32) NOT NULL, payload TEXT NULL,
        attempts INT NOT NULL DEFAULT 0, next_attempt_at DATETIME NOT NULL,
        delivered_at DATETIME NULL, created_at DATETIME NOT NULL,
        UNIQUE KEY uq_outbox_event(event_id), KEY idx_outbox_pending(delivered_at,next_attempt_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function site_bridge_queue(PDO $pdo, array $data): string {
    $eventId=bin2hex(random_bytes(16));
    $payload=json_encode(['type'=>'contact','event_id'=>$eventId,'name'=>$data['name'],'email'=>$data['email'],
        'phone'=>$data['phone'],'subject'=>$data['subject'],'message'=>$data['message']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false || strlen($payload)>32768) throw new RuntimeException('Cannot encode inquiry');
    $st=$pdo->prepare('INSERT INTO website_crm_outbox (event_id,payload,next_attempt_at,created_at) VALUES (?,?,?,?)');
    $now=date('Y-m-d H:i:s'); $st->execute([$eventId,$payload,$now,$now]);
    return $eventId;
}
/** Serialized delivery across web and cron; failed items retry with bounded backoff. */
function site_bridge_pump(PDO $pdo, int $limit = 10): array {
    $report=['sent'=>0,'failed'=>0,'locked'=>false];
    if (!site_bridge_configured() || !function_exists('curl_init')) throw new RuntimeException('Bridge is not configured or cURL is missing');
    $lock=$pdo->query("SELECT GET_LOCK('wttp_site_crm_outbox',0)")->fetchColumn();
    if ((int)$lock !== 1) { $report['locked']=true; return $report; }
    try {
        $limit=max(1,min(50,$limit));
        $st=$pdo->prepare("SELECT id,event_id,payload,attempts FROM website_crm_outbox WHERE delivered_at IS NULL AND next_attempt_at<=? ORDER BY id LIMIT $limit");
        $st->execute([date('Y-m-d H:i:s')]);
        foreach ($st->fetchAll() as $row) {
            $body=(string)$row['payload']; $ts=(string)time();
            $sig=hash_hmac('sha256',$ts.'.'.$body,(string)SITE_CRM_BRIDGE_KEY);
            $ch=curl_init((string)SITE_CRM_BRIDGE_URL);
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
                CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Site-Timestamp: '.$ts,'X-Site-Signature: '.$sig],
                CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
            $response=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            $decoded=is_string($response) ? json_decode($response,true) : null;
            if ($http>=200 && $http<300 && is_array($decoded) && !empty($decoded['ok']) && ($decoded['event_id'] ?? '') === $row['event_id']) {
                $done=$pdo->prepare('UPDATE website_crm_outbox SET delivered_at=?,payload=NULL WHERE id=? AND delivered_at IS NULL');
                $done->execute([date('Y-m-d H:i:s'),$row['id']]); $report['sent']++;
            } else {
                $attempts=(int)$row['attempts']+1; $delay=min(3600,30 * (2 ** min($attempts,7)));
                $retry=$pdo->prepare('UPDATE website_crm_outbox SET attempts=?,next_attempt_at=? WHERE id=? AND delivered_at IS NULL');
                $retry->execute([$attempts,date('Y-m-d H:i:s',time()+$delay),$row['id']]); $report['failed']++;
            }
        }
    } finally { try { $pdo->query("SELECT RELEASE_LOCK('wttp_site_crm_outbox')"); } catch (Throwable $e) {} }
    return $report;
}
