<?php
/**
 * فروشگاه — سفارش‌های من
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

$shopTitle  = 'سفارش‌های من';
$shopActive = 'account';
require __DIR__ . '/../shop/_header.php';

$orders = Customers::orders((int)$shopUser['id']);
?>

<div class="page-head">
    <div class="breadcrumb"><a href="/">خانه</a> / حساب کاربری / سفارش‌ها</div>
    <h1>سلام <?= htmlspecialchars($shopUser['full_name']) ?> 👋</h1>
    <p>لیست سفارش‌های شما</p>
    <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
        <a href="/account/profile" class="btn btn-ghost btn-sm">⚙️ ویرایش اطلاعات</a>
        <a href="/shop/" class="btn btn-gold btn-sm">🛍️ ادامه خرید</a>
    </div>
</div>

<?php if ($orders): ?>
    <div class="form-card" style="padding:0; overflow-x:auto;">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>کد سفارش</th><th>تاریخ</th><th>اقلام</th><th>مبلغ</th>
                    <th>وضعیت</th><th>پرداخت</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td style="direction:ltr; text-align:right; font-weight:700;"><?= htmlspecialchars($o['order_no']) ?></td>
                    <td><?= Jalali::date($o['created_at']) ?></td>
                    <td><?= Price::faNumber((int)$o['total_toman']) !== '' ? '' : '' ?>—</td>
                    <td style="color:var(--shop-gold); font-weight:800;"><?= Price::faNumber((int)$o['total_toman']) ?> تومان</td>
                    <td><span class="status-pill st-<?= $o['order_status'] ?>"><?= Orders::STATUS_LABELS[$o['order_status']] ?? $o['order_status'] ?></span></td>
                    <td><span class="status-pill st-<?= $o['payment_status'] === 'paid' ? 'paid' : 'pending' ?>"><?= Orders::PAYMENT_LABELS[$o['payment_status']] ?? '' ?></span></td>
                    <td><a href="order.php?id=<?= (int)$o['id'] ?>" class="btn btn-ghost btn-sm">جزئیات</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="empty-state">
        <div class="ico">📦</div>
        <h3>هنوز سفارشی ثبت نکرده‌اید</h3>
        <p>اولین سفارش خود را از فروشگاه ثبت کنید.</p>
        <a href="/shop/" class="btn btn-gold">مشاهده محصولات</a>
    </div>
<?php endif; ?>

<div style="height:50px;"></div>
<?php require __DIR__ . '/../shop/_footer.php'; ?>
