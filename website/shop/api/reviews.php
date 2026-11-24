<?php
/**
 * فروشگاه — API ثبت دیدگاه
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Customers.php';
require_once BASE_PATH . '/includes/shop/Products.php';

use Shop\Customers;

header('Content-Type: application/json; charset=utf-8');

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Auth::verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = Customers::user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'برای ثبت نظر باید وارد حساب کاربری شوید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$productId = (int)($_POST['product_id'] ?? 0);
$rating    = max(1, min(5, (int)($_POST['rating'] ?? 5)));
$body      = trim((string)($_POST['body'] ?? ''));

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'محصول نامعتبر.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$product = \Shop\Products::byId($productId);
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'محصول یافت نشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($body) < 10) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'متن نظر باید حداقل ۱۰ کاراکتر باشد.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($body) > 2000) {
    $body = mb_substr($body, 0, 2000);
}

// یک کاربر روی هر محصول فقط یک نظر
$exists = \DB::fetch(
    'SELECT id FROM product_reviews WHERE product_id = ? AND customer_id = ? LIMIT 1',
    [$productId, (int)$user['id']]
);
if ($exists) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'شما قبلاً برای این محصول نظر ثبت کرده‌اید.'], JSON_UNESCAPED_UNICODE);
    exit;
}

\DB::insert('product_reviews', [
    'product_id'    => $productId,
    'customer_id'   => (int)$user['id'],
    'customer_name' => $user['full_name'],
    'rating'        => $rating,
    'body'          => $body,
]);

echo json_encode(['success' => true]);
