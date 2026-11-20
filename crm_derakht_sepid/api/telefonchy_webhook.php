<?php
/**
 * وب‌هوک ورودی تلفنچی (CDR / ایونت تماس) — فاز «CRM فروش تلفنی + هوش مصنوعی» / Screen Pop
 *
 * کارکرد:
 *   • GET  → پاسخ زنده‌بودن سرویس (برای تست پنل تلفنچی)
 *   • POST → ذخیرهٔ خام ایونت در جدول telefonchy_events (تشخیص سرتاسری — دیباگ)
 *          + ذخیرهٔ ایونت ringing/hangup در live_call_events برای پاپ‌آپ شناسایی (Screen Pop)
 *
 * امنیت (دو راه پشتیبانی می‌شود؛ یکی کافی است):
 *   الف) توکن وب‌سرویس: ثابت TELEFONCHY_WEBSERVICE_TOKEN در config.local.php.
 *        تلفنچی طبق مستندات رسمی («دریافت ایونت تماس») توکن را در ریشهٔ بدنه با کلید
 *        webservice_token می‌فرستد. در این حالت، توکن با hash_equals مقایسه می‌شود.
 *   ب) کلید وب‌هوک (Legacy / سازگاری): ثابت TELEFONCHY_WEBHOOK_KEY و ?key=<key> در آدرس ثبت‌شده.
 *   • اگر هیچ‌کدام تعریف نشده باشد، درخواست‌ها با برچسب source=nokey ذخیره می‌شوند
 *     (فقط برای تست اولیه؛ پس از تنظیم یکی، سایت را امن کنید).
 *
 * راه‌اندازی (روی هاست جدید):
 *   ۱) config.local.php:  define('TELEFONCHY_WEBSERVICE_TOKEN', '<همان توکنی که در پنل نمایش داده می‌شود>');
 *   ۲) آدرس «تنظیمات وب‌سرویس → آدرس فراخوانی / Events Hook» در پنل تلفنچی را برابر
 *      https://wttp.ir/CRM/api/telefonchy_webhook.php بگذارید.
 *   ۳) پنل را ذخیره و با یک تماس داخلی↔داخلی تست کنید؛ جوابی غیر از 200 را قبول نکنید.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/screenpop.php';

header('Content-Type: application/json; charset=utf-8');

// ── زنده‌بودن سرویس (GET) ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode([
        'ok' => true,
        'service' => 'telefonchy-webhook',
        'time' => time(),
        'config' => [
            'webservice_token_configured' => tel_configured(),
            'webhook_key_configured' => defined('TELEFONCHY_WEBHOOK_KEY') && trim((string)TELEFONCHY_WEBHOOK_KEY) !== '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
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

// ── احراز هویت ─────────────────────────────────────────────────────────
$expectedToken = trim((string)(defined('TELEFONCHY_WEBSERVICE_TOKEN') ? TELEFONCHY_WEBSERVICE_TOKEN : ''));
$expectedKey   = trim((string)(defined('TELEFONCHY_WEBHOOK_KEY') ? TELEFONCHY_WEBHOOK_KEY : ''));
$source = 'nokey';
$authorized = false;

if ($expectedToken !== '') {
    $given = is_string($data['webservice_token'] ?? null) ? (string)$data['webservice_token'] : '';
    if ($given !== '' && hash_equals($expectedToken, $given)) { $authorized = true; $source = 'webservice'; }
}
if (!$authorized && $expectedKey !== '') {
    $given = (string)($_GET['key'] ?? $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '');
    if ($given !== '' && hash_equals($expectedKey, $given)) { $authorized = true; $source = 'webhook'; }
}
if (!$authorized && $expectedToken === '' && $expectedKey === '') {
    $authorized = true; // هیچ احراز هویت پیکربندی نشده — ذخیره با برچسب nokey (فقط تست اولیه)
}
if (!$authorized) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ذخیرهٔ خام ایونت در telefonchy_events (دیباگ / تاریخچه) ─────────────
$pick = function (array $d, array $keys) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $d) && $d[$k] !== null && $d[$k] !== '') return $d[$k];
    }
    return null;
};
$findKey = function (array $d, array $candidates) use (&$findKey) {
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

$entity = $data; // طبق مستندات رسمی، فیلدها در ریشه‌اند؛ با این حال برای CDR عمومی هم حدس می‌زنیم
$cuid       = $findKey($entity, ['cuid', 'unique_id', 'cu_id', 'uid']);
$eventType  = $pick($entity, ['event', 'type', 'event_type', 'status']);
$direction  = ($eventType === 'incoming' || $eventType === 'outgoing') ? $eventType
              : $findKey($entity, ['direction', 'call_type', 'calltype']);
$fromNumber = $pick($entity, ['src', 'from', 'caller', 'caller_number', 'callerid', 'source']);
$toNumber   = $pick($entity, ['dest', 'to', 'callee', 'callee_number', 'destination', 'dst', 'call_to']);
$extension  = $findKey($entity, ['extension', 'ext', 'internal', 'interna']);
$duration   = $findKey($entity, ['duration', 'billsec', 'bill_sec', 'call_duration', 'seconds']);
$callStatus = $findKey($entity, ['hangup_cause', 'disposition', 'call_status', 'state']);

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
} catch (Throwable $e) {
    // هر خطا در لاگ خام مانع پاسخ ۲۰۰ نشود (طبق مستندات پاسخ سریع الزامی است)
}

// ── پردازش ایونت برای Screen Pop / Live Calls ───────────────────────────
$liveResult = ['stored' => false, 'event' => null];
try {
    if (is_array($data)) {
        $liveResult = screenpop_store_event($pdo, $data, $raw, $_SERVER['REMOTE_ADDR'] ?? null);
    }
} catch (Throwable $e) { /* لاگ خام بالا کافی است */ }

http_response_code(200);
echo json_encode(['ok' => true, 'stored' => true, 'screenpop' => $liveResult], JSON_UNESCAPED_UNICODE);
