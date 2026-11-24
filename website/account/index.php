<?php
/**
 * فروشگاه — حساب کاربری (مرکزی)
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Customers;

if (!Customers::isLoggedIn()) {
    header('Location: /account/login');
    exit;
}
header('Location: /account/orders');
exit;
