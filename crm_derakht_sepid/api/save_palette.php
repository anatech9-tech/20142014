<?php
/** ذخیرهٔ پالت رنگی کاربر روی پروفایل (هر بار ورود اعمال می‌شود). */
require_once __DIR__ . '/../config.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}
$allowed = ['teal', 'ocean', 'orchid', 'ruby', 'amber', 'emerald'];
$palette = (string)($_POST['palette'] ?? '');
if (!in_array($palette, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_palette']);
    exit;
}
try {
    ensure_palette_schema($pdo);
    $st = $pdo->prepare("UPDATE users SET palette = ? WHERE id = ?");
    $st->execute([$palette, (int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true, 'palette' => $palette]);
} catch (Throwable $e) {
    error_log('save_palette: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
