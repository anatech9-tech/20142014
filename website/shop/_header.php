<?php
/**
 * فروشگاه — هدر مشترک
 * متغیرهای قابل استفاده: $shopTitle, $shopActive (home|shop|cart|checkout|account)
 */
require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Settings.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
require_once BASE_PATH . '/includes/shop/Rates.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Cart.php';
require_once BASE_PATH . '/includes/shop/Customers.php';
require_once BASE_PATH . '/includes/shop/Products.php';

use Shop\Jalali, Shop\Rates, Shop\Price, Shop\Cart, Shop\Customers, Shop\Products;

$shopTitle  = $shopTitle ?? SITE_NAME;
$shopActive = $shopActive ?? 'shop';
$usdRate    = Rates::getUsd();
$cartCount  = Cart::count();
$shopUser   = Customers::user();
$shopName   = (string)Settings::get('shop_name', 'فروشگاه درخت سپید');
$freeMin    = (int)Settings::get('shop_free_shipping_min', 0);
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($shopTitle) ?> — <?= SITE_NAME ?></title>
    <link rel="icon" href="/logo.webp" type="image/webp">
    <meta name="csrf-token" content="<?= htmlspecialchars(Auth::csrfToken()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/shop.css">
</head>
<body class="shop-body">

<div class="shop-topbar">
    <div class="wrap">
        <span>🚚 <?= $freeMin > 0 ? 'ارسال رایگان برای سفارش‌های بالای ' . Price::faNumber($freeMin) . ' تومان' : 'ارسال به سراسر کشور' ?> — ثبت سفارش امروز: <?= Jalali::date(date('Y-m-d H:i:s')) ?></span>
        <span class="rate">💵 دلار امروز: <?= Price::faNumber($usdRate) ?> تومان</span>
    </div>
</div>

<header class="shop-header">
    <div class="wrap">
        <a href="/" class="shop-logo">
            <img src="/logo.webp" alt="<?= SITE_NAME ?>">
            <span>
                <strong><?= htmlspecialchars($shopName) ?></strong>
                <small><?= SITE_NAME_EN ?></small>
            </span>
        </a>

        <nav class="shop-nav">
            <a href="/" <?= $shopActive === 'home' ? 'class="active"' : '' ?>>خانه</a>
            <a href="/shop/" <?= $shopActive === 'shop' ? 'class="active"' : '' ?>>فروشگاه</a>
            <a href="/blog/" <?= $shopActive === 'blog' ? 'class="active"' : '' ?>>بلاگ</a>
            <a href="/track/" <?= $shopActive === 'track' ? 'class="active"' : '' ?>>ردیابی محموله</a>
            <a href="/contact.php" <?= $shopActive === 'contact' ? 'class="active"' : '' ?>>تماس</a>
        </nav>

        <div class="shop-actions">
            <?php if ($shopUser): ?>
                <a href="/account/" class="icon-btn" title="حساب کاربری">👤</a>
            <?php else: ?>
                <a href="/account/login" class="btn btn-ghost btn-sm">ورود / ثبت‌نام</a>
            <?php endif; ?>
            <a href="/cart/" class="icon-btn" title="سبد خرید">
                🛒
                <span class="cart-count" data-count="<?= $cartCount ?>" style="<?= $cartCount ? '' : 'display:none' ?>"><?= $cartCount ?></span>
            </a>
        </div>
    </div>
</header>

<main class="wrap">
