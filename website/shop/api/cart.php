<?php
/**
 * فروشگاه — API سبد خرید
 * actions: add | set | remove | coupon | coupon_remove
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Cart.php';
require_once BASE_PATH . '/includes/shop/Products.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Coupons.php';

use Shop\Cart;

header('Content-Type: application/json; charset=utf-8');

$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Auth::verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $r = Cart::add((int)($_POST['id'] ?? 0), (int)($_POST['qty'] ?? 1));
        break;

    case 'set':
        $r = Cart::setQty((int)($_POST['id'] ?? 0), (int)($_POST['qty'] ?? 0));
        break;

    case 'remove':
        $r = Cart::remove((int)($_POST['id'] ?? 0));
        break;

    case 'coupon':
        $r = Cart::applyCoupon((string)($_POST['code'] ?? ''));
        if ($r['success']) {
            $r['discount_label'] = \Shop\Price::faNumber($r['discount']) . ' تومان';
        }
        break;

    case 'coupon_remove':
        Cart::removeCoupon();
        $r = ['success' => true, 'count' => Cart::count()];
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'عملیات نامعتبر.'], JSON_UNESCAPED_UNICODE);
        exit;
}

if (isset($r['success']) && !$r['success']) {
    http_response_code(422);
}
echo json_encode($r, JSON_UNESCAPED_UNICODE);
