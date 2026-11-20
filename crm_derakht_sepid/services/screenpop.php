<?php
/**
 * ══ Screen Pop / Click-to-Call :: سرویس مشترک تلفنچی ══
 *
 * معماری:
 *   ۱) وب‌هوک تلفنچی (api/telefonchy_webhook.php) با رویداد ringing/hangup فرم پهن
 *      هماهنگِ مستندات رسمی (فیلدهای ریشه‌ای، webservice_token) را دریافت می‌کند.
 *   ۲) این فایل ایونت را ذخیره و شماره را به مشتری/سرنخِ CRM تطبیق می‌دهد
 *      (۱۰ رقمی آخر — همان تل‌فون_tail در services/telefonchy.php).
 *   ۳) api/live_calls.php (نیازمند لاگین) رویدادهای «زنگ» همین داخلی را
 *      برای صفحه‌های باز CRM برمی‌گرداند تا پاپ‌آپ شناسایی نشان داده شود.
 *   ۴) api/click_to_call.php با وب‌سرویس smartcall تماس خروجی از داخلی کارشناس
 *      به شمارهٔ مشتری می‌سازد.
 *
 * جدول live_call_events در اولین اجرا خودترمیم ساخته می‌شود.
 */

require_once __DIR__ . '/telefonchy.php'; // tel_phone_tail / tel_match_customer / tel_service_id / tel_to_int

