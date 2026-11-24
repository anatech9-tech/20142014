<?php
/**
 * درخت سپید تجارت پارس — Secure Contact Form Handler
 *
 * ویژگی‌های امنیتی:
 *   - CSRF token validation
 *   - Honeypot field for bots
 *   - Rate limiting (session-based)
 *   - Input sanitization & validation
 *   - XSS-safe output
 *   - Email header injection prevention
 *
 * قبل از استفاده در production:
 *   1) $mailTo را به ایمیل واقعی شرکت تغییر دهید
 *   2) اگر هاست SMTP دارد، تنظیمات SMTP را در پایین فعال کنید
 *   3) مقدار session_name() را اگر لازم است عوض کنید
 */

declare(strict_types=1);

// === تنظیمات ایمیل — این مقادیر را حتماً تغییر دهید ===
const MAIL_TO         = 'info@sepidtrade.com';      // ایمیل مقصد
const MAIL_FROM       = 'noreply@sepidtrade.com';    // ایمیل فرستنده (همان دامنه)
const MAIL_SUBJECT_PREFIX = '[وبسایت درخت سپید] ';
const SITE_NAME       = 'درخت سپید تجارت پارس';

// === تنظیمات Rate Limiting ===
const RATE_LIMIT_WINDOW = 300;  // ۵ دقیقه
const RATE_LIMIT_MAX    = 3;     // حداکثر ۳ پیام در هر بازه

// === تنظیمات Session ===
if (session_status() === PHP_SESSION_NONE) {
    session_name('STSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// === توابع کمکی ===
function json_response(int $status, array $data): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool {
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function clean_input(string $value, int $maxLen = 5000): string {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    return mb_substr($value, 0, $maxLen);
}

function has_header_injection(string $value): bool {
    // Prevent email header injection (\r\n)
    return (bool) preg_match('/[\r\n]/', $value);
}

// === Rate Limiting ===
function check_rate_limit(): bool {
    $now = time();
    $history = $_SESSION['contact_history'] ?? [];

    // پاک کردن رکوردهای قدیمی
    $history = array_filter($history, fn($t) => ($now - $t) < RATE_LIMIT_WINDOW);

    if (count($history) >= RATE_LIMIT_MAX) {
        return false;
    }

    $history[] = $now;
    $_SESSION['contact_history'] = $history;
    return true;
}

// === Endpoint: دریافت CSRF token (برای لود اولیه فرم) ===
if (($_GET['action'] ?? '') === 'csrf') {
    json_response(200, [
        'token'      => csrf_token(),
        'site_name'  => SITE_NAME,
    ]);
}

// === فقط POST ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, [
        'status'  => 'error',
        'message' => 'فقط درخواست POST پذیرفته می‌شود.',
    ]);
}

// === CSRF Validation ===
$csrfInput = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!verify_csrf($csrfInput)) {
    json_response(403, [
        'status'  => 'error',
        'message' => 'توکن امنیتی نامعتبر است. لطفاً صفحه را refresh کنید.',
    ]);
}

// === Honeypot (فیلد مخفی - اگه پر شده یعنی ربات) ===
if (!empty($_POST['website'])) {
    // بی‌صدا موفقیت برگردان تا ربات فکر کنه ارسال شده
    json_response(200, ['status' => 'ok']);
}

// === Rate Limiting ===
if (!check_rate_limit()) {
    json_response(429, [
        'status'  => 'error',
        'message' => 'تعداد درخواست‌های شما زیاد است. لطفاً چند دقیقه بعد تلاش کنید.',
    ]);
}

// === دریافت و اعتبارسنجی ===
$name    = clean_input($_POST['name']    ?? '', 100);
$email   = clean_input($_POST['email']   ?? '', 200);
$phone   = clean_input($_POST['phone']   ?? '', 50);
$subject = clean_input($_POST['subject'] ?? '', 200);
$message = clean_input($_POST['message'] ?? '', 5000);

$errors = [];

if ($name === '' || mb_strlen($name) < 2) {
    $errors['name'] = 'نام باید حداقل ۲ کاراکتر باشد.';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'ایمیل معتبر نیست.';
}

if ($message === '' || mb_strlen($message) < 10) {
    $errors['message'] = 'پیام باید حداقل ۱۰ کاراکتر باشد.';
}

if (mb_strlen($message) > 5000) {
    $errors['message'] = 'پیام نباید بیش از ۵۰۰۰ کاراکتر باشد.';
}

if ($phone !== '' && !preg_match('/^[\d\s\+\-\(\)]{5,30}$/', $phone)) {
    $errors['phone'] = 'شماره تلفن معتبر نیست.';
}

// Email header injection check
if (has_header_injection($name) || has_header_injection($email) || has_header_injection($subject)) {
    json_response(400, [
        'status'  => 'error',
        'message' => 'ورودی نامعتبر شناسایی شد.',
    ]);
}

if (!empty($errors)) {
    json_response(400, [
        'status' => 'error',
        'errors' => $errors,
    ]);
}

// === ساخت محتوای ایمیل ===
$mailSubject = MAIL_SUBJECT_PREFIX;
if ($subject !== '') {
    $mailSubject .= $subject;
} else {
    $mailSubject .= 'پیام جدید از ' . $name;
}

$mailBody = "پیام جدید از وبسایت " . SITE_NAME . "\n"
          . "تاریخ: " . date('Y-m-d H:i:s') . "\n"
          . "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n"
          . str_repeat('─', 50) . "\n\n"
          . "نام: $name\n"
          . "ایمیل: $email\n"
          . "تلفن: " . ($phone ?: '-') . "\n\n"
          . "پیام:\n$message\n";

$headers = [
    'From: ' . SITE_NAME . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: PHP/' . phpversion(),
    'X-Form-Type: contact',
];

// === ارسال ایمیل ===
$sent = @mail(MAIL_TO, $mailSubject, $mailBody, implode("\r\n", $headers));

if ($sent) {
    json_response(200, [
        'status'  => 'ok',
        'message' => 'پیام شما با موفقیت ارسال شد. به‌زودی پاسخ می‌دهیم.',
    ]);
}

// === اگر mail() شکست خورد، لاگ کن (اختیاری) ===
error_log(sprintf(
    'Contact form failed: name=%s email=%s ip=%s',
    $name,
    $email,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
));

json_response(500, [
    'status'  => 'error',
    'message' => 'متأسفانه خطایی در ارسال رخ داد. لطفاً مستقیماً به ' . MAIL_TO . ' ایمیل بزنید.',
]);
