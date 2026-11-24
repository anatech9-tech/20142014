<?php
/**
 * فروشگاه — جزئیات یک سفارش (مشتری)
 */
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
// ------------------

use Shop\Jalali, Shop\Price, Shop\Customers, Shop\Orders;

if (!Customers::isLoggedIn()) {
    header('Location: /account/login');
    exit;
}
$shopUser = Customers::user();
$orderId  = (int)($_GET['id'] ?? 0);
$order    = Orders::byId($orderId);

// فقط مالک می‌تواند ببیند
if (!$order || (int)$order['customer_id'] !== (int)$shopUser['id']) {
    header('Location: /account/orders');
    exit;
}

$shopTitle  = 'سفارش ' . $order['order_no'];
$shopActive = 'account';
require __DIR__ . '/../shop/_header.php';
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / <a href="orders.php">سفارش‌های من</a> / جزئیات</div>
    <h1>سفارش <span dir="ltr" style="color:var(--shop-gold);"><?= htmlspecialchars($order['order_no']) ?></span></h1>
    <p>ثبت‌شده در <?= Jalali::dateTime($order['created_at']) ?></p>
</div>

<div style="display:grid; grid-template-columns:1fr 340px; gap:24px; padding-bottom:50px; align-items:start;">
    <div>
        <div class="form-card" style="padding:0; overflow-x:auto; margin-bottom:20px;">
            <table class="orders-table">
                <thead><tr><th>کالا</th><th>قیمت واحد</th><th>تعداد</th><th>جمع</th></tr></thead>
                <tbody>
                <?php foreach ($order['items'] as $it): ?>
                    <tr>
                        <td>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <?php if ($it['product_image']): ?><img src="<?= htmlspecialchars($it['product_image']) ?>" style="width:48px;height:48px;border-radius:10px;object-fit:cover;background:#f4f5f7;" alt=""><?php endif; ?>
                                <span><?= htmlspecialchars($it['product_name']) ?></span>
                            </div>
                        </td>
                        <td><?= Price::faNumber((int)$it['price_toman']) ?></td>
                        <td><?= Price::faNumber((int)$it['qty']) ?></td>
                        <td style="color:var(--shop-gold); font-weight:700;"><?= Price::faNumber((int)$it['total_toman']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-card">
            <h3 style="color:#fff; margin-top:0;">تاریخچه سفارش</h3>
            <ul class="timeline">
                <?php foreach ($order['events'] as $i => $ev): ?>
                    <li class="<?= $i > 0 ? 'old' : '' ?>">
                        <div class="tl-status"><?= Orders::STATUS_LABELS[$ev['status']] ?? $ev['status'] ?></div>
                        <div class="tl-date"><?= Jalali::dateTime($ev['created_at']) ?></div>
                        <?php if ($ev['note']): ?><div class="tl-note"><?= htmlspecialchars($ev['note']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div>
        <div class="summary-card" style="position:static;">
            <h3>خلاصه</h3>
            <div class="sum-row"><span>جمع کالاها</span><span><?= Price::faNumber((int)$order['subtotal_toman']) ?> تومان</span></div>
            <?php if ($order['discount_toman']): ?><div class="sum-row"><span>تخفیف</span><span style="color:var(--shop-green);">− <?= Price::faNumber((int)$order['discount_toman']) ?></span></div><?php endif; ?>
            <div class="sum-row"><span>ارسال</span><span><?= $order['shipping_fee'] ? Price::faNumber((int)$order['shipping_fee']) . ' تومان' : 'رایگان' ?></span></div>
            <div class="sum-row total"><span>مجموع</span><span class="val"><?= Price::faNumber((int)$order['total_toman']) ?> تومان</span></div>
            <div style="margin-top:16px;">
                <span class="status-pill st-<?= $order['order_status'] ?>" style="font-size:.85rem;"><?= Orders::STATUS_LABELS[$order['order_status']] ?? '' ?></span>
                <span class="status-pill st-<?= $order['payment_status'] === 'paid' ? 'paid' : 'pending' ?>" style="font-size:.85rem;"><?= Orders::PAYMENT_LABELS[$order['payment_status']] ?? '' ?></span>
            </div>
        </div>
        <div class="form-card" style="margin-top:16px; font-size:.9rem; line-height:2;">
            <strong style="color:#fff;">📍 آدرس ارسال</strong>
            <div><?= htmlspecialchars($order['customer_name']) ?></div>
            <div><?= htmlspecialchars($order['address']) ?></div>
            <?php if ($order['city'] || $order['province']): ?><div><?= htmlspecialchars(trim($order['province'] . '، ' . $order['city'])) ?></div><?php endif; ?>
            <?php if ($order['postal_code']): ?><div>کد پستی: <?= htmlspecialchars($order['postal_code']) ?></div><?php endif; ?>
            <div dir="ltr" style="text-align:right;">📞 <?= htmlspecialchars($order['customer_phone']) ?></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../shop/_footer.php'; ?>
