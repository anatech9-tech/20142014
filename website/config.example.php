<?php
/**
 * درخت سپید تجارت پارس — Main Configuration
 * نمونهٔ بدون اطلاعات محرمانه؛ از آن فقط برای تنظیم دستی config.php روی هاست استفاده کنید.
 */

declare(strict_types=1);

// === Database ===
define('DB_HOST', getenv('DB_HOST') ?: 'CHANGE_ME');
define('DB_NAME', getenv('DB_NAME') ?: 'CHANGE_ME');                // ✅ نام کامل دیتابیس (wttpir_sepid)
define('DB_USER', getenv('DB_USER') ?: 'CHANGE_ME');             // ✅ نام کامل کاربر
define('DB_PASS', getenv('DB_PASS') ?: 'CHANGE_ME');        // ⚠️ رمز دیتابیس رو اینجا بذار
define('DB_CHARSET', 'utf8mb4');

// === Site Identity ===
define('SITE_NAME', 'درخت سپید تجارت پارس');
define('SITE_NAME_EN', 'Sepid Trade Pars');
define('SITE_URL', 'https://wttp.ir');             // دامنه اصلی هاستت
define('SITE_EMAIL', 'info@wttp.ir');              // ایمیل اصلی (بعداً در پنل عوض کن)
define('SITE_PHONE', '+98 21 0000 0000');
define('SITE_LOGO', '/logo.webp');
define('SITE_FAVICON', '/favicon.ico');

// === Default Language ===
define('DEFAULT_LANG', 'fa');
define('AVAILABLE_LANGS', ['fa', 'en']);

// === Security ===
define('SESSION_NAME', 'STPSID');
define('SESSION_LIFETIME', 86400 * 7);     // 7 روز
define('CSRF_TOKEN_LIFETIME', 3600);       // 1 ساعت
define('RATE_LIMIT_LOGIN_ATTEMPTS', 5);    // 5 تلاش
define('RATE_LIMIT_LOGIN_WINDOW', 600);    // در 10 دقیقه
define('PASSWORD_MIN_LENGTH', 10);

// === File Paths ===
define('BASE_PATH', __DIR__);
define('ADMIN_PATH', BASE_PATH . '/admin');
define('UPLOAD_PATH', BASE_PATH . '/uploads');
define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5MB

// === File Upload allowed types ===
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx']);

// === Error Reporting (در production: false) ===
define('DEBUG_MODE', false);
define('LOG_ERRORS', true);
define('LOG_PATH', BASE_PATH . '/logs');

// === Timezone ===
date_default_timezone_set('Asia/Tehran');

// === Session Config ===
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly'  => true,
        'samesite'  => 'Lax',
    ]);
    session_start();
}

// === Error Handling ===
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

if (LOG_ERRORS && !is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0755, true);
}

// === Autoloader (case-insensitive: Linux filesystem is case-sensitive) ===
// نگاشت کامل فایل‌ها در هر درخواست ساخته می‌شود تا class DB → db.php یا
// Shop\Customers → shop/Customers.php هر دو پیدا شوند.
spl_autoload_register(function (string $class): void {
    static $map = null;
    if ($map === null) {
        $map   = [];
        $roots = [
            BASE_PATH . '/includes/',
            BASE_PATH . '/admin/includes/',
        ];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $rel = substr((string)$file, strlen($root), -4); // بدون .php
                    $map[strtolower(str_replace('\\', '/', $rel))] = (string)$file;
                }
            }
        }
    }
    $key = strtolower(str_replace('\\', '/', $class));
    if (isset($map[$key])) {
        require_once $map[$key];
    }
});

// === Helper: secure random token ===
function sts_random(int $length = 32): string {
    return bin2hex(random_bytes(max(1, intdiv($length, 2))));
}

// === Helper: sanitize input ===
function sts_clean(string $value, int $maxLen = 5000): string {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    return mb_substr($value, 0, $maxLen);
}

// === Helper: check if request is AJAX ===
function sts_is_ajax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// === Helper: JSON response ===
function sts_json(int $status, array $data): void {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// === Helper: redirect ===
function sts_redirect(string $url, int $status = 302): void {
    if (!headers_sent()) {
        header('Location: ' . $url, true, $status);
    } else {
        echo '<script>window.location="' . htmlspecialchars($url, ENT_QUOTES) . '";</script>';
    }
    exit;
}

// === Helper: hash IP for privacy (GDPR-friendly analytics) ===
function sts_hash_ip(string $ip): string {
    return substr(hash('sha256', $ip . SITE_NAME), 0, 32);
}

// Prevent any accidental output if this file is accessed directly
__halt_compiler();
