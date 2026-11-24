<?php
/**
 * فروشگاه — API ثبت سفارش
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Orders.php';
require_once BASE_PATH . '/includes/shop/Cart.php';
require_once BASE_PATH . '/includes/shop/Customers.php';
require_once BASE_PATH . '/includes/shop/Rates.php';

use Shop\Orders;

header('Content-Type: application/json; charset=utf-8');

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Auth::verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
    exit;
}

// بدنه می‌تواند JSON یا form باشد
$input = json_decode(file_get_contents('php://input') ?: '', true);
$data = is_array($input) && !empty($input) ? $input : $_POST;

$r = Orders::create($data);
if ($r['success']) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(422);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
}
