<?php
/** Authenticated website contact events and their CRM inbox. */
function site_inquiries_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_site_inquiries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id CHAR(32) NOT NULL, payload_hash CHAR(64) NOT NULL,
        name VARCHAR(100) NOT NULL, email VARCHAR(200) NOT NULL, phone VARCHAR(50) NOT NULL DEFAULT '',
        subject VARCHAR(200) NOT NULL DEFAULT '', message TEXT NOT NULL,
        owner_user_id INT NULL, team_id INT NULL, task_id INT NULL, lead_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new', received_at DATETIME NOT NULL,
        assigned_at DATETIME NULL, assigned_by INT NULL,
        UNIQUE KEY uq_site_inquiry_event(event_id), KEY idx_site_inquiry_owner(owner_user_id,id), KEY idx_site_inquiry_team(team_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function ensure_site_inquiries(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_site_inquiries LIMIT 1'); return true; }
    catch (Throwable $e) {}
    try { $pdo->exec(site_inquiries_schema()); return true; }
    catch (Throwable $e) { error_log('Site inbox schema: ' . $e->getMessage()); return false; }
}
/** Signature covers timestamp and raw bytes; timestamp prevents indefinite replay. */
function site_inquiry_signature_valid(string $key, string $timestamp, string $signature, string $body, int $now): bool {
    if (strlen($key) < 32 || !preg_match('/^[0-9]{10}$/', $timestamp) || abs($now - (int)$timestamp) > 300) return false;
    if (!preg_match('/^[a-f0-9]{64}$/', $signature)) return false;
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $key), $signature);
}
function site_inquiry_validate($data): ?array {
    if (!is_array($data) || ($data['type'] ?? '') !== 'contact' || !preg_match('/^[a-f0-9]{32}$/', (string)($data['event_id'] ?? ''))) return null;
    $out = [];
    foreach (['name'=>100, 'email'=>200, 'phone'=>50, 'subject'=>200, 'message'=>5000] as $k=>$max) {
        if (!isset($data[$k]) || !is_string($data[$k])) return null;
        $v = trim($data[$k]);
        if (mb_strlen($v, 'UTF-8') > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v)) return null;
        $out[$k] = $v;
    }
    if (mb_strlen($out['name'], 'UTF-8') < 2 || !filter_var($out['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($out['message'], 'UTF-8') < 10) return null;
    if ($out['phone'] !== '' && !preg_match('/^[\d\s\+\-\(\)]{5,50}$/u', $out['phone'])) return null;
    $out['event_id'] = $data['event_id'];
    return $out;
}
/** Unique event IDs make retries safe. A reused ID with different bytes is refused. */
function site_inquiry_store(PDO $pdo, array $data, string $payloadHash): string {
    try {
        $st=$pdo->prepare("INSERT INTO crm_site_inquiries (event_id,payload_hash,name,email,phone,subject,message,received_at) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$data['event_id'],$payloadHash,$data['name'],$data['email'],$data['phone'],$data['subject'],$data['message'],date('Y-m-d H:i:s')]);
        return 'created';
    } catch (PDOException $e) {
        $st=$pdo->prepare('SELECT payload_hash FROM crm_site_inquiries WHERE event_id=?');
        $st->execute([$data['event_id']]); $existing=$st->fetchColumn();
        if ($existing !== false) return hash_equals((string)$existing,$payloadHash) ? 'duplicate' : 'conflict';
        throw $e;
    }
}
