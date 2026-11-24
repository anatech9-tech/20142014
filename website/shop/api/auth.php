<?php
/**
 * فروشگاه — API احراز هویت مشتری (ورود، ثبت‌نام، پروفایل، رمز، خروج)
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) require_once __DIR__ . '/../../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Customers.php';

use Shop\Customers;

header('Content-Type: application/json; charset=utf-8');

// ورود/ثبت‌نام بدون CSRF هم از JSON می‌آیند؛ ولی CSRF را برای همه بررسی می‌کنیم
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!Auth::verifyCsrf($csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'توکن امنیتی نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mode = $_POST['mode'] ?? 'login';

switch ($mode) {
    case 'register':
        $r = Customers::register($_POST);
        if ($r['success']) {
            echo json_encode(['success' => true, 'redirect' => '/account/orders.php'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'errors' => $r['errors']], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'login':
        $r = Customers::login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($r['success']) {
            echo json_encode(['success' => true, 'redirect' => '/account/orders.php'], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'errors' => [$r['error']]], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'profile':
        if (!Customers::isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'وارد نشده‌اید.']); break; }
        $r = Customers::updateProfile((int)Customers::user()['id'], $_POST);
        if ($r['success']) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'errors' => $r['errors']], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'password':
        if (!Customers::isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'وارد نشده‌اید.']); break; }
        $r = Customers::changePassword(
            (int)Customers::user()['id'],
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? '')
        );
        if ($r['success']) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(422);
            echo json_encode(['success' => false, 'errors' => [$r['error']]], JSON_UNESCAPED_UNICODE);
        }
        break;

    case 'logout':
        Customers::logout();
        echo json_encode(['success' => true, 'redirect' => '/account/login.php']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'عملیات نامعتبر.']);
}
