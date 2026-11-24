<?php
/**
 * File upload API
 * POST /api/upload.php - multipart/form-data with field 'file'
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}

if (empty($_FILES['file'])) {
    sts_json(400, ['success' => false, 'error' => 'فایلی ارسال نشده.']);
}

$file = $_FILES['file'];
$allowed = ALLOWED_IMAGE_TYPES;
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed, true)) {
    sts_json(400, ['success' => false, 'error' => 'فرمت فایل مجاز نیست.']);
}

if ($file['size'] > UPLOAD_MAX_SIZE) {
    sts_json(400, ['success' => false, 'error' => 'حجم فایل بیش از حد مجاز است.']);
}

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    sts_json(400, ['success' => false, 'error' => 'آپلود فایل ناموفق بود.']);
}
$validMime = ['jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'webp'=>'image/webp', 'gif'=>'image/gif'];
$mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : false;
if (!isset($validMime[$ext]) || $mime !== $validMime[$ext] || !@getimagesize($file['tmp_name'])) {
    sts_json(400, ['success' => false, 'error' => 'محتوای تصویر معتبر نیست.']);
}

$subdir = $_POST['subdir'] ?? 'general';
$subdir = preg_replace('/[^a-z0-9_-]/', '', $subdir) ?: 'general';

$uploadDir = UPLOAD_PATH . '/' . $subdir;
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$filename = sts_random(16) . '.' . $ext;
$dest = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    sts_json(500, ['success' => false, 'error' => 'خطا در ذخیره فایل.']);
}

$url = '/uploads/' . $subdir . '/' . $filename;
sts_json(200, ['success' => true, 'url' => $url, 'filename' => $filename]);
