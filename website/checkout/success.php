<?php
/**
 * فروشگاه — صفحه تأیید ثبت سفارش
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Jalali, Shop\Price, Shop\Orders;

$orderNo = (string)($_GET['n'] ?? '');
$token   = (string)($_GET['t'] ?? '');
$order   = Orders::byNo($orderNo, $token);

$shopTitle  = 'سفارش ثبت شد';
$shopActive = 'shop';
require __DIR__ . '/../shop/_header.php';

if (!$order) {
    echo '<div class="empty-state"><div class="ico">😕</div><h1>سفارش یافت نشد</h1><p>لینک نامعتبر است یا منقضی شده است.</p><a href="/shop/" class="btn btn-gold">بازگشت به فروشگاه</a></div>';
    require __DIR__ . '/../shop/_footer.php';
    exit;
}
?>

<div class="success-hero">
    <div class="check">✓</div>
    <h1>سفارش شما با موفقیت ثبت شد! 🎉</h1>
    <p>کد پیگیری سفارش شما:</p>
    <div class="order-no-box" dir="ltr"><?= htmlspecialchars($order['order_no']) ?></div>
    <p style="max-width:520px; margin:10px auto 0;">
        تیم درخت سپید در اسرع وقت سفارش شما را بررسی و برای هماهنگی ارسال و پرداخت با شما تماس می‌گیرد.
        شماره تماس ثبت‌شده: <strong style="color:#fff;"><?= htmlspecialchars($order['customer_phone']) ?></strong>
    </p>
    <div style="margin-top:26px; display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
        <a href="/account/orders" class="btn btn-gold">پیگیری سفارش‌هایم</a>
        <a href="/shop/" class="btn btn-ghost">ادامه خرید</a>
    </div>
</div>

<div class="form-card" style="max-width:760px; margin:0 auto 50px;">
    <h3 style="color:#fff; margin-top:0;">خلاصه سفارش</h3>
    <table class="orders-table">
        <thead><tr><th>کالا</th><th>تعداد</th><th>قیمت</th><th>جمع</th></tr></thead>
        <tbody>
        <?php foreach ($order['items'] as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['product_name']) ?></td>
                <td><?= Price::faNumber((int)$it['qty']) ?></td>
                <td><?= Price::faNumber((int)$it['price_toman']) ?></td>
                <td style="color:var(--shop-gold); font-weight:700;"><?= Price::faNumber((int)$it['total_toman']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div style="margin-top:16px; display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
        <span>جمع کالاها: <strong><?= Price::faNumber((int)$order['subtotal_toman']) ?> تومان</strong></span>
        <?php if ($order['discount_toman']): ?><span>تخفیف: <strong style="color:var(--shop-green);">− <?= Price::faNumber((int)$order['discount_toman']) ?> تومان</strong></span><?php endif; ?>
        <span>هزینه ارسال: <strong><?= $order['shipping_fee'] ? Price::faNumber((int)$order['shipping_fee']) . ' تومان' : 'رایگان' ?></strong></span>
        <span style="font-size:1.15rem; color:var(--shop-gold); font-weight:900;">مبلغ کل: <?= Price::faNumber((int)$order['total_toman']) ?> تومان</span>
        <?php if ($order['usd_rate']): ?>
            <span style="color:var(--shop-muted); font-size:.78rem;">(نرخ دلار لحظه ثبت: <?= Price::faNumber((int)$order['usd_rate']) ?> تومان)</span>
        <?php endif; ?>
    </div>
    <div class="alert info" style="margin-bottom:0;">
        📍 آدرس ارسال: <?= htmlspecialchars($order['address']) ?>
        <?php if ($order['city']): ?>— <?= htmlspecialchars($order['province']) ?>، <?= htmlspecialchars($order['city']) ?><?php endif; ?>
        (<?= Jalali::dateTime($order['created_at']) ?>)
    </div>
</div>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
