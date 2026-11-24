<?php
/**
 * Admin API — محصولات فروشگاه (ذخیره/حذف)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}

$action = $_POST['action'] ?? 'save';

if ($action === 'delete') {
    Auth::requirePermission('shop.products');
    $id = (int)($_POST['id'] ?? 0);
    DB::execute('DELETE FROM products WHERE id = ?', [$id]);
    sts_json(200, ['success' => true]);
}

// ---- save ----
Auth::requirePermission('shop.products');

$id      = (int)($_POST['id'] ?? 0);
$nameFa  = trim((string)($_POST['name_fa'] ?? ''));
$nameEn  = trim((string)($_POST['name_en'] ?? ''));
$catId   = (int)($_POST['category_id'] ?? 0);
$brand   = trim((string)($_POST['brand'] ?? ''));
$short   = trim((string)($_POST['short_desc_fa'] ?? ''));
$desc    = (string)($_POST['description_fa'] ?? '');
$sku     = trim((string)($_POST['sku'] ?? ''));
$weight  = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
$stock   = max(0, (int)($_POST['stock'] ?? 0));
$isActive  = (int)($_POST['is_active'] ?? 1);
$isFeatured = (int)($_POST['is_featured'] ?? 0);
$imagesRaw = (string)($_POST['images'] ?? '[]');
$images = json_decode($imagesRaw, true);
if (!is_array($images)) $images = [];
$images = array_values(array_filter(array_map(fn($x) => is_string($x) ? $x : '', $images)));

// فقط مسیرهای امن آپلود
$images = array_values(array_filter($images, fn($u) => str_starts_with($u, '/uploads/')));

$currency = (string)($_POST['currency'] ?? 'toman') === 'usd' ? 'usd' : 'toman';
if ($currency === 'usd') {
    $priceUsd = (float)($_POST['price_usd'] ?? 0);
    if ($priceUsd <= 0) sts_json(422, ['success' => false, 'error' => 'قیمت دلار باید بزرگ‌تر از صفر باشد.']);
    $data = [
        'name_fa' => $nameFa, 'name_en' => $nameEn, 'category_id' => $catId ?: null,
        'brand' => $brand, 'short_desc_fa' => $short, 'description_fa' => $desc,
        'images' => json_encode($images, JSON_UNESCAPED_UNICODE),
        'price_toman' => null, 'price_usd' => $priceUsd, 'currency' => 'usd',
        'old_price_toman' => (int)($_POST['old_price_toman'] ?? 0) ?: null,
        'stock' => $stock, 'sku' => $sku, 'weight_kg' => $weight,
        'is_active' => $isActive, 'is_featured' => $isFeatured,
    ];
} else {
    $priceToman = (int)($_POST['price_toman'] ?? 0);
    if ($priceToman <= 0) sts_json(422, ['success' => false, 'error' => 'قیمت تومان باید بزرگ‌تر از صفر باشد.']);
    $data = [
        'name_fa' => $nameFa, 'name_en' => $nameEn, 'category_id' => $catId ?: null,
        'brand' => $brand, 'short_desc_fa' => $short, 'description_fa' => $desc,
        'images' => json_encode($images, JSON_UNESCAPED_UNICODE),
        'price_toman' => $priceToman, 'price_usd' => null, 'currency' => 'toman',
        'old_price_toman' => (int)($_POST['old_price_toman'] ?? 0) ?: null,
        'stock' => $stock, 'sku' => $sku, 'weight_kg' => $weight,
        'is_active' => $isActive, 'is_featured' => $isFeatured,
    ];
}

if (mb_strlen($nameFa) < 3) {
    sts_json(422, ['success' => false, 'error' => 'نام محصول را کامل وارد کنید.']);
}

// اسلاگ
$slugBase = $sku !== '' ? strtolower($sku) : 'p' . ($id > 0 ? $id : (time() % 100000));
$slug = preg_replace('/[^a-z0-9\-_]/', '-', mb_strtolower($nameFa, 'UTF-8'));
$slug = trim(substr($slug, 0, 80), '-');
if ($slug === '') $slug = $slugBase;
$exists = DB::fetch('SELECT id FROM products WHERE slug = ? AND id != ? LIMIT 1', [$slug, $id]);
if ($exists) $slug = $slug . '-' . substr(sts_random(6), 0, 4);

$data['slug'] = $slug;

if ($id > 0) {
    DB::update('products', $data, 'id = :id', ['id' => $id]);
    sts_json(200, ['success' => true, 'id' => $id]);
} else {
    DB::insert('products', $data);
    sts_json(200, ['success' => true, 'id' => (int)DB::pdo()->lastInsertId()]);
}
