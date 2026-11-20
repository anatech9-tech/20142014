<?php
/**
 * api/click_to_call.php — تماس خروجی از داخلی همین کاربر به شمارهٔ مشتری (smartcall تلفنچی)
 * POST JSON یا فرم: to | customer_id | lead_id
 * نیازمند لاگین + CSRF (window.__CSRF).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/screenpop.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit; }

// CSRF: هم از فرم هم از هدر X-CSRF-Token
$csrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!verify_csrf($csrf)) { http_response_code(419); echo json_encode(['ok' => false, 'error' => 'csrf']); exit; }

// بدنه خام اگر JSON آمده باشد
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode((string)$rawBody, true);
if (!is_array($jsonBody)) $jsonBody = $_POST;

ensure_screenpop_schema($pdo);
$me = (int)($_SESSION['user_id'] ?? 0);
$st = $pdo->prepare("SELECT telefonchy_ext FROM users WHERE id = ?");
$st->execute([$me]);
$ext = trim((string)$st->fetchColumn());
if ($ext === '') { echo json_encode(['ok' => false, 'error' => 'no_ext', 'message' => 'داخلی تلفنچی شما ثبت نشده است؛ از مدیر بخواهید در «پرسنل» داخلی PortSIP شما را ثبت کند.']); exit; }

// مقصد: یا مستقیم، یا از روی مشتری/سرنخ
$to = trim((string)($jsonBody['to'] ?? ''));
$name = '';
$customerId = (int)($jsonBody['customer_id'] ?? 0);
$leadId = (int)($jsonBody['lead_id'] ?? 0);
try {
    if ($to === '' && $customerId > 0) {
        $q = $pdo->prepare("SELECT name, mobile, phone FROM crm_customers WHERE id = ? AND deleted_at IS NULL");
        $q->execute([$customerId]);
        $r = $q->fetch();
        if ($r) { $name = $r['name']; $to = trim((string)($r['mobile'] ?: $r['phone'])); }
    } elseif ($to === '' && $leadId > 0) {
        $q = $pdo->prepare("SELECT name, company, mobile, phone FROM crm_leads WHERE id = ?");
        $q->execute([$leadId]);
        $r = $q->fetch();
        if ($r) { $name = trim($r['name'] . ' ' . $r['company']); $to = trim((string)($r['mobile'] ?: $r['phone'])); }
    }
} catch (Throwable $e) {}
$digits = preg_replace('/\D+/', '', normalize_digits($to));
if ($digits === '') { echo json_encode(['ok' => false, 'error' => 'no_number', 'message' => 'شمارهٔ مقصدی برای تماس یافت نشد (مشتری/سرنخ یا شماره‌اش خالی است).']); exit; }
// تلفنچی فرمت داخلی-انگلیسی می‌خواهد (۰۹… یا 98…)؛ همان ارقام خام را می‌فرستیم
if (strlen($digits) === 10 && substr($digits, 0, 1) === '9') $digits = '0' . $digits;
if (strlen($digits) === 12 && substr($digits, 0, 2) === '98') $digits = '0' . substr($digits, 2);
if (strlen($digits) === 13 && substr($digits, 0, 3) === '989') $digits = '09' . substr($digits, 3);

$serviceId = tel_service_id($pdo);
$url = screenpop_smartcall_url($serviceId, $ext, $digits);
$r = tel_http('GET', $url, null, 20);
tel_usage_log($pdo, 'click_to_call', null, $r['ok'] ? 1 : 0, (int)$r['http'], 'ext:' . $ext . ' to:' . $digits . ($customerId ? ' cust:' . $customerId : '') . ($leadId ? ' lead:' . $leadId : ''));
try {
    operations_audit($pdo, $me, 'click_to_call', $customerId ? 'customer' : ($leadId ? 'lead' : 'phone'), $customerId ?: ($leadId ?: 0), 'داخلی ' . $ext . ' → ' . $digits . ($name !== '' ? ' (' . $name . ')' : ''));
} catch (Throwable $e) {}

$respMessage = '';
if (is_array($r['json'])) {
    $respMessage = trim((string)($r['json']['message'] ?? $r['json']['status'] ?? ''));
    if (isset($r['json']['code']) || isset($r['json']['status'])) {
        $ok = (int)($r['json']['code'] ?? 200) === 200 || strtoupper((string)$r['json']['status'] ?? '') === 'OK';
        echo json_encode(['ok' => $ok, 'http' => $r['http'], 'to' => $digits, 'name' => $name, 'response' => $r['json']], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
echo json_encode(['ok' => (bool)$r['ok'], 'http' => $r['http'], 'to' => $digits, 'name' => $name, 'message' => $respMessage ?: $r['error']], JSON_UNESCAPED_UNICODE);
