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

/** Linked inquiries must be in both the inbox assignment and the current lead scope.
 * A missing lead is visible only to a strict admin; no automatic ownership transfer. */
function site_inquiry_scope(int $me,bool $admin,array $managed): array {
    if ($admin) return ['1=1',[]];
    if ($me<=0) return ['1=0',[]];
    $managed=array_values(array_unique(array_filter(array_map('intval',$managed),static fn($x)=>$x>0)));
    $own='i.owner_user_id=?'; $lead='l.owner_user_id=?'; $a=[$me]; $b=[$me];
    if ($managed) {
        $ph=implode(',',array_fill(0,count($managed),'?'));
        $own="($own OR i.team_id IN ($ph))"; $lead="($lead OR l.team_id IN ($ph))";
        $a=array_merge($a,$managed); $b=array_merge($b,$managed);
    }
    return ["($own AND (i.lead_id IS NULL OR i.lead_id=0 OR EXISTS (SELECT 1 FROM crm_leads l WHERE l.id=i.lead_id AND $lead)))",array_merge($a,$b)];
}
function site_inquiry_in_scope(array $inquiry,?array $lead,int $me,bool $admin,array $managed): bool {
    if ($admin) return true;
    if ($me<=0) return false;
    $allowed=static fn($r)=>$r && ((int)($r['owner_user_id'] ?? 0)===$me || in_array((int)($r['team_id'] ?? 0),$managed,true));
    if (!$allowed($inquiry)) return false;
    if (empty($inquiry['lead_id'])) return true;
    return $lead!==null && (int)($lead['id'] ?? 0)===(int)$inquiry['lead_id'] && $allowed($lead);
}
function site_inquiry_reconcile_schema(): string {
    return "CREATE TABLE IF NOT EXISTS crm_site_inquiry_owner_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, inquiry_id BIGINT UNSIGNED NOT NULL,
        lead_id INT NOT NULL, actor_id INT NOT NULL, old_owner INT NULL, old_team INT NULL,
        new_owner INT NOT NULL, new_team INT NULL, created_at DATETIME NOT NULL,
        KEY idx_inquiry_owner_event(inquiry_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
function site_inquiry_reconcile_ensure(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM crm_site_inquiry_owner_events LIMIT 1'); return true; }
    catch (Throwable $e) { try { $pdo->exec(site_inquiry_reconcile_schema()); return true; } catch (Throwable $e) { error_log('Site inbox owner audit unavailable'); return false; } }
}
function site_inquiry_require_innodb(PDO $pdo,array $tables): void {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') return; // SQLite fixture only.
    $st=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($tables as $table) {
        $st->execute([$table]); if (strtoupper((string)$st->fetchColumn())!=='INNODB') throw new DomainException('جدول‌های صندوق باید تراکنشی و InnoDB باشند.');
    }
}
/** Explicit admin reconciliation, not proof of lead identity after deletion or restore. */
function site_inquiry_reconcile(PDO $pdo,int $id,int $actor,bool $admin,int $expectedLead,int $expectedOwner,int $expectedTeam): void {
    if (!$admin || $id<=0 || $actor<=0 || $expectedLead<=0 || $expectedOwner<=0 || $expectedTeam<0) throw new DomainException('درخواست یا دسترسی نامعتبر است.');
    site_inquiry_require_innodb($pdo,['users','crm_leads','crm_site_inquiries','crm_site_inquiry_owner_events']);
    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT status,role FROM users WHERE id=? FOR UPDATE'); $st->execute([$actor]); $u=$st->fetch(PDO::FETCH_ASSOC);
        if (!$u || $u['status']!=='active' || $u['role']!=='admin') throw new DomainException('فقط مدیر کل فعال مجاز است.');
        $st=$pdo->prepare('SELECT owner_user_id,team_id,lead_id FROM crm_site_inquiries WHERE id=? FOR UPDATE'); $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['lead_id']!==$expectedLead) throw new DomainException('درخواست یا سرنخ تغییر کرده است.');
        $st=$pdo->prepare('SELECT id,owner_user_id,team_id FROM crm_leads WHERE id=? FOR UPDATE'); $st->execute([$expectedLead]); $lead=$st->fetch(PDO::FETCH_ASSOC);
        if (!$lead || (int)$lead['owner_user_id']!==$expectedOwner || (int)$lead['team_id']!==$expectedTeam) throw new DomainException('سرنخ یا ارجاع آن تغییر کرده است.');
        $st=$pdo->prepare('SELECT status,team_id,role,username FROM users WHERE id=? FOR UPDATE'); $st->execute([$expectedOwner]); $target=$st->fetch(PDO::FETCH_ASSOC);
        if (!$target || $target['status']!=='active' || ($target['role'] ?? '')==='karfarma' || in_array($target['username'] ?? '', ['yaser','javidhse'],true) || (int)$target['team_id']!==$expectedTeam) throw new DomainException('مسئول سرنخ فعال نیست یا تیم او با سرنخ سازگار نیست.');
        $oldOwner=(int)($row['owner_user_id'] ?? 0); $oldTeam=(int)($row['team_id'] ?? 0);
        if ($oldOwner===$expectedOwner && $oldTeam===$expectedTeam) throw new DomainException('ارجاع صندوق از قبل همسان است.');
        $now=date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE crm_site_inquiries SET owner_user_id=?,team_id=?,assigned_at=?,assigned_by=? WHERE id=?')->execute([$expectedOwner,$expectedTeam ?: null,$now,$actor,$id]);
        $pdo->prepare('INSERT INTO crm_site_inquiry_owner_events(inquiry_id,lead_id,actor_id,old_owner,old_team,new_owner,new_team,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$id,$expectedLead,$actor,$oldOwner ?: null,$oldTeam ?: null,$expectedOwner,$expectedTeam ?: null,$now]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
