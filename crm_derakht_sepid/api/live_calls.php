<?php
/**
 * api/live_calls.php — رویدادهای زنگِ همین کاربر (Polling صفحه‌ای)
 * بدون لاگین = 401؛ بدون داخلی ثبت‌شده = enabled:false
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/screenpop.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['enabled' => false, 'error' => 'unauthorized']); exit; }

ensure_screenpop_schema($pdo);
$me = (int)($_SESSION['user_id'] ?? 0);

$ext = '';
try {
    $st = $pdo->prepare("SELECT telefonchy_ext FROM users WHERE id = ?");
    $st->execute([$me]);
    $ext = trim((string)$st->fetchColumn());
} catch (Throwable $e) {}

if ($ext === '') {
    echo json_encode(['enabled' => false, 'reason' => 'no_ext'], JSON_UNESCAPED_UNICODE);
    exit;
}

$events = screenpop_live_events_for_user($pdo, $me, 90);

// خلاصهٔ سریع تماس‌های مشتری (تعداد تماس‌ها و آخرین وضعیت از CDR) فقط برای همین مشتریان
foreach ($events as &$e) {
    if ($e['customer_id']) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) AS n, MAX(COALESCE(created_at_tel, created_at)) AS last_at FROM phone_calls WHERE customer_id = ?");
            if ($st) { $st->execute([$e['customer_id']]); $r = $st->fetch(PDO::FETCH_ASSOC); $e['customer_calls'] = (int)($r['n'] ?? 0); $e['customer_last_call'] = $r['last_at'] ?? null; }
        } catch (Throwable $e2) { $e['customer_calls'] = 0; $e['customer_last_call'] = null; }
    }
}
unset($e);

echo json_encode(['enabled' => true, 'ext' => $ext, 'events' => $events], JSON_UNESCAPED_UNICODE);
