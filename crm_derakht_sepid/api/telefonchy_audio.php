<?php
/**
 * پراکسی صوت مکالمهٔ تلفنچی (فقط مدیر کل)
 * api/telefonchy_audio.php?cuid=XXX[&action=stream|archive]
 *   stream  → پخش مستقیم (Content-Type: audio/mpeg)
 *   archive → ذخیره در uploads/calls/<cuid>.mp3 (اگر موجود نباشد) و در انتها دانلود فایل
 * توکن وب‌سرویس فقط از config.local.php خوانده می‌شود؛ هرگز در خروجی نمایش داده نمی‌شود.
 */
require_once __DIR__ . '/../config.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'لطفاً ابتدا وارد شوید.';
    exit;
}
if (!is_strict_admin()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'دسترسی مجاز نیست.';
    exit;
}

require_once __DIR__ . '/../services/telefonchy.php';
ensure_telefonchy_schema($pdo);

$cuid   = trim((string)($_GET['cuid'] ?? ''));
$action = (($_GET['action'] ?? 'stream') === 'archive') ? 'archive' : 'stream';

if (!preg_match('/^[A-Za-z0-9._\-]{1,96}$/', $cuid)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'شناسهٔ تماس نامعتبر است.';
    exit;
}

if (!tel_configured()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'توکن وب‌سرویس تلفنچی روی سرور تنظیم نشده است.';
    exit;
}

$st = $pdo->prepare("SELECT file_id FROM phone_calls WHERE cuid = ? LIMIT 1");
$st->execute([$cuid]);
$fileId = $st->fetchColumn();
if (!$fileId) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'فایل صوتی برای این تماس ثبت نشده است (file_id خالی).';
    exit;
}

$safeCuid = preg_replace('/[^A-Za-z0-9._\-]/', '_', $cuid);

if ($action === 'archive') {
    $dest = tel_archive_path($cuid);
    if (!is_file($dest)) {
        $r = tel_download_audio((string)$fileId, $cuid, $dest);
        if (empty($r['ok'])) {
            http_response_code(502);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'خطا در دریافت صوت از تلفنچی.' . (isset($r['http']) ? ' (HTTP ' . (int)$r['http'] . ')' : '');
            exit;
        }
    }
    $path = $dest;
    $mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) ?: 'audio/mpeg') : 'audio/mpeg';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="call-' . $safeCuid . '.mp3"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

// stream: دانلود به فایل موقت سپس پخش (مدیریت ساده و درستِ خطا)
$tmp = tempnam(sys_get_temp_dir(), 'telv_');
$r = tel_download_audio((string)$fileId, $cuid, $tmp);
if (empty($r['ok'])) {
    @unlink($tmp);
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'خطا در دریافت صوت از تلفنچی.' . (isset($r['http']) ? ' (HTTP ' . (int)$r['http'] . ')' : '');
    exit;
}
$mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) ?: 'audio/mpeg') : 'audio/mpeg';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="call-' . $safeCuid . '.mp3"');
header('Content-Length: ' . filesize($tmp));
header('X-Content-Type-Options: nosniff');
readfile($tmp);
@unlink($tmp);
exit;
