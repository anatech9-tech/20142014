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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sts_json(405, ['success'=>false,'error'=>'روش نامعتبر است.']);
$id = (int)($_POST['id'] ?? 0);
$orderStatus = (string)($_POST['order_status'] ?? '');
$payStatus = (string)($_POST['payment_status'] ?? '');
$note = trim((string)($_POST['note'] ?? ''));
$expectedStatus = (string)($_POST['expected_status'] ?? '');
$expectedPayment = (string)($_POST['expected_payment'] ?? '');
$expectedEvent = (string)($_POST['expected_event_id'] ?? '');
if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $expectedEvent) || !array_key_exists($expectedStatus, Orders::STATUS_LABELS) || !array_key_exists($expectedPayment, Orders::PAYMENT_LABELS)
    || ($orderStatus === '' && $payStatus === '')) sts_json(400, ['success'=>false,'error'=>'صفحه را دوباره بارگذاری کنید.']);
$result = Orders::update($id, $orderStatus !== '' ? $orderStatus : null, $payStatus !== '' ? $payStatus : null, $note, $expectedStatus, $expectedPayment, (int)$expectedEvent);
if (!$result['success']) sts_json(409, $result);
sts_json(200, $result);