function ensure_screenpop_schema(PDO $pdo): bool {
    try {
        // ── ستون داخلی کارشناس روی users (idempotent) ──
        $col = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'telefonchy_ext'");
        $col->execute();
        if ((int)$col->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN telefonchy_ext VARCHAR(32) NULL AFTER team_id");
        }
        // ── جدول ایونت‌های زنده ──
        $pdo->exec("CREATE TABLE IF NOT EXISTS live_call_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            cuid VARCHAR(96) NULL,
            event VARCHAR(16) NOT NULL,
            direction VARCHAR(12) NULL,
            src VARCHAR(48) NULL,
            dest VARCHAR(48) NULL,
            trunk VARCHAR(64) NULL,
            agent_user_id INT NULL,
            customer_id INT NULL,
            lead_id INT NULL,
            raw MEDIUMTEXT NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_live_user_time (agent_user_id, created_at),
            KEY idx_live_cuid (cuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** داخلی عاملِ تماس: در ورودی «dest» است، در خروجی معمولاً «src» است؛
 *  اما چون در برخی پیکربندی‌ها src همان ترانک است، اگر داخلی در یکی از دو فیلد
 *  بودنده کاربر CRM ثبت شده باشد، همان انتخاب می‌شود. */
function screenpop_match_agent(PDO $pdo, ?string $direction, ?string $src, ?string $dest): ?array {
    $cands = [];
    $dir = strtolower((string)$direction);
    if ($dir === 'outgoing') { $cands = [$src, $dest]; }
    else { $cands = [$dest, $src]; }
    $exts = [];
    foreach ($cands as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $exts, true)) $exts[] = $c;
    }
    if (!$exts) return null;
    try {
        $in = implode(',', array_fill(0, count($exts), '?'));
        $st = $pdo->prepare("SELECT id, full_name, telefonchy_ext FROM users WHERE telefonchy_ext IN ($in) LIMIT 1");
        $st->execute($exts);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** شمارهٔ «آن‌طرف خط» (مشتری/سرنخ) */
function screenpop_customer_number(?string $direction, ?string $src, ?string $dest): ?string {
    $dir = strtolower((string)$direction);
    if ($dir === 'outgoing') return $dest ? trim((string)$dest) : null;
    return $src ? trim((string)$src) : null;
}

/** ذخیرهٔ یک ایونت ringing/hangup؛ برمی‌گرداند آرایهٔ نام‌گذاری‌شده با نتایج تطبیق */
function screenpop_store_event(PDO $pdo, array $p, string $raw, ?string $ip): array {
    ensure_screenpop_schema($pdo);
    $out = ['stored' => false, 'event' => null, 'agent' => null, 'customer' => null, 'lead' => null, 'error' => null];
    try {
        $event = strtolower(trim((string)($p['event'] ?? '')));
        $dir   = strtolower(trim((string)($p['type'] ?? $p['direction'] ?? '')));
        $src   = trim((string)($p['src'] ?? ''));
        $dest  = trim((string)($p['dest'] ?? ''));
        $cuid  = substr(trim((string)($p['cuid'] ?? $p['unique_id'] ?? '')), 0, 94);
        $trunk = substr(trim((string)($p['trunk_number'] ?? $p['trunk'] ?? '')), 0, 62);
        if (!in_array($event, ['ringing', 'hangup'], true)) $event = in_array($event, ['incoming', 'outgoing'], true) ? 'ringing' : 'hangup';
        if (!in_array($dir, ['incoming', 'outgoing'], true)) $dir = null;

        $agent = screenpop_match_agent($pdo, $dir, $src, $dest);
        $number = screenpop_customer_number($dir, $src, $dest);
        $match = $number !== null ? tel_match_customer($pdo, $number) : ['customer_id' => null, 'lead_id' => null];

        $st = $pdo->prepare("INSERT INTO live_call_events (cuid, event, direction, src, dest, trunk, agent_user_id, customer_id, lead_id, raw, ip)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $cuid !== '' ? $cuid : null,
            $event, $dir,
            substr($src, 0, 46), substr($dest, 0, 46), $trunk,
            $agent['id'] ?? null,
            $match['customer_id'] ?? null,
            $match['lead_id'] ?? null,
            $raw, $ip,
        ]);
        $out['stored'] = true;
        $out['event'] = $event;
        if ($agent) $out['agent'] = ['id' => (int)$agent['id'], 'name' => $agent['full_name'], 'ext' => $agent['telefonchy_ext']];
        $out['customer'] = $match['customer_id'] ?? null;
        $out['lead'] = $match['lead_id'] ?? null;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/** پنجرهٔ پاپ‌آپِ زنده برای داخلی همین کاربر؛ فقط آخرین رویداد هر cuid و فقط «ringing» */
function screenpop_live_events_for_user(PDO $pdo, int $userId, int $windowSec = 90): array {
    ensure_screenpop_schema($pdo);
    $events = [];
    try {
        $st = $pdo->prepare(
            "SELECT l.* FROM live_call_events l
             INNER JOIN (SELECT cuid, MAX(id) AS mid FROM live_call_events
                        WHERE agent_user_id = ? AND created_at >= NOW() - INTERVAL ? SECOND
                        GROUP BY cuid) lm ON lm.mid = l.id
             WHERE l.event = 'ringing' ORDER BY l.id DESC LIMIT 5");
        $st->execute([$userId, max(10, $windowSec)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $events[] = [
                'id'         => (int)$r['id'],
                'cuid'       => $r['cuid'],
                'direction'  => $r['direction'],
                'number'     => $r['direction'] === 'outgoing' ? $r['dest'] : $r['src'],
                'customer_id' => $r['customer_id'] ? (int)$r['customer_id'] : null,
                'lead_id'    => $r['lead_id'] ? (int)$r['lead_id'] : null,
                'created_at' => $r['created_at'],
            ];
        }
        if ($events) {
            $custIds = array_values(array_unique(array_column(array_filter($events, fn($e) => $e['customer_id']), 'customer_id')));
            $leadIds = array_values(array_unique(array_column(array_filter($events, fn($e) => $e['lead_id']), 'lead_id')));
            $custMap = []; $leadMap = [];
            if ($custIds) {
                $in = implode(',', array_fill(0, count($custIds), '?'));
                $cst = $pdo->prepare("SELECT id, name, mobile, phone, owner_user_id FROM crm_customers WHERE id IN ($in)");
                $cst->execute($custIds);
                foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $c) $custMap[(int)$c['id']] = $c;
            }
            if ($leadIds) {
                $in = implode(',', array_fill(0, count($leadIds), '?'));
                $lst = $pdo->prepare("SELECT id, name, company, mobile, phone, owner_user_id FROM crm_leads WHERE id IN ($in)");
                $lst->execute($leadIds);
                foreach ($lst->fetchAll(PDO::FETCH_ASSOC) as $l) $leadMap[(int)$l['id']] = $l;
            }
            foreach ($events as &$e) {
                $e['customer'] = $e['customer_id'] && isset($custMap[$e['customer_id']]) ? [
                    'name' => $custMap[$e['customer_id']]['name'],
                    'phone' => $custMap[$e['customer_id']]['mobile'] ?: $custMap[$e['customer_id']]['phone'],
                    'url'  => 'index.php?page=crm_customers&view=' . $e['customer_id'],
                ] : null;
                $e['lead'] = $e['lead_id'] && isset($leadMap[$e['lead_id']]) ? [
                    'name' => trim(($leadMap[$e['lead_id']]['name'] ?? '') . ' ' . ($leadMap[$e['lead_id']]['company'] ?? '')),
                    'phone' => $leadMap[$e['lead_id']]['mobile'] ?: $leadMap[$e['lead_id']]['phone'],
                    'url'  => 'index.php?page=crm_leads&edit=' . $e['lead_id'],
                ] : null;
            }
            unset($e);
        }
    } catch (Throwable $e) {}
    return $events;
}

/** ساخت لینک اسمارت‌کال (Click-to-Call) — جدا از curl تا قابل تست باشد */
function screenpop_smartcall_url(string $serviceId, string $exten, string $to): string {
    return 'https://panel.telefonchy.com/webservice/v1/smartcall?' . http_build_query([
        'service_id' => $serviceId,
        'exten'      => $exten,
        'to'         => $to,
    ]);
}
