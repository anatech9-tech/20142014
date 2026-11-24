<?php
declare(strict_types=1);

// --- Bootstrap ---
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../config.php';
}
require_once BASE_PATH . '/includes/db.php';
require_once BASE_PATH . '/includes/Auth.php';
require_once BASE_PATH . '/includes/shop/Orders.php';
require_once BASE_PATH . '/includes/shop/Price.php';
require_once BASE_PATH . '/includes/shop/Jalali.php';
// ------------------
Auth::requirePermission('shop.orders');
$pageTitle = 'سفارش‌های فروشگاه';
$activeMenu = 'shop-orders';

$status = (string)($_GET['status'] ?? '');
$orders = Shop\Orders::list($status ?: null, 200);

$stats = [
    'total'     => (int)DB::fetchColumn('SELECT COUNT(*) FROM orders'),
    'pending'   => (int)DB::fetchColumn("SELECT COUNT(*) FROM orders WHERE order_status = 'pending'"),
    'shipped'   => (int)DB::fetchColumn("SELECT COUNT(*) FROM orders WHERE order_status = 'shipped'"),
    'delivered' => (int)DB::fetchColumn("SELECT COUNT(*) FROM orders WHERE order_status = 'delivered'"),
    'revenue'   => (int)DB::fetchColumn("SELECT COALESCE(SUM(total_toman),0) FROM orders WHERE order_status != 'cancelled'"),
];
?>
<?php ob_start(); ?>
<div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; margin-bottom:20px;">
    <div class="stat-card"><div class="stat-value"><?= Shop\Jalali::toPersianDigits($stats['total']) ?></div><div class="stat-label">کل سفارش‌ها</div></div>
    <div class="stat-card" style="border-color:rgba(243,156,18,.4);"><div class="stat-value"><?= Shop\Jalali::toPersianDigits($stats['pending']) ?></div><div class="stat-label">در انتظار بررسی</div></div>
    <div class="stat-card"><div class="stat-value"><?= Shop\Jalali::toPersianDigits($stats['shipped']) ?></div><div class="stat-label">ارسال شده</div></div>
    <div class="stat-card"><div class="stat-value"><?= Shop\Jalali::toPersianDigits($stats['delivered']) ?></div><div class="stat-label">تحویل شده</div></div>
    <div class="stat-card" style="border-color:rgba(212,175,55,.5);"><div class="stat-value" style="font-size:1.2rem;"><?= Shop\Price::faNumber($stats['revenue']) ?></div><div class="stat-label">درآمد (تومان)</div></div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">سفارش‌ها</h2>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php foreach (['' => 'همه', 'pending' => 'در انتظار', 'confirmed' => 'تأیید شده', 'packed' => 'پک شده', 'shipped' => 'ارسال شده', 'delivered' => 'تحویل شده', 'cancelled' => 'لغو شده'] as $v => $label): ?>
                <a href="?status=<?= $v ?>" class="btn btn-sm <?= $status === $v ? 'btn-primary' : 'btn-secondary' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr><th>کد سفارش</th><th>مشتری</th><th>موبایل</th><th>مبلغ</th><th>پرداخت</th><th>وضعیت</th><th>تاریخ</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="8" style="text-align:center; color:var(--text-2); padding:30px;">سفارشی یافت نشد</td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): ?>
                <tr>
                    <td dir="ltr" style="text-align:right; font-weight:700;"><?= htmlspecialchars($o['order_no']) ?></td>
                    <td>
                        <?= htmlspecialchars($o['customer_name']) ?>
                        <?php if ($o['customer_id']): ?><br><small style="color:var(--text-2);">کاربر ثبت‌شده</small><?php endif; ?>
                    </td>
                    <td dir="ltr" style="text-align:right;"><?= htmlspecialchars($o['customer_phone']) ?></td>
                    <td style="white-space:nowrap; color:var(--gold, #d4af37); font-weight:700;"><?= Shop\Price::faNumber((int)$o['total_toman']) ?> ت</td>
                    <td>
                        <span class="badge <?= $o['payment_status'] === 'paid' ? 'success' : ($o['payment_status'] === 'pending' ? 'warning' : 'error') ?>">
                            <?= Shop\Orders::PAYMENT_LABELS[$o['payment_status']] ?? $o['payment_status'] ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?= [
                            'pending' => 'warning', 'confirmed' => 'new', 'packed' => 'in_progress',
                            'shipped' => 'replied', 'delivered' => 'success', 'cancelled' => 'spam',
                        ][$o['order_status']] ?? 'muted' ?>">
                            <?= Shop\Orders::STATUS_LABELS[$o['order_status']] ?? $o['order_status'] ?>
                        </span>
                    </td>
                    <td><small><?= Shop\Jalali::dateTime($o['created_at']) ?></small></td>
                    <td><a href="shop-order-view.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-secondary">مشاهده</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$contentEcho = ob_get_clean();
include __DIR__ . '/includes/layout.php';
