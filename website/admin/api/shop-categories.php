<?php
/**
 * Admin API — دسته‌بندی‌های فروشگاه
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}
Auth::requirePermission('shop.products');

$action = $_POST['action'] ?? 'save';

if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $cnt = (int)DB::fetchColumn('SELECT COUNT(*) FROM products WHERE category_id = ?', [$id]);
    if ($cnt > 0) {
        // محصولاتی که دارند: به «بدون دسته» منتقل می‌شوند
        DB::execute('UPDATE products SET category_id = NULL WHERE category_id = ?', [$id]);
    }
    DB::execute('DELETE FROM product_categories WHERE id = ?', [$id]);
    sts_json(200, ['success' => true]);
}

// save
$nameFa = trim((string)($_POST['name_fa'] ?? ''));
if (mb_strlen($nameFa) < 2) sts_json(422, ['success' => false, 'error' => 'نام دسته را وارد کنید.']);

$slug = trim(preg_replace('/[^a-z0-9\-_]/', '-', mb_strtolower((string)($_POST['name_en'] ?? ''), 'UTF-8')), '-');
if ($slug === '') $slug = 'cat-' . substr(sts_random(8), 0, 6);

$data = [
    'name_fa' => $nameFa,
    'name_en' => trim((string)($_POST['name_en'] ?? '')),
    'slug'    => $slug,
    'icon'    => trim((string)($_POST['icon'] ?? '📦')) ?: '📦',
    'is_active' => (int)($_POST['is_active'] ?? 1),
    'display_order' => (int)($_POST['display_order'] ?? 0),
];

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    DB::update('product_categories', $data, 'id = :id', ['id' => $id]);
} else {
    DB::insert('product_categories', $data);
}
sts_json(200, ['success' => true]);
