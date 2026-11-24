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
$shopLanding = !empty($shopLanding);
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
    <?php if ($shopLanding): ?><link rel="stylesheet" href="/css/shop-home.css?v=1"><?php endif; ?>
</head>
<body class="shop-body<?= $shopLanding ? ' shop-home' : '' ?>">
<?php if ($shopLanding): ?>
<div class="market-announcement"><div class="wrap"><span>انتخابی تازه، برای هر روز</span><a href="/#contact">راهنمایی و ارتباط با ما <span aria-hidden="true">←</span></a></div></div>
<header class="market-header">
  <div class="wrap market-header-main">
    <a href="/shop/" class="market-brand" aria-label="صفحه اصلی فروشگاه درخت سپید"><img src="/logo.webp" alt=""><span><strong><?= htmlspecialchars($shopName, ENT_QUOTES, 'UTF-8') ?></strong><small>تجارت فراتر از مرزها</small></span></a>
    <form class="market-search" action="/shop/" method="get" role="search"><label class="sr-only" for="market-search">جست‌وجوی محصول</label><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5"/><path d="m16 16 4 4"/></svg><input id="market-search" name="q" type="search" value="<?= htmlspecialchars(trim((string)($_GET['q'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" placeholder="دنبال چه کالایی می‌گردی؟"><button type="submit">جست‌وجو</button></form>
    <div class="market-actions"><a class="market-account" href="<?= $shopUser ? '/account/' : '/account/login.php' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.2-4 2.7-6 7-6s6.8 2 7 6"/></svg><span><?= $shopUser ? 'حساب من' : 'ورود / ثبت‌نام' ?></span></a><a href="/cart/" class="market-cart" aria-label="سبد خرید"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2 11h11l2-8H6"/><circle cx="9" cy="19" r="1"/><circle cx="17" cy="19" r="1"/></svg><span class="cart-count" data-count="<?= (int)$cartCount ?>" style="<?= $cartCount ? '' : 'display:none' ?>"><?= (int)$cartCount ?></span></a></div>
  </div>
  <nav class="market-nav wrap" aria-label="منوی فروشگاه"><a class="market-nav-primary" href="/shop/#shop-categories"><span aria-hidden="true">☰</span> دسته‌بندی کالاها</a><a href="/shop/#shop-products">همه محصولات</a><a href="/track/">پیگیری محموله</a><a href="/blog/">بلاگ</a><a href="/#contact">تماس با ما</a><a class="market-nav-home" href="/">سایت درخت سپید <span aria-hidden="true">↗</span></a></nav>
</header>
<?php else: ?>

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
            <a href="/#contact" <?= $shopActive === 'contact' ? 'class="active"' : '' ?>>تماس</a>
        </nav>

        <div class="shop-actions">
            <?php if ($shopUser): ?>
                <a href="/account/" class="icon-btn" title="حساب کاربری">👤</a>
            <?php else: ?>
                <a href="/account/login.php" class="btn btn-ghost btn-sm">ورود / ثبت‌نام</a>
            <?php endif; ?>
            <a href="/cart/" class="icon-btn" title="سبد خرید">
                🛒
                <span class="cart-count" data-count="<?= $cartCount ?>" style="<?= $cartCount ? '' : 'display:none' ?>"><?= $cartCount ?></span>
            </a>
        </div>
    </div>
</header>

<?php endif; ?>
<main class="wrap">
