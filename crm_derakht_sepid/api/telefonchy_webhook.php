<?php
/**
 * وب‌هوک ورودی تلفنچی (CDR / ایونت تماس) — فاز «CRM فروش تلفنی + هوش مصنوعی»
 *
 * کارکرد:
 *   • GET  → پاسخ زنده‌بودن سرویس (برای تست پنل تلفنچی)
 *   • POST → خواندن بدنهٔ خام، ذخیرهٔ ایونت در جدول telefonchy_events (ساخت خودکار)
 *
 * امنیت:
 *   • اگر در config.local.php ثابت TELEFONCHY_WEBHOOK_KEY تعریف شود، فقط درخواست‌هایی
 *     که ?key=<همان کلید> داشته باشند پذیرفته می‌شوند (هر چیز دیگر = 403).
 *   • اگر تعریف نشده باشد، درخواست‌ها با برچسب source=nokey ذخیره می‌شوند
 *     (فقط برای تست اولیه؛ بعد از تنظیم کلید، سایت را امن کنید).
 *   • هیچ نشست/لاگینی وجود ندارد؛ فایل هیچ خروجی جز JSON ندارد.
 *
 * یادآوری راه‌اندازی:
 *   ۱) یک خط به config.local.php اضافه کنید:
 *        define('TELEFONCHY_WEBHOOK_KEY', '<کلید تصادفی قوی>');
 *   ۲) آدرس "https://<دامنهٔ-شما>/<مسیر-پنل>/api/telefonchy_webhook.php?key=<همان کلید>"
 *      را در پنل تلفنچی به‌عنوان «آدرس فراخوانی» ثبت کنید.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

// ── زنده‌بودن سرویس (GET) ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'telefonchy-webhook', 'time' => time()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── دروازهٔ کلید ───────────────────────────────────────────────────────
$expectedKey = defined('TELEFONCHY_WEBHOOK_KEY') ? (string)TELEFONCHY_WEBHOOK_KEY : '';
$source = 'nokey';
if ($expectedKey !== '') {
    $given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '');
    if (!hash_equals($expectedKey, $given)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'invalid_key'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $source = 'webhook';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── خواندن بدنهٔ خام (سقف 1 مگابایت) ───────────────────────────────────
$raw = file_get_contents('php://input', false, null, 0, 1024 * 1024 + 1);
if ($raw === false) { $raw = ''; }
if (strlen($raw) > 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload_too_large'], JSON_UNESCAPED_UNICODE);
    exit;
}
$json = json_decode($raw, true);
$data = is_array($json) ? $json : [];

// ── استخراج حدس-محورِ فیلدهای پرتکرار CDR (هر چی نبود NULL می‌شود) ──────
$pick = function (array $d, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $d) && $d[$k] !== null && $d[$k] !== '') return $d[$k];
    }
    return null;
};
$findKey = function (array $d, array $candidates) use (&$findKey) {
    // جستجوی عمیق یک‌سطحی/دو‌سطحی برای کلیدهای متداول
    foreach ($candidates as $c) {
        if (array_key_exists($c, $d)) return $d[$c];
    }
    foreach ($d as $v) {
        if (is_array($v)) {
            $hit = $findKey($v, $candidates);
            if ($hit !== null) return $hit;
        }
    }
    return null;
};

$entity = $data['data'] ?? $data['event'] ?? $data['cdr'] ?? $data;
if (!is_array($entity)) $entity = $data;

$cuid        = $findKey($entity, ['cuid', 'cu_id', 'unique_id', 'uid']);
$eventType   = $findKey($entity, ['event', 'event_type', 'type', 'status']);
$direction   = $findKey($entity, ['direction', 'call_type', 'calltype']);
$fromNumber  = $findKey($entity, ['from', 'caller', 'caller_number', 'callerid', 'src', 'source']);
$toNumber    = $findKey($entity, ['to', 'callee', 'callee_number', 'destination', 'dst', 'call_to']);
$extension   = $findKey($entity, ['extension', 'ext', 'internal', 'interna']);
$duration    = $findKey($entity, ['duration', 'billsec', 'bill_sec', 'call_duration', 'seconds']);
$callStatus  = $findKey($entity, ['disposition', 'call_status', 'state', 'hangup_cause']);

// ── ذخیره در دیتابیس ──────────────────────────────────────────────────
$storedId = null;
$dbError  = null;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS telefonchy_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(48) NULL,
        cuid VARCHAR(96) NULL,
        direction VARCHAR(16) NULL,
        from_number VARCHAR(48) NULL,
        to_number VARCHAR(48) NULL,
        extension VARCHAR(24) NULL,
        duration_sec INT NULL,
        call_status VARCHAR(32) NULL,
        payload MEDIUMTEXT NOT NULL,
        source VARCHAR(16) NOT NULL DEFAULT 'webhook',
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_cuid (cuid),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $st = $pdo->prepare("INSERT INTO telefonchy_events
        (event_type, cuid, direction, from_number, to_number, extension, duration_sec, call_status, payload, source, ip)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $eventType !== null ? substr((string)$eventType, 0, 46) : null,
        $cuid !== null ? substr((string)$cuid, 0, 94) : null,
        $direction !== null ? substr((string)$direction, 0, 14) : null,
        $fromNumber !== null ? substr((string)$fromNumber, 0, 46) : null,
        $toNumber !== null ? substr((string)$toNumber, 0, 46) : null,
        $extension !== null ? substr((string)$extension, 0, 22) : null,
        is_numeric($duration) ? (int)$duration : null,
        $callStatus !== null ? substr((string)$callStatus, 0, 30) : null,
        $raw === '' ? '(empty)' : $raw,
        $source,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
    $storedId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

// ── فالبک: ذخیرهٔ فایل در پوشهٔ backups (که به‌صورت عمومی بسته است) ──────
if ($storedId === null) {
    try {
        if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
        @file_put_contents(BACKUP_DIR . 'telefonchy_webhook.log',
            date('Y-m-d H:i:s') . "\t" . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\t" . str_replace("\n", ' ', $raw) . "\n",
            FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {}
}

if ($dbError !== null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'stored' => false, 'error' => $dbError], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'stored' => true, 'id' => $storedId], JSON_UNESCAPED_UNICODE);
