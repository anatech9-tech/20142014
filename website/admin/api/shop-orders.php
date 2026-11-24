<?php
/**
 * Admin API — سفارش‌های فروشگاه (تغییر وضعیت/پرداخت)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Orders.php';

use Shop\Orders;

Auth::requireLogin();
if (!Auth::verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    sts_json(403, ['success' => false, 'error' => 'CSRF']);
}
Auth::requirePermission('shop.orders');

$id = (int)($_POST['id'] ?? 0);
$order = DB::fetch('SELECT * FROM orders WHERE id = ? LIMIT 1', [$id]);
if (!$order) sts_json(404, ['success' => false, 'error' => 'سفارش یافت نشد.']);

$orderStatus = (string)($_POST['order_status'] ?? '');
$payStatus   = (string)($_POST['payment_status'] ?? '');
$note        = trim((string)($_POST['note'] ?? ''));

if ($orderStatus !== '' && array_key_exists($orderStatus, Orders::STATUS_LABELS)) {
    Orders::setStatus($id, $orderStatus, $note);
}
if ($payStatus !== '' && array_key_exists($payStatus, Orders::PAYMENT_LABELS)) {
    Orders::setPaymentStatus($id, $payStatus);
}

sts_json(200, ['success' => true]);
