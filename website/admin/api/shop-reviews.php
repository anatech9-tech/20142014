<?php
/**
 * Admin API — دیدگاه‌های فروشگاه
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}
Auth::requirePermission('shop.reviews');

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($action === 'toggle') {
    $active = (int)($_POST['active'] ?? 1);
    DB::execute('UPDATE product_reviews SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    sts_json(200, ['success' => true]);
}

if ($action === 'delete') {
    DB::execute('DELETE FROM product_reviews WHERE id = ?', [$id]);
    sts_json(200, ['success' => true]);
}

sts_json(400, ['success' => false, 'error' => 'عملیات نامعتبر.']);
